"""External callback custody. Delivery receipts never represent settlement.

No listener, provider calls, replay worker or application signer is installed here.
The caller must supply original request bytes from an independently accepted ingress.
"""
import hashlib
import hmac
import json
import os
from pathlib import Path
import sqlite3
import stat
import time


class CustodyError(RuntimeError):
    pass


def strict_json(raw):
    def pairs(items):
        value = {}
        for key, item in items:
            if key in value:
                raise ValueError('Duplicate JSON field')
            value[key] = item
        return value
    return json.loads(raw, object_pairs_hook=pairs,
                      parse_constant=lambda _: (_ for _ in ()).throw(ValueError('Nonfinite JSON')))


def private_directory(path):
    path = Path(path)
    if not path.is_absolute() or path.resolve(strict=True) != path:
        raise CustodyError('An existing ordinary absolute private directory is required')
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
        raise CustodyError('Custody directory must be owned by this operator with mode 0700')
    for parent in path.parents:
        info = parent.lstat()
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
            raise CustodyError('Unsafe ancestor')
        # A sticky temporary parent is allowed only above the private owned root.
        if info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX:
            raise CustodyError('Writable ancestor')
    return path


def private_file(path):
    info = path.lstat()
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
            or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1):
        raise CustodyError('An ordinary owned 0600 file without links is required')
    return info.st_dev, info.st_ino


class Custody:
    def __init__(self, root):
        self.root = private_directory(root)
        self.path = self.root / 'custody.sqlite'
        try:
            fd = os.open(self.path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        except FileExistsError:
            pass
        else:
            os.close(fd)
        self.identity = private_file(self.path)
        try:
            self.db = sqlite3.connect(str(self.path), timeout=15)
            self.db.execute('PRAGMA journal_mode=DELETE')
            self.db.execute('PRAGMA synchronous=FULL')
            self.db.execute('PRAGMA foreign_keys=ON')
            self.db.executescript('''
                CREATE TABLE IF NOT EXISTS events (
                    provider TEXT NOT NULL, event_id TEXT NOT NULL, body_sha256 TEXT NOT NULL,
                    PRIMARY KEY(provider,event_id));
                CREATE TABLE IF NOT EXISTS arrivals (
                    id INTEGER PRIMARY KEY, received_ns TEXT NOT NULL, provider TEXT NOT NULL,
                    method TEXT NOT NULL, target TEXT NOT NULL, headers TEXT NOT NULL,
                    body BLOB NOT NULL, body_sha256 TEXT NOT NULL, event_id TEXT,
                    disposition TEXT NOT NULL);
            ''')
            self._check()
        except sqlite3.Error as exc:
            raise CustodyError('Custody database could not be opened') from exc

    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.close()

    def close(self):
        self.db.close()

    def _check(self):
        private_directory(self.root)
        if private_file(self.path) != self.identity:
            raise CustodyError('Custody database identity changed')

    def _sync(self):
        self._check()
        fd = os.open(self.path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            if (os.fstat(fd).st_dev, os.fstat(fd).st_ino) != self.identity:
                raise CustodyError('Custody file changed during durability check')
            os.fsync(fd)
        finally:
            os.close(fd)
        fd = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    @staticmethod
    def _envelope(request):
        if not isinstance(request, dict) or set(request) != {'method', 'target', 'headers', 'body'}:
            raise CustodyError('An exact original request envelope is required')
        if request['method'] != 'POST' or not isinstance(request['body'], bytes) or len(request['body']) > 1048576:
            raise CustodyError('Only bounded original POST bodies are accepted')
        if not isinstance(request['target'], str) or not request['target'].startswith('/') or len(request['target']) > 8192:
            raise CustodyError('Original path and query are required')
        headers = request['headers']
        if not isinstance(headers, list) or len(headers) > 100:
            raise CustodyError('Original ordered headers are required')
        for pair in headers:
            if (not isinstance(pair, list) or len(pair) != 2 or
                    any(not isinstance(v, str) or '\n' in v or '\r' in v or '\0' in v for v in pair)):
                raise CustodyError('Malformed original headers')
        encoded = json.dumps(headers, separators=(',', ':'), ensure_ascii=False)
        if len(encoded.encode()) > 16384:
            raise CustodyError('Headers exceed custody bound')
        return encoded

    def authorizenet(self, request, signature_key):
        headers = self._envelope(request)
        if not isinstance(signature_key, bytes) or len(signature_key) != 64:
            raise CustodyError('A separately supplied 64-byte signature key is required')
        signatures = [value for name, value in request['headers'] if name.lower() == 'x-anet-signature']
        expected = 'sha512=' + hmac.new(signature_key, request['body'], hashlib.sha512).hexdigest()
        event_id = None
        if len(signatures) == 1 and signatures[0].isascii() and hmac.compare_digest(signatures[0].lower(), expected):
            try:
                parsed = strict_json(request['body'])
                candidate = parsed.get('notificationId') if isinstance(parsed, dict) else None
                if isinstance(candidate, str) and 0 < len(candidate) <= 128:
                    event_id = candidate
            except (ValueError, UnicodeError):
                pass
        return self._retain('authorizenet', request, headers, event_id)

    def retain(self, provider, request):
        if provider not in {'webmoney', 'klarna', 'wave', 'unknown'}:
            raise CustodyError('Unknown custody-only provider')
        return self._retain(provider, request, self._envelope(request), None)

    def _retain(self, provider, request, headers, event_id):
        self._check()
        digest = hashlib.sha256(request['body']).hexdigest()
        accepted = False
        try:
            self.db.execute('BEGIN IMMEDIATE')
            disposition = 'quarantined'
            if event_id is not None:
                row = self.db.execute('SELECT body_sha256 FROM events WHERE provider=? AND event_id=?',
                                      (provider, event_id)).fetchone()
                if row is None:
                    self.db.execute('INSERT INTO events VALUES (?,?,?)', (provider, event_id, digest))
                    disposition = 'received'
                    accepted = True
                elif row[0] == digest:
                    disposition = 'duplicate'
                    accepted = True
            self.db.execute('INSERT INTO arrivals(received_ns,provider,method,target,headers,body,body_sha256,event_id,disposition) VALUES (?,?,?,?,?,?,?,?,?)',
                            (str(time.time_ns()), provider, request['method'], request['target'], headers,
                             request['body'], digest, event_id, disposition))
            self.db.commit()
            self._sync()
        except (sqlite3.Error, OSError) as exc:
            self.db.rollback()
            raise CustodyError('Callback custody did not complete; do not acknowledge delivery') from exc
        return {'purpose': 'callback-custody-receipt', 'delivery_acknowledged': accepted,
                'financially_processed': False, 'event_sha256': digest}

    def counts(self):
        self._check()
        return {'arrivals': self.db.execute('SELECT COUNT(*) FROM arrivals').fetchone()[0],
                'events': self.db.execute('SELECT COUNT(*) FROM events').fetchone()[0],
                'quarantined': self.db.execute("SELECT COUNT(*) FROM arrivals WHERE disposition='quarantined'").fetchone()[0]}
