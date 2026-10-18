"""Publish protected bridge authority only; no install, SQL or AdmittedRun.

Only the public entry fixes Linux/root and the actual boot/clock. Internal file
helpers also serve real-file tests with their test user's fixture ownership.
Explicit root approval is policy authority, never proof of native DB behavior.
"""
from dataclasses import dataclass
import hashlib
import os
from pathlib import PurePosixPath
import re
import stat
import sys
import time

from _schema import AdmissionError, digest, document, keys, require, text

_CASES = ('F1', 'F2', 'F3', 'F4')
_DIAGNOSTICS = tuple('writer.' + case + '.original' for case in _CASES)
_TABLES = ['paymenter_qa_business_v1', 'paymenter_qa_journal_v1']


def _sha(raw):
    return hashlib.sha256(raw).hexdigest()


def _path(path):
    require(type(path) is str and 1 < len(path) <= 1024 and path.startswith('/') and
            str(PurePosixPath(path)) == path and '..' not in PurePosixPath(path).parts and
            '\\' not in path and '=' not in path and
            all(32 < ord(c) < 127 for c in path), 'issuer-path')
    return path


def _stamp(s):
    return (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid, s.st_nlink,
            s.st_size, s.st_mtime_ns, s.st_ctime_ns)


def _directory(path, uid, gid, private):
    _path(path)
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for part in path.split('/')[1:]:
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                              dir_fd=fd)
            os.close(fd)
            fd = next_fd
            s = os.fstat(fd)
            require(stat.S_ISDIR(s.st_mode) and s.st_uid in (0, uid) and
                    not s.st_mode & 0o22, 'issuer-directory')
        if private:
            require(s.st_uid == uid and s.st_gid == gid and
                    stat.S_IMODE(s.st_mode) == 0o700, 'issuer-directory')
        return fd
    except BaseException:
        os.close(fd)
        raise


def _read_file(directory, name, maximum, uid, gid, private):
    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK,
                 dir_fd=directory)
    try:
        before = os.fstat(fd)
        require(stat.S_ISREG(before.st_mode) and before.st_uid == uid and
                before.st_nlink == 1 and 0 < before.st_size <= maximum and
                ((before.st_gid == gid and stat.S_IMODE(before.st_mode) == 0o600) if private
                 else not before.st_mode & 0o22), 'issuer-file' if private else 'issuer-image')
        raw = bytearray()
        while len(raw) <= maximum:
            chunk = os.read(fd, min(8192, maximum + 1 - len(raw)))
            if not chunk:
                break
            raw.extend(chunk)
        require(0 < len(raw) <= maximum and len(raw) == before.st_size and
                _stamp(before) == _stamp(os.fstat(fd)) ==
                _stamp(os.stat(name, dir_fd=directory, follow_symlinks=False)),
                'issuer-file-changed')
        return bytes(raw), _stamp(before)
    finally:
        os.close(fd)


class _Store:
    """Anchored operation directory. Not an execution or signalling capability."""
    def __init__(self, path, uid, gid):
        self.path, self.uid, self.gid, self.pid = path, uid, gid, os.getpid()
        self.fd = -1
        try:
            self.fd = _directory(path, uid, gid, True)
            s = os.fstat(self.fd)
            self.identity = s.st_dev, s.st_ino
        except OSError:
            raise AdmissionError('issuer-io') from None

    def __enter__(self):
        return self

    def __exit__(self, *unused):
        os.close(self.fd)
        self.fd = -1

    def check(self):
        require(self.fd >= 0 and os.getpid() == self.pid, 'issuer-process')
        try:
            fresh = _directory(self.path, self.uid, self.gid, True)
            try:
                s = os.fstat(fresh)
                require((s.st_dev, s.st_ino) == self.identity, 'issuer-directory-changed')
            finally:
                os.close(fresh)
        except OSError:
            raise AdmissionError('issuer-io') from None

    @staticmethod
    def name(name):
        require(type(name) is str and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,62}', name),
                'issuer-name')

    def exists(self, name):
        self.name(name)
        self.check()
        try:
            os.stat(name, dir_fd=self.fd, follow_symlinks=False)
            return True
        except FileNotFoundError:
            return False
        except OSError:
            raise AdmissionError('issuer-io') from None

    def read(self, name):
        self.name(name)
        self.check()
        try:
            result = _read_file(self.fd, name, 65536, self.uid, self.gid, True)
            self.check()
            return result
        except OSError:
            raise AdmissionError('issuer-io') from None

    def create(self, name, raw):
        self.name(name)
        self.check()
        require(type(raw) is bytes and 0 < len(raw) <= 65536, 'issuer-size')
        fd = -1
        try:
            fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                         0o600, dir_fd=self.fd)
            s = os.fstat(fd)
            require(s.st_uid == self.uid and s.st_gid == self.gid and
                    stat.S_IMODE(s.st_mode) == 0o600 and s.st_nlink == 1, 'issuer-file')
            used = 0
            while used < len(raw):
                count = os.write(fd, raw[used:])
                require(count > 0, 'issuer-write')
                used += count
            os.fsync(fd)
            os.fsync(self.fd)
            self.check()
        except OSError:
            raise AdmissionError('issuer-io') from None
        finally:
            if fd >= 0:
                os.close(fd)

    def unseal(self, original):
        """Remove only this issuer's exact revocation fence after durable checks."""
        self.check()
        require(self.read('revoked.v1') == original, 'issuer-fence-changed')
        try:
            os.unlink('revoked.v1', dir_fd=self.fd)
            os.fsync(self.fd)
        except OSError:
            # An unlink followed by failed directory sync must not expose authority.
            if not self.exists('revoked.v1'):
                self.create('revoked.v1', original[0])
            raise AdmissionError('issuer-io') from None


def _image(ref, store):
    keys(ref, 'path sha256 bytes')
    path = _path(ref['path'])
    digest(ref['sha256'])
    require(type(ref['bytes']) is int and 1 <= ref['bytes'] <= 16777216, 'issuer-image')
    parent, name = path.rsplit('/', 1)
    require(bool(parent), 'issuer-path')
    fd = -1
    try:
        fd = _directory(parent, store.uid, store.gid, False)
        raw, identity = _read_file(fd, name, 16777216, store.uid, store.gid, False)
        require(raw.startswith(b'\x7fELF') and len(raw) == ref['bytes'] and
                _sha(raw) == ref['sha256'], 'issuer-image')
        return identity
    except OSError:
        raise AdmissionError('issuer-io') from None
    finally:
        if fd >= 0:
            os.close(fd)


@dataclass(frozen=True)
class IssuedAuthority:
    """File receipt only. Not AdmittedRun, DB acceptance or install permission."""
    run_id: str
    authority_sha256: str


def _prepare(store, boot, clock):
    originals = {name: store.read(name) for name in
                 ('approval.original', 'registry.original', 'build.original')}
    approval, registry, build = (document(originals[name][0]) for name in originals)
    keys(approval, 'purpose intent run boot actor issued expires registry_sha256 build_sha256 cases')
    require(approval['purpose'] == 'source-framework-authority-approval-v1' and
            approval['intent'] == 'issue-framework-authority', 'issuer-approval')
    run = digest(approval['run'])
    require(type(approval['boot']) is str and re.fullmatch(
        '[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}', approval['boot']) and
        approval['boot'] == boot, 'issuer-boot')
    actor = text(approval['actor'], 128).encode('utf-8')
    require(len(actor) <= 128, 'issuer-actor')
    issued, expires = approval['issued'], approval['expires']
    require(type(issued) is int and type(expires) is int and
            0 < issued < expires < 2**63 and expires - issued <= 600, 'issuer-window')
    def window():
        now = clock()
        require(type(now) is int and issued <= now < expires, 'issuer-window')
    window()
    deadline = time.monotonic() + min(600, expires - clock())
    keys(registry, 'purpose run tables objects diagnostics')
    require(registry['purpose'] == 'source-framework-control-registry-v1' and
            registry['run'] == run and registry['tables'] == _TABLES and registry['objects'] == [] and
            registry['diagnostics'] == list(_DIAGNOSTICS), 'issuer-framework-registry')
    keys(build, 'purpose run module runtime')
    require(build['purpose'] == 'source-framework-control-build-v1' and build['run'] == run,
            'issuer-build')
    for key, name in [('registry_sha256', 'registry.original'), ('build_sha256', 'build.original')]:
        require(digest(approval[key]) == _sha(originals[name][0]), 'issuer-original-hash')
    keys(approval['cases'], 'F1 F2 F3 F4')
    nonces = []
    for case in _CASES:
        binding = approval['cases'][case]
        keys(binding, 'nonce facts_hash')
        nonces.append(digest(binding['nonce']))
        digest(binding['facts_hash'])
    require(len(set(nonces)) == 4, 'issuer-nonce-reuse')
    images = {key: _image(build[key], store) for key in ('module', 'runtime')}
    require(build['module']['path'] != build['runtime']['path'] and
            images['module'][:2] != images['runtime'][:2], 'issuer-build')
    fields = [('intent', 'framework-qa-execute'), ('run', run), ('boot', boot),
              ('actor_hex', actor.hex()), ('issued', str(issued)), ('expires', str(expires)),
              ('module_path', build['module']['path']), ('module_sha256', build['module']['sha256']),
              ('runtime_path', build['runtime']['path']), ('runtime_sha256', build['runtime']['sha256']),
              ('registry_sha256', approval['registry_sha256']), ('build_sha256', approval['build_sha256'])]
    for case in _CASES:
        fields.extend([('nonce_' + case, approval['cases'][case]['nonce']),
                       ('facts_' + case, approval['cases'][case]['facts_hash'])])
    raw = ('paymenter-source-qa-authority-v1\n' +
           ''.join(key + '=' + value + '\n' for key, value in fields)).encode('ascii')
    require(len(raw) <= 4096, 'issuer-size')
    def unchanged():
        store.check()
        window()
        require(time.monotonic() < deadline, 'issuer-window')
        require(not store.exists('retired.v1'), 'issuer-reused-operation')
        for name, original in originals.items():
            require(store.read(name) == original, 'issuer-original-changed')
        for key, identity in images.items():
            require(_image(build[key], store) == identity, 'issuer-image-changed')
    return approval, build, originals, raw, unchanged


def _claim(approval, originals, raw):
    return ('paymenter-source-qa-issuance-v1\nrun=' + approval['run'] + '\napproval_sha256=' +
            _sha(originals['approval.original'][0]) + '\nauthority_sha256=' + _sha(raw) + '\n').encode()


def _issue(store, boot, clock):
    store.check()
    reserved = ('authority.v1', 'intent.v1', 'issuance.original', 'revoked.v1', 'retired.v1',
                'release.F4.v1', 'barrier.original') + _DIAGNOSTICS
    require(not any(store.exists(name) for name in reserved), 'issuer-reused-operation')
    approval, build, originals, raw, unchanged = _prepare(store, boot, clock)
    run = approval['run']
    unchanged()
    fence = b'paymenter-source-qa-issuance-incomplete-v1\nrun=' + run.encode() + b'\n'
    store.create('revoked.v1', fence)
    fence_original = store.read('revoked.v1')
    # Once the fence is created, every failure preserves it and all partial files.
    unchanged()
    store.create('issuance.original', _claim(approval, originals, raw))
    store.create('authority.v1', raw)
    published = store.read('authority.v1')
    require(published[0] == raw, 'issuer-publish')
    unchanged()
    store.unseal(fence_original)
    return IssuedAuthority(run, _sha(raw))


def _native_boot():
    require(sys.platform == 'linux' and os.geteuid() == 0 and os.getegid() == 0,
            'issuer-linux-root-required')
    try:
        fd = os.open('/proc/sys/kernel/random/boot_id', os.O_RDONLY | os.O_CLOEXEC)
        try:
            boot = os.read(fd, 128).decode('ascii').removesuffix('\n')
        finally:
            os.close(fd)
        return boot
    except (OSError, UnicodeError):
        raise AdmissionError('issuer-io') from None


def issue_framework_authority(operation_directory: str) -> IssuedAuthority:
    """Publish only the explicitly approved native bridge authority, once.

    No public owner/clock/boot/image override. The later admitted-run controller
    must independently qualify installation, DB observations and retirement.
    """
    boot = _native_boot()
    with _Store(operation_directory, 0, 0) as store:
        return _issue(store, boot, lambda: int(time.time()))


def _kernel_file(path, maximum):
    fd = -1
    try:
        fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
        raw = bytearray()
        while len(raw) <= maximum:
            chunk = os.read(fd, min(8192, maximum + 1 - len(raw)))
            if not chunk:
                break
            raw.extend(chunk)
        require(0 < len(raw) <= maximum, 'issuer-manager')
        return bytes(raw)
    except OSError:
        raise AdmissionError('issuer-manager') from None
    finally:
        if fd >= 0:
            os.close(fd)


def _process_start(raw, pid):
    try:
        line = raw.decode('ascii')
        require(line.split(' ', 1)[0] == str(pid), 'issuer-manager')
        end = line.rfind(')')
        require(end > 0 and line[line.find(' ') + 1] == '(', 'issuer-manager')
        parts = line[end + 1:].split()
        require(len(parts) >= 20 and re.fullmatch('[1-9][0-9]{0,18}', parts[19]), 'issuer-manager')
        return int(parts[19])
    except (UnicodeError, IndexError):
        raise AdmissionError('issuer-manager') from None


def _mapping(raw, path, device, inode):
    try:
        for line in raw.decode('ascii').splitlines():
            fields = line.split(None, 5)
            if len(fields) != 6 or fields[5] != path:
                continue
            major, minor = fields[3].split(':')
            if fields[1] == 'r-xp' and int(fields[4]) == inode and (
                    int(major, 16), int(minor, 16)) == device:
                return True
        return False
    except (UnicodeError, ValueError):
        raise AdmissionError('issuer-manager') from None


def _manager(pid, start, build, boot):
    """Fixed actual Linux reads; no shell, alternate proc root or fact override."""
    try:
        require(_native_boot() == boot, 'issuer-manager')
        base = '/proc/' + str(pid)
        require(_process_start(_kernel_file(base + '/stat', 8192), pid) == start, 'issuer-manager')
        require(os.readlink(base + '/exe') == build['runtime']['path'], 'issuer-manager')
        actual = os.stat(base + '/exe')
        expected = os.stat(build['runtime']['path'], follow_symlinks=False)
        require((actual.st_dev, actual.st_ino) == (expected.st_dev, expected.st_ino), 'issuer-manager')
        uid = [line.split()[1:] for line in _kernel_file(base + '/status', 65536).splitlines()
               if line.startswith(b'Uid:')]
        require(uid == [[b'0', b'0', b'0', b'0']], 'issuer-manager')
        module = os.stat(build['module']['path'], follow_symlinks=False)
        require(_mapping(_kernel_file(base + '/maps', 1048576), build['module']['path'],
                         (os.major(module.st_dev), os.minor(module.st_dev)), module.st_ino),
                'issuer-manager')
        require(_process_start(_kernel_file(base + '/stat', 8192), pid) == start, 'issuer-manager')
        return True
    except OSError:
        raise AdmissionError('issuer-manager') from None


@dataclass(frozen=True)
class IssuedCase:
    """File receipt only; no invocation, commit, resolution or retirement claim."""
    run_id: str
    case_id: str
    intent_sha256: str


def _issue_case(store, case, boot, clock, check_manager):
    require(type(case) is str and case in _CASES, 'issuer-case')
    reserved = ('intent.v1', 'revoked.v1', 'retired.v1', 'intent.' + case + '.original',
                'writer.' + case + '.original', 'release.F4.v1', 'barrier.original')
    require(not any(store.exists(name) for name in reserved), 'issuer-reused-case')
    approval, build, originals, raw, unchanged = _prepare(store, boot, clock)
    issued_authority = store.read('authority.v1')
    issuance = store.read('issuance.original')
    require(issued_authority[0] == raw and issuance[0] == _claim(approval, originals, raw),
            'issuer-original-changed')
    name = 'case.' + case + '.approval.original'
    original = store.read(name)
    permit = document(original[0])
    keys(permit, 'purpose intent run case nonce facts_hash pid start_ticks boot issued expires authority_sha256')
    require(permit['purpose'] == 'source-framework-case-approval-v1' and
            permit['intent'] == 'issue-framework-case' and permit['run'] == approval['run'] and
            permit['case'] == case and permit['boot'] == boot and
            permit['nonce'] == approval['cases'][case]['nonce'] and
            permit['facts_hash'] == approval['cases'][case]['facts_hash'] and
            permit['authority_sha256'] == _sha(raw), 'issuer-case-binding')
    pid, start, issued, expires = (permit[key] for key in ('pid', 'start_ticks', 'issued', 'expires'))
    require(type(pid) is int and 1 < pid <= 2147483647 and type(start) is int and
            0 < start < 2**63, 'issuer-manager')
    require(type(issued) is int and type(expires) is int and
            approval['issued'] <= issued < expires <= approval['expires'] and
            expires - issued <= 30, 'issuer-window')
    require(issued <= clock() < expires, 'issuer-window')
    deadline = time.monotonic() + min(30, expires - clock())
    def current():
        unchanged()
        require(issued <= clock() < expires and time.monotonic() < deadline, 'issuer-window')
        require(store.read(name) == original and store.read('authority.v1') == issued_authority and
                store.read('issuance.original') == issuance, 'issuer-original-changed')
        require(check_manager(pid, start, build, boot) is True, 'issuer-manager')
    current()
    fence = ('paymenter-source-qa-case-incomplete-v1\nrun=' + approval['run'] + '\ncase=' + case + '\n').encode()
    store.create('revoked.v1', fence)
    fence_original = store.read('revoked.v1')
    current()
    intent = ('paymenter-source-qa-intent-v1\nrun=' + approval['run'] + '\ncase=' + case +
              '\nnonce=' + permit['nonce'] + '\nfacts=' + permit['facts_hash'] + '\npid=' +
              str(pid) + '\nstart=' + str(start) + '\nissued=' + str(issued) +
              '\nexpires=' + str(expires) + '\n').encode()
    store.create('intent.' + case + '.original', intent)
    store.create('intent.v1', intent)
    require(store.read('intent.v1')[0] == intent and
            store.read('intent.' + case + '.original')[0] == intent, 'issuer-publish')
    current()
    store.unseal(fence_original)
    return IssuedCase(approval['run'], case, _sha(intent))


def issue_framework_case(operation_directory: str, case_id: str) -> IssuedCase:
    """Publish one explicitly approved case for the actual loaded root process.

    Does not invoke it, remove an earlier intent, advance a case sequence, release
    an observer barrier or decide transaction acceptance. Those remain separate.
    """
    boot = _native_boot()
    with _Store(operation_directory, 0, 0) as store:
        return _issue_case(store, case_id, boot, lambda: int(time.time()), _manager)
