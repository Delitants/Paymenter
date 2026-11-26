"""Retain exact legacy callback arrivals on the source without acknowledging them."""

import argparse
from contextlib import contextmanager
import fcntl
import json
import os
import re
import stat
import sys
import time

from dispatch import DispatchError, INTEGER_TYPES, canonical, digest, parse_document, token


VERSION = b'PAYMENTER-LEGACY-CUSTODY-V1'
PART_LIMITS = (16, 8192, 65536, 1048576)
FRAME_LIMIT = len(VERSION) + 33 + sum(PART_LIMITS)
METADATA_LIMIT = 1024
RECORD_LIMIT = FRAME_LIMIT + METADATA_LIMIT + 1
GENESIS = '0' * 64
RECORD_NAME = re.compile(r'([0-9]{9})\.receipt\Z')


class CustodyError(RuntimeError):
    pass


def require(condition, description):
    if not condition:
        raise CustodyError(description)


def decode_frame(raw):
    require(isinstance(raw, bytes) and len(raw) <= FRAME_LIMIT, 'invalid original frame size')
    fields = raw.split(b'\n', 5)
    require(len(fields) == 6 and fields[0] == VERSION, 'invalid original frame')
    require(all(re.match(br'(0|[1-9][0-9]{0,6})\Z', field) for field in fields[1:5]), 'invalid frame lengths')
    sizes = list(map(int, fields[1:5]))
    require(all(size <= limit for size, limit in zip(sizes, PART_LIMITS)) and sum(sizes) == len(fields[5]),
            'original frame lengths differ')
    payload = fields[-1]
    method_end, target_end, headers_end = sizes[0], sum(sizes[:2]), sum(sizes[:3])
    method, target = payload[:method_end], payload[method_end:target_end]
    require(re.match(br'[A-Z][A-Z0-9_-]{0,15}\Z', method) is not None and target.startswith(b'/')
            and not re.search(br'[\x00-\x20\x7f]', target), 'original method or request target is invalid')
    return method, target, payload[target_end:headers_end], payload[headers_end:]


def read_frame(stream):
    raw = stream.read(FRAME_LIMIT + 1)
    decode_frame(raw)
    return raw


def file_identity(info):
    return (info.st_dev, info.st_ino, info.st_uid, info.st_mode, info.st_nlink,
            info.st_size, info.st_mtime, info.st_ctime)


class Custody(object):
    def __init__(self, root, max_records, max_bytes, reserve_bytes):
        require(os.path.isabs(root) and root == os.path.normpath(root), 'custody root must be an absolute directory')
        require(type(max_records) in INTEGER_TYPES and 0 < max_records < 10 ** 9 and
                all(type(value) in INTEGER_TYPES and value > 0 for value in (max_bytes, reserve_bytes)),
                'explicit custody limits are required')
        self.root = root
        self.max_records = max_records
        self.max_bytes = max_bytes
        self.reserve_bytes = reserve_bytes

    def _check_root(self, descriptor):
        actual = os.fstat(descriptor)
        path_info = os.lstat(self.root)
        require(stat.S_ISDIR(actual.st_mode) and actual.st_uid == os.geteuid() and
                stat.S_IMODE(actual.st_mode) == 0o700 and os.path.realpath(self.root) == self.root and
                (actual.st_dev, actual.st_ino) == (path_info.st_dev, path_info.st_ino), 'custody root identity differs')
        parent = os.path.dirname(self.root)
        while True:
            info = os.lstat(parent)
            require(stat.S_ISDIR(info.st_mode) and info.st_uid in (0, os.geteuid()) and
                    (not info.st_mode & 0o022 or bool(info.st_mode & stat.S_ISVTX)), 'unsafe custody ancestor')
            if parent == os.path.dirname(parent):
                return
            parent = os.path.dirname(parent)

    @contextmanager
    def _lock(self, exclusive):
        try:
            descriptor = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX if exclusive else fcntl.LOCK_SH)
                self._check_root(descriptor)
                yield descriptor
                self._check_root(descriptor)
            finally:
                os.close(descriptor)
        except EnvironmentError:
            raise CustodyError('custody storage did not complete')

    def _load(self, name, sequence, previous, remaining_bytes):
        path = os.path.join(self.root, name)
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(descriptor, 'rb') as stream:
            info = os.fstat(stream.fileno())
            require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid() and
                    stat.S_IMODE(info.st_mode) == 0o600 and info.st_nlink == 1 and
                    0 < info.st_size <= min(RECORD_LIMIT, remaining_bytes), 'invalid custody record')
            raw = stream.read(info.st_size + 1)
            require(len(raw) == info.st_size and file_identity(info) == file_identity(os.fstat(stream.fileno())) ==
                    file_identity(os.lstat(path)), 'custody record changed during reading')
        metadata, separator, frame = raw.partition(b'\n')
        require(separator == b'\n', 'custody record is incomplete')
        try:
            value = parse_document(metadata, METADATA_LIMIT)
        except DispatchError:
            raise CustodyError('invalid custody metadata')
        require(set(value) == {'version', 'provider', 'sequence', 'previous_sha256', 'raw_sha256', 'received_at'} and
                type(value['version']) is int and value['version'] == 1 and type(value['sequence']) in INTEGER_TYPES and
                value['sequence'] == sequence and value['previous_sha256'] == previous and
                token(value['provider'], r'[a-z][a-z0-9_-]{0,63}') and
                token(value['received_at'], r'[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z') and
                value['raw_sha256'] == digest(frame), 'custody sequence or original bytes differ')
        decode_frame(frame)
        return self._receipt(value, raw), frame, len(raw)

    @staticmethod
    def _receipt(metadata, raw):
        return dict(sequence=metadata['sequence'], provider=metadata['provider'], received_at=metadata['received_at'],
                    raw_sha256=metadata['raw_sha256'],
                    receipt_sha256=digest(raw), delivery_acknowledged=False)

    def _scan(self, selected=None):
        names = sorted(os.listdir(self.root))
        require(len(names) <= self.max_records, 'custody record budget exceeded')
        last = dict(sequence=0, receipt_sha256=GENESIS)
        total = 0
        found = None
        for sequence, name in enumerate(names, 1):
            match = RECORD_NAME.match(name)
            require(match and int(match.group(1)) == sequence, 'unknown, incomplete or missing custody record')
            last, frame, length = self._load(name, sequence, last['receipt_sha256'], self.max_bytes - total)
            total += length
            if sequence == selected:
                found = last, frame
        return last, total, found

    def _available(self, descriptor, size):
        info = os.fstatvfs(descriptor)
        block = info.f_frsize or info.f_bsize
        require(block > 0, 'custody filesystem block size is unavailable')
        allocated = ((size + block - 1) // block + 2) * block
        return info.f_bavail * block >= self.reserve_bytes + allocated

    def retain(self, provider, frame):
        require(token(provider, r'[a-z][a-z0-9_-]{0,63}'), 'invalid custody provider label')
        decode_frame(frame)
        with self._lock(exclusive=True) as root_descriptor:
            last, used, _ = self._scan()
            sequence = last['sequence'] + 1
            metadata = dict(version=1, provider=provider, sequence=sequence, previous_sha256=last['receipt_sha256'],
                            raw_sha256=digest(frame), received_at=time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()))
            raw = canonical(metadata) + b'\n' + frame
            require(sequence <= self.max_records and used + len(raw) <= self.max_bytes and
                    self._available(root_descriptor, len(raw)), 'custody capacity is unavailable')
            name = '%09d.receipt' % sequence
            pending = os.path.join(self.root, '.pending-' + name)
            final = os.path.join(self.root, name)
            descriptor = os.open(pending, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            with os.fdopen(descriptor, 'wb') as stream:
                os.fchmod(stream.fileno(), 0o600)
                os.fsync(root_descriptor)
                stream.write(raw)
                stream.flush()
                os.fsync(stream.fileno())
            self._check_root(root_descriptor)
            os.link(pending, final)
            os.fsync(root_descriptor)
            os.unlink(pending)
            os.fsync(root_descriptor)
            return self._receipt(metadata, raw)

    def verify(self, expected_sequence=None, expected_sha256=None):
        with self._lock(exclusive=False):
            last, used, _ = self._scan()
            if expected_sequence is not None or expected_sha256 is not None:
                require(expected_sequence == last['sequence'] and expected_sha256 == last['receipt_sha256'],
                        'custody head differs from the expected receipt')
            return dict(sequence=last['sequence'], receipt_sha256=last['receipt_sha256'], stored_bytes=used,
                        delivery_acknowledged=False)

    def read(self, sequence):
        require(type(sequence) in INTEGER_TYPES and sequence > 0, 'a positive custody sequence is required')
        with self._lock(exclusive=False):
            _, _, original = self._scan(selected=sequence)
            require(original is not None, 'custody sequence is absent')
            return original


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=('retain', 'verify'))
    parser.add_argument('--root', required=True)
    parser.add_argument('--max-records', type=int, required=True)
    parser.add_argument('--max-bytes', type=int, required=True)
    parser.add_argument('--reserve-bytes', type=int, required=True)
    parser.add_argument('--provider')
    parser.add_argument('--expected-sequence', type=int)
    parser.add_argument('--expected-sha256')
    args = parser.parse_args(argv)
    os.umask(0o077)
    store = Custody(args.root, args.max_records, args.max_bytes, args.reserve_bytes)
    if args.operation == 'retain':
        require(args.provider is not None and args.expected_sequence is None and args.expected_sha256 is None,
                'a provider label is required when retaining an original')
        result = store.retain(args.provider, read_frame(getattr(sys.stdin, 'buffer', sys.stdin)))
    else:
        require(args.provider is None, 'verification does not accept a provider label')
        result = store.verify(args.expected_sequence, args.expected_sha256)
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv[1:]))
    except (CustodyError, EnvironmentError):
        sys.stderr.write('Legacy callback custody stopped.\n')
        sys.exit(1)
