from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from fixtures import case, encode
try:
    import protocol as api
except ModuleNotFoundError as exc:
    if exc.name != 'protocol':
        raise
    api = None


class ProtocolTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(api, 'closed case protocol is not implemented')

    def test_fixed_qa_receipt_retains_original_reference_without_granting_acceptance(self):
        receipt = api.parse_case(encode(case()))
        self.assertEqual('source-payment-qa-v1', receipt.purpose)
        self.assertEqual('F1', receipt.case_id)
        self.assertEqual('c' * 64, receipt.evidence[0].sha256)
        self.assertFalse(hasattr(receipt, 'success') or hasattr(receipt, 'movement_id'))

    def test_every_defined_case_and_phase_is_preserved(self):
        for name in ['F' + str(n) for n in range(1, 6)] + ['M' + str(n) for n in range(1, 6)]:
            for phase in ['before', 'barrier', 'after', 'retired']:
                with self.subTest(case_id=name, phase=phase):
                    receipt = api.parse_case(encode(case(name, phase)))
                    self.assertEqual((name, phase), (receipt.case_id, receipt.phase))

    def test_unknown_financial_purpose_success_flags_and_dynamic_cases_refuse(self):
        for values in [dict(purpose='legacy-financial-receipt'), dict(success=True),
                       dict(commit_proof=True), dict(case_id='F6'), dict(phase='committed'),
                       dict(movement_id=1), dict(provider_validation=True)]:
            document = case()
            document.update(values)
            with self.subTest(fields=tuple(values)), self.assertRaises(api.AdmissionError):
                api.parse_case(encode(document))

    def test_missing_duplicate_and_unbounded_references_refuse(self):
        valid = case()
        for refs in [[], valid['evidence'] * 2, valid['evidence'] * 9]:
            with self.subTest(count=len(refs)), self.assertRaises(api.AdmissionError):
                api.parse_case(encode(dict(valid, evidence=refs)))
        with self.assertRaises(api.AdmissionError):
            api.parse_case(b'{' + b' ' * 16384 + b'}')

    def test_duplicate_keys_float_nan_and_non_utf8_refuse(self):
        for raw in [b'{"purpose":1,"purpose":2}', b'{"value":NaN}', b'{"value":1.2}', b'\xff']:
            with self.subTest(length=len(raw)), self.assertRaises(api.AdmissionError):
                api.parse_case(raw)


if __name__ == '__main__':
    unittest.main()
