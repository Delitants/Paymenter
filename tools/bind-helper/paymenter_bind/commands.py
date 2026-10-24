"""Closed BIND argv surface and bounded child groups; never a shell command."""
import os,selectors,signal,subprocess,time,uuid,re,hashlib
from dataclasses import dataclass
from pathlib import Path
from .protocol import need
from .custody import RootDirectory
ACTIVE_CHILDREN=set()

def run_bounded(argv,timeout,limit):
 need(type(argv)is list and argv and os.path.isabs(argv[0])and all(type(a)is str and '\x00'not in a for a in argv)and 0<timeout<=60 and 0<limit<=2097152)
 child=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,start_new_session=True,env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LC_ALL':'C','LANG':'C','HOME':'/nonexistent'})
 ACTIVE_CHILDREN.add(child)
 selector=selectors.DefaultSelector();outputs={child.stdout:bytearray(),child.stderr:bytearray()};end=time.monotonic()+timeout
 try:
  for stream in outputs:os.set_blocking(stream.fileno(),False);selector.register(stream,selectors.EVENT_READ)
  while selector.get_map():
   left=end-time.monotonic()
   if left<=0:raise TimeoutError('BIND child deadline')
   for key,_ in selector.select(min(left,0.05)):
    b=os.read(key.fileobj.fileno(),65536)
    if not b:selector.unregister(key.fileobj);continue
    outputs[key.fileobj].extend(b);need(sum(map(len,outputs.values()))<=limit)
  left=end-time.monotonic()
  if left<=0:raise TimeoutError('BIND child deadline')
  code=child.wait(timeout=left);need(code==0 and not outputs[child.stderr]);return bytes(outputs[child.stdout])
 finally:
  selector.close()
  # On failure the unreaped leader still pins its PID/session identity. Never
  # signal a numeric process group after wait() has released that identity.
  if child.returncode is None:
   try:os.killpg(child.pid,signal.SIGKILL)
   except ProcessLookupError:pass
   child.wait(timeout=2)
  ACTIVE_CHILDREN.discard(child);child.stdout.close();child.stderr.close()
@dataclass(frozen=True)
class LoadedState:
 serial:int;view:str;dynamic:bool;secure:bool;inline:bool;file_identity:tuple
class BindCommands:
 def __init__(self,binding,runner=run_bounded,budget=30):self.binding=binding;self.runner=runner;self.deadline=time.monotonic()+budget;self._binary_identity=None;self._policy_identity=None;self._prepared=None
 def remaining(self,reserve=0):
  n=self.deadline-time.monotonic()-reserve
  if n<=0:raise TimeoutError('BIND operation deadline')
  return min(n,10)
 def status(self,binding):
  self._pins()
  raw=self.runner([binding.rndc,'zonestatus',binding.zone,'IN',binding.view],self.remaining(),32768).decode('utf-8');values={}
  for line in raw.splitlines():
   if ':'not in line:continue
   k,v=line.split(':',1);k=k.strip().lower();need(k not in values);values[k]=v.strip()
  need(values.get('name','').rstrip('.')==binding.zone.rstrip('.')and values.get('type')in({'master','primary'}if binding.role=='primary'else{'slave','secondary'}))
  if binding.status_profile=='strict':
   for k in ['secure','inline signing','dynamic']:need(values.get(k)=='no')
   need(values.get('key maintenance')in {'none','off'})
  else:
   from .legacy import loaded_flags
   loaded_flags(values);self._guard_policy()
  need(re.fullmatch('[0-9]{1,10}',values.get('serial',''))is not None)
  path=Path(binding.zone_root)/binding.file;need(values.get('files')in {binding.file,str(path)})
  with RootDirectory(binding.zone_root,binding.uid if binding.role=='secondary'else binding.owner_uid)as root:image=root.read(binding.file,uid=binding.uid)
  serial=int(values['serial']);need(0<=serial<=4294967295);return LoadedState(serial,binding.view,False,False,False,image.identity)
 def zone_snapshot(self,binding):
  from .zone import read_zone,snapshot
  if binding.role=='primary':return read_zone(binding)
  with RootDirectory(binding.zone_root,binding.uid)as root:image=root.read(binding.file,uid=binding.uid)
  if image.raw[:4]!=b'\x00\x00\x00\x02':return snapshot(image.raw,binding,image)
  self._pins()
  name='raw-secondary-'+str(uuid.uuid4())+'.zone'
  with RootDirectory(binding.state_root,binding.owner_uid,True)as state:state.save(name,image.raw)
  raw=self.runner([binding.compilezone,'-q','-f','raw','-F','text','-o','-',binding.zone,str(Path(binding.state_root)/name)],self.remaining(),2097152)
  with RootDirectory(binding.zone_root,binding.uid)as root:need(root.read(binding.file,uid=binding.uid)==image)
  return snapshot(raw,binding,image)
 def check(self,candidate):
  self._pins()
  b=candidate.binding;name='validation-'+str(uuid.uuid4())+'.zone'
  with RootDirectory(b.state_root,b.owner_uid,True)as state:state.save(name,candidate.raw)
  self.runner([b.checkzone,'-q',b.zone,str(Path(b.state_root)/name)],self.remaining(reserve=5),65536)
 def _pins(self):
  b=self.binding
  if b.status_profile=='strict':return
  from .legacy import PROFILE,PIN_NAMES,executable
  need(b.status_profile==PROFILE and set(dict(b.binary_pins))==PIN_NAMES and re.fullmatch(r'BIND 9\.11\.4-P2(?:[ -][A-Za-z0-9_.:<> ()-]+)?',b.expected_version)is not None)
  current=tuple((name,executable(getattr(b,name),digest,b.owner_uid))for name,digest in b.binary_pins)
  if self._binary_identity is not None:need(current==self._binary_identity)
  version=self.runner([b.named,'-v'],self.remaining(),4096).decode().strip();need(version==b.expected_version)
  running=self.runner([b.rndc,'status'],self.remaining(),16384).decode();versions=[line.split(':',1)[1].strip()for line in running.splitlines()if line.startswith('version:')];need(len(versions)==1 and versions[0].removeprefix('BIND ')==b.expected_version.removeprefix('BIND '))
  need(current==tuple((name,executable(getattr(b,name),digest,b.owner_uid))for name,digest in b.binary_pins));self._binary_identity=current
 def _canonical(self,path):
  self._pins()
  with RootDirectory(str(Path(path).parent),self.binding.owner_uid)as root:
   before=root.read(Path(path).name,524288);raw=self.runner([self.binding.checkconf,'-p','-x',path],self.remaining(),524288).decode('utf8');need(root.read(Path(path).name,524288)==before)
  self._pins();return raw
 def _proof(self,path):
  from .legacy import policy
  self.binding.assert_current();return policy(self._canonical(path),self.binding)
 def _guard_policy(self):
  proof=self._proof(self.binding.main_config)
  if self._prepared is not None:need(proof==self._prepared[1])
  if self._policy_identity is not None:need(proof==self._policy_identity)
  self._policy_identity=proof
 def guard_install(self):
  if self.binding.status_profile=='strict':return
  if self._prepared is not None:
   path,expected,before=self._prepared;need(self._proof(path)==expected and hashlib.sha256(self._canonical(self.binding.main_config).encode()).hexdigest()==before)
  else:self._guard_policy()
 def reload(self,binding):
  binding.assert_current()
  if binding.status_profile!='strict':self._guard_policy()
  self.runner([binding.rndc,'reload',binding.zone,'IN',binding.view],self.remaining(),65536)
 def notify(self,binding):
  binding.assert_current()
  if binding.status_profile!='strict':self._guard_policy()
  self.runner([binding.rndc,'notify',binding.zone,'IN',binding.view],self.remaining(),65536)
 def check_config(self,path):
  self._pins();self.runner([self.binding.checkconf,path],self.remaining(reserve=5),65536)
  if self.binding.status_profile!='strict':self._prepared=(path,self._proof(path),hashlib.sha256(self._canonical(self.binding.main_config).encode()).hexdigest())
 def reconfigure(self):
  self.binding.assert_current()
  if self.binding.status_profile!='strict':need(self._prepared is not None);self._guard_policy()
  self.runner([self.binding.rndc,'reconfig'],self.remaining(),65536)
