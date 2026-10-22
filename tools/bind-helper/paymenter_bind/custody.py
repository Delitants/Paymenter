"""Descriptor-relative zone custody. Privileged noncooperating writers are excluded operationally."""
import os,stat,fcntl,hashlib
from dataclasses import dataclass
from contextlib import contextmanager
from .protocol import need,uuid
from .config import relative
LIMIT=2097152
def identity(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def dir_identity(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid)
@dataclass(frozen=True)
class FileImage:
 raw:bytes;identity:tuple;mode:int;uid:int;gid:int
 @property
 def sha256(self):return hashlib.sha256(self.raw).hexdigest()
class RootDirectory:
 def __init__(self,path,uid=0,private=False):
  need(type(path)is str and path.startswith('/')and '..'not in path.split('/'));self.path=path;self.uid=uid;self.private=private;self.fd,self.chain=self._walk()
 def _walk(self):
  fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);chain=[dir_identity(os.fstat(fd))]
  try:
   for part in self.path.split('/')[1:]:
    if not part:continue
    n=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=fd);os.close(fd);fd=n;s=os.fstat(fd)
    need(s.st_uid in {0,self.uid}and not s.st_mode&0o022);chain.append(dir_identity(s))
   s=os.fstat(fd);need(s.st_uid==self.uid and(not self.private or stat.S_IMODE(s.st_mode)==0o700));return fd,chain
  except BaseException:os.close(fd);raise
 def recheck(self):
  fd,chain=self._walk()
  try:need(chain==self.chain and dir_identity(os.fstat(fd))==dir_identity(os.fstat(self.fd)))
  finally:os.close(fd)
 def close(self):
  if self.fd is not None:os.close(self.fd);self.fd=None
 def __enter__(self):return self
 def __exit__(self,*_):self.close()
 @contextmanager
 def parent(self,name):
  need(relative(name));self.recheck();fd=os.dup(self.fd);dev=os.fstat(fd).st_dev
  try:
   parts=name.split('/')
   for part in parts[:-1]:
    nxt=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=fd);os.close(fd);fd=nxt;s=os.fstat(fd);need(s.st_dev==dev and s.st_uid in {0,self.uid}and not s.st_mode&0o022)
   yield fd,parts[-1]
  finally:os.close(fd)
 def read(self,name,limit=LIMIT,uid=None):
  with self.parent(name)as(parent,leaf):
   fd=os.open(leaf,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=parent)
   try:
    s=os.fstat(fd);need(stat.S_ISREG(s.st_mode)and s.st_nlink==1 and s.st_uid==(self.uid if uid is None else uid)and not s.st_mode&0o022 and s.st_size<=limit)
    raw=b''
    while len(raw)<=limit:
     b=os.read(fd,min(65536,limit+1-len(raw)))
     if not b:break
     raw+=b
    need(len(raw)==s.st_size and identity(s)==identity(os.fstat(fd))==identity(os.stat(leaf,dir_fd=parent,follow_symlinks=False)));self.recheck()
    return FileImage(raw,identity(s),stat.S_IMODE(s.st_mode),s.st_uid,s.st_gid)
   finally:os.close(fd)
 def exists(self,name):
  with self.parent(name)as(fd,leaf):
   try:os.stat(leaf,dir_fd=fd,follow_symlinks=False);return True
   except FileNotFoundError:return False
 def save(self,name,raw,mode=0o600):
  need(type(raw)is bytes and len(raw)<=LIMIT)
  with self.parent(name)as(parent,leaf):
   fd=os.open(leaf,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,mode,dir_fd=parent)
   with os.fdopen(fd,'wb')as f:f.write(raw);f.flush();os.fsync(f.fileno())
   os.fsync(parent);self.recheck()
 def install(self,name,raw,request_id,before,uid,gid):
  need(uuid(request_id)and type(raw)is bytes and len(raw)<=LIMIT)
  with self.parent(name)as(parent,leaf):
   candidate='.paymenter-'+request_id+'.candidate';fd=os.open(candidate,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=parent)
   with os.fdopen(fd,'wb')as f:
    f.write(raw);f.flush()
    if(os.fstat(f.fileno()).st_uid,os.fstat(f.fileno()).st_gid)!=(uid,gid):os.fchown(f.fileno(),uid,gid)
    os.fchmod(f.fileno(),before.mode if before is not None else 0o640);os.fsync(f.fileno());candidate_identity=identity(os.fstat(f.fileno()))
   if before is None:need(not self.exists(name))
   else:need(self.read(name,uid=uid)==before)
   self.recheck();need(identity(os.stat(candidate,dir_fd=parent,follow_symlinks=False))==candidate_identity)
   os.replace(candidate,leaf,src_dir_fd=parent,dst_dir_fd=parent);os.fsync(parent)
   after=self.read(name,uid=uid);need(after.raw==raw and after.identity[:2]==candidate_identity[:2]);self.recheck();return after
class LockedZone:
 def __init__(self,binding):
  self.binding=binding;self.lock=None;self.root=RootDirectory(binding.zone_root,binding.uid if binding.role=='secondary'else binding.owner_uid)
  try:self.state=RootDirectory(binding.state_root,binding.owner_uid,True)
  except BaseException:self.root.close();raise
 def __enter__(self):
  try:
   name=self.binding.id+'.lock';fd=os.open(name,os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW|os.O_NONBLOCK,0o600,dir_fd=self.state.fd);self.lock=fd;s=os.fstat(fd);need(stat.S_ISREG(s.st_mode)and s.st_uid==self.binding.owner_uid and s.st_nlink==1 and stat.S_IMODE(s.st_mode)==0o600)
   fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);return self
  except BaseException:self.__exit__();raise
 def read(self):return self.root.read(self.binding.file,uid=self.binding.uid)
 def install(self,raw,request_id,before):return self.root.install(self.binding.file,raw,request_id,before,self.binding.uid,self.binding.gid)
 def __exit__(self,*_):
  if self.lock is not None:os.close(self.lock);self.lock=None
  self.root.close();self.state.close()
def locked_zone(binding):return LockedZone(binding)
