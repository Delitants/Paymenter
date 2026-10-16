"""Standalone Python 2.7/3 read-only collector. stdin profile; stdout private JSON."""
from __future__ import print_function
import base64
import hashlib
import json
import os
import re
import select
import socket
import stat
import subprocess
import sys
import uuid

MAX_PART=4*1024**2
MAX_CAPTURE=32*1024**2
try:TEXT=(basestring,)
except NameError:TEXT=(str,)

class CaptureError(ValueError):pass

def need(ok):
    if not ok:raise CaptureError('Invalid or incomplete capture')

def exact(v,names):need(type(v) is dict and set(v)==set(names.split()))

def plain(v,empty=False):need(isinstance(v,TEXT) and len(v)<=MAX_PART and '\x00' not in v and (empty or bool(v)))

def absolute(v):plain(v);need(v.startswith('/') and os.path.normpath(v)==v and v!='/' and '..' not in v.split('/'))

def validate_profile(p):
    exact(p,'schema_version purpose expected_host file_roots read_paths process_selectors database_schema')
    need(type(p['schema_version']) is int and p['schema_version']==1 and p['purpose']=='source-control-capture-profile')
    exact(p['expected_host'],'hostname');plain(p['expected_host']['hostname'])
    need(isinstance(p['database_schema'],TEXT) and re.match(r'^[A-Za-z_][A-Za-z0-9_]{0,63}\Z',p['database_schema']))
    for k in ['file_roots','read_paths','process_selectors']:need(type(p[k]) is list and len(p[k])<=10000)
    need(p['file_roots'])
    for r in p['file_roots']:absolute(r)
    seen=set()
    for f in p['read_paths']:
        exact(f,'path kind');absolute(f['path']);need(f['kind'] in ['nginx','nginx-root','cron','service','manager','plain'] and f['path'] not in seen and any(f['path'].startswith(r+'/') for r in p['file_roots']));seen.add(f['path'])
    for s in p['process_selectors']:exact(s,'exe module');absolute(s['exe']);plain(s['module'],True)
    return p

def b64(raw):return base64.b64encode(raw).decode('ascii')

def error(errors,code,ref):errors.append({'code':code,'ref':ref})

def collect(profile,ops,clock):
    validate_profile(profile);errors=[];files=[];start=clock.monotonic_seconds();host=ops.host_identity()
    if host['hostname']!=profile['expected_host']['hostname']:raise CaptureError('Wrong source identity')
    samples=[ops.sample_processes(profile['process_selectors'])]
    for item in profile['read_paths']:
        try:f=ops.read_regular(item['path'],MAX_PART);f['kind']=item['kind'];files.append(f)
        except (OSError,IOError,CaptureError):error(errors,'file-unavailable',item['path'])
    outputs={}
    for name in ['listeners','services','database']:
        try:
            r=ops.run_operation(name,profile['database_schema'],MAX_PART,10)
            need(all(len(base64.b64decode(r[k]))<=MAX_PART for k in ['stdout_b64','stderr_b64']))
            outputs[name]=r
            if r['status']!=0:error(errors,'operation-failed',name)
        except (OSError,IOError,CaptureError):
            outputs[name]={'status':1,'stdout_b64':'','stderr_b64':''};error(errors,'operation-unavailable',name)
    for before in files:
        try:
            after=ops.read_regular(before['path'],MAX_PART);after['kind']=before['kind']
            if before!=after:error(errors,'capture-changed',before['path'])
        except (OSError,IOError,CaptureError):error(errors,'capture-changed',before['path'])
    try:
        after=ops.run_operation('database',profile['database_schema'],MAX_PART,10)
        need(after['status']==0 and all(len(base64.b64decode(after[k]))<=MAX_PART for k in ['stdout_b64','stderr_b64']))
        def manifest(r):return sorted(line for line in base64.b64decode(r['stdout_b64']).splitlines() if not line.startswith(b'SESSION\t'))
        if outputs['database']['status']!=0 or manifest(after)!=manifest(outputs['database']):error(errors,'capture-changed','database-manifest')
    except (OSError,IOError,CaptureError):error(errors,'capture-changed','database-manifest')
    samples.append(ops.sample_processes(profile['process_selectors']))
    def stable(ps):return sorted([{k:v for k,v in p.items() if k!='state'} for p in ps],key=lambda p:p['pid'])
    if stable(samples[0])!=stable(samples[1]) or host!=ops.host_identity():error(errors,'capture-changed','source-process-or-host')
    errors.extend(getattr(ops,'errors',[]))
    result={'schema_version':1,'purpose':'source-control-raw-capture','observation_id':str(uuid.uuid4()),'host_identity':host,
            'capture_interval':{'start_uptime':start,'end_uptime':clock.monotonic_seconds()},'files':files,
            'process_samples':samples,'operations':outputs,'capture_errors':errors}
    need(len(json.dumps(result,sort_keys=True,separators=(',',':'),allow_nan=False).encode('utf-8'))<=MAX_CAPTURE)
    return result

class ReadOps:
    def __init__(self):self.errors=[]
    def host_identity(self):
        return {'hostname':socket.gethostname(),'boot_id':open('/proc/sys/kernel/random/boot_id').read().strip(),'kernel':os.uname()[2],'python':sys.version.split()[0]}
    def monotonic_seconds(self):return float(open('/proc/uptime').read().split()[0])
    def read_regular(self,path,limit):
        absolute(path)
        current='/'
        for part in path.split('/')[1:]:
            current=os.path.join(current,part);need(not stat.S_ISLNK(os.lstat(current).st_mode))
        fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
        try:
            st=os.fstat(fd);need(stat.S_ISREG(st.st_mode) and st.st_size<=limit)
            with os.fdopen(fd,'rb') as stream:
                fd=None;raw=stream.read(limit+1);last=os.fstat(stream.fileno())
            final=os.lstat(path);need(len(raw)<=limit and (st.st_dev,st.st_ino,st.st_size,st.st_mtime,st.st_mode,st.st_uid,st.st_gid)==(last.st_dev,last.st_ino,last.st_size,last.st_mtime,last.st_mode,last.st_uid,last.st_gid) and (st.st_dev,st.st_ino)==(final.st_dev,final.st_ino))
            return {'path':path,'kind':'plain','uid':st.st_uid,'gid':st.st_gid,'mode':stat.S_IMODE(st.st_mode),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'raw_b64':b64(raw)}
        finally:
            if fd is not None:os.close(fd)
    def sample_processes(self,selectors):
        selected=[]
        for name in os.listdir('/proc'):
            if not name.isdigit():continue
            base='/proc/'+name
            try:exe=os.readlink(base+'/exe')
            except OSError:continue
            if exe.endswith(' (deleted)') and any(s['exe']==exe[:-10] for s in selectors):
                error(self.errors,'process-executable-deleted',name);continue
            candidates=[s for s in selectors if s['exe']==exe]
            if not candidates:continue
            try:
                args=open(base+'/cmdline','rb').read(MAX_PART+1);need(len(args)<=MAX_PART)
                words=args.split(b'\0');matches=[s for s in candidates if not s['module'] or s['module'].encode('utf-8') in words]
                if not matches:continue
                fields=open(base+'/stat').read().rsplit(')',1)[1].split();status=open(base+'/status').read();uid=int(re.search(r'^Uid:\s*(\d+)',status,re.M).group(1))
                with open(base+'/exe','rb') as f:
                    h=hashlib.sha256()
                    while True:
                        chunk=f.read(1024**2)
                        if not chunk:break
                        h.update(chunk)
                selected.append({'pid':int(name),'ppid':int(fields[1]),'start_ticks':fields[19],'exe':exe,'exe_sha256':h.hexdigest(),'uid':uid,'module':matches[0]['module'],'argv_sha256':hashlib.sha256(args).hexdigest(),'cgroup':open(base+'/cgroup').read(),'state':fields[0]})
            except (OSError,IOError,IndexError,AttributeError,CaptureError):error(self.errors,'process-unavailable',name)
        return sorted(selected,key=lambda p:p['pid'])
    def run_operation(self,name,schema,byte_limit,timeout_seconds):
        need(re.match(r'^[A-Za-z_][A-Za-z0-9_]{0,63}\Z',schema))
        commands={'listeners':['ss','-lntp'],'services':['systemctl','list-units','--all','--no-pager','--no-legend']}
        sql="SELECT 'VERSION',@@version,@@innodb_table_locks,@@autocommit,@@read_only; "
        sql+="SELECT 'OBJECT',TABLE_TYPE,HEX(TABLE_NAME),COALESCE(ENGINE,'') FROM information_schema.TABLES WHERE TABLE_SCHEMA='%s' ORDER BY TABLE_NAME; " % schema
        for table,typ,col,scol in [('TRIGGERS','TRIGGER','TRIGGER_NAME','TRIGGER_SCHEMA'),('ROUTINES','ROUTINE','ROUTINE_NAME','ROUTINE_SCHEMA'),('EVENTS','EVENT','EVENT_NAME','EVENT_SCHEMA')]:
            sql+="SELECT 'OBJECT','%s',HEX(%s),'' FROM information_schema.%s WHERE %s='%s' ORDER BY %s; " % (typ,col,table,scol,schema,col)
        sql+="SELECT 'PRINCIPAL',HEX(GRANTEE),PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE='SUPER' ORDER BY GRANTEE; "
        sql+="SELECT 'SESSION',ID,HEX(USER),HEX(HOST),HEX(COMMAND) FROM information_schema.PROCESSLIST WHERE DB='%s' ORDER BY ID;" % schema
        commands['database']=['mysql','--batch','--raw','--skip-column-names','-e',sql]
        need(name in commands)
        env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C','HOME':os.environ.get('HOME','/root')}
        proc=subprocess.Popen(commands[name],stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env,shell=False)
        data={proc.stdout:bytearray(),proc.stderr:bytearray()};active=list(data);end=self.monotonic_seconds()+timeout_seconds
        try:
            while active:
                need(self.monotonic_seconds()<end)
                ready=select.select(active,[],[],.1)[0]
                for pipe in ready:
                    chunk=os.read(pipe.fileno(),65536)
                    if chunk:data[pipe].extend(chunk);need(len(data[pipe])<=byte_limit)
                    else:active.remove(pipe)
            while proc.poll() is None:need(self.monotonic_seconds()<end);select.select([],[],[],.01)
            return {'status':proc.returncode,'stdout_b64':b64(bytes(data[proc.stdout])),'stderr_b64':b64(bytes(data[proc.stderr]))}
        finally:
            if proc.poll() is None:
                proc.terminate();end=self.monotonic_seconds()+1
                while proc.poll() is None and self.monotonic_seconds()<end:select.select([],[],[],.01)
                if proc.poll() is None:proc.kill()
            proc.wait();proc.stdout.close();proc.stderr.close()

def strict_pairs(pairs):
    result={}
    for k,v in pairs:
        if k in result:raise CaptureError('Duplicate JSON field')
        result[k]=v
    return result

def main():
    try:
        raw=sys.stdin.read(MAX_CAPTURE+1);need(len(raw)<=MAX_CAPTURE)
        profile=json.loads(raw,object_pairs_hook=strict_pairs,parse_constant=lambda _: (_ for _ in ()).throw(CaptureError('Nonfinite JSON')))
        ops=ReadOps();result=collect(profile,ops,ops);print(json.dumps(result,sort_keys=True,separators=(',',':'),allow_nan=False))
        return 1 if result['capture_errors'] else 0
    except Exception:
        print(json.dumps({'purpose':'source-control-capture-failure','source_mutations':False}));return 1

if __name__=='__main__':sys.exit(main())
