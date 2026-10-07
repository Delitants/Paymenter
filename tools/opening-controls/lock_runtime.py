"""Explicit runtime-only archive validation. No tar extraction into live paths."""
import hashlib
import io
import os
from pathlib import Path
import stat
import tarfile
from custody import private_directory,private_file,CustodyError
from source_schema import SourceError,digest,require,validate_runtime_manifest

def validate_archive(archive,manifest):
    validate_runtime_manifest(manifest);archive=Path(archive)
    try:
        private_directory(archive.parent);identity=private_file(archive)
        fd=os.open(archive,os.O_RDONLY|os.O_NOFOLLOW)
        with os.fdopen(fd,'rb') as stream:
            st=os.fstat(stream.fileno());require((st.st_dev,st.st_ino)==identity and st.st_size<=256*1024**2)
            packed=stream.read(256*1024**2+1);require(len(packed)<=256*1024**2)
        require(private_file(archive)==identity and hashlib.sha256(packed).hexdigest()==manifest['archive_sha256'])
        files={};total=0
        with tarfile.open(fileobj=io.BytesIO(packed),mode='r:*') as tar:
            for member in tar:
                require(len(files)<1000 and member.isfile() and member.name in manifest['files'] and member.name not in files)
                expected=manifest['files'][member.name];require(member.size==expected['bytes'] and member.mode==expected['mode'])
                total+=member.size;require(total<=256*1024**2)
                raw=tar.extractfile(member).read(64*1024**2+1)
                require(len(raw)==member.size and hashlib.sha256(raw).hexdigest()==expected['sha256']);files[member.name]=raw
        require(set(files)==set(manifest['files']) and private_file(archive)==identity)
        return {'files':files,'archive_identity':list(identity),'manifest_sha256':digest(manifest),'archive_sha256':manifest['archive_sha256']}
    except (OSError,CustodyError,tarfile.TarError,EOFError) as exc:raise SourceError('Explicit private runtime archive required') from exc

def stage_runtime(archive,manifest,owned_root):
    checked=validate_archive(archive,manifest);root=Path(owned_root)
    try:
        private_directory(root.parent);require(not root.exists() and not root.is_symlink())
        root.mkdir(mode=0o700);private_directory(root)
        for name,raw in checked['files'].items():
            path=root/name;path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
            for parent in [path.parent,*path.parent.parents]:
                if parent==root.parent:break
                require(not parent.is_symlink())
            fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'wb') as stream:stream.write(raw);stream.flush();os.fsync(stream.fileno());os.fchmod(stream.fileno(),manifest['files'][name]['mode'])
        st=root.stat();result={'root':str(root),'root_identity':[st.st_dev,st.st_ino],'manifest_sha256':checked['manifest_sha256'],'manifest':manifest}
        verify_runtime(result);return result
    except (OSError,CustodyError) as exc:raise SourceError('Exclusive owned runtime staging required') from exc

def verify_runtime(runtime):
    root=Path(runtime['root']);private_directory(root);st=root.stat();require([st.st_dev,st.st_ino]==runtime['root_identity'] and digest(runtime['manifest'])==runtime['manifest_sha256'])
    expected=runtime['manifest']['files']
    actual=set()
    for p in root.rglob('*'):
        require(not p.is_symlink())
        if p.is_file():actual.add(str(p.relative_to(root)))
    # Runtime resources are immutable; fictional data is separate from this tree.
    require(actual==set(expected))
    for name,meta in expected.items():
        p=root/name;st=p.stat();require(stat.S_ISREG(st.st_mode) and st.st_uid==os.geteuid() and st.st_nlink==1 and stat.S_IMODE(st.st_mode)==meta['mode'] and st.st_size==meta['bytes'] and hashlib.sha256(p.read_bytes()).hexdigest()==meta['sha256'])
