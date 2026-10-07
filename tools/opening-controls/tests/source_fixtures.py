"""Fictional source shapes. No provider, application or real source access."""
import base64
import hashlib
import io
import json
from pathlib import Path
import tarfile


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()).hexdigest()


def capture_profile():
    return {'schema_version': 1, 'purpose': 'source-control-capture-profile',
            'expected_host': {'hostname': 'fixture-source'}, 'file_roots': ['/fixture'],
            'read_paths': [{'path': '/fixture/main.conf', 'kind': 'nginx-root'}, {'path': '/fixture/crontab', 'kind': 'cron'}],
            'process_selectors': [{'exe': '/fixture/core', 'module': 'billmgr'}], 'database_schema': 'fixture_billing'}


def file_record(path, kind, data):
    return {'path': path, 'kind': kind, 'uid': 0, 'gid': 0, 'mode': 384, 'bytes': len(data),
            'sha256': hashlib.sha256(data).hexdigest(), 'raw_b64': base64.b64encode(data).decode()}


def process():
    return {'pid': 1234, 'ppid': 1, 'start_ticks': '456', 'exe': '/fixture/core', 'exe_sha256': 'a'*64,
            'uid': 0, 'module': 'billmgr', 'argv_sha256': 'b'*64, 'cgroup': '1:freezer:/', 'state': 'S'}


def raw_capture():
    return {'schema_version': 1, 'purpose': 'source-control-raw-capture', 'observation_id': 'fixture-capture',
            'host_identity': {'hostname': 'fixture-source', 'boot_id': 'fixture-source-boot', 'kernel': 'fixture-kernel', 'python': '2.7.5'},
            'capture_interval': {'start_uptime': 10.0, 'end_uptime': 11.0},
            'files': [file_record('/fixture/main.conf', 'nginx-root', b'events {} http { server { listen 443 ssl; server_name example.invalid; location /billmgr { proxy_pass https://127.0.0.1:1500; } } }'),
                      file_record('/fixture/crontab', 'cron', b'* * * * * /fixture/cron-billmgr daily\n')],
            'process_samples': [[process()], [process()]],
            'operations': {n: {'status': 0, 'stdout_b64': base64.b64encode(v).decode(), 'stderr_b64': ''} for n,v in {
                'listeners': b'LISTEN 0 128 0.0.0.0:1500 *:* users:(("ihttpd",pid=555,fd=1))\n',
                'services': b'fixture.service loaded active running Fixture\n',
                'database': b'VERSION\t5.5.68-MariaDB\t1\t1\t0\nOBJECT\tBASE TABLE\t6163636f756e74\tInnoDB\nPRINCIPAL\t726f6f74406c6f63616c686f7374\tSUPER\nSESSION\t3\t726f6f74\t6c6f63616c686f7374\t536c656570\n'}.items()},
            'capture_errors': []}


def observation():
    objects = []
    for line in [1, 2]:
        identity = {'path': '/fixture/crontab', 'line': line, 'raw_line_sha256': 'a'*64}
        details = {'module': 'billmgr'}
        objects.append({'id': 'job:fixture:%d' % line, 'kind': 'job', 'identity': identity,
                        'details': details, 'sha256': digest({'identity': identity, 'details': details})})
    obs = {'schema_version': 1, 'purpose': 'source-control-observation', 'observation_id': 'fixture-capture',
           'host_identity': raw_capture()['host_identity'], 'capture_interval': raw_capture()['capture_interval'],
           'objects': objects, 'errors': [], 'raw_capture_sha256': digest(raw_capture())}
    obs['receipt'] = {'payload_sha256': digest(obs), 'raw_capture_sha256': obs['raw_capture_sha256'],
                      'controller_boot_id': 'fixture-controller-boot', 'capture_start_ns': 0, 'capture_end_ns': 1_000_000_000}
    return obs


CONTROL_CODES = ['source-scheduler','source-process','source-ingress','source-cli-sql-ddl','secondary-lock-keeper','provider-custody-reconciliation','target-and-cohort-deassignment']

def coverage_profile(obs):
    return {'schema_version': 1, 'purpose': 'source-control-coverage-profile', 'accepted_observation_sha256': digest(obs),
            'expected_host': obs['host_identity'], 'assignments': [{'object_id': o['id'], 'sha256': o['sha256'],
            'disposition': 'selected', 'justification': 'Fictional selected writer', 'evidence_sha256': 'c'*64} for o in obs['objects']],
            'required_controls': CONTROL_CODES.copy()}


class FixtureClock:
    def boot_id(self): return 'fixture-controller-boot'
    def now_ns(self): return 2_000_000_000
    def monotonic_seconds(self): return 10.0


def runtime_archive(root):
    roles = dict(zip(['loader','mysqld','mysql','mysqladmin','bootstrap_sql','bootstrap_data','errmsg','charsets'],
                     ['lib/loader','usr/bin/mysqld','usr/bin/mysql','usr/bin/mysqladmin','share/mysql_system_tables.sql','share/mysql_system_tables_data.sql','share/errmsg.sys','share/charsets.xml']))
    files = {path: b'fictional runtime resource' for path in roles.values()}
    archive = Path(root)/'runtime.tar.gz'
    with tarfile.open(archive, 'w:gz') as tar:
        for path,data in files.items():
            member = tarfile.TarInfo(path);member.size=len(data);member.mode=0o700;tar.addfile(member, io.BytesIO(data))
    archive.chmod(0o600)
    manifest = {'schema_version':1,'purpose':'mariadb55-disposable-runtime','expected_version':'5.5.68-MariaDB',
                'archive_sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),'roles':roles,'library_dirs':['lib'],
                'files':{path:{'bytes':len(data),'sha256':hashlib.sha256(data).hexdigest(),'mode':0o700,'type':'regular'} for path,data in files.items()}}
    return {'archive':archive,'manifest':manifest}

class FakeReadOps:
    def __init__(self, snapshot, faults=None):
        import copy
        self.snapshot=copy.deepcopy(snapshot);self.faults=faults or {};self.calls=[];self.samples=0;self.reads={};self.errors=[]
    def host_identity(self):return self.snapshot['host_identity'].copy()
    def read_regular(self,path,limit):
        import copy
        self.calls.append(('read',path));self.reads[path]=self.reads.get(path,0)+1
        if self.faults.get('unreadable'):raise OSError('Fixture inaccessible file')
        f=copy.deepcopy(next(f for f in self.snapshot['files'] if f['path']==path))
        if self.faults.get('change_file') and self.reads[path]>1:f['mode']=0o644
        return f
    def sample_processes(self,selectors):
        import copy
        self.samples+=1;s=copy.deepcopy(self.snapshot['process_samples'][0])
        if self.samples>1 and self.faults.get('replace_pid_start'):s[0]['start_ticks']='999'
        if self.samples>1 and self.faults.get('disappear'):return []
        return s
    def run_operation(self,name,schema,byte_limit,timeout_seconds):
        import copy
        self.calls.append(('operation',name));r=copy.deepcopy(self.snapshot['operations'][name])
        if self.faults.get('fail_operation')==name:r['status']=1
        if self.faults.get('oversize')==name:r['stdout_b64']=base64.b64encode(b'x'*(byte_limit+1)).decode()
        return r
