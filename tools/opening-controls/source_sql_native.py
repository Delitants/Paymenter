"""Fixed owned qualification, never a deployment command or live SQL adapter."""
from dataclasses import asdict
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import time
import traceback
from containment_admission import boot_id
from containment_runtime import create_owned_context, retire_owned_context
from containment_schema import (ControlContext, EvidenceRef, ContainmentError,
    canonical, digest, require)
import sql_fence_driver as d
import sql_fence_harness as h
from sql_fence_schema import SqlDocuments, CASE_IDS, PRIVILEGES, SqlFenceError
from sql_fence_projection import GrantOperation, identifier
from source_sql_projection import compile_source_projection
import source_sql_adapter as a

CASES = ('proxy-precedence', 'cached-session', 'active-unowned', 'grant-integrity',
         'keeper-loss', 'exact-restore', 'restart-recovery')


def _ref(path):
    raw = path.read_bytes()
    return EvidenceRef(str(path), digest(raw), len(raw))


def _write(path, value):
    h._exclusive(path, canonical(value))
    return _ref(path)


def _input_plan(root, archive, manifest):
    root.mkdir(mode=0o700)
    principals = [{'user': u, 'host': 'localhost', 'role': role} for u, role in (
        ('fx_writer', 'writer'), ('fx_shared', 'shared_writer'),
        ('fx_collect', 'collector'), ('fx_keeper', 'keeper'), ('fx_admin', 'administrator'))]
    protected = 'fixture_source'
    preserved = ['fixture_legacy', 'fixture_tracking', 'fixture_web']
    seed = {'schema_version': 1, 'purpose': 'fictional-sql-grant-seed',
        'schemas': [protected, *preserved, 'fixture_demo', 'fixtureXsource', 'fixture_pct%', 'fixture_pctX'],
        'principals': principals, 'grants': [
            {'principal': ['fx_admin', 'localhost'], 'level': 'global', 'target': [], 'privileges': sorted(PRIVILEGES)},
            {'principal': ['fx_writer', 'localhost'], 'level': 'global', 'target': [],
             'privileges': ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'SUPER', 'FILE', 'GRANT OPTION', 'CREATE USER', 'CREATE TABLESPACE']},
            {'principal': ['fx_shared', 'localhost'], 'level': 'global', 'target': [],
             'privileges': ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX']},
            {'principal': ['fx_writer', 'localhost'], 'level': 'table', 'target': [protected, 't000'], 'privileges': ['SELECT', 'UPDATE']},
            {'principal': ['fx_writer', 'localhost'], 'level': 'column', 'target': [protected, 't001', 'value'], 'privileges': ['UPDATE']},
            *[{'principal': [user, 'localhost'], 'level': 'database', 'target': [protected], 'privileges': ['SELECT']}
              for user in ('fx_collect', 'fx_keeper')]], 'definitions': []}
    seed_ref = _write(root/'grant-seed.json', seed)
    business = [{'schema': schema, 'role': role, 'privileges': privileges}
        for schema, role, privileges in (
            (preserved[0], 'shared_writer', ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX']),
            (preserved[1], 'writer', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']),
            (preserved[2], 'shared_writer', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']))]
    plan = {'schema_version': 1, 'purpose': 'fictional-sql-privilege-rehearsal',
        'runtime_archive': {'path': archive.path, 'sha256': archive.sha256},
        'runtime_manifest': {'path': manifest.path, 'sha256': manifest.sha256},
        'grant_seed': {'path': seed_ref.path, 'sha256': seed_ref.sha256},
        'protected_schema': protected, 'preserved_schemas': preserved,
        'required_business': business, 'cases': list(CASE_IDS)}
    return _write(root/'plan.json', plan)


def _setup(c, sup):
    sup.clients_allowed = True
    extra = []
    for n, host in ((1, '127.0.0.1'), (2, '%')):
        user = 'fx_manual'+str(n); password = secrets.token_hex(24)
        d._query(c, "CREATE USER '"+user+"'@'"+host+"' IDENTIFIED BY '"+password+"'; "
                 "GRANT SELECT ON *.* TO '"+user+"'@'"+host+"';")
        role = 'manual_'+str(n); option = c.jail/'tmp'/('client-manual-'+str(n)+'.cnf')
        sup.exclusive_bytes(option, ('[client]\nuser='+user+'\npassword='+password+'\n').encode())
        c.options[role] = option; c.actors[role] = (user, host)
        c.option_identities[role] = (d._inode(option), digest(option.read_bytes()))
        extra.append({'user': user, 'host': host, 'role': 'administrator'})
    # Trusted fixture administration creates exact source-shaped proxy originals.
    # No source rows/authentication files are loaded or copied.
    d._query(c, "INSERT INTO mysql.proxies_priv (Host,User,Proxied_host,Proxied_user,With_grant,Grantor,Timestamp) VALUES "
        "('localhost','fx_admin','127.0.0.1','fx_manual1',1,CURRENT_USER(),CURRENT_TIMESTAMP),"
        "('localhost','fx_admin','%','fx_manual2',0,CURRENT_USER(),CURRENT_TIMESTAMP); FLUSH PRIVILEGES;")
    d._query(c, 'GRANT LOCK TABLES ON '+identifier('fixture\\_source')+".* TO 'fx_keeper'@'localhost';")
    profiles = sup.evidence_root/'source-inputs'; profiles.mkdir(mode=0o700)
    authority = _write(profiles/'authority.json', {
        'schema_version': 1, 'purpose': 'source-containment-authority', 'source_boot_id': boot_id(),
        'automated_actors': ['writer', 'shared_writer', 'collector', 'keeper'],
        'manual_actors': ['administrator', 'manual_1', 'manual_2'],
        'exclusive_maintenance': True, 'refs': []})
    budget = _write(profiles/'budget.json', {
        'schema_version': 1, 'purpose': 'source-containment-budget', 'root_reserve': h.ROOT_RESERVE,
        'ram_reserve': 536870912, 'staging_limit': h.TMPFS_LIMIT, 'memory_limit': h.MEMORY_LIMIT,
        'evidence_limit': h.EVIDENCE_LIMIT, 'pids_limit': h.PIDS_LIMIT, 'swap_limit': 0,
        'full_cohort_accepted': False, 'refs': []})
    profile = _write(profiles/'profile.json', {
        'schema_version': 1, 'purpose': 'source-containment-profile',
        'source_boot_id': boot_id(), 'assignments': [], 'refs': []})
    context = ControlContext('owned-source-sql', 'owned-freeze', 1, boot_id(),
        profile.sha256, digest(Path(a.__file__).read_bytes()), authority, budget)
    runtime = _write(profiles/'runtime.json', {
        'schema_version': 1, 'purpose': 'source-containment-owned-runtime', 'kind': 'sql',
        'source_boot_id': context.source_boot_id,
        'adapter_manifest_sha256': context.adapter_manifest_sha256, 'refs': []})
    principals = [asdict(p) for p in c.plan.seed.principals]+extra
    authenticated = []
    for role, (user, host) in c.actors.items():
        result = d._query(c, 'SELECT CURRENT_USER();', role, tcp=role.startswith('manual_'))
        require(result.rows == ((user+'@'+host,),), 'sql-native-current-user')
        principal = next(p for p in principals if (p['user'], p['host']) == (user, host))
        authenticated.append({'actor_id': role, 'user': user, 'host': host,
            'role': principal['role'], 'mode': 'manual' if principal['role'] == 'administrator' else 'automatic',
            'path_id': 'tcp' if role.startswith('manual_') else 'unix', 'current_user': result.rows[0][0]})
    schemas = c.plan.seed.schemas
    query = ('SELECT TABLE_SCHEMA,TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ('+
        ','.join(d._literal(s.encode()) for s in schemas)+') ORDER BY TABLE_SCHEMA,TABLE_NAME;')
    objects = []
    for schema, table in d._query(c, query).rows:
        result = d._query(c, 'SHOW CREATE TABLE '+identifier(schema)+'.'+identifier(table)+';')
        objects.append({'schema': schema, 'kind': 'table', 'name': table,
                        'definition_sha256': digest(canonical(result.rows))})
    layout = {'schema_version': 1, 'purpose': 'source-containment-sql-census',
        'runtime_version': '5.5.68-MariaDB', 'source_boot_id': context.source_boot_id,
        'profile_sha256': context.profile_sha256, 'adapter_manifest_sha256': context.adapter_manifest_sha256,
        'authority_sha256': authority.sha256, 'exclusive_manual_administration': True,
        'protected_schema': c.plan.value['protected_schema'],
        'preserved_schemas': list(c.plan.value['preserved_schemas']),
        'nonbusiness_schemas': [s for s in schemas if s not in
            (c.plan.value['protected_schema'], *c.plan.value['preserved_schemas'])],
        'schemas': list(schemas), 'objects': objects, 'principals': principals,
        'connection_paths': [{'path_id': 'unix', 'kind': 'unix'}, {'path_id': 'tcp', 'kind': 'loopback-tcp'}],
        'authenticated_actors': authenticated, 'definitions': []}
    owned = create_owned_context('sql', context, runtime)
    try: state = a._bind_owned(owned, c, context, layout)
    except BaseException:
        retire_owned_context(owned)
        raise
    return owned, state


def _operator(state):
    c = state['driver']
    p = c.supervisor.spawn((sys.executable, '-I', '-c', 'import time; time.sleep(600)'), 'controller', False)
    state['operator'] = p; state['retirement'] = None
    state['operator_identity'] = h.process_identity(p.pid)


def _retire_operator(state):
    p = state['operator']; state['driver'].supervisor.stop_owned(p)
    require(p.poll() is not None, 'sql-operator-not-retired')
    state['retirement'] = a._evidence(state, 'source-operator-retired', {
        'purpose': 'source-containment-owned-operator-retirement', 'real_execution_ready': False,
        'operator_identity': state['operator_identity'], 'exit': p.returncode,
        'context_sha256': digest(canonical(asdict(state['context']))),
        'generation': state['context'].generation})
    state['retirement_identity'] = d._inode(Path(state['retirement'].path))
    return state['retirement']


def _reset(state):
    c = state['driver']; c.supervisor.clients_allowed = False
    for session in list(c.sessions): d._close_session(c, session)
    state['keeper'] = None
    # Explicit trusted fixture administrator, exact observed current rows only.
    a._restore_exact(state, a._snapshot(state)); a._new_journal(state)
    state['attempts'] = []; state['held'] = False; c.supervisor.clients_allowed = True
    if state.get('operator') is not None and state['operator'].poll() is None:
        c.supervisor.stop_owned(state['operator'])
    _operator(state)


def _held(ctx, state):
    result = a.establish_sql(ctx, state['projection'])
    require(result.state == 'held', 'sql-native-establish-'+','.join(result.failures))
    return result


def _attempts(state): return [asdict(r) for r in state['attempts']]


def _case_proxy(ctx, state):
    before = a._snapshot(state); _held(ctx, state); after = a._snapshot(state)
    proxies = lambda s: tuple(r for r in s.raw_grant_rows if r['table'] == 'proxies_priv')
    for role in ('manual_1', 'manual_2'):
        d._query(state['driver'], 'SELECT 1;', role, tcp=True)
    return {'principals': len(after.grants.principals), 'proxies': len(proxies(after)),
        'authenticated_tcp': 2, 'manual_proxy_rows_unchanged': proxies(before) == proxies(after),
        'protected_unchanged': before.protected_sha256 == after.protected_sha256,
        'attempt_refs': _attempts(state)}


def _case_cached(ctx, state):
    c = state['driver']; old = d._open_session(c, 'writer')
    current = a._snapshot(state)
    # Demonstration behind owned fixture admission: cached global grants remain.
    for op in state['projection'].establish:
        intent = state['journal'].intent(op, current)
        current = a._execute(state, op); state['journal'].readback(intent, current)
    require(d._session_statement(c, old, 'zero_update') == ('ZERO_AUTHORIZED',), 'sql-cached-proof')
    fresh = d._query(c, 'UPDATE '+identifier(c.plan.value['protected_schema'])+
                    '.t000 SET value=value WHERE id=0;', 'writer', allow_error=True)
    d.assert_denial(fresh, frozenset({1044, 1142}))
    state['held'] = True
    rejected = a.read_sql(ctx)
    require(rejected.state != 'held' and 'sql-cached-session' in rejected.failures, 'sql-cached-admission')
    _held(ctx, state)
    require(old['process'].poll() is not None and old not in c.sessions, 'sql-idle-not-drained')
    return {'cached_authorized': True, 'fresh_denied': True, 'held_denied_until_drained': True,
            'closed_owned_idle': 1, 'attempt_refs': _attempts(state)}


def _case_active(ctx, state):
    c = state['driver']; before = a._snapshot(state)
    active = d._open_session(c, 'writer'); d._session_statement(c, active, 'active')
    rejected = a.establish_sql(ctx, state['projection'])
    require(rejected.state != 'held' and 'sql-active-session' in rejected.failures, 'sql-active-not-blocked')
    require(active['process'].poll() is None and d._session_line(active['process']) == ('0',), 'sql-active-killed')
    d._close_session(c, active)
    require(a._snapshot(state) == before, 'sql-active-trial-drift')
    # Explicit owned-fixture preparation for the independent unknown trial.
    # Rejected public establishment itself never reopens client admission.
    c.supervisor.clients_allowed = True
    unknown = d._open_session(c, 'collector'); c.sessions.remove(unknown)
    rejected = a.establish_sql(ctx, state['projection'])
    require(rejected.state != 'held' and 'sql-unowned-session' in rejected.failures and
            unknown['process'].poll() is None, 'sql-unknown-killed')
    c.sessions.append(unknown); d._close_session(c, unknown)
    require(a._snapshot(state) == before, 'sql-blocked-mutated')
    return {'blocked_active': 1, 'blocked_unowned': 1, 'killed_sessions': 0,
            'grants_unchanged': True, 'attempt_refs': _attempts(state)}


def _case_integrity(ctx, state):
    _held(ctx, state); result = a._integrity(state)
    require(a.recover_sql(ctx).state == 'held', 'sql-valid-recovery')
    return {'checked_tables': len(result.rows), 'actual_status_rows_ok': True,
            'fresh_denial_observed': True, 'attempt_refs': _attempts(state)}


def _case_keeper(ctx, state):
    _held(ctx, state); require(a.establish_keeper(ctx).state == 'held', 'sql-keeper-not-held')
    c = state['driver']; keeper = state['keeper']
    require(d._session_statement(c, keeper, 'transaction') == ('TRANSACTION_STARTED',), 'sql-keeper-transaction')
    require(a.read_keeper(ctx).state == 'lost', 'sql-keeper-transaction-undetected')
    d._close_session(c, keeper); state['keeper'] = None
    require(a.establish_keeper(ctx).state == 'held', 'sql-keeper-reacquire')
    keeper = state['keeper']; d._close_session(c, keeper, hard=True)
    require(a.read_keeper(ctx).state == 'lost', 'sql-keeper-exit-undetected')
    state['keeper'] = None
    require(a.read_sql(ctx).state == 'held', 'sql-primary-opened-on-keeper-loss')
    return {'locked_tables': 300, 'transaction_released_locks_detected': True,
            'keeper_exit_detected': True, 'primary_denial_persisted': True,
            'attempt_refs': _attempts(state)}


def _case_restore(ctx, state):
    c = state['driver']
    d._query(c, 'UPDATE '+identifier(c.plan.value['preserved_schemas'][1])+
             '.counter SET value=value+1 WHERE id=1;', 'writer')
    state['expected'] = a._snapshot(state)
    partial_count = max(1, len(state['projection'].establish)//2)
    require(partial_count < len(state['projection'].establish), 'sql-partial-prefix')
    for operation in state['projection'].establish[:partial_count]:
        intent = state['journal'].intent(operation, state['expected'])
        after = a._execute(state, operation)
        state['journal'].readback(intent, after)
    require(state['expected'].raw_grant_rows != state['original'].raw_grant_rows and
            state['expected'].grants != state['projection'].held, 'sql-partial-not-observed')
    fake = EvidenceRef('/tmp/not-a-retirement', '0'*64, 0)
    try: a.release_sql(ctx, fake)
    except ContainmentError as exc: require(exc.code == 'sql-operator-not-retired')
    else: raise ContainmentError('sql-live-operator-released')
    before = a._snapshot(state)
    _retire_operator(state)
    # Fault injection belongs to disposable RAM copies, never the immutable
    # originals watched by the independent custody/resource monitor.
    negative = c.supervisor.root/('negative-retirement-'+secrets.token_hex(8))
    negative.mkdir(mode=0o700); negative_id = d._inode(negative)
    copy = negative/'original.json'
    c.supervisor.exclusive_bytes(copy, Path(state['retirement'].path).read_bytes())
    copy_id = d._inode(copy)
    alias = negative/'alias.json'; alias.symlink_to(copy); alias_id = d._inode(alias)
    borrowed = EvidenceRef(str(alias), state['retirement'].sha256, state['retirement'].size)
    try:
        try: a._read_retirement_file(borrowed, copy_id, negative)
        except ContainmentError as exc: require(exc.code == 'private-reference')
        else: raise ContainmentError('sql-retirement-substitution-accepted')
        try: a.release_sql(ctx, borrowed)
        except ContainmentError as exc: require(exc.code == 'sql-operator-not-retired')
        else: raise ContainmentError('sql-borrowed-retirement-accepted')
        require(a._snapshot(state) == before, 'sql-negative-trial-mutated')
        a._evidence(state, 'source-retirement-negative', {
            'purpose': 'source-containment-owned-retirement-negative', 'real_execution_ready': False,
            'copied_original_sha256': state['retirement'].sha256,
            'copy_identity': list(copy_id), 'alias_identity': list(alias_id),
            'reference_rejected': True, 'borrowed_release_rejected': True})
    finally:
        require(d._inode(negative) == negative_id and d._inode(alias) == alias_id and
                d._inode(copy) == copy_id, 'sql-negative-copy-drift')
        alias.unlink(); copy.unlink(); negative.rmdir()
    released = a.release_sql(ctx, state['retirement'])
    first = a._snapshot(state)
    require(released.state == 'unproved' and released.failures == ('released',) and
            first.raw_grant_rows == state['original'].raw_grant_rows and
            first.unrelated_sha256 == before.unrelated_sha256, 'sql-positive-release')
    positive_ref = a._evidence(state, 'source-positive-release', {
        'purpose': 'source-containment-owned-positive-release', 'real_execution_ready': False,
        'grant_rows_restored': True, 'unrelated_deltas_retained': True})
    _reset(state); _held(ctx, state); before = a._snapshot(state); _retire_operator(state)
    # Competing grant is observed and cannot be overwritten by public release.
    d._query(c, 'GRANT SELECT ON '+identifier('fixture\\_demo')+".* TO 'fx_writer'@'localhost';")
    try: a.release_sql(ctx, state['retirement'])
    except ContainmentError as exc: require(exc.code == 'sql-competing-restore-state')
    else: raise ContainmentError('sql-competing-restore-overwritten')
    actual = a._snapshot(state)
    a._restore_exact(state, actual)  # Explicit fixture-admin reset under actual current rows.
    restored = a._snapshot(state)
    require(restored.raw_grant_rows == state['original'].raw_grant_rows and
            restored.unrelated_sha256 == before.unrelated_sha256, 'sql-restoration-result')
    return {'live_operator_release_denied': True, 'competing_grant_restore_denied': True,
        'positive_release_completed': True, 'positive_release_ref': asdict(positive_ref),
        'partial_operations_restored': partial_count,
        'full_establishment_operations': len(state['projection'].establish),
        'retirement_substitution_denied': True,
        'all_grant_rows_restored': True,
        'authentication_unchanged': restored.account_nonprivilege_sha256 == state['original'].account_nonprivilege_sha256,
        'unrelated_deltas_retained': restored.unrelated_sha256 == before.unrelated_sha256,
        'protected_unchanged': restored.protected_sha256 == state['original'].protected_sha256,
        'attempt_refs': _attempts(state)}


def _restart(state, hard):
    c = state['driver']; before = a._snapshot(state)
    c.supervisor.clients_allowed = False
    require(not c.sessions, 'sql-restart-session-census')
    if hard: c.supervisor.stop_owned(c.server, hard=True)
    else: d.shutdown_owned_server(c)
    if c.socket.exists():
        require(d._inode(c.socket) == c.socket_identity, 'sql-reused-socket'); c.socket.unlink()
    d._start_server(c); a._integrity(state)
    after = a._snapshot(state); require(after == before, 'sql-restart-readback')
    a._session_census(state); c.supervisor.clients_allowed = True


def _case_restart(ctx, state):
    _held(ctx, state)
    for hard in (False, True):
        _restart(state, hard); require(a.read_sql(ctx).state == 'held', 'sql-restart-denial')
    _reset(state)
    operation = state['projection'].establish[0]
    current = a._snapshot(state); state['journal'].intent(operation, current)
    # The requesting controller is killed around a real supervisor operation;
    # it never borrows the owning process's capability.
    from sql_fence_cases import _controller_checkpoint
    _controller_checkpoint(state['driver'].supervisor, state['journal'],
                           lambda: a._execute(state, operation))
    actual = a._snapshot(state)
    result = a.recover_sql(ctx)
    require(result.state == 'recovery-required' and a._snapshot(state) == actual,
            'sql-unacknowledged-auto-restored')
    return {'restarts': 2, 'physical_integrity_before_readmission': True,
        'unacknowledged_native_operation_observed': actual.raw_grant_rows != current.raw_grant_rows,
        'recovered_state': result.state, 'no_automatic_restore': True,
        'attempt_refs': _attempts(state)}


def _worker(config_fd, barrier_fd):
    raw = os.read(config_fd, 65537); os.close(config_fd)
    require(len(raw) <= 65536 and os.read(barrier_fd, 2) == b'A', 'sql-startup-barrier')
    os.close(barrier_fd); config = json.loads(raw)
    outcome = {'cases': {}, 'error': None, 'retirement': {'complete': False},
               'real_execution_ready': False}
    sup = None; ctx = None; state = None
    try:
        with SqlDocuments(Path(config['reference_root'])) as documents:
            plan = documents.load(config['plan_ref']); sup = h.OwnedSupervisor(config, plan)
            documents.verify_archive(); runtime = sup.prepare_runtime(documents)
            c = d.create_fixture(runtime, sup); sup.context = c
            ctx, state = _setup(c, sup)
            functions = (_case_proxy, _case_cached, _case_active, _case_integrity,
                         _case_keeper, _case_restore, _case_restart)
            for case, function in zip(CASES, functions):
                documents.verify_archive(); sup.deadline_guard(); _reset(state)
                outcome['cases'][case] = function(ctx, state)
            _reset(state); _retire_operator(state)
            a._restore_exact(state, a._snapshot(state))
            documents.verify_archive()
    except BaseException as exc:
        outcome['error'] = getattr(exc, 'code', type(exc).__name__)
        h._exclusive(Path(config['evidence'])/'worker-failure.json', canonical({
            'error': outcome['error'], 'traceback': traceback.format_exc()[-12000:],
            'real_execution_ready': False}))
    finally:
        if state is not None:
            state['journal'].close()
            try: retire_owned_context(ctx)
            except BaseException as exc:
                outcome['error'] = outcome['error'] or getattr(exc, 'code', type(exc).__name__)
        if sup is not None:
            try: outcome['retirement'] = h.retire(sup, Path(config['evidence']))
            except BaseException as exc:
                outcome['retirement'] = {'complete': False, 'error': getattr(exc, 'code', type(exc).__name__)}
        h._exclusive(Path(config['evidence'])/'worker-outcome.json', canonical(outcome))
    os._exit(0 if outcome['error'] is None and outcome['retirement'].get('owned_mounts_retired') else 1)


def _launch(plan, documents, evidence):
    """Same bounded supervisor primitives, fixed source-owned worker entrypoint."""
    require(sys.platform == 'linux' and os.geteuid() == 0, 'sql-linux-root')
    h.check_budget(h.resources()); h.private_directory(evidence)
    require(not any(evidence.iterdir()), 'sql-evidence-exclusive')
    parent = Path('/sys/fs/cgroup')
    require({'memory', 'pids'} <= set((parent/'cgroup.subtree_control').read_text().split()), 'sql-cgroup-delegation')
    suffix = secrets.token_hex(12); root = Path('/dev/shm')/('opening-sql-source-'+suffix)
    group = parent/('opening-sql-source-'+suffix)
    h._exclusive(evidence/'allocation-intent.json', canonical({
        'purpose': 'source-containment-owned-resource-intent', 'real_execution_ready': False,
        'owner': h.process_identity(os.getpid()), 'root': str(root), 'cgroup': str(group)}))
    root.mkdir(mode=0o700); root_id = h._token(root); group.mkdir(); group_id = h._token(group)
    limits = {'memory.max': str(h.MEMORY_LIMIT), 'memory.swap.max': '0', 'pids.max': str(h.PIDS_LIMIT)}
    for key, value in limits.items(): (group/key).write_text(value)
    require(h._cgroup_limits(group) == limits, 'sql-cgroup-admission')
    h._exclusive(evidence/'allocation-readback.json', canonical({
        'purpose': 'source-containment-owned-resource-readback', 'real_execution_ready': False,
        'root_identity': root_id, 'cgroup_identity': group_id, 'limits': limits}))
    namespaces = d._namespaces(os.getpid()); deadline = time.monotonic()+600
    config = {'root': str(root), 'root_identity': root_id, 'cgroup': str(group),
        'cgroup_identity': group_id, 'parent_namespaces': namespaces, 'evidence': str(evidence),
        'deadline': deadline, 'reference_root': str(documents.root), 'plan_ref': documents.plan_reference}
    r, w = os.pipe(); br, bw = os.pipe()
    code = 'import sys;sys.path.insert(0,'+repr(str(Path(__file__).resolve().parent))+');from source_sql_native import _worker;_worker('+str(r)+','+str(br)+')'
    log = open(evidence/'launcher.log', 'xb'); os.chmod(evidence/'launcher.log', 0o600)
    process = subprocess.Popen(('unshare', '--net', '--pid', '--mount', '--fork', '--kill-child=SIGKILL',
        '--mount-proc', sys.executable, '-I', '-c', code), pass_fds=(r, br), stdin=subprocess.DEVNULL, stdout=log, stderr=log)
    identity = h.process_identity(process.pid); os.close(r); os.close(br)
    try:
        os.write(w, canonical(config)); os.close(w)
        until = time.monotonic()+10; child = None
        while child is None:
            require(process.poll() is None and time.monotonic() < until, 'sql-launch-readiness')
            children = Path('/proc/%d/task/%d/children' % (process.pid, process.pid)).read_text().split()
            if children:
                require(len(children) == 1, 'sql-launch-child-census'); child = int(children[0])
            else: time.sleep(.025)
        child_identity = h.process_identity(child); ns = d._namespaces(child)
        require(all(ns[k] != namespaces[k] for k in ns), 'sql-launch-namespace')
        (group/'cgroup.procs').write_text(str(child))
        require(h.process_identity(child) == child_identity and
                Path('/proc/%d/cgroup' % child).read_text().strip() == '0::/'+group.name,
                'sql-launch-membership')
        h._exclusive(evidence/'parent-admission.json', canonical({
            'worker_identity': child_identity, 'wrapper_identity': identity, 'namespaces': ns,
            'root_identity': root_id, 'cgroup_identity': group_id, 'limits': limits,
            'resources': h.resources(), 'real_execution_ready': False}))
        os.write(bw, b'A'); os.close(bw)
        while process.poll() is None:
            require(time.monotonic() < deadline and shutil.disk_usage('/').free >= h.ROOT_RESERVE,
                    'sql-invocation-budget'); h._evidence_size(evidence); time.sleep(.1)
        log.flush(); os.fsync(log.fileno()); log.close()
        result = json.loads((evidence/'worker-outcome.json').read_bytes())
        require(result['retirement'].get('owned_mounts_retired') is True, 'sql-uncertain-retirement')
        require(h._token(root) == root_id and not any(root.iterdir()), 'sql-reused-root'); root.rmdir()
        require(h._token(group) == group_id and h._cgroup_limits(group) == limits and
                not (group/'cgroup.procs').read_text().strip(), 'sql-reused-cgroup'); group.rmdir()
        result['retirement'].update(complete=True, owned_root_retired=True, owned_cgroup_retired=True)
        h._exclusive(evidence/'retirement.json', canonical(result['retirement']))
        return result
    except BaseException:
        if process.poll() is None:
            require(h.process_identity(process.pid) == identity, 'sql-reused-wrapper'); process.terminate()
            try: process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                require(h.process_identity(process.pid) == identity, 'sql-reused-wrapper'); process.kill(); process.wait(timeout=5)
        raise
    finally:
        for fd in (w, bw):
            try: os.close(fd)
            except OSError: pass
        if not log.closed: log.flush(); log.close()


def run_owned_qualification(archive, manifest, evidence):
    require(sys.platform == 'linux' and os.geteuid() == 0, 'source-native-unavailable')
    require(type(archive) is EvidenceRef and type(manifest) is EvidenceRef,
            'source-runtime-reference')
    require(not evidence.exists(), 'source-evidence-existing')
    inputs = evidence.parent/('source-plan-'+secrets.token_hex(8))
    plan_ref = _input_plan(inputs, archive, manifest)
    evidence.mkdir(mode=0o700)
    reference_root = Path(os.path.commonpath([str(inputs), str(Path(archive.path).parent),
                                            str(Path(manifest.path).parent)]))
    with SqlDocuments(reference_root) as documents:
        plan = documents.load({'path': plan_ref.path, 'sha256': plan_ref.sha256})
        result = _launch(plan, documents, evidence)
        documents.verify_archive()
    require(result['error'] is None and set(result['cases']) == set(CASES) and
            result['retirement']['complete'], 'source-native-'+str(result['error']))
    return result
