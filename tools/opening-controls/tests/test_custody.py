import concurrent.futures
import hashlib
import hmac
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from custody import Custody, CustodyError


class CustodyTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name).resolve()
        self.key = bytes(range(64))  # Fictional signature key, never merchant credentials.
        self.body = b'{"notificationId":"fixture-event","eventType":"fixture","payload":{"id":"old-source-ref"}}'

    def tearDown(self):
        self.tmp.cleanup()

    def request(self, body=None):
        body = self.body if body is None else body
        digest = hmac.new(self.key, body, hashlib.sha512).hexdigest()
        return {'method': 'POST', 'target': '/legacy/callback?ref=fixture',
                'headers': [['X-ANET-Signature', 'sha512=' + digest], ['Host', 'fixture.invalid']], 'body': body}

    def test_retains_exact_request_before_ack_and_survives_restart(self):
        store = Custody(self.root)
        receipt = store.authorizenet(self.request(), self.key)
        self.assertTrue(receipt['delivery_acknowledged'])
        self.assertFalse(receipt['financially_processed'])
        store.close()
        again = Custody(self.root)
        self.assertEqual(again.counts(), {'arrivals': 1, 'events': 1, 'quarantined': 0})
        row = again.db.execute('SELECT body,headers,target FROM arrivals').fetchone()
        self.assertEqual(row[0], self.body)
        self.assertEqual(json.loads(row[1]), self.request()['headers'])
        self.assertEqual(row[2], self.request()['target'])
        self.assertEqual(os.stat(self.root / 'custody.sqlite').st_mode & 0o777, 0o600)
        again.close()

    def test_duplicate_retains_both_arrivals_one_event(self):
        with Custody(self.root) as store:
            first = store.authorizenet(self.request(), self.key)
            duplicate = store.authorizenet(self.request(), self.key)
            self.assertEqual(first['event_sha256'], duplicate['event_sha256'])
            self.assertTrue(duplicate['delivery_acknowledged'])
            self.assertEqual(store.counts(), {'arrivals': 2, 'events': 1, 'quarantined': 0})

    def test_conflicting_event_is_retained_but_not_acknowledged(self):
        with Custody(self.root) as store:
            store.authorizenet(self.request(), self.key)
            changed = self.body.replace(b'"fixture"', b'"other"')
            conflict = store.authorizenet(self.request(changed), self.key)
            self.assertFalse(conflict['delivery_acknowledged'])
            self.assertEqual(store.counts(), {'arrivals': 2, 'events': 1, 'quarantined': 1})

    def test_invalid_signature_and_duplicate_json_are_quarantined(self):
        with Custody(self.root) as store:
            request = self.request()
            request['headers'][0][1] = 'sha512=' + '0' * 128
            self.assertFalse(store.authorizenet(request, self.key)['delivery_acknowledged'])
            duplicate = b'{"notificationId":"a","notificationId":"b"}'
            self.assertFalse(store.authorizenet(self.request(duplicate), self.key)['delivery_acknowledged'])
            self.assertEqual(store.counts(), {'arrivals': 2, 'events': 0, 'quarantined': 2})

    def test_non_ascii_signature_is_retained_as_invalid_authentication(self):
        with Custody(self.root) as store:
            request = self.request()
            request['headers'][0][1] = 'sha512=é'
            self.assertFalse(store.authorizenet(request, self.key)['delivery_acknowledged'])
            self.assertEqual(store.counts(), {'arrivals': 1, 'events': 0, 'quarantined': 1})

    def test_other_provider_has_custody_without_delivery_ack(self):
        with Custody(self.root) as store:
            for name in ('webmoney', 'klarna', 'wave', 'unknown'):
                receipt = store.retain(name, self.request())
                self.assertFalse(receipt['delivery_acknowledged'])
                self.assertFalse(receipt['financially_processed'])
            self.assertEqual(store.counts()['events'], 0)

    def test_storage_failure_and_fsync_failure_never_return_ack(self):
        with Custody(self.root) as store:
            with patch.object(store, '_sync', side_effect=OSError('fixture disk failure')):
                with self.assertRaises(CustodyError):
                    store.authorizenet(self.request(), self.key)
            self.assertEqual(store.counts()['events'], 1)
            self.assertTrue(store.authorizenet(self.request(), self.key)['delivery_acknowledged'])
            store.db.execute('PRAGMA query_only=ON')
            with self.assertRaises(CustodyError):
                store.authorizenet(self.request(), self.key)

    def test_insecure_paths_links_and_db_replacement_are_rejected(self):
        self.root.chmod(0o755)
        with self.assertRaises(CustodyError):
            Custody(self.root)
        self.root.chmod(0o700)
        with Custody(self.root) as store:
            os.link(self.root / 'custody.sqlite', self.root / 'linked')
            with self.assertRaises(CustodyError):
                store.authorizenet(self.request(), self.key)
            (self.root / 'linked').unlink()
            (self.root / 'custody.sqlite').rename(self.root / 'old')
            (self.root / 'custody.sqlite').touch(mode=0o600)
            with self.assertRaises(CustodyError):
                store.authorizenet(self.request(), self.key)
        alias = self.root / 'alias'
        alias.symlink_to(self.root, target_is_directory=True)
        with self.assertRaises(CustodyError):
            Custody(alias)

    def test_concurrent_duplicate_deliveries_remain_one_event(self):
        def deliver(_):
            with Custody(self.root) as store:
                return store.authorizenet(self.request(), self.key)['delivery_acknowledged']
        with Custody(self.root):
            pass
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            self.assertTrue(all(pool.map(deliver, range(12))))
        with Custody(self.root) as store:
            self.assertEqual(store.counts(), {'arrivals': 12, 'events': 1, 'quarantined': 0})

    def test_malformed_or_oversized_envelope_is_rejected(self):
        with Custody(self.root) as store:
            for change in ({'method': 'GET'}, {'body': b'x' * (1048576 + 1)},
                           {'headers': [['X', 'a\nb']]}, {'extra': 'unknown'}):
                request = self.request() | change
                with self.assertRaises(CustodyError):
                    store.authorizenet(request, self.key)


if __name__ == '__main__':
    unittest.main()
