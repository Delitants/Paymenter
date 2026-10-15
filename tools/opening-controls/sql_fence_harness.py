"""Bounded independently owned SQL experiment, not a source boundary adapter."""
from dataclasses import asdict
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
import stat
import subprocess
import sys
import time
from control import process_identity
from custody import private_directory,private_file,CustodyError,strict_json
from lock_runtime import stage_runtime,verify_runtime
from sql_fence_schema import (SqlFenceError,canonical,sha,require,SqlDocuments,detached,CASE_IDS)
from sql_fence_projection import semantic_hash,apply_operation
from sql_fence_driver import (snapshot,snapshot_hash,snapshot_record,execute_operation,
    restore_original,drain_barrier,shutdown_owned_server,_namespaces)

MIB=1024**2
ROOT_RESERVE=1024**3+128*MIB
EVIDENCE_LIMIT=16*MIB
TMPFS_LIMIT=256*MIB
MEMORY_LIMIT=512*MIB
PROCESS_ALLOWANCE=256*MIB
RAM_RESERVE=512*MIB
PIDS_LIMIT=64


def check_budget(resources):
    require(type(resources) is dict and set(resources)=={'root_free','shm_free','mem_available'},'sql-budget')
    require(all(type(x) is int and x>=0 for x in resources.values()),'sql-budget')
    require(resources['root_free']>=ROOT_RESERVE+EVIDENCE_LIMIT and
            resources['shm_free']>=RAM_RESERVE+TMPFS_LIMIT+PROCESS_ALLOWANCE and
            resources['mem_available']>=RAM_RESERVE+TMPFS_LIMIT+PROCESS_ALLOWANCE,'sql-budget')


def resources():
    mem={k:int(v.split()[0])*1024 for k,v in (line.split(':',1) for line in Path('/proc/meminfo').read_text().splitlines())}
    return {'root_free':shutil.disk_usage('/').free,'shm_free':shutil.disk_usage('/dev/shm').free,'mem_available':mem['MemAvailable']}


def _token(path):
    s=Path(path).lstat();return (s.st_dev,s.st_ino)


def _exclusive(path,raw):
    require(type(raw) is bytes and len(raw)<=4*MIB,'sql-evidence-part')
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as f:f.write(raw);f.flush();os.fsync(f.fileno())
        d=os.open(Path(path).parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(d)
        finally:os.close(d)
    except OSError:raise SqlFenceError('sql-evidence-durability') from None


def _evidence_size(root):
    total=0
    for p in Path(root).rglob('*'):
        s=p.lstat();require(not stat.S_ISLNK(s.st_mode),'sql-evidence-link')
        if stat.S_ISREG(s.st_mode):total+=s.st_size
        else:require(stat.S_ISDIR(s.st_mode),'sql-evidence-type')
    require(total<=EVIDENCE_LIMIT,'sql-evidence-budget');return total

class OperationJournal:
    def __init__(self,root: Path,generation: str):
        try:self.root=private_directory(Path(root).resolve())
        except (OSError,CustodyError):raise SqlFenceError('sql-journal-directory') from None
        require(type(generation) is str and 0<len(generation)<=64,'sql-journal-generation')
        self.generation=generation;self.identity=_token(self.root);self.held={};self.failed=False;self.closed=False
        self.verify_prefix()
    def __enter__(self):return self
    def __exit__(self,*args):self.close()
    def close(self):self.closed=True
    def verify_prefix(self):
        require(not self.closed and not self.failed and _token(self.root)==self.identity,'sql-journal-identity')
        try:
            private_directory(self.root);paths=sorted(self.root.iterdir());records=[];prev='0'*64;pending=None
            require(len(paths)<=10000,'sql-journal-budget')
            for seq,p in enumerate(paths,1):
                require(p.name=='%06d.json'%seq,'sql-journal-gap')
                token=private_file(p);require(p.stat().st_size<=4*MIB,'sql-journal-budget')
                raw=p.read_bytes();require(private_file(p)==token,'sql-journal-replacement')
                h=sha(raw)
                if p.name in self.held:require(self.held[p.name]==(token,h),'sql-journal-replacement')
                v=strict_json(raw)
                require(type(v) is dict and set(v)=={'schema_version','purpose','generation','seq','previous','kind','intent_id','value'},'sql-journal-record')
                require(type(v['schema_version']) is int and v['schema_version']==1 and v['purpose']=='fictional-sql-grant-operation' and v['generation']==self.generation and type(v['seq']) is int and v['seq']==seq and v['previous']==prev,'sql-journal-chain')
                if v['kind']=='intent':
                    require(pending is None and type(v['value']) is dict and set(v['value'])=={'operation','before'},'sql-journal-intent')
                    require(v['intent_id']==sha(canonical(v['value'])),'sql-journal-intent');pending=v['intent_id']
                else:
                    require(v['kind']=='readback' and pending==v['intent_id'] and type(v['value']) is dict and set(v['value'])=={'after'},'sql-journal-readback');pending=None
                self.held[p.name]=(token,h);prev=h;records.append(v)
            return tuple(records)
        except (OSError,ValueError,CustodyError):raise SqlFenceError('sql-journal-read') from None
    def _append(self,kind,intent_id,value):
        records=self.verify_prefix();seq=len(records)+1
        record={'schema_version':1,'purpose':'fictional-sql-grant-operation','generation':self.generation,
            'seq':seq,'previous':sha(canonical(records[-1])) if records else '0'*64,
            'kind':kind,'intent_id':intent_id,'value':value}
        raw=canonical(record);require(sum(p.stat().st_size for p in self.root.iterdir())+len(raw)<=EVIDENCE_LIMIT,'sql-journal-budget')
        pending=self.root/('%06d.pending'%seq);final=self.root/('%06d.json'%seq)
        try:
            _exclusive(pending,raw);os.link(pending,final,follow_symlinks=False);pending.unlink()
            fd=os.open(self.root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
            try:os.fsync(fd)
            finally:os.close(fd)
        except (OSError,SqlFenceError):self.failed=True;raise SqlFenceError('sql-journal-durability') from None
        self.verify_prefix()
    def intent(self,op,before):
        records=self.verify_prefix();require(not records or records[-1]['kind']=='readback','sql-journal-unacknowledged')
        value={'operation':asdict(op),'before':snapshot_record(before)}
        # asdict preserves tuples; canonical serialization normalizes the on-disk record.
        value=json.loads(canonical(value));intent=sha(canonical(value));self._append('intent',intent,value);return intent
    def readback(self,intent_id,after):
        records=self.verify_prefix();require(records and records[-1]['kind']=='intent' and records[-1]['intent_id']==intent_id,'sql-journal-readback')
        self._append('readback',intent_id,{'after':snapshot_record(after)})


def restorable(original,current):
    return original.account_nonprivilege_sha256==current.account_nonprivilege_sha256 and original.protected_sha256==current.protected_sha256


def establish(context,projection,journal):
    current=snapshot(context);require(current.grants==projection.before,'sql-establish-before')
    context.supervisor.set_client_admission(False,None)
    # Existing sessions are rejected, never killed to obtain the barrier.
    rows=context.supervisor.clients_allowed;context.supervisor.clients_allowed=True
    try:drain_barrier(context,())
    finally:context.supervisor.clients_allowed=rows
    context.accepted=current
    for op in projection.establish:
        ident=journal.intent(op,current);current=execute_operation(context,op);journal.readback(ident,current)
    require(current.grants==projection.held and journal.verify_prefix()[-1]['kind']=='readback','sql-incomplete-establish')
    context.supervisor.set_client_admission(True,snapshot_hash(current));drain_barrier(context,());return current


def recover(context,original,projection,journal):
    current=snapshot(context);records=journal.verify_prefix()
    if not restorable(original,current):return 'recovery-required'
    if not records:return 'restored' if current.raw_grant_rows==original.raw_grant_rows else 'recovery-required'
    last=records[-1]
    expected=last['value']['after'] if last['kind']=='readback' else last['value']['before']
    if snapshot_record(current)!=expected:return 'recovery-required'
    # In particular, an unacknowledged native operation is not inferred from semantics.
    restore_original(context,original,current);return 'restored'


def _run_fixed(argv,timeout=8):
    p=subprocess.run(argv,capture_output=True,timeout=timeout)
    require(p.returncode==0 and len(p.stdout)+len(p.stderr)<=MIB,'sql-native-infrastructure')
    return p.stdout


def _mount_record(path):
    matches=[]
    for line in Path('/proc/self/mountinfo').read_text().splitlines():
        before,after=line.split(' - ',1);cols=before.split()
        if cols[4]==str(path):matches.append({'id':cols[0],'path':cols[4],'fs':after.split()[0],'options':cols[5]})
    require(len(matches)==1,'sql-owned-mount');return matches[0]

class OwnedSupervisor:
    """Internal worker owner; the external retainer controls its startup barrier."""
    def __init__(self,config,plan):
        self._admitted=False;self.config=config;self.plan=plan;self.root=Path(config['root'])
        self.evidence_root=Path(config['evidence']);self.cgroup=Path(config['cgroup']);self.processes=[]
        self.deadline=config['deadline'];self.clients_allowed=False;self.counter=0;self.context=None
        self.mounts=[];self.log_files=[];self._runtime=None
        self._admission_guard();self._admitted=True
        _run_fixed(('mount','--make-rprivate','/'))
        _run_fixed(('mount','-t','tmpfs','-o','size=268435456,mode=0700,nosuid,nodev','sql-fixture',str(self.root)))
        self.mounts.append(_mount_record(self.root));self.root_identity=_token(self.root)
        require(os.statvfs(self.root).f_blocks*os.statvfs(self.root).f_frsize==TMPFS_LIMIT,'sql-tmpfs-budget')
        _run_fixed(('ip','link','set','lo','up'))
        require(set(os.listdir('/sys/class/net'))=={'lo'},'sql-external-interface')
        require(not _run_fixed(('ip','route','show')).strip() and not _run_fixed(('ip','-6','route','show','default')).strip(),'sql-external-route')
        self.evidence('admission',{'namespaces':_namespaces(os.getpid()),'cgroup_identity':list(_token(self.cgroup)),
              'limits':self._limits(),'root_mount':self.mounts[0],'resources':resources(),'real_execution_ready':False})
    def _limits(self):return {k:(self.cgroup/k).read_text().strip() for k in ('memory.max','memory.swap.max','pids.max')}
    def _admission_guard(self):
        require(sys.platform=='linux' and os.geteuid()==0,'sql-linux-root')
        require(_token(self.cgroup)==tuple(self.config['cgroup_identity']),'sql-cgroup-identity')
        require(self._limits()=={'memory.max':str(MEMORY_LIMIT),'memory.swap.max':'0','pids.max':str(PIDS_LIMIT)},'sql-cgroup-limits')
        require(Path('/proc/self/cgroup').read_text().strip()=='0::/'+self.cgroup.name,'sql-cgroup-membership')
        ns=_namespaces(os.getpid());require(all(ns[k]!=self.config['parent_namespaces'][k] for k in ns),'sql-namespace-admission')
        self.deadline_guard()
    def assert_admitted(self,runtime):
        require(self._admitted,'sql-not-admitted');self._admission_guard()
        require(runtime is self._runtime and self._runtime is not None,'sql-runtime-custody')
        require(_token(self.root)==self.root_identity and _mount_record(self.root)==self.mounts[0],'sql-mount-replacement')
        verify_runtime(runtime)
    def deadline_guard(self):require(time.monotonic()<self.deadline,'sql-invocation-deadline')
    def exclusive_bytes(self,path,raw):_exclusive(path,raw)
    def evidence(self,label,value):
        self.deadline_guard();self.counter+=1
        raw=canonical(value);require(_evidence_size(self.evidence_root)+len(raw)<=EVIDENCE_LIMIT,'sql-evidence-budget')
        _exclusive(self.evidence_root/('%05d-%s.json'%(self.counter,label)),raw)
    def spawn(self,argv,label,interactive):
        require(self._admitted and type(argv) is tuple and label in ('bootstrap','server','client','shutdown','controller'),'sql-not-admitted')
        self._admission_guard()
        require(len(argv)>0 and all(type(x) is str for x in argv),'sql-internal-command')
        if interactive:out=subprocess.PIPE;err=subprocess.PIPE
        else:
            p=self.root/('process-'+secrets.token_hex(8)+'.log');f=open(p,'xb');os.chmod(p,0o600)
            self.log_files.append((p,f));out=f;err=f
        proc=subprocess.Popen(argv,stdin=subprocess.PIPE if interactive else subprocess.DEVNULL,stdout=out,stderr=err,close_fds=True)
        self.processes.append({'process':proc,'identity':process_identity(proc.pid)})
        return proc
    def stop_owned(self,proc,hard=False):
        owned=next((p for p in self.processes if p['process'] is proc),None);require(owned is not None,'sql-unowned-process')
        if proc.poll() is not None:return
        require(process_identity(proc.pid)==owned['identity'],'sql-reused-process')
        proc.kill() if hard else proc.terminate()
        try:proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            require(process_identity(proc.pid)==owned['identity'],'sql-reused-process');proc.kill();proc.wait(timeout=5)
    def set_client_admission(self,allow,expected_snapshot_sha256):
        require(type(allow) is bool,'sql-client-admission')
        if allow:
            require(self.context is not None and expected_snapshot_sha256==snapshot_hash(snapshot(self.context)),'sql-client-readmission')
        self.clients_allowed=allow
        self.evidence('client-admission',{'allow':allow,'snapshot_sha256':expected_snapshot_sha256,'real_execution_ready':False})
    def prepare_runtime(self,documents):
        self._admission_guard();documents.verify_unchanged()
        self._runtime=stage_runtime(documents.archive_reference()['path'],documents.runtime_manifest_record(),self.root/'runtime')
        self.jail=self.root/'jail';self.jail.mkdir(mode=0o700)
        for d in ('tmp','data-v1'):(self.jail/d).mkdir(mode=0o700)
        for name in sorted({Path(n).parts[0] for n in self._runtime['manifest']['files']}):
            target=self.jail/name;target.mkdir(mode=0o700)
            _run_fixed(('mount','--bind',str(Path(self._runtime['root'])/name),str(target)))
            _run_fixed(('mount','-o','remount,bind,ro',str(target)))
            self.mounts.append(_mount_record(target))
        self.assert_admitted(self._runtime);return self._runtime


def _manifest(root,name):
    files={}
    for p in sorted(root.rglob('*')):
        if p.is_file():
            private_file(p);raw=p.read_bytes();files[str(p.relative_to(root))]={'bytes':len(raw),'sha256':sha(raw)}
    value={'schema_version':1,'purpose':'fictional-sql-evidence-manifest','files':files}
    _exclusive(root/name,canonical(value));return {'path':str(root/name),'sha256':sha(canonical(value))}


def retire(supervisor,evidence_root):
    s=supervisor;s.clients_allowed=False
    # Never restore privileges as an exit handler.
    if s.context is not None and s.context.server.poll() is None:
        try:shutdown_owned_server(s.context)
        except (SqlFenceError,OSError,subprocess.SubprocessError):s.stop_owned(s.context.server)
    for owned in reversed(s.processes):s.stop_owned(owned['process'])
    require(all(p['process'].poll() is not None for p in s.processes),'sql-process-retirement')
    for path,f in s.log_files:
        f.flush();os.fsync(f.fileno());f.close()
        require(path.stat().st_size<=MIB,'sql-log-budget')
        s.evidence('process-log',{'name':path.name,'raw_hex':path.read_bytes().hex()})
    if s.context is not None:
        log=s.context.jail/'tmp/server.log'
        if log.exists():
            require(log.stat().st_size<=MIB,'sql-log-budget');s.evidence('database-log',{'raw_hex':log.read_bytes().hex()})
        require(not s.context.socket.exists(),'sql-socket-retirement')
    peak=int((s.cgroup/'memory.peak').read_text());events=(s.cgroup/'memory.events').read_text()
    require(peak<=MEMORY_LIMIT and all(int(x.split()[1])==0 for x in events.splitlines() if x.split()[0] in ('oom','oom_kill','max')),'sql-memory-budget')
    s.evidence('pre-retirement',{'all_owned_processes_retired':True,'memory_peak':peak,'memory_events':events,'evidence_bytes':_evidence_size(evidence_root),'real_execution_ready':False})
    manifest=_manifest(evidence_root,'phase-manifest.json')
    _exclusive(evidence_root/'retention-request.json',canonical({'schema_version':1,'purpose':'fictional-sql-retention-request','manifest_sha256':manifest['sha256']}))
    # The operator independently retains and verifies the phase originals, then
    # places a closed acknowledgment. No local artifact silently self-acknowledges.
    ack=evidence_root/'retention-ack.json'
    while not ack.exists():s.deadline_guard();time.sleep(.1)
    private_file(ack);require(ack.stat().st_size<=4096,'sql-retention-ack')
    v=strict_json(ack.read_bytes());require(type(v) is dict and set(v)=={'schema_version','purpose','manifest_sha256'} and type(v['schema_version']) is int and v['schema_version']==1 and v['purpose']=='fictional-sql-retention-ack' and v['manifest_sha256']==manifest['sha256'],'sql-retention-ack')
    # The pinned original manifest and every original must still match the acknowledgment.
    require(sha(Path(manifest['path']).read_bytes())==manifest['sha256'],'sql-retention-drift')
    for rel,meta in strict_json(Path(manifest['path']).read_bytes())['files'].items():
        p=evidence_root/rel;private_file(p);require(p.stat().st_size==meta['bytes'] and sha(p.read_bytes())==meta['sha256'],'sql-retention-drift')
    for m in reversed(s.mounts):
        p=Path(m['path']);require(_mount_record(p)==m,'sql-reused-mount');_run_fixed(('umount',str(p)))
    require(_token(s.root)==tuple(s.config['root_identity']),'sql-root-retirement')
    return {'all_owned_processes_retired':True,'owned_mounts_retired':True,'phase_manifest':manifest,
            'memory_peak':peak,'evidence_bytes':_evidence_size(evidence_root),'complete':False}


def _worker(config_fd,barrier_fd):
    """Not a CLI mode. Fixed internal launcher passes inherited anonymous pipes."""
    raw=os.read(config_fd,65537);os.close(config_fd);require(len(raw)<=65536,'sql-startup-config')
    config=json.loads(raw);require(os.read(barrier_fd,2)==b'A','sql-startup-barrier');os.close(barrier_fd)
    outcome={'checks':{},'error':None,'retirement':{'complete':False},'real_execution_ready':False}
    sup=None
    try:
        with SqlDocuments(Path(config['reference_root'])) as documents:
            plan=documents.load(config['plan_ref']);sup=OwnedSupervisor(config,plan)
            runtime=sup.prepare_runtime(documents)
            from sql_fence_driver import create_fixture
            from sql_fence_cases import run_case
            c=create_fixture(runtime,sup);sup.context=c
            sup.clients_allowed=True
            for case in CASE_IDS:
                documents.verify_unchanged();sup.deadline_guard()
                outcome['checks'][case]=run_case(case,c,sup)
            documents.verify_unchanged()
    except BaseException as e:
        outcome['error']=e.code if isinstance(e,SqlFenceError) else type(e).__name__
        # The traceback stays private and bounded; public stdout never contains it.
        import traceback
        _exclusive(Path(config['evidence'])/'worker-failure.json',canonical({'error':outcome['error'],'traceback':traceback.format_exc()[-12000:],'real_execution_ready':False}))
    finally:
        if sup is not None:
            try:outcome['retirement']=retire(sup,Path(config['evidence']))
            except BaseException as e:
                outcome['retirement']={'complete':False,'error':e.code if isinstance(e,SqlFenceError) else type(e).__name__}
        _exclusive(Path(config['evidence'])/'worker-outcome.json',canonical(outcome))
    os._exit(0 if outcome['error'] is None and outcome['retirement'].get('owned_mounts_retired') else 1)


def _cgroup_limits(path):
    return {k:(path/k).read_text().strip() for k in ('memory.max','memory.swap.max','pids.max')}


def _launch(plan,documents,evidence_root):
    require(sys.platform=='linux' and os.geteuid()==0,'sql-linux-root');check_budget(resources())
    parent=Path(evidence_root).parent
    try:private_directory(parent)
    except (OSError,CustodyError):raise SqlFenceError('sql-evidence-parent') from None
    require(Path(evidence_root).is_absolute() and not Path(evidence_root).exists() and not Path(evidence_root).is_symlink(),'sql-evidence-exclusive')
    evidence_root.mkdir(mode=0o700)
    cgroup_parent=Path('/sys/fs/cgroup')
    require({'memory','pids'}<=set((cgroup_parent/'cgroup.subtree_control').read_text().split()),'sql-cgroup-delegation')
    suffix=secrets.token_hex(12);root=Path('/dev/shm')/('opening-sql-'+suffix);group=cgroup_parent/('opening-sql-'+suffix)
    root.mkdir(mode=0o700);root_identity=_token(root)
    group.mkdir();group_identity=_token(group)
    expected={'memory.max':str(MEMORY_LIMIT),'memory.swap.max':'0','pids.max':str(PIDS_LIMIT)}
    for key,value in expected.items():(group/key).write_text(value)
    require(_cgroup_limits(group)==expected and not (group/'cgroup.procs').read_text().strip(),'sql-cgroup-admission')
    parentns=_namespaces(os.getpid());deadline=time.monotonic()+600
    config={'root':str(root),'root_identity':root_identity,'cgroup':str(group),'cgroup_identity':group_identity,
            'parent_namespaces':parentns,'evidence':str(evidence_root),'deadline':deadline,
            'reference_root':str(documents.root),'plan_ref':documents.plan_reference}
    r,w=os.pipe();br,bw=os.pipe()
    code='import sys;sys.path.insert(0,'+repr(str(Path(__file__).resolve().parent))+');from sql_fence_harness import _worker;_worker('+str(r)+','+str(br)+')'
    # Namespace launch is fixed infrastructure. No shell or user command is accepted.
    log=open(evidence_root/'launcher.log','xb');os.chmod(evidence_root/'launcher.log',0o600)
    proc=subprocess.Popen(('unshare','--net','--pid','--mount','--fork','--kill-child=SIGKILL','--mount-proc',sys.executable,'-I','-c',code),
                          pass_fds=(r,br),stdin=subprocess.DEVNULL,stdout=log,stderr=log)
    outer_identity=process_identity(proc.pid);os.close(r);os.close(br)
    try:
        os.write(w,canonical(config));os.close(w)
        until=time.monotonic()+10;child=None
        while child is None:
            require(proc.poll() is None and time.monotonic()<until,'sql-launch-readiness')
            children=Path('/proc/%d/task/%d/children'%(proc.pid,proc.pid)).read_text().split()
            if children:
                require(len(children)==1,'sql-launch-child-census');child=int(children[0])
            else:time.sleep(.025)
        child_identity=process_identity(child);ns=_namespaces(child)
        require(all(ns[k]!=parentns[k] for k in ns),'sql-launch-namespace')
        (group/'cgroup.procs').write_text(str(child))
        require(process_identity(child)==child_identity and Path('/proc/%d/cgroup'%child).read_text().strip()=='0::/'+group.name,'sql-launch-membership')
        _exclusive(evidence_root/'parent-admission.json',canonical({'worker_identity':child_identity,'wrapper_identity':outer_identity,
            'namespaces':ns,'root_identity':root_identity,'cgroup_identity':group_identity,'limits':expected,
            'resources':resources(),'real_execution_ready':False}))
        os.write(bw,b'A');os.close(bw)
        while proc.poll() is None:
            require(time.monotonic()<deadline,'sql-invocation-deadline');_evidence_size(evidence_root)
            require(shutil.disk_usage('/').free>=ROOT_RESERVE,'sql-root-reserve');time.sleep(.1)
        log.flush();os.fsync(log.fileno());log.close()
        outcome_path=evidence_root/'worker-outcome.json'
        require(outcome_path.exists() and outcome_path.stat().st_size<=4*MIB,'sql-worker-outcome')
        outcome=strict_json(outcome_path.read_bytes())
        # A failed worker with uncertain resources is never cleaned by a prefix match.
        retired=outcome['retirement'];require(retired.get('owned_mounts_retired') is True,'sql-uncertain-retirement')
        require(_token(root)==root_identity and not any(root.iterdir()),'sql-reused-root');root.rmdir()
        require(_token(group)==group_identity and _cgroup_limits(group)==expected and not (group/'cgroup.procs').read_text().strip(),'sql-reused-cgroup')
        group.rmdir();retired.update(complete=True,owned_cgroup_retired=True,owned_root_retired=True)
        _exclusive(evidence_root/'retirement.json',canonical(retired))
        outcome['retirement']=retired;return outcome
    except BaseException:
        # Only the wrapper with its original process identity may be interrupted.
        # PID-namespace init teardown kills its own children; no host process census is signaled.
        if proc.poll() is None:
            require(process_identity(proc.pid)==outer_identity,'sql-reused-wrapper');proc.terminate()
            try:proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                require(process_identity(proc.pid)==outer_identity,'sql-reused-wrapper');proc.kill();proc.wait(timeout=5)
        raise
    finally:
        for fd in (w,bw):
            try:os.close(fd)
            except OSError:pass
        if not log.closed:log.flush();log.close()
