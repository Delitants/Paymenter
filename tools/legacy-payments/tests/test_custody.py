from concurrent.futures import ThreadPoolExecutor
import errno
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from custody import Custody, CustodyError, read_frame
from io import BytesIO


def arrival(method=b'POST', target=b'/result?merchant=one%2Btwo',
            headers=b'X-Trace: one\r\nx-trace: two\r\n\r\n', body=b'a=one%2Btwo&z=%FF\n'):
    parts = (method, target, headers, body)
    counts = ''.join('%d\n' % len(part) for part in parts).encode('ascii')
    return b'PAYMENTER-LEGACY-CUSTODY-V1\n' + counts + b''.join(parts)


class CustodyTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name).resolve() / 'receipts'
        self.root.mkdir(mode=0o700)
        self.store = Custody(str(self.root), max_records=16, max_bytes=8 * 1024**2, reserve_bytes=4096)

    def test_exact_original_and_repeated_arrivals_are_retained_without_provider_ack(self):
        frame = arrival()
        first = self.store.retain('webmoney', read_frame(BytesIO(frame)))
        second = self.store.retain('webmoney', frame)
        self.assertEqual((first['sequence'], second['sequence']), (1, 2))
        self.assertEqual(first['raw_sha256'], second['raw_sha256'])
        self.assertNotEqual(first['receipt_sha256'], second['receipt_sha256'])
        self.assertFalse(first['delivery_acknowledged'])
        self.assertFalse(second['delivery_acknowledged'])
        reference, original = self.store.read(1)
        self.assertEqual(reference, first)
        self.assertEqual(original, frame)
        self.assertEqual(self.store.verify(2, second['receipt_sha256'])['sequence'], 2)

    def test_invalid_framing_fails_before_any_write(self):
        for wire in (arrival() + b'x', arrival()[:-1], arrival(method=b'POST\n'),
                     arrival(target=b'without-leading-slash'), arrival(body=b'x' * (1 + 1024**2))):
            with self.assertRaises(CustodyError):
                self.store.retain('webmoney', wire)
        self.assertEqual(list(self.root.iterdir()), [])

    def test_sync_failure_retains_no_success_and_blocks_new_writes_until_reviewed(self):
        sync = os.fsync

        def fail_file(descriptor):
            if os.fstat(descriptor).st_ino != self.root.stat().st_ino:
                raise OSError(errno.EIO, 'fixture disk sync failure')
            return sync(descriptor)

        with patch('custody.os.fsync', side_effect=fail_file):
            with self.assertRaises(CustodyError):
                self.store.retain('webmoney', arrival())
        remaining = list(self.root.iterdir())
        self.assertEqual(len(remaining), 1)
        self.assertTrue(remaining[0].name.startswith('.pending-'))
        self.assertGreater(remaining[0].stat().st_size, 0)
        with self.assertRaises(CustodyError):
            self.store.retain('webmoney', arrival())
        self.assertEqual(list(self.root.iterdir()), remaining)

    def test_record_budget_and_filesystem_reserve_refuse_without_new_file(self):
        limited = Custody(str(self.root), max_records=1, max_bytes=1024**2, reserve_bytes=4096)
        limited.retain('webmoney', arrival())
        with self.assertRaises(CustodyError):
            limited.retain('webmoney', arrival())
        before = list(self.root.iterdir())
        with patch('custody.os.fstatvfs') as statvfs:
            statvfs.return_value.f_frsize = 4096
            statvfs.return_value.f_bsize = 4096
            statvfs.return_value.f_bavail = 1
            with self.assertRaises(CustodyError):
                self.store.retain('webmoney', arrival())
        self.assertEqual(list(self.root.iterdir()), before)

    def test_sequence_preserves_concurrent_callbacks(self):
        with ThreadPoolExecutor(max_workers=4) as workers:
            results = list(workers.map(lambda n: self.store.retain('webmoney', arrival(body=str(n).encode())), range(8)))
        self.assertEqual(sorted(item['sequence'] for item in results), list(range(1, 9)))
        self.assertEqual(self.store.verify()['sequence'], 8)

    def test_tampering_fails_verification_and_admission(self):
        self.store.retain('webmoney', arrival())
        second = self.store.retain('webmoney', arrival())
        first_path, second_path = sorted(self.root.iterdir())
        second_path.unlink()
        with self.assertRaises(CustodyError):
            self.store.verify(2, second['receipt_sha256'])
        original = first_path.read_bytes()
        first_path.write_bytes(original[:-1] + b'X')
        with self.assertRaises(CustodyError):
            self.store.retain('webmoney', arrival())

    def test_hard_link_and_symlink_cannot_be_used_as_store_records(self):
        self.store.retain('webmoney', arrival())
        original, = list(self.root.iterdir())
        other = self.root.parent / 'other'
        other.hardlink_to(original)
        with self.assertRaises(CustodyError):
            self.store.verify()
        other.unlink()
        original.unlink()
        original.symlink_to(other)
        with self.assertRaises(CustodyError):
            self.store.verify()


if __name__ == '__main__':
    unittest.main()
