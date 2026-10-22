"""Factory-issued allocation only. No server, SQL, command or live resource API."""
from dataclasses import asdict
import os
from pathlib import Path
import secrets
import shutil
import stat
import sys
import tempfile
from boundary_schema import BoundaryError
from control import process_identity
from containment_admission import EvidenceGraph, boot_id
from containment_schema import (ControlContext, EvidenceRef, ContainmentError,
    canonical, digest, require)

_SEAL = object()
_OWNED = {}


class OwnedContext:
    __slots__ = ('_root',)

    def __init__(self, seal=None):
        require(seal is _SEAL, 'owned-factory')

    @property
    def root(self): return self._root

    def __reduce__(self):
        raise ContainmentError('owned-serialization')


def _token(path):
    s = path.lstat()
    return s.st_dev, s.st_ino


def _exclusive(path, raw):
    fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as out:
        out.write(raw); out.flush(); os.fsync(out.fileno())
    fd = os.open(str(path.parent), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)


def create_owned_context(kind, context, runtime):
    require(kind == 'sql', 'runtime-unqualified')
    require(type(context) is ControlContext and type(runtime) is EvidenceRef)
    require(sys.platform == 'linux' and os.geteuid() == 0, 'owned-linux-root')
    require(context.source_boot_id == boot_id(), 'owned-boot')
    try:
        with EvidenceGraph(Path(context.authority_ref.path).parent) as graph:
            authority = graph.visit(context.authority_ref)
            budget = graph.visit(context.budget_ref)
            profile = graph.visit(runtime)
            require(authority['purpose'] == 'source-containment-authority' and
                    budget['purpose'] == 'source-containment-budget' and
                    profile['purpose'] == 'source-containment-owned-runtime', 'reference-kind')
            require(authority['source_boot_id'] == context.source_boot_id and
                    authority['exclusive_maintenance'], 'maintenance-unaccepted')
            require(profile['source_boot_id'] == context.source_boot_id and
                    profile['adapter_manifest_sha256'] == context.adapter_manifest_sha256,
                    'owned-runtime')
            graph.verify()
    except BoundaryError as exc:
        raise ContainmentError(str(exc)) from None
    require(shutil.disk_usage('/').free-budget['evidence_limit'] >= budget['root_reserve'],
            'owned-root-reserve')
    available = dict(line.split(':', 1) for line in Path('/proc/meminfo').read_text().splitlines())
    memory = int(available['MemAvailable'].strip().split()[0])*1024
    require(memory-budget['memory_limit']-budget['staging_limit'] >= budget['ram_reserve']
            and shutil.disk_usage('/dev/shm').free-budget['staging_limit'] >=
            budget['ram_reserve'], 'owned-ram-reserve')
    # This factory allocates two bounded control files only. Native server staging
    # requires its own admitted namespace/cgroup supervisor in the SQL task.
    root = Path(tempfile.mkdtemp(prefix='source-containment-', dir='/dev/shm'))
    root.chmod(0o700)
    ctx = OwnedContext(_SEAL); ctx._root = root
    row = {'context': ctx, 'root_token': _token(root), 'source': context,
           'owner': process_identity(os.getpid()),
           'runtime_ref': runtime, 'files': {}, 'retired': False}
    _OWNED[id(ctx)] = row  # Register obligations before the first control write.
    for name, raw in [('allocation-intent.json', canonical({
            'purpose': 'source-containment-owned-allocation', 'real_execution_ready': False,
            'context_sha256': digest(canonical(asdict(context))),
            'runtime_sha256': runtime.sha256, 'nonce': secrets.token_hex(24)})),
            ('marker', secrets.token_bytes(32))]:
        _exclusive(root/name, raw)
        row['files'][name] = (_token(root/name), digest(raw))
    return ctx


def _guard(ctx):
    require(type(ctx) is OwnedContext and id(ctx) in _OWNED, 'owned-factory')
    row = _OWNED[id(ctx)]
    require(row['context'] is ctx and not row['retired'], 'owned-retired')
    require(row['owner'] == process_identity(os.getpid()), 'owned-owner')
    try:
        s = ctx.root.lstat()
        require(_token(ctx.root) == row['root_token'] and stat.S_ISDIR(s.st_mode)
                and s.st_uid == 0 and stat.S_IMODE(s.st_mode) == 0o700, 'owned-drift')
        require(row['source'].source_boot_id == boot_id(), 'owned-boot')
        require({p.name for p in ctx.root.iterdir()} == set(row['files']), 'owned-drift')
        for name, (token, sha256) in row['files'].items():
            p = ctx.root/name; s = p.lstat()
            require(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and s.st_nlink == 1 and
                    stat.S_IMODE(s.st_mode) == 0o600 and _token(p) == token and
                    digest(p.read_bytes()) == sha256, 'owned-drift')
    except (OSError, AttributeError):
        raise ContainmentError('owned-drift') from None
    return row


def retire_owned_context(ctx):
    row = _guard(ctx)
    # A second original-byte check precedes deletion. No recursive removal.
    _guard(ctx)
    receipt = canonical({'schema_version': 1,
        'purpose': 'source-containment-owned-retirement', 'real_execution_ready': False,
        'source_boot_id': row['source'].source_boot_id,
        'context_sha256': digest(canonical(asdict(row['source']))),
        'root_identity': list(row['root_token']), 'owned_files': sorted(row['files'])})
    output = Path(row['source'].authority_ref.path).parent/('retirement-'+secrets.token_hex(12)+'.json')
    # Retain intent before retirement and actual outcome separately afterward.
    intent = output.with_suffix('.intent')
    _exclusive(intent, receipt)
    for name in row['files']: (ctx.root/name).unlink()
    ctx.root.rmdir()
    row['retired'] = True
    _exclusive(output, receipt)
    return EvidenceRef(str(output), digest(receipt), len(receipt))
