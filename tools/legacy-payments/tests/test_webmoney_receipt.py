from hashlib import sha256
import os
from pathlib import Path
import sys
import tempfile
import unittest
from urllib.parse import urlencode

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from custody import Custody, CustodyError
from webmoney_receipt import authenticate


SECRET = b'fictional-native-fixture-key'
EXPECTED = dict(target=b'/mancgi/wmresult', purse=b'Z000000000001', payment=b'90011',
                amount=b'5.0000', source_account=b'901', source_subaccount=b'902')


def signed_fields(purse='Z000000000001', amount='5.00', payment='90011', mode='0'):
    values = {'LMI_PAYEE_PURSE': purse, 'LMI_PAYMENT_AMOUNT': amount, 'LMI_PAYMENT_NO': payment,
              'LMI_MODE': mode, 'LMI_SYS_INVS_NO': '117', 'LMI_SYS_TRANS_NO': '73321',
              'LMI_SYS_TRANS_DATE': '20261126 12:00:00', 'LMI_PAYER_PURSE': 'Z000000000020',
              'LMI_PAYER_WM': '000000000021'}
    signed = purse + amount + payment + mode + '1177332120261126 12:00:00'
    values['LMI_HASH'] = sha256(signed.encode() + SECRET + b'Z000000000020000000000021').hexdigest()
    return values


def form(values):
    return urlencode(values).encode('ascii')


def arrival(body, target=EXPECTED['target'], method=b'POST',
            headers=b'Content-Type: application/x-www-form-urlencoded\r\nX-Trace: first\r\nx-trace: second\r\n\r\n'):
    parts = (method, target, headers, body)
    counts = ''.join('%d\n' % len(part) for part in parts).encode('ascii')
    return b'PAYMENTER-LEGACY-CUSTODY-V1\n' + counts + b''.join(parts)


class WebMoneyReceiptTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        path = Path(temporary.name).resolve() / 'webmoney'
        path.mkdir(mode=0o700)
        self.store = Custody(str(path), 20, 1024**2, 4096)

    def validation(self, frame, expected=EXPECTED, secret=SECRET, provider='webmoney'):
        entry = self.store.retain(provider, frame)
        return authenticate(self.store, entry['sequence'], expected, secret)

    def test_signature_binds_original_bytes_and_frozen_source_owner_without_acknowledging(self):
        fields = signed_fields()
        fields['source_account'] = 'untrusted-form-owner'
        frame = arrival(form(fields))
        proof = self.validation(frame)
        self.assertEqual(proof['raw_sha256'], sha256(frame).hexdigest())
        self.assertEqual(proof['provider_reference'], '73321')
        self.assertEqual(proof['provider_amount'], '5.00')
        self.assertEqual(proof['provider_currency'], 'USD')
        self.assertEqual((proof['source_payment'], proof['source_account'], proof['source_subaccount']),
                         ('90011', '901', '902'))
        self.assertFalse(proof['delivery_acknowledged'])
        self.assertFalse(proof['financially_processed'])

    def test_repeat_validation_preserves_arrival_identities_and_original_effect_reference(self):
        frame = arrival(form(signed_fields()))
        first, second = self.validation(frame), self.validation(frame)
        self.assertEqual(first['provider_reference'], second['provider_reference'])
        self.assertEqual(first['raw_sha256'], second['raw_sha256'])
        self.assertNotEqual(first['receipt_sha256'], second['receipt_sha256'])
        self.assertEqual(self.store.verify()['sequence'], 2)

    def test_changed_signature_and_independently_signed_wrong_amount_or_payment_refuse(self):
        modified = signed_fields()
        modified['LMI_SYS_TRANS_NO'] = '73322'
        for fields in (modified, signed_fields(amount='6'), signed_fields(payment='90012'),
                       signed_fields(purse='Z000000000002')):
            with self.assertRaises(CustodyError):
                self.validation(arrival(form(fields)))
        with self.assertRaises(CustodyError):
            self.validation(arrival(form(signed_fields())), secret=b'other-fixture-secret')

    def test_prerequest_test_mode_holds_and_other_signature_format_do_not_admit(self):
        fields = signed_fields()
        for invalid in (dict(fields, LMI_PREREQUEST='1'), signed_fields(mode='1'),
                        dict(fields, LMI_HOLD='10'), dict(fields, LMI_HASH2='a'*64)):
            with self.assertRaises(CustodyError):
                self.validation(arrival(form(invalid)))

    def test_duplicate_form_keys_and_invalid_percent_escape_refuse(self):
        body = form(signed_fields())
        for invalid in (body+b'&%4cMI_PAYMENT_NO=90011', body+b'&other=%GG'):
            with self.assertRaises(CustodyError):
                self.validation(arrival(invalid))

    def test_other_method_target_label_and_ambiguous_content_type_refuse(self):
        body = form(signed_fields())
        for invalid in (arrival(body, target=b'/mancgi/anotherresult'), arrival(body, method=b'GET'),
                        arrival(body, headers=b'Content-Type: application/x-www-form-urlencoded\r\n'
                                              b'content-type: application/x-www-form-urlencoded\r\n\r\n')):
            with self.assertRaises(CustodyError):
                self.validation(invalid)
        with self.assertRaises(CustodyError):
            self.validation(arrival(body), provider='another-provider')

    def test_euro_purse_keeps_original_currency_and_amount(self):
        values = signed_fields(purse='E000000000001', amount='5.25')
        expected = dict(EXPECTED, purse=b'E000000000001', amount=b'5.2500')
        proof = self.validation(arrival(form(values)), expected)
        self.assertEqual((proof['provider_currency'], proof['provider_amount']), ('EUR', '5.25'))
        self.assertFalse(proof['financially_processed'])


if __name__ == '__main__':
    unittest.main()
