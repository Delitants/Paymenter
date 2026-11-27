from io import BytesIO
from hashlib import sha256
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from custody import Custody, decode_frame
from dispatch import DispatchError
from webmoney_ingress import serve
from webmoney_receipt import SIGNED_FIELDS


PATH = b'/mancgi/wmresult'
PURSE = b'Z000000000001'
SECRET = b'fictional-webmoney-merchant-key'
REFERENCE = b'100000000000123'


def request(form, suffix=b''):
    headers = b'Content-Type: application/x-www-form-urlencoded\r\n'
    headers += b'X-Trace: first\r\nx-trace: second\r\n'
    headers += b'Content-Length: ' + str(len(form)).encode() + b'\r\n\r\n'
    wire = b'POST ' + PATH + suffix + b' HTTP/1.1\r\n' + headers + form
    return wire, headers


def paymenter_fields():
    return dict(zip(SIGNED_FIELDS, (PURSE, b'1.50', REFERENCE, b'0', b'101',
        b'102', b'20261001 10:20:30', b'Z000000000002', b'000000000003')))


def signed_result(fields):
    parts = b''.join(fields[key] for key in SIGNED_FIELDS[:7]) + SECRET
    parts += b''.join(fields[key] for key in SIGNED_FIELDS[7:])
    values = dict(fields)
    values[b'LMI_HASH'] = sha256(parts).hexdigest().encode('ascii')
    return b'&'.join(key+b'='+value.replace(b' ',b'+') for key,value in values.items())


class WebMoneyIngressTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name).resolve() / 'receipts'
        root.mkdir(mode=0o700)
        self.store = Custody(str(root), 2, 1024*1024, 4096)
        self.looked_up = []

    def run_gate(self, wire, decision, **routing):
        def lookup(payment):
            self.looked_up.append(payment)
            if isinstance(decision, Exception):
                raise decision
            return decision

        response = BytesIO()
        self.disposition = serve(BytesIO(wire), response, PATH, lookup, self.store, **routing)
        return response.getvalue()

    def test_native_result_is_handled_by_nginx_with_the_original_request(self):
        wire, _ = request(b'LMI_PAYMENT_NO=812')
        reply = self.run_gate(wire, 'native')
        self.assertIn(b'X-Accel-Redirect: @paymenter_legacy_webmoney_native\r\n', reply)
        self.assertEqual(self.looked_up, [812])
        self.assertEqual(self.store.verify()['sequence'], 0)

    def test_retired_result_is_retained_before_the_failure_response(self):
        body = b'LMI_PAYMENT_NO=812&LMI_PAYMENT_AMOUNT=1.50&extra=a%2Bb'
        wire, headers = request(body, b'?project=fixture')
        reply = self.run_gate(wire, 'retired')
        self.assertTrue(reply.startswith(b'HTTP/1.1 503 '))
        self.assertEqual(self.disposition, 'retained')
        self.assertNotIn(b'X-Accel-Redirect', reply)
        receipt, original = self.store.read(1)
        self.assertEqual(decode_frame(original), (b'POST', PATH + b'?project=fixture', headers, body))
        self.assertFalse(receipt['delivery_acknowledged'])
        self.assertEqual(self.looked_up, [812])

    def test_retired_prerequest_does_not_receive_webmoney_permission(self):
        wire, _ = request(b'LMI_PAYMENT_NO=812&LMI_PREREQUEST=1')
        reply = self.run_gate(wire, 'retired')
        self.assertTrue(reply.startswith(b'HTTP/1.1 503 '))
        self.assertNotIn(b'YES', reply)
        self.assertEqual(self.store.verify()['sequence'], 1)

    def test_retry_preserves_each_original_and_refuses_when_custody_is_full(self):
        wire, _ = request(b'LMI_PAYMENT_NO=812&LMI_PAYMENT_AMOUNT=1.50')
        for _ in range(2):
            self.assertTrue(self.run_gate(wire, 'retired').startswith(b'HTTP/1.1 503 '))
        head = self.store.verify()
        self.assertEqual(head['sequence'], 2)
        self.assertTrue(self.run_gate(wire, 'retired').startswith(b'HTTP/1.1 503 '))
        self.assertEqual(self.disposition, 'custody_unavailable')
        self.assertEqual(self.store.verify(), head)

    def test_empty_prerequest_uses_the_database_arm_state(self):
        wire, _ = request(b'')
        self.assertIn(b'X-Accel-Redirect', self.run_gate(wire, 'native'))
        self.assertNotIn(b'X-Accel-Redirect', self.run_gate(wire, 'unknown'))
        self.assertEqual(self.looked_up, [0, 0])
        self.assertEqual(self.store.verify()['sequence'], 0)

    def test_lookup_failure_or_unknown_owner_cannot_reach_the_native_handler(self):
        wire, _ = request(b'LMI_PAYMENT_NO=812')
        for result, status in (('unknown', 'unclassified'),
                               (DispatchError('fixture connection closed'), 'lookup_unavailable')):
            reply = self.run_gate(wire, result)
            self.assertTrue(reply.startswith(b'HTTP/1.1 503 '))
            self.assertEqual(self.disposition, status)
        self.assertEqual(self.store.verify()['sequence'], 0)

    def test_truncated_receipt_and_different_route_do_not_enter_the_database(self):
        wire, _ = request(b'LMI_PAYMENT_NO=812')
        for form in (wire[:-1], wire.replace(PATH, b'/mancgi/another-result')):
            self.assertTrue(self.run_gate(form, 'native').startswith(b'HTTP/1.1 503 '))
        self.assertEqual(self.looked_up, [])
        self.assertEqual(self.store.verify()['sequence'], 0)

    def test_destination_prerequest_never_uses_the_source_account_lookup(self):
        body = b'LMI_PAYEE_PURSE='+PURSE+b'&LMI_PAYMENT_AMOUNT=1.50&LMI_PAYMENT_NO='+REFERENCE+b'&LMI_MODE=0&LMI_PREREQUEST=1'
        wire, _ = request(body)
        reply = self.run_gate(wire, 'native', paymenter=(PURSE, SECRET))
        self.assertIn(b'X-Accel-Redirect: @paymenter_webmoney_target\r\n', reply)
        self.assertEqual(self.disposition, 'paymenter_prerequest')
        self.assertEqual(self.looked_up, [])
        self.assertEqual(self.store.verify()['sequence'], 0)

    def test_signed_destination_result_is_retained_before_forwarding(self):
        body = signed_result(paymenter_fields())
        wire, headers = request(body)
        reply = self.run_gate(wire, 'unknown', paymenter=(PURSE, SECRET))
        self.assertIn(b'X-Accel-Redirect: @paymenter_webmoney_target\r\n', reply)
        self.assertEqual(self.disposition, 'retained_paymenter')
        receipt, original = self.store.read(1)
        self.assertEqual(decode_frame(original), (b'POST', PATH, headers, body))
        self.assertFalse(receipt['delivery_acknowledged'])
        self.assertEqual(self.looked_up, [])

    def test_an_invalid_destination_result_cannot_fill_custody_or_reach_either_handler(self):
        body = signed_result(paymenter_fields())
        for form, suffix in ((body.replace(b'1.50',b'2.00'),b''), (body,b'?unexpected=1')):
            wire, _ = request(form, suffix)
            self.assertTrue(self.run_gate(wire, 'native', paymenter=(PURSE, SECRET)).startswith(b'HTTP/1.1 503 '))
        self.assertEqual(self.looked_up, [])
        self.assertEqual(self.store.verify()['sequence'], 0)


if __name__ == '__main__':
    unittest.main()
