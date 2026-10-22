"""Ten fixed native cases. Every passing record requires its own exact effects."""
from dataclasses import replace
import json
import os
from pathlib import Path
import secrets
import select
import sys
import time
from sql_fence_schema import (CASE_IDS,SqlFenceError,GrantRow,DefinerObject,canonical,sha,require)
from sql_fence_projection import compile_projection,semantic_hash
from sql_fence_driver import (snapshot,snapshot_hash,snapshot_record,execute_operation,restore_original,
    restart_owned_server,drain_barrier,assert_denial,_query,_probe,_open_session,_session_statement,
    _session_line,_close_session,identifier,database_pattern)

CHECKS=frozenset(CASE_IDS)
FIELDS={CASE_IDS[0]:{'demo_counter'},CASE_IDS[1]:{'denials','protected_unchanged'},
 CASE_IDS[2]:{'counters','myisam_preserved'},CASE_IDS[3]:{'cached_authorized','fresh_denied','drained'},
 CASE_IDS[4]:{'host_identity','pattern_denials','unsafe_rejections'},
 CASE_IDS[5]:{'escalation_denials','definer_rejected','admin_trusted'},
 CASE_IDS[6]:{'keeper_losses','controller_losses','continuity_denied'},
 CASE_IDS[7]:{'restarts','unchanged'},CASE_IDS[8]:{'operation_trials','boundaries','competing_denied'},
 CASE_IDS[9]:{'raw_exact','protected_unchanged','unrelated_preserved','repeat_exact'}}


def validate_case(case_id,effects):
    require(case_id in CHECKS and type(effects) is dict and set(effects)==FIELDS[case_id],'sql-case-effects')
    for k,v in effects.items():
        if k in ('counters',):require(v==[1,1,1] and all(type(x) is int for x in v),'sql-case-effects')
        elif k=='host_identity':require(v=='fx_writer@127.0.0.1','sql-case-effects')
        elif k in ('demo_counter','restarts'):require(type(v) is int and v==2,'sql-case-effects')
        elif k=='denials':require(type(v) is int and v==10,'sql-case-effects')
        elif k=='pattern_denials':require(type(v) is int and v==2,'sql-case-effects')
        elif k=='unsafe_rejections':require(type(v) is int and v>=3,'sql-case-effects')
        elif k=='escalation_denials':require(type(v) is int and v==5,'sql-case-effects')
        elif k in ('keeper_losses','controller_losses'):require(type(v) is int and v==1,'sql-case-effects')
        elif k=='operation_trials':require(type(v) is int and v>=4,'sql-case-effects')
        elif k=='boundaries':require(v==['before_intent','after_intent','before_readback','after_readback'],'sql-case-effects')
        else:require(v is True,'sql-case-effects')


def _rejected(call):
    try:call()
    except SqlFenceError:return
    raise SqlFenceError('sql-expected-rejection')


def _projection(c):
    return compile_projection(c.plan.seed,c.plan.value['protected_schema'],tuple(c.plan.value['preserved_schemas']),c.plan.required_business)


def _journal(c,label):
    from sql_fence_harness import OperationJournal
    root=c.supervisor.evidence_root/('journal-'+label+'-'+secrets.token_hex(5));root.mkdir(mode=0o700)
    journal=OperationJournal(root,root.name);journal.evidence_root=c.supervisor.evidence_root;return journal


def _controller_checkpoint(s,j,action=None):
    """Interrupt a real expendable controller after it reads durable originals.

    Native SQL remains with the independently owning supervisor. A fresh controller
    verifies the original prefix again; that alone never establishes physical state.
    """
    records=j.verify_prefix()
    code='import sys,os,json,signal;sys.path.insert(0,'+repr(str(Path(__file__).resolve().parent))+');from pathlib import Path;from sql_fence_harness import OperationJournal;\nwith OperationJournal(Path(sys.argv[1]),sys.argv[2]) as j:\n r=j.verify_prefix();print(json.dumps({"count":len(r),"tail":r[-1]["kind"] if r else None}),flush=True)\n'
    request='import sys,json;print(json.dumps({"phase":"request"}),flush=True);assert sys.stdin.readline()=='+repr('A\n')+';\n'
    p=s.spawn((sys.executable,'-I','-c',request+code+'os.kill(os.getpid(),signal.SIGSTOP)',str(j.root),j.generation),'controller',True)
    require(select.select([p.stdout],[],[],8)[0],'sql-controller-readiness')
    require(json.loads(p.stdout.readline(4096))=={'phase':'request'},'sql-controller-request')
    if action is not None:action()
    records=j.verify_prefix();wanted={'count':len(records),'tail':records[-1]['kind'] if records else None}
    p.stdin.write(b'A\n');p.stdin.flush()
    require(select.select([p.stdout],[],[],8)[0],'sql-controller-readback')
    require(json.loads(p.stdout.readline(4096))==wanted,'sql-controller-prefix')
    until=time.monotonic()+2
    while Path('/proc/%d/stat'%p.pid).read_text().rsplit(')',1)[1].split()[0]!='T':
        require(time.monotonic()<until,'sql-controller-stop');time.sleep(.01)
    s.stop_owned(p,hard=True)
    fresh=s.spawn((sys.executable,'-I','-c',code,str(j.root),j.generation),'controller',True)
    out,err=fresh.communicate(timeout=8);require(fresh.returncode==0 and not err and json.loads(out)==wanted,'sql-fresh-controller-prefix')
    s.evidence('controller-interruption',{'prefix':wanted,'controller_requested_before_action':True,'interrupted_status':p.returncode,'fresh_status':fresh.returncode,'real_execution_ready':False})


def _broader(c,s):
    before=snapshot(c)
    # A narrow revoke does not neutralize an existing global UPDATE grant.
    _query(c,"GRANT UPDATE ON fixture_demo.* TO 'fx_writer'@'localhost'; REVOKE UPDATE ON fixture_demo.* FROM 'fx_writer'@'localhost';")
    _query(c,'UPDATE fixture_demo.counter SET value=value+1 WHERE id=1;','writer')
    _query(c,'SET GLOBAL read_only=1;')
    try:
        _query(c,'UPDATE fixture_demo.counter SET value=value+1 WHERE id=1;','writer')
        ordinary=_query(c,'UPDATE fixture_demo.counter SET value=value+1 WHERE id=1;','shared_writer',allow_error=True)
        require(ordinary.status==1 and ordinary.error_code==1290,'sql-readonly-demonstration')
    finally:_query(c,'SET GLOBAL read_only=0;')
    require(_query(c,'SELECT value FROM fixture_demo.counter WHERE id=1;').rows==( ('2',), ),'sql-demo-counter')
    current=snapshot(c);require(current.protected_sha256==before.protected_sha256 and current.unrelated_sha256==before.unrelated_sha256,'sql-demo-scope')
    restore_original(c,before,current);return {'demo_counter':2}


def _protected(c,s):
    from sql_fence_harness import establish
    before=snapshot(c)
    with _journal(c,'establish') as j:establish(c,_projection(c),j)
    for key in ('update','zero_update','insert','delete','create','alter','drop','truncate','rename','column'):
        assert_denial(_probe(c,key,'writer'),frozenset({1044,1142}))
    after=snapshot(c);require(after.protected_sha256==before.protected_sha256,'sql-protected-changed')
    return {'denials':10,'protected_unchanged':True}


def _unrelated(c,s):
    counters=[]
    for r in c.plan.required_business:
        target=identifier(r.schema)+'.counter'
        require(_query(c,'SELECT value FROM '+target+' WHERE id=1;',r.role).rows==( ('0',), ),'sql-unrelated-before')
        _query(c,'INSERT INTO '+target+' VALUES(2,7); UPDATE '+target+' SET value=8 WHERE id=2; DELETE FROM '+target+' WHERE id=2; UPDATE '+target+' SET value=value+1 WHERE id=1;',r.role)
        if 'CREATE' in r.privileges:
            t=identifier(r.schema)+'.accepted'
            _query(c,'CREATE TABLE '+t+'(id INT PRIMARY KEY,value INT NOT NULL) ENGINE=InnoDB; ALTER TABLE '+t+' ADD COLUMN extra INT; CREATE INDEX accepted_idx ON '+t+'(value); DROP INDEX accepted_idx ON '+t+'; DROP TABLE '+t+';',r.role)
        rows=_query(c,'SELECT id,value FROM '+target+' ORDER BY id;',r.role).rows
        require(rows==( ('1','1'), ),'sql-unrelated-counter');counters.append(int(rows[0][1]))
    require(_query(c,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA="+"X'"+c.plan.value['preserved_schemas'][1].encode().hex()+"' AND TABLE_NAME='counter';").rows==( ('MyISAM',), ),'sql-myisam-work')
    c.unrelated_expected=snapshot(c).unrelated_sha256
    return {'counters':counters,'myisam_preserved':True}


def _cached(c,s):
    restore_original(c,c.original,snapshot(c));s.clients_allowed=True
    unknown=_open_session(c,'collector');_rejected(lambda:drain_barrier(c,()));_close_session(c,unknown)
    old=_open_session(c,'writer');_session_statement(c,old,'active');_rejected(lambda:drain_barrier(c,()))
    require(_session_line(old['process'])==('0',),'sql-active-session-result')
    require(_session_statement(c,old,'zero_update')==('ZERO_AUTHORIZED',),'sql-prior-cache')
    p=_projection(c);current=snapshot(c);c.accepted=current
    with _journal(c,'cached') as j:
        for op in p.establish:
            intent=j.intent(op,current);current=execute_operation(c,op);j.readback(intent,current)
        require(current.grants==p.held,'sql-cached-held-model')
    require(_session_statement(c,old,'zero_update')==('ZERO_AUTHORIZED',),'sql-cached-global-authorization')
    assert_denial(_probe(c,'zero_update','writer'),frozenset({1044,1142}))
    _rejected(lambda:drain_barrier(c,()))
    _close_session(c,old);drain_barrier(c,())
    require(snapshot(c).protected_sha256==c.original.protected_sha256,'sql-cached-protected-data')
    s.set_client_admission(True,snapshot_hash(snapshot(c)))
    return {'cached_authorized':True,'fresh_denied':True,'drained':True}


def _patterns(c,s):
    before=snapshot(c);old_opts=c.options['writer'];old_actor=c.actors['writer'];old_identity=c.option_identities['writer']
    password=secrets.token_hex(24);path=c.jail/'tmp'/('host-'+secrets.token_hex(6)+'.cnf')
    s.exclusive_bytes(path,('[client]\nuser=fx_writer\npassword='+password+'\n').encode())
    _query(c,"CREATE USER 'fx_writer'@'%' IDENTIFIED BY '"+password+"'; CREATE USER 'fx_writer'@'127.0.0.1' IDENTIFIED BY '"+password+"'; GRANT UPDATE ON *.* TO 'fx_writer'@'%'; GRANT SELECT ON "+identifier(database_pattern(c.plan.value['protected_schema']))+".* TO 'fx_writer'@'127.0.0.1';")
    c.options['writer']=path;c.actors['writer']=('fx_writer','127.0.0.1');c.option_identities['writer']=((path.stat().st_dev,path.stat().st_ino),sha(path.read_bytes()))
    try:
        _query(c,'SELECT 1;','writer',tcp=True)
        q=_query(c,'UPDATE '+identifier(c.plan.value['protected_schema'])+'.t000 SET value=value WHERE id=0;','writer',tcp=True,allow_error=True)
        assert_denial(q,frozenset({1044,1142}))
    finally:
        c.options['writer']=old_opts;c.actors['writer']=old_actor;c.option_identities['writer']=old_identity
        _query(c,"DROP USER 'fx_writer'@'%'; DROP USER 'fx_writer'@'127.0.0.1';")
    for exact,neighbor in (('fixture_source','fixtureXsource'),('fixture_pct%','fixture_pctX')):
        _query(c,'GRANT SELECT ON '+identifier(database_pattern(exact))+".* TO 'fx_writer'@'localhost';")
        table='t000' if exact=='fixture_source' else 'counter'
        _query(c,'SELECT value FROM '+identifier(exact)+'.'+table+' WHERE id=1;','writer')
        q=_query(c,'SELECT value FROM '+identifier(neighbor)+'.counter WHERE id=1;','writer',allow_error=True)
        assert_denial(q,frozenset({1044,1142}))
    unsafe=replace(c.plan.seed,grants=c.plan.seed.grants+(GrantRow(('fx_writer','localhost'),'proxy',('fx_admin','localhost'),('PROXY',)),))
    _rejected(lambda:compile_projection(unsafe,c.plan.value['protected_schema'],tuple(c.plan.value['preserved_schemas']),c.plan.required_business))
    unsafe=replace(c.plan.seed,definitions=(DefinerObject('fixture_web','view',('fx_admin','localhost'),'0'*64),))
    _rejected(lambda:compile_projection(unsafe,c.plan.value['protected_schema'],tuple(c.plan.value['preserved_schemas']),c.plan.required_business))
    q=_query(c,"GRANT SELECT ON fixture_demo.* TO 'fx_writer'@'localhost';",'writer',allow_error=True)
    assert_denial(q,frozenset({1044,1142,1227}))
    restore_original(c,before,snapshot(c))
    return {'host_identity':'fx_writer@127.0.0.1','pattern_denials':2,'unsafe_rejections':3}


def _escape(c,s):
    before=snapshot(c)
    for key in ('credentials','grant_table','create_user','super','event_scheduler'):
        assert_denial(_probe(c,key,'writer'),frozenset({1044,1142,1227}))
    require(_query(c,'SELECT @@global.event_scheduler;').rows==( ('OFF',), ),'sql-event-scheduler-unchanged')
    _query(c,'UPDATE '+identifier(c.plan.value['protected_schema'])+'.t000 SET value=value WHERE id=0;')
    _query(c,'CREATE DEFINER=\'fx_admin\'@\'localhost\' SQL SECURITY DEFINER VIEW fixture_web.definer_escape AS SELECT value FROM '+identifier(c.plan.value['protected_schema'])+'.t000;')
    try:_rejected(lambda:snapshot(c))
    finally:_query(c,'DROP VIEW fixture_web.definer_escape;')
    require(snapshot(c)==before,'sql-definer-cleanup')
    return {'escalation_denials':5,'definer_rejected':True,'admin_trusted':True}


def _loss(c,s):
    before=snapshot(c);keeper=_open_session(c,'keeper')
    require(_session_statement(c,keeper,'transaction')==('TRANSACTION_STARTED',),'sql-keeper-transaction')
    assert_denial(_probe(c,'zero_update','writer'),frozenset({1044,1142}));_close_session(c,keeper,hard=True)
    with _journal(c,'new-controller') as j:
        _controller_checkpoint(s,j)
        from sql_fence_harness import recover
        require(recover(c,c.original,_projection(c),j)=='recovery-required','sql-invented-continuity')
    assert_denial(_probe(c,'zero_update','writer'),frozenset({1044,1142}));require(snapshot(c)==before,'sql-loss-opened-primary')
    return {'keeper_losses':1,'controller_losses':1,'continuity_denied':True}


def _restarts(c,s):
    before=snapshot(c)
    for hard in (False,True):
        restart_owned_server(c,hard);assert_denial(_probe(c,'zero_update','writer'),frozenset({1044,1142}))
        require(snapshot(c)==before,'sql-restart-integrity')
    return {'restarts':2,'unchanged':True}


def _partial(c,s):
    from sql_fence_harness import recover
    p=_projection(c);boundaries=['before_intent','after_intent','before_readback','after_readback'];trials=0
    for n in range(len(p.establish)):
        for boundary in boundaries:
            restore_original(c,c.original,snapshot(c));current=snapshot(c);c.accepted=current
            with _journal(c,'partial') as j:
                for op in p.establish[:n]:
                    intent=j.intent(op,current);current=execute_operation(c,op);j.readback(intent,current)
                def supervised_action():
                    nonlocal current
                    if boundary!='before_intent':intent=j.intent(p.establish[n],current)
                    if boundary in ('before_readback','after_readback'):current=execute_operation(c,p.establish[n])
                    if boundary=='after_readback':j.readback(intent,current)
                _controller_checkpoint(s,j,action=supervised_action)
                state=recover(c,c.original,p,j)
                expected='recovery-required' if boundary=='before_readback' or (boundary=='before_intent' and n==0 and current.raw_grant_rows!=c.original.raw_grant_rows) else 'restored'
                require(state==expected,'sql-recovery-boundary')
                if state=='recovery-required':
                    # No unacknowledged metadata is guessed. The trusted fixture
                    # administrator explicitly resets this fully observed trial.
                    require(current.grants in tuple(_prefixes(p)),'sql-unrecognized-prefix')
                    restore_original(c,c.original,snapshot(c))
                require(snapshot(c).raw_grant_rows==c.original.raw_grant_rows,'sql-partial-exact-restore');trials+=1
    # A competing grant with the same account/authentication must not be overwritten.
    current=snapshot(c)
    with _journal(c,'competing') as j:
        intent=j.intent(p.establish[0],current)
        _query(c,"GRANT SELECT ON "+identifier(database_pattern("fixture_demo"))+".* TO 'fx_collect'@'localhost';")
        changed=snapshot(c);require(recover(c,c.original,p,j)=='recovery-required','sql-competing-restore')
        require(snapshot(c)==changed,'sql-competing-overwritten')
        restore_original(c,c.original,changed)
    # Restore the held state for the final recovery case.
    c.accepted=snapshot(c)
    with _journal(c,'final-establish') as j:
        for op in p.establish:
            intent=j.intent(op,c.accepted);current=execute_operation(c,op);j.readback(intent,current)
    return {'operation_trials':trials,'boundaries':boundaries,'competing_denied':True}


def _prefixes(p):
    state=p.before;yield state
    for op in p.establish:
        from sql_fence_projection import apply_operation
        state=apply_operation(state,op);yield state


def _exact(c,s):
    before=snapshot(c);require(before.unrelated_sha256==c.unrelated_expected,'sql-unrelated-delta-lost')
    after=restore_original(c,c.original,before);again=snapshot(c)
    require(after==again and after.raw_grant_rows==c.original.raw_grant_rows and after.account_nonprivilege_sha256==c.original.account_nonprivilege_sha256 and after.protected_sha256==c.original.protected_sha256 and after.unrelated_sha256==before.unrelated_sha256,'sql-final-exact')
    s.evidence('final-snapshot',snapshot_record(after))
    return {'raw_exact':True,'protected_unchanged':True,'unrelated_preserved':True,'repeat_exact':True}

RUNNERS=dict(zip(CASE_IDS,(_broader,_protected,_unrelated,_cached,_patterns,_escape,_loss,_restarts,_partial,_exact)))


def run_case(case_id,context,supervisor):
    require(case_id in CHECKS,'sql-case')
    effects=RUNNERS[case_id](context,supervisor);validate_case(case_id,effects)
    result={'case_id':case_id,'passed':True,'effects':effects,'real_execution_ready':False}
    supervisor.evidence('case-'+case_id,result);return result
