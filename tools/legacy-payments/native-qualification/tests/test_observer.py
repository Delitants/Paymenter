"""Fictional observation documents test decoding, never native visibility."""
from dataclasses import replace
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from fixtures import encode, RUN, BOOT, RUNTIME
from admission import AdmittedRun
try:
    import observer as api
except ModuleNotFoundError as exc:
    if exc.name != 'observer':
        raise
    api = None


def observation():
    return dict(purpose='source-payment-qa-observation-v1', run_id=RUN,
                runtime_id=RUNTIME, boot_id=BOOT, request_id='b' * 64,
                case_id='F1', phase='after', started_at='2026-10-18T00:00:01Z',
                finished_at='2026-10-18T00:00:02Z', reader_connection='12',
                writer_connection='11', isolation='READ COMMITTED', autocommit=True,
                transaction_open=False, business_rows=[dict(run_id=RUN, case_id='F1',
                amount='0.0100', facts_hash='c' * 64)], journal_rows=[dict(run_id=RUN,
                case_id='F1', business_case_id='F1', facts_hash='c' * 64)],
                payment_rows=[], subaccount_rows=[])


class ObserverTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(api, 'observation decoder is not implemented')
        self.expected = api.ReadExpectation(RUN, RUNTIME, BOOT, 'b' * 64, 'F1',
                'after', '11', 'c' * 64, '2026-10-18T00:00:00Z',
                '2026-10-18T00:00:30Z', ('10',))

    def test_decoding_preserves_rows_but_grants_no_native_acceptance(self):
        result = api.decode_observation(encode(observation()), self.expected)
        self.assertEqual(('12', '11'), (result.reader_connection, result.writer_connection))
        self.assertEqual('0.0100', result.business_rows[0].amount)
        self.assertEqual('F1', result.journal_rows[0].business_case_id)
        self.assertFalse(hasattr(result, 'framework_atomicity') or hasattr(result, 'committed'))
        with self.assertRaises(AttributeError):
            result.business_rows[0].amount = '2.0000'

    def test_dirty_repeatable_and_active_transaction_reads_refuse(self):
        for fields in [dict(isolation='READ UNCOMMITTED'), dict(isolation='REPEATABLE READ'),
                       dict(autocommit=False), dict(transaction_open=True),
                       dict(autocommit=1), dict(transaction_open=0)]:
            with self.subTest(fields=fields), self.assertRaises(api.AdmissionError):
                api.decode_observation(encode(dict(observation(), **fields)), self.expected)

    def test_writer_connection_and_previously_used_reader_refuse(self):
        for identity in ['11', '10', '0', '012', '12\n', 12, '1' * 21]:
            with self.subTest(identity=identity), self.assertRaises(api.AdmissionError):
                api.decode_observation(encode(dict(observation(), reader_connection=identity)), self.expected)

    def test_replayed_cross_runtime_or_cross_case_response_refuses(self):
        for fields in [dict(request_id='d' * 64), dict(run_id='d' * 64),
                       dict(runtime_id='different'), dict(boot_id='different'),
                       dict(case_id='F2'), dict(phase='barrier'), dict(writer_connection='15')]:
            with self.subTest(fields=fields), self.assertRaises(api.AdmissionError):
                api.decode_observation(encode(dict(observation(), **fields)), self.expected)

    def test_stale_future_reversed_and_over_deadline_windows_refuse(self):
        for start, finish in [('2026-10-17T23:59:59Z', '2026-10-18T00:00:02Z'),
                              ('2026-10-18T00:00:02Z', '2026-10-18T00:00:01Z'),
                              ('2026-10-18T00:00:01Z', '2026-10-18T00:00:31Z')]:
            with self.subTest(start=start), self.assertRaises(api.AdmissionError):
                api.decode_observation(encode(dict(observation(), started_at=start,
                                                  finished_at=finish)), self.expected)
        with self.assertRaises(api.AdmissionError):
            replace(self.expected, deadline='2026-10-18T00:00:31Z')

    def test_wrong_row_binding_amount_hash_and_duplicate_rows_refuse(self):
        for slot, fields in [('business_rows', dict(amount='0.02')),
                             ('business_rows', dict(run_id='d' * 64)),
                             ('business_rows', dict(facts_hash='d' * 64)),
                             ('journal_rows', dict(business_case_id='F2')),
                             ('journal_rows', dict(case_id='F2'))]:
            value = observation()
            value[slot][0].update(fields)
            with self.subTest(slot=slot, fields=fields), self.assertRaises(api.AdmissionError):
                api.decode_observation(encode(value), self.expected)
        value = observation()
        value['business_rows'] *= 2
        with self.assertRaises(api.AdmissionError):
            api.decode_observation(encode(value), self.expected)

    def test_split_and_absent_outcomes_preserved_for_later_failure_analysis(self):
        for business, journal in [(0, 0), (1, 0), (0, 1)]:
            value = observation()
            value['business_rows'] *= business
            value['journal_rows'] *= journal
            result = api.decode_observation(encode(value), self.expected)
            self.assertEqual((business, journal), (len(result.business_rows), len(result.journal_rows)))

    def test_native_row_guesses_unknown_fields_and_malformed_documents_refuse(self):
        for fields in [dict(payment_rows=[dict(id='123')]), dict(subaccount_rows=[dict(id='2')]),
                       dict(committed=True), dict(purpose='payment')]:
            with self.subTest(fields=fields), self.assertRaises(api.AdmissionError):
                api.decode_observation(encode(dict(observation(), **fields)), self.expected)
        for raw in [b'\xff', b'{"a":1,"a":2}', b'{', b'{}' * 10000]:
            with self.subTest(length=len(raw)), self.assertRaises(api.AdmissionError):
                api.decode_observation(raw, self.expected)
        with self.assertRaises(api.AdmissionError):
            replace(self.expected, case_id='M1')

    def test_read_refuses_even_a_constructor_bypassed_capability_type(self):
        for alleged_run in [self.expected, None, object.__new__(AdmittedRun)]:
            with self.assertRaisesRegex(api.AdmissionError, '^native-issuer-unavailable$'):
                api.CommittedObserver().read(alleged_run, 'F1', 'after')


if __name__ == '__main__':
    unittest.main()
