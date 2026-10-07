"""Persistent disposable rehearsal controller. Cannot issue native opening leases.

Gate files are consumed only by the disposable rehearsal writers. They do not
control BILLmanager, a web server, MariaDB, arbitrary SQL clients or Paymenter.
"""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import secrets

from custody import CustodyError, private_directory, private_file, strict_json


class ControlError(RuntimeError):
    pass


CONTROLS = {'source-writer', 'target-writer', 'ingress'}


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def process_identity(pid):
    if type(pid) is not int or pid <= 0:
        raise ControlError('An exact positive operator PID is required')
    try:
        raw = Path('/proc/%d/stat' % pid).read_text()
        fields = raw.rsplit(')', 1)[1].split()
        boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
        return {'pid': pid, 'boot_id': boot, 'start_ticks': fields[19]}
    except (OSError, IndexError) as exc:
        raise ControlError('Linux operator identity could not be observed') from exc


def operator_alive(identity):
    if (not isinstance(identity, dict) or set(identity) != {'pid', 'boot_id', 'start_ticks'}
            or type(identity['pid']) is not int or identity['pid'] <= 0
            or not isinstance(identity['boot_id'], str) or not identity['boot_id']
            or not isinstance(identity['start_ticks'], str) or not identity['start_ticks'].isdigit()):
        raise ControlError('Incomplete operator process identity')
    try:
        raw = Path('/proc/%d/stat' % identity['pid']).read_text()
        fields = raw.rsplit(')', 1)[1].split()
        return fields[0] not in {'Z', 'X'} and process_identity(identity['pid']) == identity
    except FileNotFoundError:
        # A missing PID is affirmative exit evidence; permission errors are not.
        return False
    except (OSError, IndexError) as exc:
        raise ControlError('Operator exit could not be verified') from exc


class ControlSession:
    def __init__(self, root, manifest, identity):
        if (not isinstance(manifest, dict) or set(manifest) != {'schema_version', 'purpose', 'freeze_id', 'controls'}
                or type(manifest['schema_version']) is not int or manifest['schema_version'] != 1
                or manifest['purpose'] != 'opening-control-rehearsal-manifest'
                or not isinstance(manifest['freeze_id'], str) or not manifest['freeze_id']
                or not isinstance(manifest['controls'], list) or len(manifest['controls']) != 3
                or not all(isinstance(v, str) for v in manifest['controls']) or set(manifest['controls']) != CONTROLS):
            raise ControlError('Only the closed disposable rehearsal manifest is supported')
        self.root = private_directory(root)
        self.manifest = strict_json(canonical(manifest))
        self.fingerprint = hashlib.sha256(canonical(self.manifest)).hexdigest()
        self.identity = strict_json(canonical(identity))
        self.state_path = self.root / 'control-state.json'
        path = self.root / 'control.lock'
        self.fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        try:
            self.lock_identity = private_file(path)
            descriptor = os.fstat(self.fd)
            if (descriptor.st_dev, descriptor.st_ino) != self.lock_identity:
                raise ControlError('Controller lock changed while opening')
            fcntl.flock(self.fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            if self.state_path.exists():
                self._load()
        except (OSError, CustodyError, ControlError) as exc:
            os.close(self.fd)
            raise ControlError('Exclusive intact rehearsal controller is required') from exc

    def __enter__(self):
        return self

    def __exit__(self, *_):
        os.close(self.fd)

    def _save(self, state):
        self._check_lock()
        private_directory(self.root)
        if self.state_path.exists():
            private_file(self.state_path)
        path = self.root / ('state-next-' + secrets.token_hex(12))
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(fd, 'wb') as stream:
                stream.write(canonical(state))
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(path, self.state_path)
            self._sync_directory()
        finally:
            if path.exists():
                path.unlink()

    def _sync_directory(self):
        fd = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def _check_lock(self):
        try:
            private_directory(self.root)
            descriptor = os.fstat(self.fd)
            if ((descriptor.st_dev, descriptor.st_ino) != self.lock_identity
                    or private_file(self.root / 'control.lock') != self.lock_identity):
                raise ControlError('Original controller lock identity changed')
        except (OSError, CustodyError) as exc:
            raise ControlError('Original exclusive controller lock is required') from exc

    def _load(self):
        self._check_lock()
        try:
            private_directory(self.root)
            private_file(self.state_path)
            state = strict_json(self.state_path.read_bytes())
            if (set(state) != {'purpose', 'manifest_sha256', 'operator', 'status', 'sequence', 'issued_at', 'expires_at', 'lock_identity'}
                    or state['purpose'] != 'opening-control-rehearsal-state'
                    or state['manifest_sha256'] != self.fingerprint or state['operator'] != self.identity
                    or state['lock_identity'] != list(self.lock_identity)
                    or state['status'] not in {'fenced', 'denied', 'released'}
                    or type(state['sequence']) is not int or state['sequence'] < 0
                    or type(state['issued_at']) is not int or state['issued_at'] < 0
                    or type(state['expires_at']) is not int or state['expires_at'] < state['issued_at']):
                raise ControlError('Rehearsal session identity or state changed')
            return state
        except (OSError, ValueError, TypeError, CustodyError) as exc:
            raise ControlError('Original rehearsal state is required') from exc

    def gate_bytes(self, name):
        return canonical({'purpose': 'disposable-writer-deny', 'control': name, 'manifest_sha256': self.fingerprint})

    def _verify_gates(self, releasing=False):
        remaining = {path.stem for path in self.root.glob('*.gate')}
        if (not remaining.issubset(CONTROLS) or (not releasing and remaining != CONTROLS)):
            raise ControlError('Missing or unknown rehearsal boundary')
        for name in remaining:
            path = self.root / (name + '.gate')
            try:
                private_file(path)
                if path.read_bytes() != self.gate_bytes(name):
                    raise ControlError('Rehearsal boundary bytes changed')
            except (OSError, CustodyError) as exc:
                raise ControlError('Rehearsal boundary could not be verified') from exc
        return remaining

    def establish(self):
        self._check_lock()
        if self.state_path.exists() or list(self.root.glob('*.gate')):
            raise ControlError('Do not overwrite an existing or interrupted rehearsal session')
        if not operator_alive(self.identity):
            raise ControlError('The original operator must be present')
        for name in sorted(CONTROLS):
            fd = os.open(self.root / (name + '.gate'), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'wb') as stream:
                stream.write(self.gate_bytes(name))
                stream.flush()
                os.fsync(stream.fileno())
        self._sync_directory()
        self._save({'purpose': 'opening-control-rehearsal-state', 'manifest_sha256': self.fingerprint,
                    'operator': self.identity, 'status': 'fenced', 'sequence': 0, 'issued_at': 0,
                    'expires_at': 0, 'lock_identity': list(self.lock_identity)})

    def _observed(self):
        state = self._load()
        if state['status'] != 'fenced':
            raise ControlError('Rehearsal permits are denied for this session')
        try:
            self._verify_gates()
            if not operator_alive(self.identity):
                raise ControlError('The original operator exited')
        except ControlError:
            self._save(state | {'status': 'denied'})
            raise
        return state

    def renew(self, now, ttl=60):
        if type(now) is not int or now < 0 or type(ttl) is not int or not 0 < ttl <= 60:
            raise ControlError('Exact bounded integer rehearsal times are required')
        state = self._observed()
        if now < state['issued_at']:
            self._save(state | {'status': 'denied'})
            raise ControlError('Clock moved backwards; retain gates')
        state = state | {'sequence': state['sequence'] + 1, 'issued_at': now, 'expires_at': now + ttl}
        self._save(state)
        return {'purpose': 'opening-control-rehearsal-permit', 'real_execution_ready': False,
                'manifest_sha256': self.fingerprint, 'sequence': state['sequence'],
                'issued_at': now, 'expires_at': now + ttl}

    def valid(self, permit, now):
        try:
            state = self._observed()
            return (isinstance(permit, dict) and set(permit) == {'purpose', 'real_execution_ready', 'manifest_sha256', 'sequence', 'issued_at', 'expires_at'}
                    and permit['purpose'] == 'opening-control-rehearsal-permit' and permit['real_execution_ready'] is False
                    and permit['manifest_sha256'] == self.fingerprint
                    and type(permit['sequence']) is int and permit['sequence'] == state['sequence']
                    and type(permit['issued_at']) is int and permit['issued_at'] == state['issued_at']
                    and type(permit['expires_at']) is int and 0 < permit['expires_at'] - permit['issued_at'] <= 60
                    and permit['expires_at'] == state['expires_at']
                    and type(now) is int and permit['issued_at'] <= now < permit['expires_at'])
        except (ControlError, CustodyError, OSError):
            return False

    def release(self, confirmation):
        state = self._load()
        if confirmation != 'RELEASE DISPOSABLE REHEARSAL' or operator_alive(self.identity):
            raise ControlError('Explicit rehearsal release requires verified original operator exit')
        remaining = self._verify_gates(releasing=state['status'] == 'released')
        self._save(state | {'status': 'released'})
        for name in sorted(remaining):
            (self.root / (name + '.gate')).unlink()
        self._sync_directory()
