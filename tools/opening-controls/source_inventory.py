"""Conservative private inventory; parsing never proves physical enforcement."""
import base64
import fnmatch
import hashlib
import ipaddress
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

def cron_dispatch(words,command):
    """Lexical candidates only; a basename never verifies installed code."""
    result={'executable':words[0],'wrapper_module_candidate':'',
            'forwarded_command_tokens':words,'forwarded_executable_candidate':'',
            'forwarded_module_candidate':'','working_directory_candidate':'',
            'conditional_side_effect_module_candidates':{},'semantics_verified':False,
            'blockers':[]}
    blockers=result['blockers'];name=posixpath.basename(words[0]);wrapped=name.startswith('cron-')
    # Conservative: preserve original text, but do not interpret shell/cron
    # expansions, redirections, pipelines, stdin %, or compound commands.
    if re.search(r'[;&|<>$`%*?\[\]{}~\\]',command):blockers.append('cron-shell-expansion-unproved')
    if wrapped:
        result['forwarded_command_tokens']=words[1:]
        suffix=name[5:]
        if suffix not in {'core','billmgr','dnsmgr'}:blockers.append('unsupported-cron-wrapper')
        else:
            result['wrapper_module_candidate']=suffix
            result['conditional_side_effect_module_candidates']={'license_guard':suffix,'error_registration':suffix}
        blockers.append('cron-wrapper-semantics-unproved')
        prefix,sep,tail=words[0].partition('/sbin/')
        if (sep and tail==name and prefix.startswith('/') and posixpath.normpath(words[0])==words[0]
            and re.fullmatch(r'[A-Za-z0-9_./-]+',words[0])):result['working_directory_candidate']=prefix
        else:blockers.append('cron-wrapper-working-directory-unproved')
    else:blockers.append('cron-command-semantics-unproved')
    forwarded=result['forwarded_command_tokens']
    if not forwarded:
        blockers.append('cron-forwarded-command-unproved');return result
    executable=forwarded[0]
    if (not re.fullmatch(r'[A-Za-z0-9_./-]+',executable)
        or posixpath.normpath(executable)!=executable or '..' in executable.split('/')):
        blockers.append('cron-forwarded-command-unproved')
    elif executable.startswith('/'):
        result['forwarded_executable_candidate']=executable
    elif wrapped and result['working_directory_candidate'] and '/' in executable:
        result['forwarded_executable_candidate']=posixpath.join(result['working_directory_candidate'],executable)
    else:blockers.append('cron-forwarded-command-unproved')
    # Only a leading selector on the candidate mgrctl command is considered.
    # Arguments mentioning cron-* or a later -m cannot select a manager.
    if (result['forwarded_executable_candidate'] and posixpath.basename(executable)=='mgrctl'
        and len(forwarded)>=4 and forwarded[1]=='-m'
        and re.fullmatch(r'[A-Za-z0-9_]+',forwarded[2]) and forwarded.count('-m')==1
        and re.fullmatch(r'[A-Za-z0-9_.:-]+',forwarded[3])
        and not any(x in blockers for x in ['unsupported-cron-wrapper','cron-wrapper-working-directory-unproved'])):
        result['forwarded_module_candidate']=forwarded[2]
    elif wrapped and 'cron-forwarded-command-unproved' not in blockers:blockers.append('cron-forwarded-command-unproved')
    return result

def parse_cron(raw,path):
    result=[];env={}
    for number,line in enumerate(raw.decode('utf-8').splitlines(),1):
        if not line.strip() or line.lstrip().startswith('#'):continue
        identity={'path':path,'line':number,'raw_line_sha256':hashlib.sha256(line.encode()).hexdigest()}
        if re.match(r'^\s*[A-Za-z_][A-Za-z0-9_]*\s*=',line):
            k,v=line.split('=',1);env[k.strip()]=v.strip();continue
        details={'environment':env.copy(),'module':'','command':line,'user':'','parse_error':False}
        parts=line.split(None,5)
        if line.startswith('@'):parts=line.split(None,1)
        if len(parts) not in (2,6):details['parse_error']=True
        else:
            command=parts[-1]
            try:
                if path=='/etc/crontab' or posixpath.dirname(path)=='/etc/cron.d':
                    user_command=command.split(None,1)
                    if len(user_command)!=2 or not re.fullmatch(r'[A-Za-z0-9_-]+',user_command[0]):raise ValueError
                    details['user'],command=user_command
                words=shlex.split(command)
                if not words:raise ValueError
                details['cron_dispatch']=cron_dispatch(words,command)
                if 'cron-shell-expansion-unproved' not in details['cron_dispatch']['blockers']:
                    details['module']=details['cron_dispatch']['forwarded_module_candidate']
            except ValueError:details['parse_error']=True
        result.append(record('job',identity,details))
    return result

class NginxDelimiter(str):
    """Distinguish lexical punctuation from the same quoted/escaped bytes."""

def tokens(raw):
    text=raw.decode('utf-8');out=[];word='';started=False;quote=None;i=0
    while i<len(text):
        c=text[i]
        if c=='\\':
            started=True
            i+=1
            if i>=len(text):raise SourceError('Unsupported dangling escape')
            escaped=text[i]
            word+=({'t':'\t','r':'\r','n':'\n'}.get(escaped,escaped if escaped in '\"\'\\' else '\\'+escaped))
        elif quote:
            if c==quote:quote=None
            else:word+=c
        elif c in '\"\'':quote=c;started=True
        elif c=='#':
            if started:out.append(word);word='';started=False
            while i<len(text) and text[i]!='\n':i+=1
        elif c=='$' and i+1<len(text) and text[i+1]=='{':
            end=text.find('}',i+2)
            if end<0:raise SourceError('Unsupported variable syntax')
            word+=text[i:end+1];i=end
            started=True
        elif c.isspace():
            if started:out.append(word);word='';started=False
        elif c in '{};':
            if started:out.append(word);word='';started=False
            out.append(NginxDelimiter(c))
        else:word+=c;started=True
        i+=1
    if quote:raise SourceError('Unterminated quoted token')
    if started:out.append(word)
    return out

# These are inventory forms, not a replacement for nginx's module/version
# validation. Every reviewed form is retained; none establishes route ownership.
HSL={'http','server','location'}
RULES={}
def forms(names,contexts,terminal,minimum,maximum,role='configuration'):
    for name in names.split():RULES[name]=(set(contexts),terminal,minimum,maximum,role)

forms('events http',{'main'},'{',0,0)
forms('server',{'http'},'{',0,0)
forms('location',{'server','location'},'{',1,2,'routing')
forms('types',HSL,'{',0,0)
forms('map',{'http'},'{',2,2,'routing')
forms('geo',{'http'},'{',1,2,'routing')
forms('upstream',{'http'},'{',1,1,'routing')
forms('if',{'server','location'},'{',1,None,'routing')
forms('worker_processes pid',{'main'},';',1,1)
forms('user',{'main'},';',1,2)
forms('worker_connections',{'events'},';',1,1)
forms('error_log',{'main','events'}|HSL,';',1,2)
forms('access_log',HSL,';',1,None)
forms('log_format',{'http'},';',2,None)
forms('listen server_name',{'server'},';',1,None,'routing')
forms('root',HSL|{'if-location'},';',1,1,'routing')
forms('alias',{'location'},';',1,1,'routing')
forms('index',HSL,';',1,None,'routing')
forms('proxy_pass',{'location','if-location'},';',1,1,'routing')
forms('fastcgi_pass',{'location','if-location'},';',1,1,'routing')
forms('fastcgi_param',HSL,';',2,3,'routing')
forms('proxy_set_header',HSL,';',2,2,'routing')
forms('proxy_hide_header fastcgi_index proxy_ssl_server_name',HSL,';',1,1,'routing')
forms('proxy_redirect',HSL,';',1,2,'routing')
forms('allow deny',HSL|{'limit_except'},';',1,1,'routing')
forms('satisfy',HSL,';',1,1,'routing')
forms('error_page',HSL|{'if-location'},';',2,None,'routing')
forms('rewrite',{'server','location','if-server','if-location'},';',2,3,'routing')
forms('return',{'server','location','if-server','if-location'},';',1,2,'routing')
forms('set',{'server','location','if-server','if-location'},';',2,2,'routing')
forms('default_type sendfile tcp_nopush tcp_nodelay chunked_transfer_encoding server_tokens',HSL,';',1,1)
forms('keepalive_timeout',HSL,';',1,2)
forms('client_max_body_size send_timeout proxy_read_timeout proxy_send_timeout proxy_connect_timeout fastcgi_read_timeout proxy_buffer_size proxy_busy_buffers_size proxy_buffering proxy_request_buffering proxy_http_version if_modified_since',HSL,';',1,1)
forms('proxy_buffers',HSL,';',2,2)
forms('ssl_certificate ssl_certificate_key ssl_trusted_certificate ssl_dhparam ssl_ecdh_curve',{'http','server'},';',1,1,'routing')
forms('resolver',HSL,';',1,None,'routing')
forms('add_header',HSL|{'if-location'},';',2,3,'routing')
forms('expires',HSL|{'if-location'},';',1,2,'routing')
forms('gzip',HSL|{'if-location'},';',1,1)
forms('gzip_vary gzip_comp_level gzip_http_version limit_req_log_level',HSL,';',1,1)
forms('gzip_proxied gzip_types',HSL,';',1,None)
forms('limit_req_zone',{'http'},';',3,3,'routing')

def address(value):
    if value in ('all','unix:'):return True
    try:ipaddress.ip_network(value,strict=False);return True
    except ValueError:return False

def nginx_form(name,args,terminal,ctx):
    """Return a retained role for a reviewed context/arity, else deny it."""
    parent=ctx[-1][0] if ctx else 'main'
    if parent=='if':parent='if-'+(ctx[-2][0] if len(ctx)>1 else 'invalid')
    if name=='include':
        return 'include' if terminal==';' and len(args)==1 and args[0] and parent in {'main','events','types','map','geo','upstream','if-server','if-location'}|HSL else None
    if parent=='types':
        mime=re.fullmatch(r'[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+',name)
        return 'mime-entry' if terminal==';' and mime and args and all(re.fullmatch(r'[A-Za-z0-9_.+-]+',a) for a in args) else None
    if parent=='map':
        if name in ('hostnames','volatile'):return 'map-option' if terminal==';' and not args else None
        return 'map-entry' if terminal==';' and len(args)==1 else None
    if parent=='geo':
        if name=='default' or address(name):return 'geo-entry' if terminal==';' and len(args)==1 else None
        return None
    if parent=='upstream' and name=='server':
        return 'routing' if terminal==';' and args else None
    rule=RULES.get(name)
    if not rule:return None
    contexts,end,minimum,maximum,role=rule
    if parent not in contexts or terminal!=end or len(args)<minimum or (maximum is not None and len(args)>maximum):return None
    if name=='location' and len(args)==2 and args[0] not in ('=','^~','~','~*'):return None
    if name in ('map','geo') and not all(re.fullmatch(r'\$[A-Za-z_][A-Za-z0-9_]*',a) for a in args[-1:]):return None
    if name=='set' and (len(args)!=2 or not re.fullmatch(r'\$[A-Za-z_][A-Za-z0-9_]*',args[0])):return None
    if name in ('allow','deny') and not address(args[0]):return None
    if name in ('sendfile','tcp_nopush','tcp_nodelay','chunked_transfer_encoding','server_tokens','proxy_buffering','proxy_request_buffering','proxy_ssl_server_name') and args[0] not in ('on','off'):return None
    if name=='satisfy' and args[0] not in ('all','any'):return None
    if name in ('gzip','gzip_vary') and args[0] not in ('on','off'):return None
    if name=='gzip_comp_level' and not re.fullmatch('[1-9]',args[0]):return None
    if name=='gzip_http_version' and args[0] not in ('1.0','1.1'):return None
    if name=='gzip_proxied' and not set(args).issubset({'off','expired','no-cache','no-store','private','no_last_modified','no_etag','auth','any'}):return None
    if name=='gzip_types' and not all('$' not in a and (a=='*' or re.fullmatch(r'[A-Za-z0-9!#&^_.+-]+/[A-Za-z0-9!#&^_.+-]+',a)) for a in args):return None
    if name=='limit_req_log_level' and args[0] not in ('info','notice','warn','error'):return None
    if name=='limit_req_zone':
        params=dict(a.split('=',1) for a in args[1:] if '=' in a)
        if set(params)!= {'zone','rate'}:return None
        if not re.fullmatch(r'[^:\s]+:0*[1-9][0-9]*[kKmM]?',params['zone']):return None
        if not re.fullmatch(r'0*[1-9][0-9]*r/[sm]',params['rate']):return None
    return role

def parse_nginx(files,entrypoint):
    result={'objects':[],'errors':[]};prefix=posixpath.dirname(entrypoint);expansions=0
    def read(path,context,stack,valid=True):
        nonlocal expansions
        expansions+=1
        if path in stack:result['errors'].append(problem('include-cycle',path));return
        if len(stack)>=16 or expansions>1000:result['errors'].append(problem('include-limit',path));return
        if path not in files:result['errors'].append(problem('include-unavailable',path));return
        try:ts=tokens(files[path])
        except (UnicodeError,SourceError):result['errors'].append(problem('unsupported-nginx-syntax',path));return
        pos=0
        def block(ctx,closing=False,valid=True):
            nonlocal pos
            if len(ctx)>64:raise SourceError('Nginx block depth exceeded')
            while pos<len(ts):
                if isinstance(ts[pos],NginxDelimiter) and ts[pos]=='}':
                    if not closing:raise SourceError('Unexpected closing block')
                    pos+=1;return
                at=pos;directive=[]
                while pos<len(ts) and not isinstance(ts[pos],NginxDelimiter):directive.append(ts[pos]);pos+=1
                if not directive or pos>=len(ts) or ts[pos]=='}':raise SourceError('Incomplete directive')
                terminal=ts[pos];pos+=1;name=directive[0];args=directive[1:]
                identity={'path':path,'token':at,'context':ctx,'expansion':expansions}
                details={'directive':name,'args':args,'context':ctx}
                role=nginx_form(name,args,terminal,ctx) if valid else None
                if role is None:
                    code='unsupported-nginx-context' if name in RULES or (ctx and ctx[-1][0] in ('types','map','geo')) else 'unsupported-nginx-directive'
                    result['errors'].append(problem(code,path+':'+str(at)+':'+name))
                else:
                    details['role']=role
                    result['objects'].append(record('include' if role=='include' else 'listener' if name=='listen' and role=='routing' else 'route',identity,details))
                    dynamic=role in ('routing','map-entry','geo-entry') and any('$' in a for a in [name]+args)
                    if dynamic:result['errors'].append(problem('routing-expression-unproved',path+':'+str(at)))
                    if role=='routing' and ((name=='location' and args[0].startswith(('~','@'))) or (name=='server_name' and any(a.startswith('~') for a in args))):
                        result['errors'].append(problem('routing-pattern-unproved',path+':'+str(at)))
                if role=='include':
                    if '$' in args[0]:result['errors'].append(problem('routing-expression-unproved',path+':'+str(at)));continue
                    pattern=args[0] if args[0].startswith('/') else posixpath.join(prefix,args[0]);pattern=posixpath.normpath(pattern)
                    matches=sorted(f for f in files if captured_glob(f,pattern))
                    if not matches:result['errors'].append(problem('include-unavailable',pattern))
                    for child in matches:read(child,ctx,stack+[path])
                if terminal=='{':block(ctx+[directive],True,valid and role is not None)
            if closing:raise SourceError('Missing closing block')
        try:block(context,valid=valid)
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
                    for code in job['details'].get('cron_dispatch',{}).get('blockers',[]):errors.append(problem(code,job['id']))
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
