"""Pure source-shaped census/privilege compilation. No SQL or file transport.

Complete physical originals remain separate from normalized privileges. The
returned Projection confers no live authority and is not a gate readback.
"""
from dataclasses import dataclass, replace
import re
from containment_schema import (ContainmentError, ControlContext, canonical,
    digest, document, integer, keys, require, sha, text)
from sql_fence_schema import (Principal, GrantRow, GrantModel, BusinessRequirement,
    LEVEL_PRIVILEGES, ROLES, SqlFenceError)
from sql_fence_projection import Projection, GrantOperation, BUSINESS, _diff
from sql_fence_driver import COLUMNS, FLAGS, NONPRIV, _cell, _unpattern


@dataclass(frozen=True)
class SourceCensus:
    model: GrantModel
    grant_tables: tuple
    protected: str
    preserved: tuple[str, ...]
    original_sha256: str
    nonprivilege_sha256: str


def _name(value):
    require(type(value) is str and
            re.fullmatch('[A-Za-z_][A-Za-z0-9_%]{0,63}', value), 'sql-name')
    return value


def _principal(user, host):
    require(type(user) is str and
            re.fullmatch('[A-Za-z0-9_][A-Za-z0-9_-]{0,15}', user), 'sql-principal')
    require(host in ('localhost', '127.0.0.1', '%', '127.0.0.%'), 'sql-host-unqualified')
    return user, host


def _decoded(table, row):
    require(type(row) is list and len(row) == len(COLUMNS[table]), 'sql-grant-columns')
    try:
        return {key: _cell(value) for key, value in zip(COLUMNS[table], row)}
    except SqlFenceError as exc:
        raise ContainmentError(exc.code) from None


def _string(value):
    require(type(value) is bytes, 'sql-null-identity')
    try: return value.decode('ascii')
    except UnicodeError: raise ContainmentError('sql-identity-encoding') from None


def _literal_pattern(value):
    try: return _unpattern(value)
    except SqlFenceError as exc: raise ContainmentError(exc.code) from None


def parse_source_census(raw, context):
    require(type(context) is ControlContext)
    v = document(raw)
    keys(v, 'schema_version purpose runtime_version source_boot_id profile_sha256 '
            'adapter_manifest_sha256 authority_sha256 exclusive_manual_administration '
            'protected_schema preserved_schemas nonbusiness_schemas schemas objects '
            'principals connection_paths authenticated_actors grant_tables definitions')
    integer(v['schema_version'], 1, 1)
    require(v['purpose'] == 'source-containment-sql-census' and
            v['runtime_version'] == '5.5.68-MariaDB', 'sql-census-purpose')
    require(v['source_boot_id'] == context.source_boot_id and
            v['profile_sha256'] == context.profile_sha256 and
            v['adapter_manifest_sha256'] == context.adapter_manifest_sha256 and
            v['authority_sha256'] == context.authority_ref.sha256, 'sql-context')
    require(v['exclusive_manual_administration'] is True, 'sql-maintenance-unaccepted')
    for slot in ('preserved_schemas', 'nonbusiness_schemas', 'schemas', 'objects',
                 'principals', 'connection_paths', 'authenticated_actors', 'definitions'):
        require(type(v[slot]) is list, 'sql-census-form')
    protected = _name(v['protected_schema'])
    preserved = tuple(_name(x) for x in v['preserved_schemas'])
    nonbusiness = tuple(_name(x) for x in v['nonbusiness_schemas'])
    schemas = tuple(sorted(_name(x) for x in v['schemas']))
    require(len(preserved) == 3 and len(set(preserved)) == 3 and
            protected not in preserved and len(set(schemas)) == len(schemas) and
            not set(nonbusiness) & {protected, *preserved} and
            set(schemas) == {protected, *preserved, *nonbusiness}, 'sql-schema-scope')
    require(not v['definitions'], 'sql-definer-escape')
    objects = set()
    for row in v['objects']:
        keys(row, 'schema kind name definition_sha256')
        require(row['schema'] in schemas and row['kind'] == 'table', 'sql-definer-escape')
        _name(row['name']); sha(row['definition_sha256'])
        identity = row['schema'], row['name']
        require(identity not in objects, 'sql-object-duplicate'); objects.add(identity)
    require(any(schema == protected for schema, _ in objects), 'sql-object-census')
    roles = {}
    for p in v['principals']:
        keys(p, 'user host role')
        principal = _principal(p['user'], p['host'])
        require(principal not in roles and p['role'] in ROLES, 'sql-principal')
        roles[principal] = p['role']
    require(set(roles.values()) == ROLES, 'sql-roles')
    for user in {p[0] for p in roles}:
        require(len({role for p, role in roles.items() if p[0] == user}) == 1,
                'sql-host-role-ambiguity')
    paths = {}
    for p in v['connection_paths']:
        keys(p, 'path_id kind'); text(p['path_id'])
        require(p['path_id'] not in paths and p['kind'] in ('unix', 'loopback-tcp'),
                'sql-connection-path')
        paths[p['path_id']] = p['kind']
    attributed = set(); actors = set()
    for actor in v['authenticated_actors']:
        keys(actor, 'actor_id user host role mode path_id current_user')
        text(actor['actor_id'])
        principal = _principal(actor['user'], actor['host'])
        require(principal in roles and actor['role'] == roles[principal] and
                actor['actor_id'] not in actors and actor['path_id'] in paths and
                actor['current_user'] == actor['user']+'@'+actor['host'],
                'sql-attribution-incomplete')
        require(actor['mode'] in ('manual', 'automatic'), 'sql-attribution-incomplete')
        if actor['role'] == 'administrator':
            require(actor['mode'] == 'manual', 'sql-automatic-administrator')
        else:
            require(actor['mode'] == 'automatic', 'sql-attribution-incomplete')
        require((actor['host'] == 'localhost' and paths[actor['path_id']] == 'unix') or
                (actor['host'] != 'localhost' and paths[actor['path_id']] == 'loopback-tcp'),
                'sql-connection-path')
        actors.add(actor['actor_id']); attributed.add(principal)
    require(attributed == set(roles), 'sql-attribution-incomplete')
    tables = v['grant_tables']
    require(type(tables) is dict and set(tables) == set(COLUMNS), 'sql-grant-census')
    grants = []; users = set(); grant_keys = set(); nonpriv = []; retained = []
    declared_columns = {}; actual_columns = {}
    flag_names = {k+'_priv': privilege for k, privilege in FLAGS}
    for table in sorted(COLUMNS):
        require(type(tables[table]) is list, 'sql-grant-census')
        retained.append((table, tuple(tuple(r) for r in tables[table])))
        require(table not in ('host', 'procs_priv') or not tables[table], 'sql-unsupported-graph')
        for row in tables[table]:
            cells = _decoded(table, row)
            principal = _principal(_string(cells['User']), _string(cells['Host']))
            require(principal in roles, 'sql-unmodeled-principal')
            level = {'user': 'global', 'db': 'database', 'tables_priv': 'table',
                     'columns_priv': 'column', 'proxies_priv': 'proxy'}[table]
            target = ()
            if table in ('user', 'db'):
                relevant = {k: p for k, p in flag_names.items() if k in cells}
                require(all(cells[k] in (b'Y', b'N') for k in relevant), 'sql-privilege-value')
                privileges = tuple(sorted(p for k, p in relevant.items() if cells[k] == b'Y'))
                if table == 'user':
                    require(principal not in users, 'sql-account-duplicate'); users.add(principal)
                    nonpriv.append(tuple(row[COLUMNS['user'].index(k)] for k in NONPRIV))
                    require(cells['plugin'] in (b'', b'mysql_native_password'), 'sql-auth-plugin-unqualified')
                else:
                    target = (_literal_pattern(_string(cells['Db'])),)
            elif table == 'proxies_priv':
                target = _principal(_string(cells['Proxied_user']), _string(cells['Proxied_host']))
                require(target in roles and roles[principal] == roles[target] == 'administrator',
                        'sql-proxy-escape')
                require(cells['With_grant'] in (b'0', b'1'), 'sql-proxy-value')
                privileges = ('GRANT OPTION', 'PROXY') if cells['With_grant'] == b'1' else ('PROXY',)
            else:
                target = (_string(cells['Db']), _name(_string(cells['Table_name'])))
                field = 'Table_priv'
                if table == 'columns_priv':
                    target += (_name(_string(cells['Column_name'])),); field = 'Column_priv'
                privileges = tuple(sorted('GRANT OPTION' if x.upper() == 'GRANT' else x.upper()
                    for x in _string(cells[field]).split(',') if x))
                summary_key = principal, target[:2]
                if table == 'tables_priv':
                    summary = tuple(x.upper() for x in _string(cells['Column_priv']).split(',') if x)
                    require(set(summary) <= LEVEL_PRIVILEGES['column'] and
                            len(summary) == len(set(summary)), 'sql-column-summary')
                    if summary: declared_columns[summary_key] = set(summary)
                else:
                    actual_columns.setdefault(summary_key, set()).update(privileges)
            if level not in ('global', 'proxy'):
                require(target[0] in schemas, 'sql-schema-scope')
                if level in ('table', 'column'):
                    require(target[:2] in objects, 'sql-object-census')
            require(set(privileges) <= LEVEL_PRIVILEGES[level] and
                    len(set(privileges)) == len(privileges), 'sql-privilege')
            key = principal, level, target
            require(key not in grant_keys, 'sql-grant-duplicate'); grant_keys.add(key)
            if privileges: grants.append(GrantRow(principal, level, target, privileges))
    require(users == set(roles), 'sql-account-census')
    require(declared_columns == actual_columns, 'sql-column-summary')
    model = GrantModel(schemas,
        tuple(Principal(*p, role) for p, role in sorted(roles.items())),
        tuple(sorted(grants, key=lambda g: (g.principal, g.level, g.target))), ())
    return SourceCensus(model, tuple(retained), protected, preserved, digest(raw),
                        digest(canonical(nonpriv)))


def apply_source_operation(model, operation):
    require(type(model) is GrantModel and type(operation) is GrantOperation, 'sql-operation')
    kind = operation.kind.split(':')
    require(len(kind) == 2 and kind[0] in ('grant', 'revoke') and kind[1] in LEVEL_PRIVILEGES,
            'sql-operation')
    action, level = kind
    require(operation.principal in {(p.user, p.host) for p in model.principals} and
            set(operation.privileges) <= LEVEL_PRIVILEGES[level], 'sql-operation')
    require(len(operation.target) == {'global': 0, 'database': 1, 'table': 2,
            'column': 3, 'routine': 3, 'proxy': 2}[level], 'sql-operation')
    if level not in ('global', 'proxy'):
        require(operation.target[0] in model.schemas, 'sql-operation')
    rows = {(g.principal, g.level, g.target): set(g.privileges) for g in model.grants}
    key = operation.principal, level, operation.target
    before = rows.get(key, set())
    after = before | set(operation.privileges) if action == 'grant' else before-set(operation.privileges)
    if after: rows[key] = after
    else: rows.pop(key, None)
    return replace(model, grants=tuple(GrantRow(k[0], k[1], k[2], tuple(sorted(v)))
                   for k, v in sorted(rows.items())))


def compile_source_projection(census, context, requirements):
    parsed = parse_source_census(census, context)
    model, protected, preserved = parsed.model, parsed.protected, parsed.preserved
    roles = {(p.user, p.host): p.role for p in model.principals}
    held = []
    for grant in model.grants:
        role = roles[grant.principal]
        if role == 'administrator': held.append(grant)
        elif role in ('collector', 'keeper'):
            allowed = {'SELECT'} if role == 'collector' else {'SELECT', 'LOCK TABLES'}
            require(grant.level in ('database', 'table', 'column') and
                    grant.target[0] == protected and set(grant.privileges) <= allowed,
                    'sql-readonly-role')
            held.append(grant)
    require(type(requirements) is tuple, 'sql-business-form')
    seen = set()
    for req in requirements:
        require(type(req) is BusinessRequirement and req.schema in preserved and
                req.role in ('writer', 'shared_writer') and req.privileges and
                set(req.privileges) <= BUSINESS, 'sql-business-escape')
        require((req.schema, req.role) not in seen, 'sql-business-duplicate')
        seen.add((req.schema, req.role))
        actors = [p for p, role in roles.items() if role == req.role]
        require(actors, 'sql-business-principal')
        for principal in actors:
            available = set()
            for grant in model.grants:
                if grant.principal == principal and (grant.level == 'global' or
                        (grant.level == 'database' and grant.target == (req.schema,))):
                    available.update(grant.privileges)
            require(set(req.privileges) <= available, 'sql-business-unproven')
            held.append(GrantRow(principal, 'database', (req.schema,), tuple(sorted(req.privileges))))
    require({req.schema for req in requirements} == set(preserved), 'sql-business-scope')
    held_model = replace(model, grants=tuple(sorted(held, key=lambda g: (g.principal, g.level, g.target))))
    return Projection(model, held_model, _diff(model, held_model), _diff(held_model, model))
