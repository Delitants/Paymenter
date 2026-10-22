"""Anchored root-private original-byte loading; no operations beyond local reads."""
from dataclasses import dataclass
import hashlib
import os
from pathlib import Path
import stat
import sys
from custody import strict_json
from boundary_schema import BoundaryError, PREFIX, ref, require, parse_boundary_document, MAX_PART, MAX_CAPTURE, MAX_OBJECTS

@dataclass(frozen=True)
class LoadedDocument:
    path: str
    sha256: str
    purpose: str
    value: dict

@dataclass(frozen=True)
class ResolvedBoundary:
    plan: LoadedDocument
    scope: LoadedDocument
    witnesses: tuple
    documents: dict

def typed_refs(d):
    """Only schema-declared reference slots; never an arbitrary path search."""
    p=d['purpose']
    def ev(r,kind=None):return (r,((PREFIX+'fixture-evidence',kind),))
    if p==PREFIX+'plan':
        for r in d['observation_refs']:yield r,((PREFIX+'scope',None),(PREFIX+'fixture-evidence','observation'))
        for slot,kind in [('cohort_ref','cohort'),('baseline_ref','baseline'),('acceptance_ref','acceptance')]:
            if d[slot] is not None:yield ev(d[slot],kind)
        for c in d['controls']:
            for slot,kind in [('adapter_manifest_ref','adapter-manifest'),('rollback_ref','rollback')]:yield ev(c[slot],kind)
            for slot,kind in [('unrelated_scope_refs','unrelated-scope'),('precondition_refs','precondition'),('blocking_check_refs','blocking-check')]:
                for r in c[slot]:yield ev(r,kind)
            for m in c['members']:
                for r in m['evidence_refs']:yield ev(r)
        for u in d['unresolved']:
            for r in u['evidence_refs']:yield ev(r)
    elif p==PREFIX+'scope':
        for o in d['objects']+d['unrelated']:
            for r in o['evidence_refs']:yield ev(r)
    elif p==PREFIX+'fixture-evidence':
        for r in d['refs']:yield ev(r)
    elif p==PREFIX+'witness':
        if d['gate_readback_ref'] is not None:yield ev(d['gate_readback_ref'],'gate-readback')
        for slot,kind in [('attempt_result_refs','attempt-result'),('unrelated_result_refs','unrelated-result'),('failure_refs','failure')]:
            for r in d[slot]:yield ev(r,kind)

def entry_count(value):
    # Every array is a declared row/reference array in the closed schemas.
    count=0;stack=[value]
    while stack:
        v=stack.pop()
        if type(v) is dict:stack.extend(v.values())
        elif type(v) is list:count+=len(v);stack.extend(v)
    return count

def token(s):return (s.st_dev,s.st_ino)

def ordinary(s):
    require(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_gid==0 and stat.S_IMODE(s.st_mode)==0o600 and s.st_nlink==1,'private-reference')

class ReferenceSet:
    def __init__(self,root: Path):
        self.dirs=[];self.files={};self.cache={};self.documents={};self.heights={};self.bytes=0;self.entries=0;self.closed=False
        require(sys.platform=='linux' and os.geteuid()==0,'private-reference')
        self.root=Path(root)
        ref({'path':str(self.root),'sha256':'0'*64})
        try:self.root_fd=self._directories(self.root,private_from=self.root)
        except BaseException:self.close();raise
    def __enter__(self):return self
    def __exit__(self,*args):self.close()
    def close(self):
        for fd,_,_,_ in self.files.values():os.close(fd)
        self.files.clear()
        for fd,_,_,_,_ in reversed(self.dirs):os.close(fd)
        self.dirs.clear();self.closed=True
    def _directories(self,path,private_from):
        parent=None;current=Path('/')
        for name in ['/',*path.parts[1:]]:
            if name!='/':current=current/name
            existing=next((e for e in self.dirs if e[4]==current),None)
            if existing:parent=existing[0];continue
            try:
                before=os.stat(name,dir_fd=parent,follow_symlinks=False)
                require(stat.S_ISDIR(before.st_mode),'private-reference')
                fd=os.open(name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=parent)
                after=os.fstat(fd)
                if token(before)!=token(after):os.close(fd);raise BoundaryError('reference-substitution')
                private=current==private_from or private_from in current.parents
                if not (after.st_uid==0 and after.st_gid==0 and (stat.S_IMODE(after.st_mode)==0o700 if private else not(after.st_mode & 0o022) or bool(after.st_mode & stat.S_ISVTX))):
                    os.close(fd);raise BoundaryError('private-reference')
                self.dirs.append((fd,parent,name,token(after),current));parent=fd
            except OSError:raise BoundaryError('private-reference') from None
        return parent
    def _raw(self,fd):
        os.lseek(fd,0,os.SEEK_SET);parts=[];size=0
        while True:
            chunk=os.read(fd,min(65536,MAX_PART+1-size))
            if not chunk:break
            parts.append(chunk);size+=len(chunk);require(size<=MAX_PART,'document-size')
        return b''.join(parts)
    def _read(self,r):
        ref(r);path=Path(r['path'])
        require(self.root in path.parents,'reference-path')
        parent=self._directories(path.parent,self.root)
        try:
            before=os.stat(path.name,dir_fd=parent,follow_symlinks=False);ordinary(before)
            fd=os.open(path.name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK|os.O_CLOEXEC,dir_fd=parent)
            try:
                after=os.fstat(fd);ordinary(after)
                require(token(before)==token(after),'reference-substitution')
                raw=self._raw(fd);current=os.stat(path.name,dir_fd=parent,follow_symlinks=False)
                require(token(current)==token(after),'reference-substitution');ordinary(current)
                require(hashlib.sha256(raw).hexdigest()==r['sha256'],'reference-hash')
            except BaseException:os.close(fd);raise
            self.files[str(path)]=(fd,parent,path.name,(token(after),r['sha256']))
            return raw
        except OSError:raise BoundaryError('private-reference') from None
    def _visit(self,r,allowed,level,active):
        ref(r);path=r['path'];require(path not in active,'reference-cycle');require(level<=8,'reference-depth')
        if path in self.cache:
            d=self.cache[path];require(d.sha256==r['sha256'],'reference-hash');require(level+self.heights[path]-1<=8,'reference-depth')
        else:
            raw=self._read(r);self.bytes+=len(raw);require(self.bytes<=MAX_CAPTURE,'reference-budget')
            try:v=strict_json(raw)
            except (ValueError,UnicodeError,RecursionError):
                # The closed parser assigns the sanitized error code.
                parse_boundary_document(raw,PREFIX+'fixture-evidence');raise BoundaryError('document-form')
            require(type(v) is dict and type(v.get('purpose')) is str,'document-purpose')
            v=parse_boundary_document(raw,v['purpose']);self.entries+=entry_count(v);require(self.entries<=MAX_OBJECTS,'reference-budget')
            d=LoadedDocument(path,r['sha256'],v['purpose'],v)
            self._kind(d,allowed)
            children=list(typed_refs(v))
            for child,kinds in children:self._visit(child,kinds,level+1,active|{path})
            self.heights[path]=1+max((self.heights[child['path']] for child,_ in children),default=0)
            self.cache[path]=d;self.documents[d.sha256]=d
        self._kind(d,allowed);return d
    @staticmethod
    def _kind(d,allowed):
        require(any(d.purpose==p and (k is None or d.value.get('kind')==k) for p,k in allowed),'reference-kind')
    def load_plan(self,plan_ref,witness_refs):
        require(not self.closed and not self.cache,'reference-substitution')
        require(type(witness_refs) is list and len(witness_refs)<=MAX_OBJECTS,'reference-budget');self.entries=len(witness_refs)
        plan=self._visit(plan_ref,((PREFIX+'plan',None),),1,set())
        scopes=[self.cache[r['path']] for r in plan.value['observation_refs'] if self.cache[r['path']].purpose==PREFIX+'scope']
        require(len(scopes)==1,'reference-kind')
        witnesses=tuple(self._visit(r,((PREFIX+'witness',None),),1,set()) for r in witness_refs)
        self.verify_unchanged();return ResolvedBoundary(plan,scopes[0],witnesses,dict(self.documents))
    def _verify_directories(self):
        for fd,parent,name,expected,path in self.dirs:
            current=os.stat(name,dir_fd=parent,follow_symlinks=False);held=os.fstat(fd)
            require(token(current)==expected==token(held),'reference-substitution')
            require(current.st_uid==0 and current.st_gid==0 and stat.S_ISDIR(current.st_mode),'private-reference')
            private=path==self.root or self.root in path.parents
            require(stat.S_IMODE(current.st_mode)==0o700 if private else not(current.st_mode & 0o022) or bool(current.st_mode & stat.S_ISVTX),'private-reference')
    def verify_unchanged(self):
        require(not self.closed,'reference-substitution')
        try:
            self._verify_directories()
            for fd,parent,name,(expected,h) in self.files.values():
                current=os.stat(name,dir_fd=parent,follow_symlinks=False);held=os.fstat(fd)
                require(token(current)==expected==token(held),'reference-substitution');ordinary(current);ordinary(held)
                require(hashlib.sha256(self._raw(fd)).hexdigest()==h,'reference-hash')
                # Reading pinned bytes is insufficient if their pathname was replaced meanwhile.
                current=os.stat(name,dir_fd=parent,follow_symlinks=False);held=os.fstat(fd)
                require(token(current)==expected==token(held),'reference-substitution');ordinary(current);ordinary(held)
            self._verify_directories()
        except OSError:raise BoundaryError('reference-substitution') from None
