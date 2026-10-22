"""Protected descriptor-relative I/O; never follows symlinks or overwrites."""
import json
import os
from pathlib import Path
import stat
from models import AuditError


def decode_json(raw):
    def pairs(items):
        d={}
        for k,v in items:
            if k in d: raise AuditError('json-duplicate')
            d[k]=v
        return d
    try:
        return json.loads(raw.decode('utf-8'),object_pairs_hook=pairs,
                          parse_constant=lambda _: (_ for _ in ()).throw(AuditError('json-invalid')))
    except (ValueError,UnicodeError,RecursionError): raise AuditError('json-invalid') from None


def canonical(value):
    return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=True,allow_nan=False).encode('ascii')


def _open_dir(path):
    p=Path(path)
    if not p.is_absolute() or '..' in p.parts: raise AuditError('private-path')
    fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY)
    try:
        for part in p.parts[1:]:
            child=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=fd)
            os.close(fd);fd=child
        s=os.fstat(fd)
        if s.st_uid!=os.geteuid() or stat.S_IMODE(s.st_mode)!=0o700: raise AuditError('private-mode')
        return fd
    except BaseException:
        os.close(fd);raise


class PrivateRoot:
    def __init__(self,path):
        self.path=Path(path);self.fd=_open_dir(self.path);s=os.fstat(self.fd);self.identity=(s.st_dev,s.st_ino)
    def __enter__(self): return self
    def __exit__(self,*args): os.close(self.fd)
    def check(self):
        fd=_open_dir(self.path)
        try:
            s=os.fstat(fd)
            if (s.st_dev,s.st_ino)!=self.identity: raise AuditError('private-identity')
        finally:os.close(fd)
    @staticmethod
    def name(name):
        if not isinstance(name,str) or not name or '/' in name or name in ('.','..'): raise AuditError('private-path')
    def create(self,name,data):
        self.name(name);self.check()
        try:
            fd=os.open(name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=self.fd)
            with os.fdopen(fd,'wb') as f:
                f.write(data);f.flush();os.fsync(f.fileno())
            self.check()
        except OSError:raise AuditError('private-write') from None
    def read(self,name,limit):
        self.name(name);self.check()
        try:
            fd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=self.fd)
            with os.fdopen(fd,'rb') as f:
                before=os.fstat(f.fileno())
                if not stat.S_ISREG(before.st_mode) or before.st_uid!=os.geteuid() or stat.S_IMODE(before.st_mode)!=0o600 or before.st_nlink!=1:raise AuditError('private-mode')
                if before.st_size>limit:raise AuditError('private-cap')
                raw=f.read(limit+1);after=os.fstat(f.fileno())
                if len(raw)>limit or (before.st_dev,before.st_ino,before.st_size,before.st_mtime_ns)!=(after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns):raise AuditError('private-identity')
                final=os.stat(name,dir_fd=self.fd,follow_symlinks=False)
                if (final.st_dev,final.st_ino)!=(before.st_dev,before.st_ino):raise AuditError('private-identity')
            self.check();return raw
        except OSError:raise AuditError('private-read') from None
    def mkdir(self,name):
        self.name(name);self.check()
        try:os.mkdir(name,0o700,dir_fd=self.fd);os.fsync(self.fd)
        except OSError:raise AuditError('attempt-spent') from None
        self.check();return open_private_root(self.path/name)
    def fsync(self):
        try:self.check();os.fsync(self.fd)
        except OSError:raise AuditError('private-sync') from None


def open_private_root(path):
    try:return PrivateRoot(path)
    except OSError:raise AuditError('private-open') from None


def read_private_json(path,max_bytes):
    p=Path(path)
    with open_private_root(p.parent) as root:return decode_json(root.read(p.name,max_bytes))
