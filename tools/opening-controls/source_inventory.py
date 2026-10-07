"""Conservative private inventory; parsing never proves physical enforcement."""
import base64
import fnmatch
import hashlib
import posixpath
import re
import shlex
from source_schema import SourceError,canonical,digest,require,validate_raw_capture,validate_observation

def record(kind,identity,details,raw_hash=None):
    value={'identity':identity,'details':details}
    return {'id':kind+':'+digest(identity),'kind':kind,'identity':identity,'details':details,'sha256':raw_hash or digest(value)}

def problem(code,ref):return {'code':code,'ref':ref}

def captured_glob(path,pattern):
    parts=path.split('/');patterns=pattern.split('/')
    return len(parts)==len(patterns) and all(fnmatch.fnmatchcase(part,pat) and (not part.startswith('.') or pat.startswith('.')) for part,pat in zip(parts,patterns))

def parse_cron(raw,path):
    result=[];env={}
    for number,line in enumerate(raw.decode('utf-8').splitlines(),1):
        if not line.strip() or line.lstrip().startswith('#'):continue
        identity={'path':path,'line':number,'raw_line_sha256':hashlib.sha256(line.encode()).hexdigest()}
        if re.match(r'^\s*[A-Za-z_][A-Za-z0-9_]*\s*=',line):
            k,v=line.split('=',1);env[k.strip()]=v.strip();continue
        details={'environment':env.copy(),'module':'','command':line,'parse_error':False}
        parts=line.split(None,5)
        if line.startswith('@'):parts=line.split(None,1)
        if len(parts) not in (2,6):details['parse_error']=True
        else:
            command=parts[-1]
            try:
                words=shlex.split(command)
                if not words:raise ValueError
                if any(posixpath.basename(w)=='cron-billmgr' for w in words):details['module']='billmgr'
                for i,w in enumerate(words[:-1]):
                    if w=='-m':details['module']=words[i+1]
            except ValueError:details['parse_error']=True
        result.append(record('job',identity,details))
    return result

def tokens(raw):
    text=raw.decode('utf-8');out=[];word='';quote=None;i=0
    while i<len(text):
        c=text[i]
        if c=='\\':
            i+=1
            if i>=len(text):raise SourceError('Unsupported dangling escape')
            word+=text[i]
        elif quote:
            if c==quote:quote=None
            else:word+=c
        elif c in '\"\'':quote=c
        elif c=='#':
            if word:out.append(word);word=''
            while i<len(text) and text[i]!='\n':i+=1
        elif c=='$' and i+1<len(text) and text[i+1]=='{':
            end=text.find('}',i+2)
            if end<0:raise SourceError('Unsupported variable syntax')
            word+=text[i:end+1];i=end
        elif c.isspace():
            if word:out.append(word);word=''
        elif c in '{};':
            if word:out.append(word);word=''
            out.append(c)
        else:word+=c
        i+=1
    if quote:raise SourceError('Unterminated quoted token')
    if word:out.append(word)
    return out

def parse_nginx(files,entrypoint):
    result={'objects':[],'errors':[]};prefix=posixpath.dirname(entrypoint);expansions=0
    def read(path,context,stack):
        nonlocal expansions
        expansions+=1
        if path in stack:result['errors'].append(problem('include-cycle',path));return
        if len(stack)>=16 or expansions>1000:result['errors'].append(problem('include-limit',path));return
        if path not in files:result['errors'].append(problem('include-unavailable',path));return
        try:ts=tokens(files[path])
        except (UnicodeError,SourceError):result['errors'].append(problem('unsupported-nginx-syntax',path));return
        pos=0
        def block(ctx,closing=False):
            nonlocal pos
            while pos<len(ts):
                if ts[pos]=='}':
                    if not closing:raise SourceError('Unexpected closing block')
                    pos+=1;return
                at=pos;directive=[]
                while pos<len(ts) and ts[pos] not in '{};':directive.append(ts[pos]);pos+=1
                if not directive or pos>=len(ts) or ts[pos]=='}':raise SourceError('Incomplete directive')
                terminal=ts[pos];pos+=1;name=directive[0];args=directive[1:]
                identity={'path':path,'token':at,'context':ctx,'expansion':expansions}
                details={'directive':name,'args':args,'context':ctx}
                if name=='include':
                    if terminal!=';' or len(args)!=1:raise SourceError('Unsupported include')
                    pattern=args[0] if args[0].startswith('/') else posixpath.join(prefix,args[0]);pattern=posixpath.normpath(pattern)
                    result['objects'].append(record('include',identity,details))
                    matches=sorted(f for f in files if captured_glob(f,pattern))
                    if not matches:result['errors'].append(problem('include-unavailable',pattern))
                    for child in matches:read(child,ctx,stack+[path])
                elif name in ['listen','server_name','location','proxy_pass','fastcgi_pass','fastcgi_param','error_page','rewrite','return','if','map','set','upstream']:
                    result['objects'].append(record('listener' if name=='listen' else 'route',identity,details))
                    if name in ['proxy_pass','fastcgi_pass','rewrite','return','if','map'] and any('$' in a for a in args):result['errors'].append(problem('routing-expression-unproved',path+':'+str(at)))
                elif name not in {'events','http','server','worker_processes','worker_connections','pid','error_log','access_log','user','default_type','sendfile','keepalive_timeout','tcp_nopush','tcp_nodelay'}:
                    result['errors'].append(problem('unsupported-nginx-directive',path+':'+str(at)+':'+name))
                if terminal=='{':block(ctx+[directive],True)
            if closing:raise SourceError('Missing closing block')
        try:block(context)
        except SourceError:result['errors'].append(problem('unsupported-nginx-syntax',path))
    read(entrypoint,[],[]);return result

def inventory(raw,receipt):
    validate_raw_capture(raw);objects=[];errors=raw['capture_errors'].copy();nginx={};nginx_roots=[]
    for f in raw['files']:
        details={k:f[k] for k in ['kind','uid','gid','mode','bytes']};details['raw_sha256']=f['sha256']
        objects.append(record('file',{'path':f['path']},details,f['sha256']))
        data=base64.b64decode(f['raw_b64'])
        if f['kind']=='cron':
            try:
                jobs=parse_cron(data,f['path']);objects.extend(jobs)
                for job in jobs:
                    if job['details']['parse_error']:errors.append(problem('unsupported-cron-syntax',job['id']))
            except UnicodeError:errors.append(problem('unsupported-cron-encoding',f['path']))
        if f['kind'] in ['nginx','nginx-root']:nginx[f['path']]=data
        if f['kind']=='nginx-root':nginx_roots.append(f['path'])
        if f['kind']=='service':objects.append(record('service',{'path':f['path']},{'raw_sha256':f['sha256']}))
    if nginx:
        if not nginx_roots:errors.append(problem('nginx-entrypoint-unaccepted','nginx'))
        for entry in sorted(nginx_roots):
            parsed=parse_nginx(nginx,entry);objects.extend(parsed['objects']);errors.extend(parsed['errors'])
    for p in raw['process_samples'][0]:objects.append(record('process',{k:p[k] for k in ['pid','start_ticks','exe','exe_sha256','uid','module','argv_sha256','cgroup','ppid']},{'state':p['state']}))
    for label,op in raw['operations'].items():
        if op['status']!=0:errors.append(problem('operation-failed',label));continue
        try:lines=base64.b64decode(op['stdout_b64']).decode('utf-8').splitlines()
        except UnicodeError:errors.append(problem('operation-encoding-invalid',label));continue
        if label=='database':
            version_count=0
            for n,line in enumerate(lines):
                parts=line.split('\t');tag=parts[0]
                try:
                    if tag=='VERSION':require(len(parts)==5);version_count+=1;objects.append(record('sql-object',{'type':'server-settings'},{'version':parts[1],'innodb_table_locks':parts[2],'autocommit':parts[3],'read_only':parts[4]}));continue
                    expected={'OBJECT':4,'PRINCIPAL':3,'SESSION':5};require(tag in expected and len(parts)==expected[tag])
                    hexcols={'OBJECT':[2],'PRINCIPAL':[1],'SESSION':[2,3,4]}[tag]
                    for i in hexcols:
                        parts[i]=bytes.fromhex(parts[i]).decode('utf-8');require(parts[i] and not any(ord(c)<32 for c in parts[i]))
                    kind={'OBJECT':'sql-object','PRINCIPAL':'sql-principal','SESSION':'sql-session'}[tag]
                    objects.append(record(kind,{'fields':parts[1:],'line':n},{'metadata_only':True}))
                except (ValueError,UnicodeError,SourceError):errors.append(problem('sql-metadata-invalid',str(n)))
            if version_count!=1:errors.append(problem('sql-version-missing-or-duplicate','database'))
        else:
            for n,line in enumerate(lines):
                if label=='listeners' and line.startswith('State'):continue
                if line.strip():objects.append(record('listener' if label=='listeners' else 'service',{'operation':label,'line':n},{'record':line}))
    obs={'schema_version':1,'purpose':'source-control-observation','observation_id':raw['observation_id'],'host_identity':raw['host_identity'],'capture_interval':raw['capture_interval'],'objects':objects,'errors':errors,'raw_capture_sha256':digest(raw)}
    obs['receipt']={**receipt,'payload_sha256':digest(obs),'raw_capture_sha256':obs['raw_capture_sha256']}
    return validate_observation(obs)
