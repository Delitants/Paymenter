from hashlib import sha512
import hmac
from io import BytesIO
import json
from pathlib import Path
import tempfile
import unittest

from test_authorizenet_receipt import CALLBACK, SIGNATURE_KEY, WEBHOOK_ID, event

from authorizenet_ingress import serve
from custody import Custody, CustodyError, decode_frame


def request(payload, key=SIGNATURE_KEY, target=CALLBACK):
    signature = hmac.new(key, payload, sha512).hexdigest().encode('ascii')
    headers = (
        b'Content-Type: application/json\r\n'
        b'X-ANET-Signature: sha512=' + signature + b'\r\n'
        b'Content-Length: ' + str(len(payload)).encode('ascii') + b'\r\n\r\n'
    )
    return b'POST ' + target + b' HTTP/1.1\r\n' + headers + payload, headers


class AuthorizeNetIngressTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name).resolve() / 'receipts'
        root.mkdir(mode=0o700)
        self.store = Custody(str(root), 2, 1048576, 4096)

    def route(self, wire, custody=None):
        response = BytesIO()
        decision = serve(BytesIO(wire), response, CALLBACK, WEBHOOK_ID, SIGNATURE_KEY, custody or self.store)
        return decision, response.getvalue()

    def test_authenticated_arrival_is_durable_before_native_route(self):
        body = json.dumps(event(), indent=2).encode('utf-8')
        wire, headers = request(body)
        decision, response = self.route(wire)
        self.assertEqual(decision, 'retained_native')
        self.assertIn(b'X-Accel-Redirect: @paymenter_legacy_authorizenet_native\r\n', response)
        receipt, frame = self.store.read(1)
        self.assertEqual(receipt['provider'], 'authorizenet')
        self.assertEqual(decode_frame(frame), (b'POST', CALLBACK, headers, body))
        self.assertFalse(receipt['delivery_acknowledged'])

    def test_signed_refund_without_a_source_reference_is_retained(self):
        data = event(None, 'net.authorize.payment.refund.created')
        del data['payload']['merchantReferenceId']
        wire, _ = request(json.dumps(data).encode('utf-8'))
        decision, _ = self.route(wire)
        self.assertEqual(decision, 'retained_native')
        self.assertEqual(self.store.verify()['sequence'], 1)

    def test_capture_failure_does_not_allow_the_native_route_to_acknowledge(self):
        wire, _ = request(json.dumps(event()).encode('utf-8'))
        for _ in range(2):
            self.assertEqual(self.route(wire)[0], 'retained_native')
        end = self.store.verify()
        decision, response = self.route(wire)
        self.assertEqual(decision, 'custody_unavailable')
        self.assertTrue(response.startswith(b'HTTP/1.1 503 '))
        self.assertNotIn(b'X-Accel-Redirect', response)
        self.assertEqual(self.store.verify(), end)

    def test_other_format_or_subscription_uses_the_original_handler(self):
        data = event()
        data['webhookId'] = data['notificationId']
        for wire, _ in (request(b'not json'), request(json.dumps(data).encode('utf-8')),
                        request(json.dumps(event()).encode('utf-8'), b'B2' * 64)):
            decision, response = self.route(wire)
            self.assertEqual(decision, 'native')
            self.assertIn(b'X-Accel-Redirect:', response)
        self.assertEqual(self.store.verify()['sequence'], 0)

    def test_uncertain_write_fails_before_native_callback(self):
        class Unavailable:
            def retain(self, _, __):
                raise CustodyError('fixture write refused')

        wire, _ = request(json.dumps(event()).encode('utf-8'))
        decision, response = self.route(wire, Unavailable())
        self.assertEqual(decision, 'custody_unavailable')
        self.assertTrue(response.startswith(b'HTTP/1.1 503 '))

    def test_truncated_frame_and_wrong_route_fail_closed(self):
        wire, _ = request(json.dumps(event()).encode('utf-8'))
        for malformed in (wire[:-1], wire.replace(CALLBACK, b'/fixture/wrong-hook', 1)):
            decision, response = self.route(malformed)
            self.assertEqual(decision, 'request_refused')
            self.assertTrue(response.startswith(b'HTTP/1.1 503 '))
        self.assertEqual(self.store.verify()['sequence'], 0)
