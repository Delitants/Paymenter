"""Closed private source evidence schemas. None is a native opening proof."""
import base64
import hashlib
import math
from pathlib import PurePosixPath
import re
from custody import strict_json

MAX_PART = 4 * 1024**2
MAX_CAPTURE = 32 * 1024**2
MAX_OBJECTS = 10000
MAX_AGE_NS = 60 * 10**9
CONTROL_CODES = {'source-scheduler','source-process','source-ingress','source-cli-sql-ddl','secondary-lock-keeper','provider-custody-reconciliation','target-and-cohort-deassignment'}
KINDS = {'file','job','service','process','include','listener','route','sql-object','sql-principal','sql-session'}
FILE_KINDS = {'nginx','nginx-root','cron','service','manager','plain'}
ROLES = {'loader','mysqld','mysql','mysqladmin','bootstrap_sql','bootstrap_data','errmsg','charsets'}

class SourceError(RuntimeError):
    pass

def canonical(value):
    import json
    try:return json.dumps(value,sort_keys=True,separators=(',', ':'),allow_nan=False).encode()
    except (TypeError,ValueError) as exc:raise SourceError('Invalid evidence value') from exc

def digest(value):return hashlib.sha256(canonical(value)).hexdigest()

def require(condition):
    if not condition:raise SourceError('Incomplete, changed or unsupported source evidence')

def keys(value,names):require(type(value) is dict and set(value)==set(names.split()))

def text(value,empty=False):require(isinstance(value,str) and len(value)<=MAX_PART and '\0' not in value and (empty or bool(value)))

def integer(value,low=0,high=2**63-1):require(type(value) is int and low<=value<=high)

def sha(value):require(isinstance(value,str) and re.fullmatch('[a-f0-9]{64}',value) is not None)

def path(value,absolute=True):
    text(value);p=PurePosixPath(value)
    require(p.is_absolute()==absolute and '..' not in p.parts and str(p)==value and value not in ['/', '.', ''])
    return p

def sequence(value):require(type(value) is list and len(value)<=MAX_OBJECTS)

def header(value,purpose):integer(value.get('schema_version'),1,1);require(value.get('purpose')==purpose);require(len(canonical(value))<=MAX_CAPTURE)

def host(value):
    keys(value,'hostname boot_id kernel python')
    for v in value.values():text(v)

def errors(value):
    sequence(value)
    for e in value:keys(e,'code ref');text(e['code']);text(e['ref'])

def decode(value):
    text(value,True)
    try:raw=base64.b64decode(value,validate=True)
    except (ValueError,TypeError) as exc:raise SourceError('Invalid private base64') from exc
    require(len(raw)<=MAX_PART);return raw

def validate_capture_profile(v):
    keys(v,'schema_version purpose expected_host file_roots read_paths process_selectors database_schema');header(v,'source-control-capture-profile')
    keys(v['expected_host'],'hostname');text(v['expected_host']['hostname'])
    require(isinstance(v['database_schema'],str) and re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,63}',v['database_schema']))
    sequence(v['file_roots']);require(v['file_roots']);roots=[path(x) for x in v['file_roots']]
    sequence(v['read_paths']);seen=set()
    for f in v['read_paths']:
        keys(f,'path kind');p=path(f['path']);require(f['kind'] in FILE_KINDS and f['path'] not in seen and any(r in p.parents for r in roots));seen.add(f['path'])
    sequence(v['process_selectors'])
    for s in v['process_selectors']:keys(s,'exe module');path(s['exe']);text(s['module'],True)
    return v

def validate_raw_capture(v):
    keys(v,'schema_version purpose observation_id host_identity capture_interval files process_samples operations capture_errors');header(v,'source-control-raw-capture')
    text(v['observation_id']);host(v['host_identity']);keys(v['capture_interval'],'start_uptime end_uptime')
    times=[v['capture_interval']['start_uptime'],v['capture_interval']['end_uptime']]
    require(all(type(t) in (int,float) and math.isfinite(t) and t>=0 for t in times));require(times[1]>=times[0])
    sequence(v['files']);seen=set()
    for f in v['files']:
        keys(f,'path kind uid gid mode bytes sha256 raw_b64');path(f['path']);require(f['path'] not in seen and f['kind'] in FILE_KINDS);seen.add(f['path'])
        for k in ['uid','gid','bytes']:integer(f[k])
        integer(f['mode'],0,0o777);sha(f['sha256']);raw=decode(f['raw_b64']);require(len(raw)==f['bytes'] and hashlib.sha256(raw).hexdigest()==f['sha256'])
    require(type(v['process_samples']) is list and len(v['process_samples'])==2)
    for sample in v['process_samples']:
        sequence(sample);pids=set()
        for p in sample:
            keys(p,'pid ppid start_ticks exe exe_sha256 uid module argv_sha256 cgroup state');integer(p['pid'],1);integer(p['ppid']);integer(p['uid']);path(p['exe']);sha(p['exe_sha256']);sha(p['argv_sha256'])
            require(isinstance(p['start_ticks'],str) and p['start_ticks'].isdigit());text(p['module'],True);text(p['cgroup'],True);text(p['state']);require(p['pid'] not in pids);pids.add(p['pid'])
    keys(v['operations'],'listeners services database')
    for op in v['operations'].values():keys(op,'status stdout_b64 stderr_b64');integer(op['status'],-255,255);decode(op['stdout_b64']);decode(op['stderr_b64'])
    errors(v['capture_errors']);return v

def validate_observation(v):
    keys(v,'schema_version purpose observation_id host_identity capture_interval objects errors raw_capture_sha256 receipt');header(v,'source-control-observation')
    text(v['observation_id']);host(v['host_identity']);keys(v['capture_interval'],'start_uptime end_uptime');sha(v['raw_capture_sha256']);errors(v['errors']);sequence(v['objects']);seen=set()
    for o in v['objects']:
        keys(o,'id kind identity sha256 details');text(o['id']);require(o['kind'] in KINDS and type(o['identity']) is dict and o['identity'] and type(o['details']) is dict and o['id'] not in seen);seen.add(o['id']);sha(o['sha256'])
        if o['kind']=='file':require(o['sha256']==o['details'].get('raw_sha256'))
        else:require(o['sha256']==digest({'identity':o['identity'],'details':o['details']}))
    r=v['receipt'];keys(r,'payload_sha256 raw_capture_sha256 controller_boot_id capture_start_ns capture_end_ns');sha(r['payload_sha256']);sha(r['raw_capture_sha256']);text(r['controller_boot_id']);integer(r['capture_start_ns']);integer(r['capture_end_ns']);require(r['capture_end_ns']>=r['capture_start_ns'])
    require(r['raw_capture_sha256']==v['raw_capture_sha256'] and r['payload_sha256']==digest({k:x for k,x in v.items() if k!='receipt'}))
    return v

def validate_coverage_profile(v):
    keys(v,'schema_version purpose accepted_observation_sha256 expected_host assignments required_controls');header(v,'source-control-coverage-profile');sha(v['accepted_observation_sha256']);host(v['expected_host']);sequence(v['assignments']);seen=set()
    for a in v['assignments']:
        keys(a,'object_id sha256 disposition justification evidence_sha256');text(a['object_id']);sha(a['sha256']);sha(a['evidence_sha256']);text(a['justification']);require(a['disposition'] in ['selected','unrelated'] and a['object_id'] not in seen);seen.add(a['object_id'])
    sequence(v['required_controls']);require(all(isinstance(x,str) for x in v['required_controls']) and len(v['required_controls'])==7 and set(v['required_controls'])==CONTROL_CODES)
    return v

def validate_runtime_manifest(v):
    keys(v,'schema_version purpose expected_version archive_sha256 roles library_dirs files');header(v,'mariadb55-disposable-runtime');require(v['expected_version']=='5.5.68-MariaDB');sha(v['archive_sha256']);require(type(v['roles']) is dict and set(v['roles'])==ROLES)
    require(type(v['files']) is dict and 0<len(v['files'])<=1000);total=0
    for name,f in v['files'].items():
        path(name,False);require(not name.endswith('.cnf'));keys(f,'bytes sha256 mode type');integer(f['bytes'],1,64*1024**2);sha(f['sha256']);integer(f['mode'],0,0o777);require(f['type']=='regular');total+=f['bytes']
    require(total<=256*1024**2)
    for name in v['roles'].values():path(name,False);require(name in v['files'])
    require(len(set(v['roles'].values()))==len(ROLES));sequence(v['library_dirs']);require(v['library_dirs'])
    for name in v['library_dirs']:path(name,False)
    return v

def parse_document(raw,purpose):
    require(isinstance(raw,bytes) and len(raw)<=MAX_CAPTURE)
    try:value=strict_json(raw)
    except (ValueError,TypeError,UnicodeError) as exc:raise SourceError('Invalid private JSON') from exc
    validators={'source-control-capture-profile':validate_capture_profile,'source-control-raw-capture':validate_raw_capture,'source-control-observation':validate_observation,'source-control-coverage-profile':validate_coverage_profile,'mariadb55-disposable-runtime':validate_runtime_manifest}
    require(purpose in validators);return validators[purpose](value)
