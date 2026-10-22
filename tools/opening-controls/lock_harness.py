"""Exact-version fictional database experiment. Never selects a live socket."""
import argparse
import hashlib
import os
from pathlib import Path
import select
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
from control import process_identity
from custody import private_directory,private_file,CustodyError
from source_schema import SourceError,canonical,digest,require,parse_document
from source_cli import private_save,load_private
from lock_runtime import validate_archive,stage_runtime,verify_runtime

CHECKS={'exact_source_version_and_owned_socket','three_hundred_innodb_tables_created','super_write_bypasses_global_read_only','ordinary_writer_denied_by_read_only','read_lock_blocks_super_writer','separate_snapshot_connection_reads_while_fenced','unrelated_schema_writer_continues','new_table_outside_locked_manifest_can_write','starting_transaction_on_keeper_releases_lock','keeper_connection_loss_releases_lock'}

def check_reserve(staging_bytes):
    require(shutil.disk_usage('/')[2]>=1024**3 and shutil.disk_usage('/dev/shm')[2]>=512*1024**2+staging_bytes+64*1024**2)

def verify_isolation(spec,net,pid):require(net!=spec['parent_net_ns'] and pid!=spec['parent_pid_ns'])

def message_directory(errmsg):
    # MariaDB appends its selected language below lc-messages-dir.
    return '/'+str(Path(errmsg).parent.parent)

def validate_query(status,stdout,stderr,expected=0,error=None):
    require(status==expected and (error is None or error in stderr))
    if expected==0:require(not stderr.strip())
    return stdout.strip()

def wait_for_lock(process,query,timeout=5):
    end=time.monotonic()+timeout
    while time.monotonic()<end:
        require(process.poll() is None);state=query()
        if state=='Waiting for table level lock':
            time.sleep(.1);require(process.poll() is None);return state
        time.sleep(.025)
    raise SourceError('Writer was not observed waiting for the expected lock')

def retire_owned(processes,runtime,evidence_root):
    complete=True;records=[]
    for item in reversed(processes):
        proc=item['popen'];entry={'pid':proc.pid,'identity':item['identity'],'retired':False}
        try:
            if proc.poll() is None:
                require(process_identity(proc.pid)==item['identity']);proc.terminate()
                try:proc.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    require(process_identity(proc.pid)==item['identity']);proc.kill();proc.wait(timeout=5)
            entry['retired']=proc.poll() is not None
        except (SourceError,OSError,RuntimeError,subprocess.SubprocessError):complete=False
        records.append(entry);complete=complete and entry['retired']
    return {'all_owned_processes_retired':complete,'processes':records}

def owned_parent_path(root):
    return root.parent==Path('/dev/shm') and root.name.startswith('opening-lock-')

def _clear_owned(fd):
    for name in os.listdir(fd):
        st=os.stat(name,dir_fd=fd,follow_symlinks=False)
        if stat.S_ISDIR(st.st_mode):
            child=os.open(name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=fd)
            try:
                current=os.fstat(child);require((current.st_dev,current.st_ino)==(st.st_dev,st.st_ino));_clear_owned(child)
            finally:os.close(child)
            current=os.stat(name,dir_fd=fd,follow_symlinks=False);require((current.st_dev,current.st_ino)==(st.st_dev,st.st_ino));os.rmdir(name,dir_fd=fd)
        else:os.unlink(name,dir_fd=fd)

def remove_retired_runtime(root,retirement,expected_identity=None):
    if not retirement['all_owned_processes_retired']:return False
    root=Path(root);require(owned_parent_path(root) and expected_identity is not None)
    fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        st=os.fstat(fd);require([st.st_dev,st.st_ino]==expected_identity);private_directory(root)
        _clear_owned(fd)
        current=root.lstat();require([current.st_dev,current.st_ino]==expected_identity);os.rmdir(root)
    finally:os.close(fd)
    return not root.exists()

def _log(path):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);return os.fdopen(fd,'wb')

class Experiment:
    def __init__(self,spec):
        self.spec=spec;self.root=Path(spec['runtime']['root']);self.evidence=Path(spec['evidence']);self.manifest=spec['runtime']['manifest'];self.owned=[];self.logs=[];self.transcript=[];self.checks={};self.observations={};self.server=None
        roles=self.manifest['roles'];libs=':'.join('/'+p for p in self.manifest['library_dirs'])
        self.loader=['chroot',str(self.root),'/'+roles['loader'],'--library-path',libs]
        self.client=self.loader+['/'+roles['mysql'],'--no-defaults','--socket=/tmp/fence.sock','--user=root','--batch','--raw','--skip-column-names','--unbuffered']
    def spawn(self,args,name,interactive=False):
        log=_log(self.evidence/(name+'.log'));self.logs.append(log)
        p=subprocess.Popen(args,stdin=subprocess.PIPE if interactive else subprocess.DEVNULL,stdout=subprocess.PIPE if interactive else log,stderr=log)
        self.owned.append({'popen':p,'identity':process_identity(p.pid)});return p
    def query(self,sql,expected=0,error=None,user=None):
        self.socket_guard();args=self.client.copy()
        if user:args[args.index('--user=root')]='--user='+user
        r=subprocess.run(args,input=(sql+'\n').encode(),capture_output=True,timeout=8)
        stdout=r.stdout.decode();stderr=r.stderr.decode();self.transcript.append({'sql_sha256':hashlib.sha256(sql.encode()).hexdigest(),'status':r.returncode,'stdout':stdout,'stderr':stderr})
        return validate_query(r.returncode,stdout,stderr,expected,error)
    def socket_guard(self):
        parent=self.root/'tmp';private_directory(parent);sock=parent/'fence.sock';s=sock.lstat();require(stat.S_ISSOCK(s.st_mode) and s.st_uid==0 and not sock.is_symlink())
    def session(self,sql,name):
        self.socket_guard();p=self.spawn(self.client,name,True);p.stdin.write((sql+'\n').encode());p.stdin.flush();return p
    def line(self,p):
        require(bool(select.select([p.stdout],[],[],5)[0]));raw=p.stdout.readline();require(bool(raw));return raw.decode().strip()
    def counter(self):return int(self.query('SELECT value FROM freeze_fixture.t000 WHERE id=1;'))
    def bootstrap(self):
        verify_runtime(self.spec['runtime'])
        for folder in ['data-v1','tmp']:(self.root/folder).mkdir(mode=0o700)
        roles=self.manifest['roles'];self.server_args=self.loader+['/'+roles['mysqld'],'--no-defaults','--user=root','--basedir=/usr','--datadir=/data-v1','--tmpdir=/tmp','--skip-networking','--skip-name-resolve','--socket=/tmp/fence.sock','--pid-file=/tmp/fence.pid','--log-error=/tmp/server.log','--lc-messages-dir='+message_directory(roles['errmsg']),'--innodb-buffer-pool-size=8M','--innodb-log-file-size=5M','--innodb-data-file-path=ibdata1:10M:autoextend','--innodb-use-native-aio=0','--max-connections=12']
        sql=b'CREATE DATABASE mysql;\nUSE mysql;\n'+(self.root/roles['bootstrap_sql']).read_bytes()+b'\n'+(self.root/roles['bootstrap_data']).read_bytes()
        r=subprocess.run(self.server_args+['--bootstrap'],input=sql,capture_output=True,timeout=30)
        private_save(self.evidence,'bootstrap.log',r.stdout+r.stderr);require(r.returncode==0)
    def run(self):
        self.bootstrap();self.server=self.spawn(self.server_args,'server')
        end=time.monotonic()+10
        while not (self.root/'tmp/fence.sock').exists():require(self.server.poll() is None and time.monotonic()<end);time.sleep(.05)
        identity=self.query('SELECT @@version,@@datadir,@@socket;');require(identity=='5.5.68-MariaDB\t/data-v1/\t/tmp/fence.sock');self.checks['exact_source_version_and_owned_socket']=True
        self.query('CREATE DATABASE freeze_fixture; CREATE DATABASE unrelated_fixture; CREATE TABLE unrelated_fixture.counter(value INT NOT NULL) ENGINE=InnoDB; INSERT INTO unrelated_fixture.counter VALUES(0);')
        self.query(' '.join('CREATE TABLE freeze_fixture.t%03d(id INT PRIMARY KEY,value INT NOT NULL) ENGINE=InnoDB; INSERT INTO freeze_fixture.t%03d VALUES(1,0);'%(i,i) for i in range(300)))
        require(self.query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='freeze_fixture' AND ENGINE='InnoDB';")=='300');self.checks['three_hundred_innodb_tables_created']=True
        self.query("GRANT SELECT,INSERT,UPDATE,DELETE ON freeze_fixture.* TO 'fixture_limited'@'localhost';")
        require(self.query("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=concat(char(39),'root',char(39),'@',char(39),'localhost',char(39)) AND PRIVILEGE_TYPE='SUPER';")=='1')
        self.query('SET GLOBAL read_only=1; UPDATE freeze_fixture.t000 SET value=value+1 WHERE id=1;');require(self.counter()==1);self.checks['super_write_bypasses_global_read_only']=True
        self.query('UPDATE freeze_fixture.t000 SET value=value+1 WHERE id=1;',1,'ERROR 1290','fixture_limited');self.checks['ordinary_writer_denied_by_read_only']=True
        locks='LOCK TABLES '+','.join('freeze_fixture.t%03d READ'%i for i in range(300))+';'
        keeper=self.session("SET autocommit=0; SET SESSION innodb_table_locks=1; "+locks+" SELECT 'LOCK_READY',@@autocommit,@@innodb_table_locks,CONNECTION_ID();",'keeper')
        ready=self.line(keeper).split('\t');require(ready[:3]==['LOCK_READY','0','1']);self.observations['keeper_connection_id']=int(ready[3])
        writer_sql="SELECT CONNECTION_ID(); UPDATE freeze_fixture.t000 SET value=value+1 WHERE id=1; SELECT 'WRITE_DONE';"
        writer=self.session(writer_sql,'first-writer');wid=int(self.line(writer));state=wait_for_lock(writer,lambda:self.query('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID='+str(wid)+';'));self.observations['first_wait_state']=state;require(self.counter()==1);self.checks['read_lock_blocks_super_writer']=True
        require(self.query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; START TRANSACTION WITH CONSISTENT SNAPSHOT; SELECT value FROM freeze_fixture.t000 WHERE id=1; COMMIT;')=='1' and writer.poll() is None);self.checks['separate_snapshot_connection_reads_while_fenced']=True
        self.query('UPDATE unrelated_fixture.counter SET value=value+1;');require(self.query('SELECT value FROM unrelated_fixture.counter;')=='1');self.checks['unrelated_schema_writer_continues']=True
        self.query('CREATE TABLE freeze_fixture.late_unlocked(value INT NOT NULL) ENGINE=InnoDB; INSERT INTO freeze_fixture.late_unlocked VALUES(1);');require(self.query('SELECT value FROM freeze_fixture.late_unlocked;')=='1');self.checks['new_table_outside_locked_manifest_can_write']=True
        keeper.stdin.write(b"START TRANSACTION; SELECT 'TRANSACTION_STARTED';\n");keeper.stdin.flush();require(self.line(keeper)=='TRANSACTION_STARTED' and self.line(writer)=='WRITE_DONE');writer.stdin.close();writer.wait(timeout=5);require(self.counter()==2);self.checks['starting_transaction_on_keeper_releases_lock']=True
        keeper.stdin.write(('COMMIT; '+locks+" SELECT 'RELOCKED';\n").encode());keeper.stdin.flush();require(self.line(keeper)=='RELOCKED')
        second=self.session(writer_sql,'second-writer');wid2=int(self.line(second));self.observations['second_wait_state']=wait_for_lock(second,lambda:self.query('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID='+str(wid2)+';'));require(self.counter()==2)
        item=next(x for x in self.owned if x['popen'] is keeper);require(process_identity(keeper.pid)==item['identity']);keeper.kill();keeper.wait(timeout=5);require(self.line(second)=='WRITE_DONE');second.stdin.close();second.wait(timeout=5);require(self.counter()==3);self.checks['keeper_connection_loss_releases_lock']=True
        self.observations['final_fixture_counter']=self.counter();self.observations['schema_table_count_after_unknown_create']=int(self.query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='freeze_fixture';"));require(self.observations['schema_table_count_after_unknown_create']==301 and set(self.checks)==CHECKS and all(self.checks.values()))
    def finish(self):
        shutdown={'attempted':False,'status':None}
        if self.server is not None and self.server.poll() is None:
            try:
                self.socket_guard();shutdown['attempted']=True
                r=subprocess.run(self.loader+['/'+self.manifest['roles']['mysqladmin'],'--no-defaults','--socket=/tmp/fence.sock','--user=root','shutdown'],capture_output=True,timeout=8)
                private_save(self.evidence,'shutdown.log',r.stdout+r.stderr);shutdown['status']=r.returncode
            except (OSError,SourceError,subprocess.SubprocessError):shutdown['status']=-1
        retirement=retire_owned(self.owned,self.spec['runtime'],self.evidence)
        for item in self.owned:
            for s in [item['popen'].stdin,item['popen'].stdout]:
                if s is not None and not s.closed:s.close()
        for log in self.logs:log.close()
        log=self.root/'tmp/server.log'
        if log.is_file():private_save(self.evidence,'database-server.log',log.read_bytes())
        private_save(self.evidence,'query-transcript.json',canonical(self.transcript));private_save(self.evidence,'retirement.json',canonical(retirement))
        return shutdown,retirement

def _worker(spec):
    require(set(spec)=={'runtime','evidence','owned_parent','owned_parent_identity','parent_net_ns','parent_pid_ns'})
    parent=Path(spec['owned_parent'])
    require(parent.parent==Path('/dev/shm') and parent.name.startswith('opening-lock-') and Path(spec['runtime']['root'])==parent/'root')
    private_directory(parent);require([parent.stat().st_dev,parent.stat().st_ino]==spec['owned_parent_identity']);private_directory(parent/'root')
    verify_isolation(spec,os.readlink('/proc/self/ns/net'),os.readlink('/proc/self/ns/pid'));private_directory(Path(spec['evidence']));experiment=Experiment(spec);failure=None
    try:experiment.run()
    except Exception as exc:failure=type(exc).__name__
    finally:shutdown,retirement=experiment.finish()
    report={'purpose':'exact-mariadb55-lock-rehearsal','version':spec['runtime']['manifest']['expected_version'],'runtime_manifest_sha256':spec['runtime']['manifest_sha256'],'checks':experiment.checks,'observations':experiment.observations,'fixture_data_only':True,'source_mutations':False,'real_execution_ready':False,'failure_type':failure,'shutdown':shutdown,'retirement':retirement,'native_passed':failure is None and retirement['all_owned_processes_retired'] and set(experiment.checks)==CHECKS and all(experiment.checks.values())}
    private_save(Path(spec['evidence']),'native-report.json',canonical(report));return 0 if report['native_passed'] else 1

def run_lock_rehearsal(archive,manifest,evidence_root):
    validated=validate_archive(archive,manifest);private_directory(evidence_root)
    require(sys.platform.startswith('linux') and os.geteuid()==0 and all(shutil.which(x) for x in ['unshare','chroot']))
    check_reserve(sum(f['bytes'] for f in manifest['files'].values()));parent=Path(tempfile.mkdtemp(prefix='opening-lock-',dir='/dev/shm'));parent.chmod(0o700)
    parent_identity=[parent.stat().st_dev,parent.stat().st_ino]
    evidence=Path(evidence_root)/parent.name;evidence.mkdir(mode=0o700)
    runtime=stage_runtime(archive,manifest,parent/'root');spec={'runtime':runtime,'evidence':str(evidence),'owned_parent':str(parent),'owned_parent_identity':parent_identity,'parent_net_ns':os.readlink('/proc/self/ns/net'),'parent_pid_ns':os.readlink('/proc/self/ns/pid')}
    specfile=private_save(parent,'worker.json',canonical(spec));log=_log(evidence/'namespace.log')
    worker=subprocess.Popen(['unshare','--net','--pid','--mount','--fork','--kill-child','--mount-proc',sys.executable,str(Path(__file__).resolve()),'--worker',str(specfile)],stdin=subprocess.DEVNULL,stdout=log,stderr=log)
    try:
        try:worker.wait(timeout=120)
        except subprocess.TimeoutExpired:worker.terminate();worker.wait(timeout=5)
    finally:log.close()
    report_path=evidence/'native-report.json'
    if not report_path.is_file():raise SourceError('Native worker failed; retain owned runtime and diagnostics')
    private_file(report_path)
    from custody import strict_json
    report=strict_json(report_path.read_bytes());require(worker.poll() is not None)
    # Confirm no process still uses the owned chroot or worker control path.
    remaining=[]
    for p in Path('/proc').iterdir():
        if not p.name.isdigit():continue
        try:
            args=(p/'cmdline').read_bytes();root=(p/'root').resolve()
            if str(parent).encode() in args or root==parent or parent in root.parents:remaining.append(int(p.name))
        except OSError:continue
    retirement=report['retirement'].copy();retirement['all_owned_processes_retired']=retirement['all_owned_processes_retired'] and not remaining
    try:removed=remove_retired_runtime(parent,retirement,parent_identity)
    except (OSError,SourceError,CustodyError):removed=False
    private_save(evidence,'parent-retirement.json',canonical({'remaining_pids':remaining,'owned_ram_removed':removed}))
    require(report['native_passed'] and removed);check_reserve(0);return report

def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--archive');parser.add_argument('--manifest');parser.add_argument('--evidence');parser.add_argument('--worker',help=argparse.SUPPRESS);args=parser.parse_args(argv)
    try:
        if args.worker:
            p=Path(args.worker);private_directory(p.parent);private_file(p);require(p.parent.parent==Path('/dev/shm') and p.parent.name.startswith('opening-lock-'))
            from custody import strict_json
            spec=strict_json(p.read_bytes());require(spec['owned_parent']==str(p.parent));return _worker(spec)
        require(args.archive is not None and args.manifest is not None and args.evidence is not None)
        report=run_lock_rehearsal(Path(args.archive),load_private(args.manifest,'mariadb55-disposable-runtime'),Path(args.evidence));print(canonical({'native_passed':report['native_passed'],'checks':len(report['checks']),'real_execution_ready':False}).decode());return 0
    except Exception:
        print(canonical({'purpose':'mariadb55-rehearsal-failure','real_execution_ready':False}).decode());return 1

if __name__=='__main__':sys.exit(main())
