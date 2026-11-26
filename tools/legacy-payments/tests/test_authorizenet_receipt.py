from hashlib import sha512
import hmac
from io import BytesIO
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from authorizenet_receipt import authenticate, notification
from custody import Custody, CustodyError
from webmoney_ingress import read_request


CALLBACK = b'/fixture/payment-hook'
WEBHOOK_ID = '35642262-3186-4522-9a02-cc3556d231fb'
NOTIFICATION_ID = '710f3e3c-e002-4203-bd4f-40166130fc49'
SIGNATURE_KEY = b'A1' * 64


def event(ref='8127', kind='net.authorize.payment.authcapture.created'):
    return {'notificationId': NOTIFICATION_ID, 'eventType': kind, 'eventDate': '2026-01-02T03:04:05.127',
            'webhookId': WEBHOOK_ID, 'payload': {'entityName': 'transaction', 'id': '12345678901',
                                                'merchantReferenceId': ref, 'authAmount': 15.50}}


def signed_frame(body, path=CALLBACK, signed_body=None, extra_headers=b''):
    signature = hmac.new(SIGNATURE_KEY, body if signed_body is None else signed_body, sha512).hexdigest()
    headers = (b'Content-Type: Application/JSON; charset=utf-8\r\n'
               b'X-ANET-Signature: SHA512=' + signature.upper().encode('ascii') + b'\r\n'
               + extra_headers + b'Content-Length: ' + str(len(body)).encode() + b'\r\n\r\n')
    request = b'POST ' + path + b' HTTP/1.1\r\n' + headers + body
    return read_request(BytesIO(request), path.partition(b'?')[0])[0]


class AuthorizeNetReceiptTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name).resolve() / 'receipts'
        root.mkdir(mode=0o700)
        self.store = Custody(str(root), 8, 1024*1024, 4096)

    def test_identifies_a_retained_signed_event_without_establishing_settlement(self):
        raw = json.dumps(event(), indent=2).encode()
        frame = signed_frame(raw)
        original = self.store.retain('authorizenet', frame)
        observed = authenticate(self.store, 1, CALLBACK, WEBHOOK_ID, SIGNATURE_KEY)
        self.assertEqual(observed['merchant_reference'], '8127')
        self.assertEqual(observed['provider_transaction'], '12345678901')
        self.assertEqual(observed['notification_id'], NOTIFICATION_ID)
        self.assertEqual(observed['receipt_sha256'], original['receipt_sha256'])
        self.assertFalse(observed['financially_processed'])
        self.assertEqual(self.store.read(1)[1], frame)

    def test_refunds_and_events_without_a_reference_still_have_a_verified_identity(self):
        body = event(None, 'net.authorize.payment.refund.created')
        del body['payload']['merchantReferenceId']
        verified = notification(signed_frame(json.dumps(body).encode()), CALLBACK, WEBHOOK_ID, SIGNATURE_KEY)
        self.assertEqual(verified['event_type'], 'net.authorize.payment.refund.created')
        self.assertIsNone(verified['merchant_reference'])

    def test_modified_body_or_wrong_subscription_is_rejected(self):
        raw = json.dumps(event()).encode()
        with self.assertRaises(CustodyError):
            notification(signed_frame(raw+b' ', signed_body=raw), CALLBACK, WEBHOOK_ID, SIGNATURE_KEY)
        with self.assertRaises(CustodyError):
            notification(signed_frame(raw), CALLBACK, NOTIFICATION_ID, SIGNATURE_KEY)

    def test_duplicate_signature_and_duplicate_json_field_are_rejected(self):
        raw = json.dumps(event()).encode()
        signature = hmac.new(SIGNATURE_KEY, raw, sha512).hexdigest().encode('ascii')
        with self.assertRaises(CustodyError):
            notification(signed_frame(raw, extra_headers=b'x-anet-signature: sha512='+signature+b'\r\n'),
                         CALLBACK, WEBHOOK_ID, SIGNATURE_KEY)
        duplicate = raw.replace(b'"merchantReferenceId": "8127"', b'"merchantReferenceId": "8127", "merchantReferenceId": "9000"')
        self.assertNotEqual(raw, duplicate)
        with self.assertRaises(CustodyError):
            notification(signed_frame(duplicate), CALLBACK, WEBHOOK_ID, SIGNATURE_KEY)

    def test_route_provider_and_type_must_match(self):
        raw = json.dumps(event()).encode()
        frame = signed_frame(raw, CALLBACK+b'?other=1')
        with self.assertRaises(CustodyError):
            notification(frame, CALLBACK, WEBHOOK_ID, SIGNATURE_KEY)
        self.store.retain('webmoney', signed_frame(raw))
        with self.assertRaises(CustodyError):
            authenticate(self.store, 1, CALLBACK, WEBHOOK_ID, SIGNATURE_KEY)
        malformed = event()
        malformed['payload']['id'] = 12345678901
        with self.assertRaises(CustodyError):
            notification(signed_frame(json.dumps(malformed).encode()), CALLBACK, WEBHOOK_ID, SIGNATURE_KEY)


if __name__ == '__main__':
    unittest.main()
