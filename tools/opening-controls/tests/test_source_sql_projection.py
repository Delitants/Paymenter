"""Source graph tests catch omitted authority, proxy and grant-pattern escapes."""
from pathlib import Path
import copy
import hashlib
import importlib
import json
import sys
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from containment_schema import ControlContext, EvidenceRef, ContainmentError, canonical
from sql_fence_schema import BusinessRequirement
from sql_fence_driver import COLUMNS, FLAGS


def native_row(table, **values):
    defaults = {k: ('N' if k.endswith('_priv') else '') for k in COLUMNS[table]}
    defaults.update(values)
    return ['H'+str(defaults[k]).encode().hex().upper() for k in COLUMNS[table]]


def source_fixture():
    context = ControlContext('session', 'freeze', 1, 'fictional-boot', 'a'*64,
        'b'*64, EvidenceRef('/private/authority', 'c'*64, 12),
        EvidenceRef('/private/budget', 'd'*64, 12))
    actors = [('svc_writer', 'localhost', 'writer', 'automatic'),
              ('core_writer', 'localhost', 'shared_writer', 'automatic'),
              ('reader', 'localhost', 'collector', 'automatic'),
              ('keeper', 'localhost', 'keeper', 'automatic'),
              ('root', 'localhost', 'administrator', 'manual'),
              ('root', '127.0.0.1', 'administrator', 'manual')]
    tables = {k: [] for k in COLUMNS}
    for user, host, role, mode in actors:
        values = {'Host': host, 'User': user, 'Password': '*FICTIONALHASH',
                  'max_questions': '10', 'max_updates': '11',
                  'max_connections': '12', 'max_user_connections': '13',
                  'ssl_cipher': 'fixture-cipher', 'x509_issuer': 'fixture-issuer'}
        if role in ('writer', 'shared_writer', 'administrator'):
            values.update({k+'_priv': 'Y' for k, _ in FLAGS})
        tables['user'].append(native_row('user', **values))
        if role in ('collector', 'keeper'):
            tables['db'].append(native_row('db', Host=host, User=user,
                Db=r'source\_app', Select_priv='Y'))
    tables['columns_priv'].append(native_row('columns_priv', Host='localhost',
        User='svc_writer', Db='source_app', Table_name='t000', Column_name='value',
        Timestamp='2026-01-01 00:00:00', Column_priv='Update'))
    tables['tables_priv'].append(native_row('tables_priv', Host='localhost',
        User='svc_writer', Db='source_app', Table_name='t000',
        Grantor='root@localhost', Timestamp='2026-01-01 00:00:00',
        Table_priv='', Column_priv='Update'))
    tables['proxies_priv'].append(native_row('proxies_priv', Host='localhost',
        User='root', Proxied_host='127.0.0.1', Proxied_user='root', With_grant='1',
        Grantor='root@localhost', Timestamp='2026-01-01 00:00:00'))
    census = {'schema_version': 1, 'purpose': 'source-containment-sql-census',
        'runtime_version': '5.5.68-MariaDB', 'source_boot_id': context.source_boot_id,
        'profile_sha256': context.profile_sha256,
        'adapter_manifest_sha256': context.adapter_manifest_sha256,
        'authority_sha256': context.authority_ref.sha256,
        'exclusive_manual_administration': True, 'protected_schema': 'source_app',
        'preserved_schemas': ['other_one', 'other_two', 'other_three'],
        'nonbusiness_schemas': ['sourceXapp'],
        'schemas': ['source_app', 'sourceXapp', 'other_one', 'other_two', 'other_three'],
        'objects': [{'schema': 'source_app', 'kind': 'table', 'name': 't000',
                     'definition_sha256': 'e'*64}],
        'principals': [{'user': user, 'host': host, 'role': role}
                       for user, host, role, _ in actors],
        'connection_paths': [{'path_id': 'unix', 'kind': 'unix'},
                             {'path_id': 'loopback', 'kind': 'loopback-tcp'}],
        'authenticated_actors': [{'actor_id': 'actor-'+str(n), 'user': user,
            'host': host, 'role': role, 'mode': mode,
            'path_id': 'unix' if host == 'localhost' else 'loopback',
            'current_user': user+'@'+host}
            for n, (user, host, role, mode) in enumerate(actors)],
        'grant_tables': tables, 'definitions': []}
    business = tuple(BusinessRequirement(schema, 'shared_writer',
        ('SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX'))
        for schema in ('other_one', 'other_two', 'other_three'))
    return context, census, business


class SourceSqlProjectionTests(unittest.TestCase):
    def setUp(self):
        try: self.p = importlib.import_module('source_sql_projection')
        except ModuleNotFoundError:
            self.fail('source-shaped SQL compiler is not implemented')
        self.context, self.census, self.business = source_fixture()

    def compile(self, census=None, business=None):
        return self.p.compile_source_projection(canonical(census or self.census),
            self.context, self.business if business is None else business)

    def test_global_and_column_escape_removed(self):
        projection = self.compile()
        self.assertTrue(any(g.level == 'column' for g in projection.before.grants))
        for grant in projection.held.grants:
            actor = next(p for p in projection.held.principals
                         if (p.user, p.host) == grant.principal)
            if actor.role in ('writer', 'shared_writer'):
                self.assertEqual(grant.level, 'database')
                self.assertIn(grant.target[0], ('other_one', 'other_two', 'other_three'))
                self.assertFalse(set(grant.privileges) & {'SUPER', 'FILE', 'GRANT OPTION'})
        granted = [g for g in projection.held.grants if g.principal == ('core_writer', 'localhost')]
        self.assertEqual({g.target[0] for g in granted}, {'other_one', 'other_two', 'other_three'})
        self.assertEqual({p.user for p in projection.before.principals},
                         {'svc_writer', 'core_writer', 'reader', 'keeper', 'root'})

    def test_literal_pattern_collision(self):
        self.compile()
        self.census['grant_tables']['db'][0][COLUMNS['db'].index('Db')] = 'H'+b'source_app'.hex().upper()
        with self.assertRaisesRegex(ContainmentError, 'sql-database-pattern'):
            self.compile()

    def test_proxy_reaches_writer_rejected(self):
        self.census['grant_tables']['proxies_priv'].append(native_row('proxies_priv',
            Host='localhost', User='svc_writer', Proxied_host='localhost',
            Proxied_user='root', With_grant='0', Grantor='root@localhost'))
        with self.assertRaisesRegex(ContainmentError, 'sql-proxy-escape'):
            self.compile()

    def test_admin_proxy_requires_exclusive_attribution(self):
        projection = self.compile()
        before = [g for g in projection.before.grants if g.level == 'proxy']
        self.assertEqual(len(before), 1)
        self.assertEqual(before, [g for g in projection.held.grants if g.level == 'proxy'])
        self.census['exclusive_manual_administration'] = False
        with self.assertRaisesRegex(ContainmentError, 'sql-maintenance-unaccepted'):
            self.compile()
        self.census['exclusive_manual_administration'] = True
        self.census['authenticated_actors'][-1]['mode'] = 'automatic'
        with self.assertRaisesRegex(ContainmentError, 'sql-automatic-administrator'):
            self.compile()
        self.census['authenticated_actors'].pop()
        with self.assertRaisesRegex(ContainmentError, 'sql-attribution-incomplete'):
            self.compile()

    def test_shared_global_admin_requirement_rejected(self):
        bad = self.business+(BusinessRequirement('other_one', 'shared_writer', ('SUPER',)),)
        with self.assertRaisesRegex(ContainmentError, 'sql-business-escape'):
            self.compile(business=bad)

    def test_omitted_schema_and_definer_rejected(self):
        for change, code in [('schema', 'sql-schema-scope'),
                             ('definition', 'sql-definer-escape'),
                             ('table', 'sql-grant-census')]:
            value = copy.deepcopy(self.census)
            if change == 'schema': value['schemas'].remove('other_three')
            elif change == 'definition': value['definitions'] = [{'kind': 'view'}]
            else: del value['grant_tables']['proxies_priv']
            with self.subTest(change=change):
                with self.assertRaisesRegex(ContainmentError, code): self.compile(value)

    def test_auth_tls_resource_metadata_unchanged(self):
        original = canonical(self.census)
        parsed = self.p.parse_source_census(original, self.context)
        projection = self.compile()
        restored = projection.before
        for operation in projection.establish:
            restored = self.p.apply_source_operation(restored, operation)
        self.assertEqual(restored, projection.held)
        for operation in projection.restore:
            restored = self.p.apply_source_operation(restored, operation)
        self.assertEqual(restored, projection.before)
        self.assertEqual(canonical(self.census), original)
        # Complete originals survive independently of the semantic grant model.
        self.assertEqual(dict(parsed.grant_tables)['user'][0],
                         tuple(self.census['grant_tables']['user'][0]))
        self.assertEqual(parsed.original_sha256, hashlib.sha256(original).hexdigest())

    def test_column_summary_missing_or_overstated_rejected(self):
        for missing in (True, False):
            value = copy.deepcopy(self.census)
            if missing:
                value['grant_tables']['tables_priv'] = []
            else:
                index = COLUMNS['tables_priv'].index('Column_priv')
                value['grant_tables']['tables_priv'][0][index] = 'H'+b'Select,Update'.hex().upper()
            with self.subTest(missing=missing):
                with self.assertRaisesRegex(ContainmentError, 'sql-column-summary'):
                    self.compile(value)


if __name__ == '__main__': unittest.main()
