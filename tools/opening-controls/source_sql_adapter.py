"""Owned-only source-shaped SQL control. There is no live socket/apply API."""
from dataclasses import asdict
from pathlib import Path
import os
import secrets
import time
from control import process_identity
from containment_runtime import OwnedContext, _guard as owned_guard
from containment_schema import (AdapterReadback, ContainmentError, EvidenceRef,
    canonical, digest, require)
from source_sql_projection import (parse_source_census, compile_source_projection,
    apply_source_operation)
import sql_fence_driver as d
from sql_fence_harness import OperationJournal
from sql_fence_projection import identifier
from sql_fence_schema import SqlFenceError
from boundary_refs import ReferenceSet
from boundary_schema import BoundaryError

_STATES = {}


def _evidence(state, label, value):
    raw = canonical(value); h = digest(raw)
    state['driver'].supervisor.evidence(label, value)
    p = state['driver'].supervisor.evidence_root/state['driver'].supervisor.blobs[h]
    return EvidenceRef(str(p), h, len(raw))


def _guard(ctx):
    owned_guard(ctx)
    require(id(ctx) in _STATES and _STATES[id(ctx)]['ctx'] is ctx, 'sql-owned-binding')
    state = _STATES[id(ctx)]
    d._guard(state['driver'])
    require(state['owner'] == process_identity(os.getpid()), 'sql-owned-observer')
    return state


def _snapshot(state):
    c = state['driver']; d._guard(c)
    raw, definitions = d._raw_rows(c)
    require(definitions == '0', 'sql-definer-escape')
    value = dict(state['layout'])
    value['grant_tables'] = {table: [list(row['cells']) for row in raw if row['table'] == table]
                             for table in d.COLUMNS}
    parsed = parse_source_census(canonical(value), state['context'])
    protected, unrelated = d._data_hashes(c)
    return d.SqlSnapshot(parsed.model, raw, parsed.nonprivilege_sha256, protected, unrelated)


def _bind_owned(ctx, driver, context, layout):
    """Called only by the fixed owned worker after archive/namespace admission."""
    owned_guard(ctx); d._guard(driver)
    require(id(ctx) not in _STATES and driver.supervisor.context is driver,
            'sql-owned-binding')
    state = {'ctx': ctx, 'driver': driver, 'context': context, 'layout': layout,
             'owner': process_identity(os.getpid()), 'held': False, 'keeper': None,
             'operator': None, 'retirement': None, 'journal': None, 'attempts': []}
    _STATES[id(ctx)] = state
    state['original'] = state['expected'] = _snapshot(state)
    full = {**layout, 'grant_tables': {table: [list(row['cells'])
            for row in state['original'].raw_grant_rows if row['table'] == table]
            for table in d.COLUMNS}}
    state['projection'] = compile_source_projection(canonical(full), context,
                                                    driver.plan.required_business)
    state['original_ref'] = _evidence(state, 'source-original', d.snapshot_record(state['original']))
    state['observer_ref'] = _evidence(state, 'source-observer', {
        'purpose': 'source-containment-owned-observer', 'real_execution_ready': False,
        'identity': state['owner'], 'uid': os.geteuid(),
        'context_sha256': digest(canonical(asdict(context)))})
    _new_journal(state)
    return state


def _new_journal(state):
    if state.get('journal') is not None: state['journal'].close()
    root = state['driver'].supervisor.evidence_root/('source-journal-'+secrets.token_hex(8))
    root.mkdir(mode=0o700)
    state['journal'] = OperationJournal(root, str(state['context'].generation))
    state['journal'].evidence_root = state['driver'].supervisor.evidence_root


def _readback(state, status, failures=(), control='source-sql', start=None):
    ref = _evidence(state, 'source-state', {
        'purpose': 'source-containment-owned-state', 'real_execution_ready': False,
        'state': status, 'failures': list(failures), 'control': control,
        'snapshot': d.snapshot_record(_snapshot(state))})
    state['attempts'].append(ref)
    end = time.monotonic_ns()
    return AdapterReadback(control, status, state['context'].generation,
        digest(canonical(asdict(state['context']))), state['observer_ref'],
        state['context'].source_boot_id, start if start is not None else end-1,
        end, (state['original_ref'],), tuple(state['attempts']), tuple(failures))


def _integrity(state):
    c = state['driver']
    result = d._query(c, 'CHECK TABLE '+','.join('mysql.'+identifier(t) for t in d.COLUMNS)+';')
    d.assert_grant_integrity(result)
    state['attempts'].append(_evidence(state, 'source-integrity', {
        'purpose': 'source-containment-owned-integrity', 'real_execution_ready': False,
        'rows': [list(r) for r in result.rows]}))
    return result


def _session_census(state, drain=False):
    c = state['driver']
    rows = d._query(c, 'SELECT ID,USER,COMMAND FROM information_schema.PROCESSLIST '
                      'WHERE ID<>CONNECTION_ID() ORDER BY ID;').rows
    transactions = d._query(c, 'SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX;').rows
    require(not transactions, 'sql-active-transaction')
    accepted = {s['id']: s for s in c.sessions if s['process'].poll() is None}
    for ident, user, command in rows:
        require(command == 'Sleep', 'sql-active-session')
        session = accepted.get(int(ident))
        require(session is not None and session['user'] == user, 'sql-unowned-session')
        require(any(p['process'] is session['process'] and
                    process_identity(session['process'].pid) == p['identity']
                    for p in c.supervisor.processes), 'sql-session-owner')
        if not drain:
            require(session is state.get('keeper'), 'sql-cached-session')
    if drain:
        require(state.get('keeper') is None, 'sql-keeper-before-establish')
        for session in list(c.sessions):
            require(session['id'] in {int(row[0]) for row in rows}, 'sql-session-census')
            d._close_session(c, session)
        require(not d._query(c, 'SELECT ID FROM information_schema.PROCESSLIST '
                            'WHERE ID<>CONNECTION_ID();').rows, 'sql-session-census')


def _execute(state, operation):
    before = _snapshot(state)
    require(before == state['expected'], 'sql-competing-grant')
    expected = apply_source_operation(before.grants, operation)
    d._query(state['driver'], d._grant_sql(operation))
    after = _snapshot(state)
    require(after.grants == expected and
            after.account_nonprivilege_sha256 == before.account_nonprivilege_sha256 and
            after.protected_sha256 == before.protected_sha256 and
            after.unrelated_sha256 == before.unrelated_sha256, 'sql-operation-readback')
    state['expected'] = after
    return after


def _complete_prefix(state, current):
    records = state['journal'].verify_prefix()
    actual = d.snapshot_record(current)
    recorded = dict(records[-1]['value']['after']) if records and records[-1]['kind'] == 'readback' else {}
    # Approved other-schema business deltas are not grant drift.
    actual.pop('unrelated_sha256', None); recorded.pop('unrelated_sha256', None)
    require(len(records) == 2*len(state['projection'].establish) and records and
            records[-1]['kind'] == 'readback' and
            recorded == actual,
            'sql-unacknowledged-operation')


def establish_sql(ctx, projection):
    state = _guard(ctx); start = time.monotonic_ns(); c = state['driver']
    require(projection == state['projection'], 'sql-projection-binding')
    c.supervisor.clients_allowed = False
    try:
        _session_census(state, drain=True)
        current = _snapshot(state)
        require(current == state['expected'], 'sql-competing-grant')
        if current.grants == projection.before:
            require(not state['journal'].verify_prefix(), 'sql-journal-not-empty')
            for operation in projection.establish:
                intent = state['journal'].intent(operation, current)
                current = _execute(state, operation)
                state['journal'].readback(intent, current)
        require(current.grants == projection.held, 'sql-incomplete-establish')
        _complete_prefix(state, current); _integrity(state); _session_census(state)
        state['held'] = True
        c.supervisor.clients_allowed = True
        return read_sql(ctx)
    except (ContainmentError, SqlFenceError) as exc:
        state['held'] = False
        return _readback(state, 'unproved', (exc.code,), start=start)


def _denial_and_business(state):
    c = state['driver']; protected = identifier(c.plan.value['protected_schema'])
    before = _snapshot(state)
    for statement in ('UPDATE '+protected+'.t000 SET value=value WHERE id=0;',
                      'CREATE TABLE '+protected+'.fenced_probe(id INT);',
                      'UPDATE mysql.proxies_priv SET With_grant=With_grant WHERE 0;'):
        result = d._query(c, statement, 'writer', allow_error=True)
        d.assert_denial(result, frozenset({1044, 1142}))
        state['attempts'].append(_evidence(state, 'source-denial', {
            'purpose': 'source-containment-owned-denial', 'real_execution_ready': False,
            'statement_sha256': digest(statement.encode()), 'error_code': result.error_code}))
    for req in c.plan.required_business:
        target = identifier(req.schema)+'.counter'
        statements = 'SELECT value FROM '+target+' WHERE id=1;'
        statements += 'INSERT INTO '+target+' VALUES(9000,0); UPDATE '+target+' SET value=value+1 WHERE id=1;'
        statements += 'DELETE FROM '+target+' WHERE id=9000;'
        if {'CREATE', 'DROP', 'ALTER'} <= set(req.privileges):
            temp = identifier(req.schema)+'.allowed_probe'
            statements += 'CREATE TABLE '+temp+'(id INT); ALTER TABLE '+temp+' ADD value INT; DROP TABLE '+temp+';'
        statements += 'SELECT value FROM '+target+' WHERE id=1;'
        result = d._query(c, statements, req.role)
        require(len(result.rows) == 2 and int(result.rows[1][0]) == int(result.rows[0][0])+1,
                'sql-business-counter')
        state['attempts'].append(_evidence(state, 'source-business', {
            'purpose': 'source-containment-owned-business', 'real_execution_ready': False,
            'schema': req.schema, 'role': req.role, 'rows': [list(r) for r in result.rows]}))
    after = _snapshot(state)
    require(after.raw_grant_rows == before.raw_grant_rows and
            after.account_nonprivilege_sha256 == before.account_nonprivilege_sha256 and
            after.protected_sha256 == before.protected_sha256, 'sql-protected-drift')
    state['expected'] = after


def read_sql(ctx):
    state = _guard(ctx); start = time.monotonic_ns()
    try:
        require(state['held'], 'sql-not-held')
        current = _snapshot(state)
        require(current.grants == state['projection'].held and
                current.raw_grant_rows == state['expected'].raw_grant_rows and
                current.account_nonprivilege_sha256 == state['original'].account_nonprivilege_sha256 and
                current.protected_sha256 == state['original'].protected_sha256, 'sql-held-drift')
        _session_census(state); _integrity(state)
        _denial_and_business(state)
        return _readback(state, 'held', start=start)
    except (ContainmentError, SqlFenceError) as exc:
        state['driver'].supervisor.clients_allowed = False
        return _readback(state, 'lost', (exc.code,), start=start)


def recover_sql(ctx):
    state = _guard(ctx); current = _snapshot(state)
    state['driver'].supervisor.clients_allowed = False
    try:
        _complete_prefix(state, current)
        require(current.grants == state['projection'].held, 'sql-recovery-state')
        _session_census(state); _integrity(state)
        state['held'] = True; state['driver'].supervisor.clients_allowed = True
        return read_sql(ctx)
    except (ContainmentError, SqlFenceError) as exc:
        state['held'] = False
        return _readback(state, 'recovery-required', (exc.code,))


def _restore_exact(state, expected):
    c = state['driver']; current = _snapshot(state); original = state['original']
    require(current.raw_grant_rows == expected.raw_grant_rows and
            current.account_nonprivilege_sha256 == expected.account_nonprivilege_sha256 and
            current.protected_sha256 == expected.protected_sha256 and
            current.grants.principals == original.grants.principals,
            'sql-competing-restore-state')
    require(current.account_nonprivilege_sha256 == original.account_nonprivilege_sha256 and
            current.protected_sha256 == original.protected_sha256, 'sql-restore-preservation')
    sql = []
    for row in original.raw_grant_rows:
        if row['table'] != 'user': continue
        values = dict(zip(d.COLUMNS['user'], (d._cell(v) for v in row['cells'])))
        assignments = ','.join(identifier(k+'_priv')+'='+d._literal(values[k+'_priv']) for k, _ in d.FLAGS)
        sql.append('UPDATE mysql.user SET '+assignments+' WHERE User='+d._literal(values['User'])+
                   ' AND Host='+d._literal(values['Host'])+';')
    for table, cols in d.COLUMNS.items():
        if table in ('user', 'host'): continue
        for principal in original.grants.principals:
            sql.append('DELETE FROM mysql.'+identifier(table)+' WHERE User='+d._literal(principal.user.encode())+
                       ' AND Host='+d._literal(principal.host.encode())+';')
        for row in original.raw_grant_rows:
            if row['table'] == table:
                sql.append('INSERT INTO mysql.'+identifier(table)+' ('+','.join(identifier(x) for x in cols)+
                    ') VALUES ('+','.join(d._literal(d._cell(v)) for v in row['cells'])+');')
    d._query(c, ' '.join(sql)+' FLUSH PRIVILEGES;')
    after = _snapshot(state)
    require(after.raw_grant_rows == original.raw_grant_rows and after.grants == original.grants and
            after.account_nonprivilege_sha256 == original.account_nonprivilege_sha256 and
            after.protected_sha256 == original.protected_sha256 and
            after.unrelated_sha256 == current.unrelated_sha256, 'sql-exact-restore')
    state['expected'] = after; state['held'] = False
    return after


def _read_retirement_file(retirement, expected_identity, private_root):
    require(type(retirement) is EvidenceRef)
    try:
        with ReferenceSet(private_root) as reader:
            raw = reader._read({'path': retirement.path, 'sha256': retirement.sha256})
            fd = reader.files[retirement.path][0]
            stat = os.fstat(fd)
            require((stat.st_dev, stat.st_ino) == expected_identity,
                    'reference-substitution')
            require(len(raw) == retirement.size, 'reference-size')
            reader.verify_unchanged()
    except BoundaryError as exc:
        raise ContainmentError(str(exc)) from None


def _validate_retirement(state, retirement):
    require(type(retirement) is EvidenceRef and retirement == state['retirement'] and
            state['operator'] is not None and state['operator'].poll() is not None,
            'sql-operator-not-retired')
    _read_retirement_file(retirement, state['retirement_identity'],
                          state['driver'].supervisor.evidence_root)


def release_sql(ctx, retirement):
    state = _guard(ctx); c = state['driver']
    _validate_retirement(state, retirement)
    c.supervisor.clients_allowed = False
    require(not c.sessions, 'sql-release-sessions')
    _restore_exact(state, state['expected'])
    return _readback(state, 'unproved', ('released',))


def establish_keeper(ctx):
    state = _guard(ctx); c = state['driver']
    require(state['held'] and state['keeper'] is None, 'sql-keeper-admission')
    session = d._open_session(c, 'keeper')
    tables = ','.join(identifier(c.plan.value['protected_schema'])+'.t%03d READ'%n for n in range(300))
    session['process'].stdin.write(('LOCK TABLES '+tables+"; SELECT 'KEEPER_HELD';\n").encode())
    session['process'].stdin.flush()
    require(d._session_line(session['process']) == ('KEEPER_HELD',), 'sql-keeper-locks')
    state['keeper'] = session
    return read_keeper(ctx)


def read_keeper(ctx):
    state = _guard(ctx); c = state['driver']; session = state['keeper']
    failures = []
    if session is None or session['process'].poll() is not None:
        failures.append('sql-keeper-lost')
    else:
        rows = d._query(c, 'SHOW OPEN TABLES FROM '+identifier(c.plan.value['protected_schema'])+';').rows
        locked = {r[1] for r in rows if len(r) == 4 and int(r[2]) >= 1}
        if locked != {'t%03d'%n for n in range(300)}: failures.append('sql-keeper-locks-lost')
        state['attempts'].append(_evidence(state, 'source-keeper', {
            'purpose': 'source-containment-owned-keeper', 'real_execution_ready': False,
            'rows': [list(r) for r in rows]}))
    return _readback(state, 'lost' if failures else 'held', tuple(failures),
                     control='secondary-lock-keeper')
