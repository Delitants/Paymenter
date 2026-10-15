"""Owned MariaDB 5.5 transport. Only the factory can admit a SQL context.

Private helpers accept SQL composed by this module's fixed implementations;
public attempts accept case identifiers, never SQL, paths or credentials.
"""
from dataclasses import dataclass
import os
from pathlib import Path
import re
import secrets
import select
import signal
import stat
import subprocess
import time
from control import process_identity
from lock_runtime import verify_runtime
from sql_fence_schema import (SqlFenceError,GrantRow,GrantModel,CASE_IDS,ROLES,canonical,
    model_record,parse_seed,sha,require,frozen,detached)
from sql_fence_projection import (GrantOperation,apply_operation,identifier,database_pattern,semantic_hash)

_FACTORY=object()
FLAGS=(('Select','SELECT'),('Insert','INSERT'),('Update','UPDATE'),('Delete','DELETE'),
 ('Create','CREATE'),('Drop','DROP'),('Reload','RELOAD'),('Shutdown','SHUTDOWN'),
 ('Process','PROCESS'),('File','FILE'),('Grant','GRANT OPTION'),('References','REFERENCES'),
 ('Index','INDEX'),('Alter','ALTER'),('Show_db','SHOW DATABASES'),('Super','SUPER'),
 ('Create_tmp_table','CREATE TEMPORARY TABLES'),('Lock_tables','LOCK TABLES'),
 ('Execute','EXECUTE'),('Repl_slave','REPLICATION SLAVE'),('Repl_client','REPLICATION CLIENT'),
 ('Create_view','CREATE VIEW'),('Show_view','SHOW VIEW'),('Create_routine','CREATE ROUTINE'),
 ('Alter_routine','ALTER ROUTINE'),('Create_user','CREATE USER'),('Event','EVENT'),
 ('Trigger','TRIGGER'),('Create_tablespace','CREATE TABLESPACE'))
DB_FLAGS=tuple(x for x in FLAGS if x[0] not in ('Reload','Shutdown','Process','File','Show_db',
 'Super','Repl_slave','Repl_client','Create_user','Create_tablespace'))
# Stock order differs from global flags; explicit lists are verified against the runtime.
DB_COLUMNS=('Host','Db','User','Select_priv','Insert_priv','Update_priv','Delete_priv','Create_priv',
 'Drop_priv','Grant_priv','References_priv','Index_priv','Alter_priv','Create_tmp_table_priv',
 'Lock_tables_priv','Create_view_priv','Show_view_priv','Create_routine_priv','Alter_routine_priv',
 'Execute_priv','Event_priv','Trigger_priv')
NONPRIV=('Host','User','Password','ssl_type','ssl_cipher','x509_issuer','x509_subject',
 'max_questions','max_updates','max_connections','max_user_connections','plugin','authentication_string')
COLUMNS={'user':('Host','User','Password')+tuple(k+'_priv' for k,_ in FLAGS)+NONPRIV[3:],
 'db':DB_COLUMNS,
 'tables_priv':('Host','Db','User','Table_name','Grantor','Timestamp','Table_priv','Column_priv'),
 'columns_priv':('Host','Db','User','Table_name','Column_name','Timestamp','Column_priv'),
 'procs_priv':('Host','Db','User','Routine_name','Routine_type','Grantor','Proc_priv','Timestamp'),
 'proxies_priv':('Host','User','Proxied_host','Proxied_user','With_grant','Grantor','Timestamp'),
 'host':tuple(x for x in DB_COLUMNS if x not in ('User','Event_priv'))}

@dataclass(frozen=True)
class AttemptResult:
    case_id: str
    status: int
    error_code: int | None
    rows: tuple[tuple[str,...],...]

@dataclass(frozen=True)
class SqlSnapshot:
    grants: GrantModel
    raw_grant_rows: tuple[dict,...]
    account_nonprivilege_sha256: str
    protected_sha256: str
    unrelated_sha256: str
    def __post_init__(self):
        object.__setattr__(self,'raw_grant_rows',tuple(frozen(detached(x)) for x in self.raw_grant_rows))

class OwnedSqlContext:
    def __init__(self,seal=None):
        require(seal is _FACTORY,'sql-owned-factory');self._seal=seal


def snapshot_record(s):
    return {'grants':model_record(s.grants),'raw_grant_rows':detached(s.raw_grant_rows),
        'account_nonprivilege_sha256':s.account_nonprivilege_sha256,
        'protected_sha256':s.protected_sha256,'unrelated_sha256':s.unrelated_sha256}


def snapshot_hash(s):return sha(canonical(snapshot_record(s)))


def _cell(v):
    require(type(v) is str,'sql-cell')
    if v=='N':return None
    require(v.startswith('H') and re.fullmatch('[A-F0-9]*',v[1:]) is not None and len(v)%2==1,'sql-cell')
    return bytes.fromhex(v[1:])


def _literal(raw):return 'NULL' if raw is None else "X'"+raw.hex()+"'"


def _account(principal):
    u,h=principal
    require(re.fullmatch('fx_[A-Za-z0-9_]{1,13}',u) is not None and h in ('localhost','127.0.0.1','%','127.0.0.%'),'sql-account')
    return "'"+u+"'@'"+h+"'"


def _inode(p):
    st=Path(p).lstat();return (st.st_dev,st.st_ino)


def _namespaces(pid):return {n:os.readlink('/proc/%d/ns/%s'%(pid,n)) for n in ('mnt','net','pid')}


def _guard(c):
    require(type(c) is OwnedSqlContext and c._seal is _FACTORY,'sql-owned-context')
    c.supervisor.assert_admitted(c.runtime)
    try:
        require(c.server.poll() is None and process_identity(c.server.pid)==c.server_identity,'sql-server-identity')
        require(_namespaces(c.server.pid)==c.namespaces==_namespaces(os.getpid()),'sql-server-namespace')
        for p,i in c.directories:
            st=p.lstat();require(stat.S_ISDIR(st.st_mode) and st.st_uid==0 and st.st_gid==0 and stat.S_IMODE(st.st_mode)==0o700 and _inode(p)==i,'sql-owned-directory')
        require(_inode(c.marker)==c.marker_identity and c.marker.read_bytes()==c.marker_value,'sql-fixture-marker')
        st=c.socket.lstat();require(stat.S_ISSOCK(st.st_mode) and st.st_uid==0 and _inode(c.socket)==c.socket_identity,'sql-owned-socket')
        require((c.jail/'tmp/fence.pid').read_text().strip()==str(c.server.pid),'sql-server-pidfile')
    except (OSError,AttributeError):raise SqlFenceError('sql-physical-identity') from None


def _command(c,role,tcp=False):
    require(role in c.options,'sql-role')
    opts='/tmp/'+c.options[role].name
    args=(*c.loader,'/'+c.runtime['manifest']['roles']['mysql'],'--defaults-file='+opts,
          '--batch','--raw','--skip-column-names','--unbuffered','--connect-timeout=2')
    if tcp:args+=('--protocol=TCP','--host=127.0.0.1','--port=33071')
    else:args+=('--protocol=SOCKET','--socket=/tmp/fence.sock')
    return args


def _query(c,sql,role='administrator',bootstrap=False,tcp=False,allow_error=False):
    _guard(c);c.supervisor.deadline_guard()
    require(bootstrap or c.supervisor.clients_allowed or role=='administrator','sql-client-admission')
    identity=c.actors[role]
    query='SELECT CURRENT_USER();\n'+sql+'\n'
    p=c.supervisor.spawn(_command(c,role,tcp),'client',True)
    try:out,err=p.communicate(query.encode(),timeout=8)
    except (subprocess.TimeoutExpired,BrokenPipeError):
        c.supervisor.stop_owned(p);raise SqlFenceError('sql-statement-timeout') from None
    require(len(out)+len(err)<=1024**2,'sql-output-bound')
    rows=tuple(tuple(line.split('\t')) for line in out.decode('utf-8').splitlines())
    match=re.search(rb'ERROR ([0-9]+) ',err);code=int(match[1]) if match else None
    require(rows and rows[0]==(identity[0]+'@'+identity[1],),'sql-authenticated-identity')
    record={'sql_sha256':sha(sql.encode()),'role':role,'tcp':tcp,'status':p.returncode,
            'error_code':code,'rows':[list(x) for x in rows[1:]],'stderr_hex':err.hex()}
    c.supervisor.evidence('attempt',record)
    if not allow_error:require(p.returncode==0 and not err.strip(),'sql-statement')
    return AttemptResult('',p.returncode,code,rows[1:])


def _grant_sql(op):
    action,level=op.kind.split(':');require(action in ('grant','revoke'),'sql-operation')
    require(level in ('global','database','table','column'),'sql-operation-level')
    privileges=list(op.privileges);grant_option='GRANT OPTION' in privileges
    if grant_option:privileges.remove('GRANT OPTION')
    if level=='global':target='*.*'
    elif level=='database':target=identifier(database_pattern(op.target[0]))+'.*'
    else:target=identifier(op.target[0])+'.'+identifier(op.target[1])
    ps=','.join(p+(' ('+identifier(op.target[2])+')' if level=='column' else '') for p in privileges)
    account=_account(op.principal);queries=[]
    if action=='grant':
        queries.append('GRANT '+(ps or 'USAGE')+' ON '+target+' TO '+account+(' WITH GRANT OPTION' if grant_option else '')+';')
    else:
        if ps:queries.append('REVOKE '+ps+' ON '+target+' FROM '+account+';')
        if grant_option:queries.append('REVOKE GRANT OPTION ON '+target+' FROM '+account+';')
    return ' '.join(queries)


def _raw_rows(c):
    result=[]
    for table,cols in COLUMNS.items():
        expressions=["IF("+identifier(x)+" IS NULL,'N',CONCAT('H',HEX(CAST("+identifier(x)+" AS BINARY))))" for x in cols]
        rows=_query(c,'SELECT '+','.join(expressions)+' FROM mysql.'+identifier(table)+' ORDER BY '+','.join(identifier(x) for x in cols)+';').rows
        for cells in rows:
            require(len(cells)==len(cols),'sql-grant-columns')
            for cell in cells:_cell(cell)
            result.append({'table':table,'cells':tuple(cells)})
    return tuple(result)


def _unpattern(v):
    out='';i=0
    while i<len(v):
        if v[i]=='\\':
            i+=1;require(i<len(v) and v[i] in ('\\','_','%'),'sql-database-pattern')
        else:require(v[i] not in ('_','%'),'sql-database-pattern')
        out+=v[i];i+=1
    require(database_pattern(out)==v,'sql-database-pattern');return out


def _logical(c,raw):
    grants=[];users=set();nonpriv=[];mapping={k+'_priv':v for k,v in FLAGS}
    roles={(p.user,p.host):p.role for p in c.plan.seed.principals}
    for row in raw:
        table=row['table'];r={k:_cell(v) for k,v in zip(COLUMNS[table],row['cells'])}
        require(table!='host','sql-unmodeled-host-grants')
        principal=(r['User'].decode(),r['Host'].decode());require(principal in roles,'sql-unmodeled-principal')
        level={'user':'global','db':'database','tables_priv':'table','columns_priv':'column','procs_priv':'routine','proxies_priv':'proxy'}[table]
        target=()
        if table=='user':
            users.add(principal);nonpriv.append({k:row['cells'][COLUMNS[table].index(k)] for k in NONPRIV})
            privileges=tuple(sorted(mapping[k] for k in mapping if r[k]==b'Y'))
        elif table=='db':
            target=(_unpattern(r['Db'].decode()),);privileges=tuple(sorted(mapping[k] for k in r if k in mapping and r[k]==b'Y'))
        else:
            require(table not in ('procs_priv','proxies_priv'),'sql-unmodeled-escape')
            target=(r['Db'].decode(),r['Table_name'].decode())
            field='Table_priv'
            if table=='columns_priv':target+=(r['Column_name'].decode(),);field='Column_priv'
            privileges=tuple(sorted('GRANT OPTION' if x.upper()=='GRANT' else x.upper() for x in r[field].decode().split(',') if x))
        if privileges:grants.append(GrantRow(principal,level,target,privileges))
    require(users==set(roles),'sql-account-census')
    # Unexpected objects cannot hide behind an input model that declared none.
    defs=_query(c,"SELECT (SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA LIKE 'fixture%')+(SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA LIKE 'fixture%')+(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA LIKE 'fixture%')+(SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA LIKE 'fixture%');").rows
    require(defs==( ('0',), ),'sql-definer-escape')
    m=GrantModel(c.plan.seed.schemas,c.plan.seed.principals,tuple(sorted(grants,key=lambda g:(g.principal,g.level,g.target))),())
    require(parse_seed(canonical(model_record(m)))==m,'sql-native-grants')
    return m,sha(canonical(nonpriv))


def _schema_hash(c,schemas):
    statements=[]
    for schema in schemas:
        tables=_query(c,"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA="+_literal(schema.encode())+' ORDER BY TABLE_NAME;').rows
        if schema==c.plan.value['protected_schema']:
            require(tables==tuple(('t%03d'%i,'InnoDB') for i in range(300)),'sql-protected-manifest')
        for name,engine in tables:
            require(re.fullmatch(r'(t[0-9]{3}|counter|accepted)',name) is not None,'sql-object-manifest')
            target=identifier(schema)+'.'+identifier(name)
            statements.extend(('SHOW CREATE TABLE '+target+';','SELECT * FROM '+target+' ORDER BY id;'))
    return sha(canonical([list(r) for r in _query(c,' '.join(statements)).rows]))


def snapshot(c):
    _guard(c);raw=_raw_rows(c);m,nonpriv=_logical(c,raw)
    return SqlSnapshot(m,raw,nonpriv,_schema_hash(c,(c.plan.value['protected_schema'],)),
                       _schema_hash(c,tuple(c.plan.value['preserved_schemas'])))


def execute_operation(c,op):
    _guard(c);before=snapshot(c)
    require(before.raw_grant_rows==c.accepted.raw_grant_rows and before.account_nonprivilege_sha256==c.accepted.account_nonprivilege_sha256,'sql-competing-grant')
    expected=apply_operation(before.grants,op)
    _query(c,_grant_sql(op));after=snapshot(c)
    require(after.grants==expected and after.account_nonprivilege_sha256==before.account_nonprivilege_sha256 and after.protected_sha256==before.protected_sha256,'sql-operation-readback')
    c.accepted=after;return after


def _validate_sessions(rows,expected):
    require(type(expected) is tuple,'sql-session-census')
    allow={r['id']:r['user'] for r in expected}
    require(len(allow)==len(expected),'sql-session-census')
    require(len(rows)==len(allow),'sql-session-census')
    for r in rows:require(r['command']=='Sleep' and r['id'] in allow and r['user']==allow[r['id']],'sql-active-or-unowned-session')


def drain_barrier(c,expected_sessions):
    _guard(c);rows=_query(c,'SELECT ID,USER,COMMAND FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() ORDER BY ID;').rows
    census=[{'id':int(i),'user':u,'command':command} for i,u,command in rows]
    _validate_sessions(census,expected_sessions)
    require(not expected_sessions,'sql-cached-session-not-drained')
    for role in ('writer','shared_writer','collector','keeper'):_query(c,'SELECT 1;',role)


def assert_denial(attempt,expected_codes):
    require(type(attempt) is AttemptResult and attempt.status==1 and attempt.error_code in expected_codes and bool(expected_codes) and expected_codes<=frozenset({1044,1142,1227}),'sql-not-privilege-denial')


def _probe(c,key,role):
    s=identifier(c.plan.value['protected_schema']);legacy=identifier(c.plan.value['preserved_schemas'][0])
    queries={'update':'UPDATE '+s+'.t000 SET value=value+1 WHERE id=1;',
        'zero_update':'UPDATE '+s+'.t000 SET value=value WHERE id=0;',
        'insert':'INSERT INTO '+s+'.t000 VALUES(2,1);',
        'delete':'DELETE FROM '+s+'.t000 WHERE id=1;',
        'create':'CREATE TABLE '+s+'.new_object(id INT);',
        'alter':'ALTER TABLE '+s+'.t000 ADD COLUMN forbidden INT;',
        'drop':'DROP TABLE '+s+'.t000;', 'truncate':'TRUNCATE TABLE '+s+'.t000;',
        'rename':'RENAME TABLE '+s+'.t000 TO '+legacy+'.stolen;',
        'column':'UPDATE '+s+'.t001 SET value=value+1 WHERE id=1;',
        'credentials':'SELECT Password FROM mysql.user;',
        'grant_table':'UPDATE mysql.user SET Super_priv=\'Y\' WHERE User=\'fx_writer\';',
        'create_user':"CREATE USER 'fx_escape'@'localhost';",
        'super':'SET GLOBAL read_only=1;',
        'file':"SELECT 'fixture' INTO OUTFILE '/tmp/forbidden';",
        'tablespace':'CREATE TABLESPACE fixture_escape ADD DATAFILE \'/tmp/escape\';'}
    require(key in queries and role in ROLES,'sql-probe')
    r=_query(c,queries[key],role,allow_error=True)
    return AttemptResult('protected_dml_ddl_denied',r.status,r.error_code,r.rows)


def attempt(c,case_id,role):
    require(case_id in CASE_IDS and role in ROLES,'sql-case')
    _guard(c)
    return _probe(c,'credentials' if case_id=='admin_definer_escape_denied' else 'zero_update',role)


def _start_server(c):
    c.server=c.supervisor.spawn(c.server_args,'server',False)
    c.server_identity=process_identity(c.server.pid);c.namespaces=_namespaces(c.server.pid)
    end=time.monotonic()+10
    while not c.socket.exists():
        require(c.server.poll() is None and time.monotonic()<end,'sql-server-readiness');time.sleep(.025)
    c.socket_identity=_inode(c.socket)


def create_fixture(runtime,supervisor):
    supervisor.assert_admitted(runtime)
    c=OwnedSqlContext(_FACTORY);c.supervisor=supervisor;c.runtime=runtime;c.plan=supervisor.plan
    c.jail=supervisor.jail;c.socket=c.jail/'tmp/fence.sock';c.sessions=[]
    c.marker=supervisor.root/'fixture.marker';c.marker_value=secrets.token_hex(32).encode()
    supervisor.exclusive_bytes(c.marker,c.marker_value);c.marker_identity=_inode(c.marker)
    c.directories=tuple((p,_inode(p)) for p in (supervisor.root,c.jail,c.jail/'tmp',c.jail/'data-v1'))
    roles=runtime['manifest']['roles'];libs=':'.join('/'+p for p in runtime['manifest']['library_dirs'])
    c.loader=('chroot',str(c.jail),'/'+roles['loader'],'--library-path',libs)
    c.server_args=(*c.loader,'/'+roles['mysqld'],'--no-defaults','--user=root','--basedir=/usr',
       '--datadir=/data-v1','--tmpdir=/tmp','--bind-address=127.0.0.1','--port=33071',
       '--skip-name-resolve','--socket=/tmp/fence.sock','--pid-file=/tmp/fence.pid',
       '--log-error=/tmp/server.log','--lc-messages-dir=/'+str(Path(roles['errmsg']).parent.parent),
       '--innodb-buffer-pool-size=8M','--innodb-log-file-size=5M','--innodb-data-file-path=ibdata1:10M:autoextend',
       '--innodb-use-native-aio=0','--max-connections=12','--max-allowed-packet=1M')
    bootstrap=b'CREATE DATABASE mysql;\nUSE mysql;\n'+(Path(runtime['root'])/roles['bootstrap_sql']).read_bytes()+b'\n'+(Path(runtime['root'])/roles['bootstrap_data']).read_bytes()
    p=supervisor.spawn(c.server_args+('--bootstrap',),'bootstrap',True)
    try:out,err=p.communicate(bootstrap,timeout=30)
    except subprocess.TimeoutExpired:supervisor.stop_owned(p);raise SqlFenceError('sql-bootstrap-timeout') from None
    supervisor.evidence('bootstrap',{'status':p.returncode,'stdout_hex':out.hex(),'stderr_hex':err.hex()});require(p.returncode==0,'sql-bootstrap')
    c.options={};c.actors={}
    def options(role,actor,password):
        p=c.jail/'tmp'/('client-'+secrets.token_hex(6)+'.cnf')
        supervisor.exclusive_bytes(p,('[client]\nuser='+actor[0]+'\npassword='+password+'\n').encode())
        c.options[role]=p;c.actors[role]=actor
    options('administrator',('root','localhost'),'')
    _start_server(c)
    require(_query(c,'SELECT @@version,@@datadir,@@socket;',bootstrap=True).rows==( ('5.5.68-MariaDB','/data-v1/','/tmp/fence.sock'), ),'sql-runtime-version')
    # Explicit bootstrap stock schema; no supplied SQL or option file is interpreted.
    for table,cols in COLUMNS.items():
        actual=tuple(r[0] for r in _query(c,'SHOW COLUMNS FROM mysql.'+identifier(table)+';',bootstrap=True).rows)
        require(actual==cols,'sql-runtime-grant-columns')
    passwords={(p.user,p.host):secrets.token_hex(24) for p in c.plan.seed.principals}
    for p in c.plan.seed.principals:
        account=(p.user,p.host)
        _query(c,'CREATE USER '+_account(account)+" IDENTIFIED BY '"+passwords[account]+"';",bootstrap=True)
    for g in c.plan.seed.grants:
        if next(p.role for p in c.plan.seed.principals if (p.user,p.host)==g.principal)=='administrator':
            _query(c,_grant_sql(GrantOperation('grant:'+g.level,g.principal,g.target,g.privileges)),bootstrap=True)
    for role in ROLES:
        actors=[p for p in c.plan.seed.principals if p.role==role and p.host=='localhost'];require(len(actors)==1,'sql-fixture-role-host')
        p=actors[0];options(role,(p.user,p.host),passwords[(p.user,p.host)])
    # Remove only the known stock bootstrap accounts on this newly owned server.
    _query(c,"DELETE FROM mysql.user WHERE User IN ('root',''); DELETE FROM mysql.db WHERE User=''; DELETE FROM mysql.proxies_priv; FLUSH PRIVILEGES;",bootstrap=True)
    statements=[]
    for schema in c.plan.seed.schemas:statements.append('CREATE DATABASE '+identifier(schema)+';')
    protected=identifier(c.plan.value['protected_schema'])
    for i in range(300):
        target=protected+'.t%03d'%i
        statements.extend(('CREATE TABLE '+target+'(id INT PRIMARY KEY,value INT NOT NULL) ENGINE=InnoDB;','INSERT INTO '+target+' VALUES(1,0);'))
    for schema in c.plan.seed.schemas:
        if schema==c.plan.value['protected_schema']:continue
        target=identifier(schema)+'.counter';engine='MyISAM' if schema==c.plan.value['preserved_schemas'][1] else 'InnoDB'
        statements.extend(('CREATE TABLE '+target+'(id INT PRIMARY KEY,value INT NOT NULL) ENGINE='+engine+';','INSERT INTO '+target+' VALUES(1,0);'))
    _query(c,' '.join(statements),bootstrap=True)
    for g in c.plan.seed.grants:
        if next(p.role for p in c.plan.seed.principals if (p.user,p.host)==g.principal)!='administrator':
            _query(c,_grant_sql(GrantOperation('grant:'+g.level,g.principal,g.target,g.privileges)),bootstrap=True)
    c.accepted=snapshot(c);require(c.accepted.grants==c.plan.seed,'sql-native-seed-mismatch')
    supervisor.evidence('original-snapshot',snapshot_record(c.accepted));return c
