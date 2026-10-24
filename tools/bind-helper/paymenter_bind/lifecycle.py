"""Journaled view-specific declarations. Partial creation is never erased automatically."""
import ipaddress,json,os,fcntl,stat,re,uuid
from pathlib import Path
from .protocol import need
from .custody import RootDirectory,locked_zone
from .zone import snapshot
from .journal import Journal

def tokens(text):
 out=[];i=0
 while i<len(text):
  if text[i].isspace():i+=1;continue
  if text.startswith('//',i)or text[i]=='#':
   n=text.find('\n',i);i=len(text)if n<0 else n+1;continue
  if text.startswith('/*',i):n=text.find('*/',i+2);need(n>=0);i=n+2;continue
  start=i
  if text[i]=='"':
   i+=1;value=''
   while i<len(text)and text[i]!='"':
    if text[i]=='\\':i+=1;need(i<len(text))
    value+=text[i];i+=1
   need(i<len(text));i+=1;out.append((value,start,i,True))
  elif text[i]in '{};':out.append((text[i],i,i+1,False));i+=1
  else:
   while i<len(text)and not text[i].isspace()and text[i]not in '{};"':i+=1
   need(i>start);out.append((text[start:i],start,i,False))
 return out
def replace_include(text,original,candidate,view):
 need(not any(c in original+candidate for c in '\n\r"\\\x00'))
 ts=tokens(text);contexts=['__default'];pending={};matches=[]
 for i,(v,start,end,quoted)in enumerate(ts):
  if v=='view'and not quoted:
   need(i+2<len(ts)and ts[i+1][3]);brace=i+2
   if ts[brace][0]=='IN':brace+=1
   need(brace<len(ts)and ts[brace][0]=='{');pending[brace]=ts[i+1][0]
  if v=='{'and not quoted:contexts.append(pending.get(i,contexts[-1]))
  elif v=='}'and not quoted:need(len(contexts)>1);contexts.pop()
  elif v=='include'and not quoted and i+2<len(ts)and ts[i+1][3]and ts[i+1][0]==original:
   need(ts[i+2][0]==';'and contexts[-1]==view);matches.append(ts[i+1])
 need(len(contexts)==1 and len(matches)==1);_,start,end,_=matches[0];return text[:start]+'"'+candidate+'"'+text[end:]
class Lifecycle:
 def __init__(self,binding,commands):self.binding=binding;self.commands=commands;self.journal=Journal(binding.state_root,binding.owner_uid)
 def prepare_primary(self,request,binding):return self._prepare(request,binding,False)
 def prepare_secondary(self,request,binding):return self._prepare(request,binding,True)
 def _prepare(self,request,b,secondary):
  need(b==self.binding and b.id==request['binding_id']and b.role==('secondary'if secondary else'primary')and request['operation']==('prepare_secondary'if secondary else'prepare_zone'))
  with locked_zone(b)as zone,RootDirectory(b.state_root,b.owner_uid,True)as state:
   fd=os.open('configuration.lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW|os.O_NONBLOCK,0o600,dir_fd=state.fd)
   try:
    s=os.fstat(fd);need(stat.S_ISREG(s.st_mode)and s.st_uid==b.owner_uid and stat.S_IMODE(s.st_mode)==0o600 and s.st_nlink==1);fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
    b.assert_current();attempt=self.journal.claim(request)
    if not attempt.fresh:return attempt.result
    changed=False
    try:
     need(b.managed_include.startswith('/')and b.main_config.startswith('/')and b.managed_include!=b.main_config and not zone.root.exists(b.file))
     include=Path(b.managed_include);main=Path(b.main_config)
     with RootDirectory(str(include.parent),b.owner_uid)as conf,RootDirectory(str(main.parent),b.owner_uid)as mainroot:
      original=conf.read(include.name,262144);mainimage=mainroot.read(main.name,262144)
      old=original.raw.decode();entries=tokens(old);declared=[entries[i+1][0].rstrip('.').lower()for i,t in enumerate(entries[:-1])if t[0]=='zone'and not t[3]];need(b.zone.rstrip('.')not in declared)
      p=str(ipaddress.ip_address(b.primary));s=str(ipaddress.ip_address(b.secondary));need(p!=s)
      need(not b.transfer_key or re.fullmatch('[A-Za-z0-9_.-]{1,128}',b.transfer_key)is not None)
      key=(' key "'+b.transfer_key+'"')if b.transfer_key else''
      filename=str(Path(b.zone_root)/b.file);need(not any(c in filename for c in '\n\r"\\\x00'))
      if secondary:declaration=f'zone "{b.zone}" IN {{ type slave; masters {{ {p}{key}; }}; file "{filename}"; masterfile-format text; allow-transfer {{ none; }}; allow-update-forwarding {{ none; }}; }};\n';candidate=None
      else:
       need(len(b.nameservers)>=2)
       for ns in b.nameservers:need(type(ns)is str and re.fullmatch(r'(?:[a-zA-Z0-9-]+\.)+',ns)is not None)
       records=f'$ORIGIN {b.zone}\n$TTL 300\n@ IN SOA {b.nameservers[0]} hostmaster.{b.zone} ( 1 3600 600 86400 300 )\n'+''.join('@ IN NS '+ns+'\n'for ns in b.nameservers)
       candidate=snapshot(records.encode(),b);self.commands.check(candidate)
       transfer=('key "'+b.transfer_key+'"')if b.transfer_key else s
       declaration=f'zone "{b.zone}" IN {{ type master; file "{filename}"; allow-update {{ none; }}; allow-transfer {{ {transfer}; }}; also-notify {{ {s}{key}; }}; }};\n'
      updated=(old+'\n'+declaration).encode();include_candidate=request['request_id']+'.include.conf';state.save(include_candidate,updated)
      full=replace_include(mainimage.raw.decode(),b.managed_include,str(Path(b.state_root)/include_candidate),b.view);full_candidate=request['request_id']+'.full.conf';state.save(full_candidate,full.encode());self.commands.check_config(str(Path(b.state_root)/full_candidate))
      state.save(request['request_id']+'.include.before',original.raw);need(mainroot.read(main.name,262144)==mainimage and conf.read(include.name,262144)==original)
      if candidate is not None:
       b.assert_current();self.commands.guard_install();changed=True;zone.install(candidate.raw,request['request_id'],None)
      # Setting this before install preserves uncertainty if rename succeeds but
      # its durable readback fails; partial creations never get auto-deleted.
      b.assert_current();self.commands.guard_install();changed=True;conf.install(include.name,updated,request['request_id'],original,original.uid,original.gid);self.commands.reconfigure()
      try:
       loaded=self.commands.status(b)
       if secondary:status='pending_replication';data={'serial':loaded.serial}
       else:
        from .zone import read_zone
        current=read_zone(b);need(loaded.serial==candidate.serial==current.serial and current.revision==candidate.revision and loaded.view==b.view and loaded.file_identity==current.image.identity and loaded.dynamic is False and loaded.secure is False and loaded.inline is False)
        status='committed';data={'serial':current.serial,'revision':current.revision}
      except Exception:status='pending_replication'if secondary else'uncertain';data={}
      return attempt.record(status,data,'ZONE_PREPARED')
    except Exception:return attempt.record('uncertain'if changed else'rejected',{},'PARTIAL_PREPARE'if changed else'NOT_CHANGED')
   finally:os.close(fd)
