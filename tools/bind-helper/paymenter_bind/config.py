"""Root-controlled binding identities; callers never select paths or BIND views."""
import json,os,stat,re
from dataclasses import dataclass,field
from collections.abc import Callable
from .protocol import need,uuid,OPS,pairs
@dataclass(frozen=True)
class Binding:
 id:str;zone:str;view:str;role:str;file:str;zone_root:str;state_root:str;uid:int=0;gid:int=0
 manager_uid:int|None=None;ttl_min:int=60;ttl_max:int=86400
 main_config:str='/etc/named.conf';managed_include:str='';primary:str='';secondary:str=''
 nameservers:tuple=();transfer_key:str=''
 compilezone:str='/usr/sbin/named-compilezone'
 checkzone:str='/usr/sbin/named-checkzone';checkconf:str='/usr/sbin/named-checkconf';rndc:str='/usr/sbin/rndc'
 authority:Callable[[],None]|None=field(default=None,compare=False,repr=False)
 def assert_current(self):
  if self.authority is not None:self.authority()
 @property
 def owner_uid(self):return self.uid if self.manager_uid is None else self.manager_uid
def relative(value):return type(value)is str and bool(value)and not value.startswith('/')and all(x not in {'','.','..'}for x in value.split('/'))and '\x00'not in value
def load_binding(config_dir_fd,binding_id,operation,expected_uid=0):
 need(uuid(binding_id)and operation in OPS)
 parent=os.fstat(config_dir_fd);need(stat.S_ISDIR(parent.st_mode)and parent.st_uid==expected_uid and stat.S_IMODE(parent.st_mode)==0o700)
 fd=os.open('server.json',os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=config_dir_fd)
 try:
  s=os.fstat(fd);need(stat.S_ISREG(s.st_mode)and s.st_uid==expected_uid and s.st_nlink==1 and stat.S_IMODE(s.st_mode)==0o600 and s.st_size<=262144)
  raw=os.read(fd,262145);need(len(raw)==s.st_size);after=os.fstat(fd);need((s.st_dev,s.st_ino,s.st_size,s.st_mtime_ns,s.st_ctime_ns)==(after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns))
 finally:os.close(fd)
 c=json.loads(raw.decode(),object_pairs_hook=pairs);need(c.get('version')==1 and type(c.get('bindings'))is dict and type(c.get('protected_zones'))is list)
 allowed={'version','zone_root','state_root','protected_zones','bindings','ttl_min','ttl_max','main_config','checkzone','checkconf','rndc','compilezone','nameservers','primary','secondary','transfer_key'};need(set(c)<=allowed)
 r=c['bindings'].get(binding_id);need(type(r)is dict and set(r)<={'zone','view','role','file','operations','uid','gid','managed_include'})
 need(operation in r.get('operations',[])and set(r.get('operations',[]))<=OPS)
 zone=r.get('zone');need(type(zone)is str and zone.isascii()and re.fullmatch(r'(?:[a-z0-9_*-]+\.)+',zone)is not None and len(zone)<=254 and zone not in {'.','in-addr.arpa.','ip6.arpa.'}and not zone.endswith(('.in-addr.arpa.','.ip6.arpa.')))
 for deny in c['protected_zones']:
  need(type(deny)is str and deny.endswith('.'));need(zone!=deny and not zone.endswith('.'+deny))
 need(type(r.get('view'))is str and re.fullmatch('[A-Za-z0-9_.-]{1,128}',r['view'])is not None and r.get('role')in {'primary','secondary'}and relative(r.get('file')))
 for key in ['zone_root','state_root']:need(type(c.get(key))is str and c[key].startswith('/')and '..'not in c[key].split('/'))
 values={k:c[k]for k in ['ttl_min','ttl_max','main_config','checkzone','checkconf','rndc','compilezone','nameservers','primary','secondary','transfer_key']if k in c}
 if 'nameservers'in values:need(type(values['nameservers'])is list);values['nameservers']=tuple(values['nameservers'])
 for key in ['main_config','checkzone','checkconf','rndc','compilezone']:
  if key in values:need(type(values[key])is str and values[key].startswith('/')and '\x00'not in values[key]and '..'not in values[key].split('/'))
 for key in ['uid','gid']:need(type(r.get(key,expected_uid))is int and r.get(key,expected_uid)>=0)
 lo=c.get('ttl_min',60);hi=c.get('ttl_max',86400);need(type(lo)is int and type(hi)is int and 1<=lo<=hi<=2147483647)
 return Binding(id=binding_id,zone=zone,view=r['view'],role=r['role'],file=r['file'],zone_root=c['zone_root'],state_root=c['state_root'],uid=r.get('uid',expected_uid),gid=r.get('gid',os.getgid()),manager_uid=expected_uid,managed_include=r.get('managed_include',''),**values)
