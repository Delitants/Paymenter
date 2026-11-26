import copy
from datetime import datetime
from hashlib import sha256
import json
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from dispatch import DispatchError, MysqlStore, deliver


NOW = datetime(2026, 1, 1, 0, 5)
SIGNATURE = b'{"algorithm":"rsa-sha256","key_id":"source-key","schema_version":1,"signature_b64":"eA=="}'


def canonical(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'), sort_keys=True).encode('utf-8')


def observation():
    identity = dict(source_instance='source.fixture', merchant_binding='merchant', source_payment='42',
                    effect_kind='capture', economic_effect_id=u'payment-\u00e9')
    economic = dict(source_account='1', source_subaccount='2', parent_effect_key=None, wallet_currency='USD',
                    wallet_delta='1.0100', wallet_components=[dict(component_id='net', delta='1.0100')],
                    provider_currency='USD', provider_components=[dict(component_id='gross', amount='1.01')],
                    allocation_rule='fixture-net', allocation_evidence_sha256='e' * 64)
    body = dict(schema_version=1, purpose='legacy-payment-observation', issuer='source.fixture',
                not_before='2026-01-01T00:00:00Z', expires_at='2026-01-01T00:18:00Z', nonce='observation-1',
                identity=identity, economic=economic,
                observation=dict(custody_id='fixture-custody', journal_id='journal-1', raw_sha256='a' * 64,
                                 validator_sha256='a' * 64, validation_ref='fixture-validation', credential_version='fixture-key',
                                 commit_binding_ref='sha256:' + 'a' * 64))
    payload = canonical(body)
    return dict(id=1, effect_key=sha256(b'legacy-economic-effect-v1\0' + canonical(identity)).hexdigest(),
                economic_sha256=sha256(b'legacy-economic-facts-v1\0' + canonical(economic)).hexdigest(),
                payload_sha256=sha256(payload).hexdigest(), key_id='source-key', payload=payload,
                signature=None, attempts=0)


def response(row, disposition='stored_pending'):
    sig_hash = sha256(SIGNATURE).hexdigest()
    key = sha256(b'legacy-observation-v1\0' + (row['payload_sha256'] + sig_hash).encode('ascii')).hexdigest()
    return dict(disposition=disposition, effect_key=row['effect_key'], economic_sha256=row['economic_sha256'],
                observation_key=key, payload_sha256=row['payload_sha256'], signature_sha256=sig_hash,
                result_id=7 if disposition in ('included', 'applied', 'applied_zero') else None,
                result_observation_key=key if disposition in ('included', 'applied', 'applied_zero') else None,
                movement_id=8 if disposition == 'applied' else None,
                receipt_sha256='b' * 64 if disposition in ('included', 'applied', 'applied_zero') else None)


class Store:
    def __init__(self, row):
        self.row = row
        self.claimable = True
        self.can_complete = True
        self.events = []

    def reserve(self, row, signature, delay, attempt_limit):
        self.events.append(('reserve', delay, attempt_limit))
        if not self.claimable:
            return False
        self.row = dict(row, signature=signature, attempts=row['attempts'] + 1)
        return True

    def finish(self, row, attempt, state, acknowledgement):
        self.events.append(('finish', attempt, state, acknowledgement))
        return self.can_complete

    def hold(self, row, state):
        self.events.append(('hold', state))
        return self.claimable

    def defer(self, row, delay):
        self.events.append(('defer', delay))
        return self.claimable


class DispatchTests(unittest.TestCase):
    def setUp(self):
        self.row = observation()
        self.store = Store(self.row)
        self.frames = []
        self.signed = []
        self.signable = True

    def sign(self, payload, key_id):
        self.signed.append((payload, key_id))
        return SIGNATURE if self.signable else None

    def deliver(self, respond, now=NOW, row=None):
        selected = self.row if row is None else row

        def send(frame):
            self.assertEqual(self.store.events[-1][0], 'reserve')
            self.assertEqual(self.store.row['signature'], SIGNATURE)
            self.frames.append(frame)
            return respond()

        return deliver(self.store, selected, self.sign, send, now, 30, 5)

    def test_exact_signed_bytes_replayed_after_lost_reply(self):
        def lost():
            raise DispatchError('no verified response')

        self.assertEqual(self.deliver(lost), 'unknown')
        self.assertEqual(self.store.events, [('reserve', 30, 5)])
        self.assertEqual(self.store.row['attempts'], 1)
        after_restart = copy.deepcopy(self.store.row)
        self.store = Store(after_restart)
        ack = response(self.row, 'applied')
        self.assertEqual(self.deliver(lambda: canonical(ack) + b'\n', row=after_restart), 'applied')
        self.assertEqual(self.signed, [(self.row['payload'], self.row['key_id'])])
        self.assertEqual(self.frames[0], self.frames[1])
        self.assertEqual(self.store.events[-1], ('finish', 2, 'applied', canonical(ack) + b'\n'))

    def test_pending_response_kept_for_later_status_check(self):
        ack = response(self.row)
        self.assertEqual(self.deliver(lambda: canonical(ack) + b'\n'), 'stored_pending')
        self.assertEqual(self.store.events[-1][2], 'stored_pending')

    def test_different_effect_or_changed_signature_rejected(self):
        for field in ('effect_key', 'economic_sha256', 'signature_sha256', 'payload_sha256', 'observation_key'):
            ack = dict(response(self.row, 'applied'), **{field: 'c' * 64})
            self.assertEqual(self.deliver(lambda: canonical(ack) + b'\n'), 'unknown')
        self.assertTrue(all(event[0]=='reserve' for event in self.store.events))

    def test_invalid_terminal_result_and_extra_ack_line_refused(self):
        bad = dict(response(self.row, 'applied'), movement_id=None)
        self.assertEqual(self.deliver(lambda: canonical(bad) + b'\n'), 'unknown')
        good = canonical(response(self.row, 'applied'))
        self.assertEqual(self.deliver(lambda: good + b'\nextra\n'), 'unknown')
        self.assertTrue(all(event[0]=='reserve' for event in self.store.events))

    def test_quarantine_never_has_financial_result(self):
        bad = dict(response(self.row, 'quarantined'), result_id=7)
        self.assertEqual(self.deliver(lambda: canonical(bad) + b'\n'), 'unknown')
        ack = response(self.row, 'quarantined')
        self.assertEqual(self.deliver(lambda: canonical(ack) + b'\n'), 'quarantined')

    def test_expired_row_is_held_without_signing_or_delivery(self):
        self.assertEqual(self.deliver(self.fail, now=datetime(2026, 1, 1, 0, 18)), 'expired')
        self.assertEqual(self.store.events, [('hold', 'expired')])
        self.assertEqual(self.signed, [])
        self.assertEqual(self.frames, [])

    def test_expired_lost_reply_uses_read_only_frame_without_resigning(self):
        earlier = dict(self.row, signature=SIGNATURE, attempts=1)
        self.store = Store(earlier)
        ack = response(earlier, 'applied_zero')
        self.assertEqual(self.deliver(lambda: canonical(ack) + b'\n', datetime(2026, 1, 1, 0, 18), earlier), 'applied_zero')
        self.assertTrue(self.frames[0].startswith(b'PAYMENTER-LEGACY-STATUS-V1\n'))
        self.assertEqual(self.signed, [])

    def test_result_observation_id_remains_separate_from_delivery_id(self):
        ack = dict(response(self.row, 'applied'), result_observation_key='e' * 64)
        self.assertEqual(self.deliver(lambda: canonical(ack) + b'\n'), 'applied')
        ack.pop('result_observation_key')
        self.assertEqual(self.deliver(lambda: canonical(ack) + b'\n'), 'unknown')

    def test_wrong_source_digest_is_held_without_claim(self):
        row = dict(self.row, economic_sha256='d' * 64)
        self.assertEqual(self.deliver(self.fail, row=row), 'invalid')
        self.assertEqual(self.store.events, [('hold', 'invalid')])
        self.assertEqual(self.signed, [])

    def test_lost_race_never_sends(self):
        self.store.claimable = False
        self.assertEqual(self.deliver(self.fail), 'busy')
        self.assertEqual(self.frames, [])

    def test_response_superseded_before_local_commit_is_not_reported_as_delivered(self):
        self.store.can_complete = False
        ack = response(self.row, 'included')
        self.assertEqual(self.deliver(lambda: canonical(ack) + b'\n'), 'unknown')

    def test_bad_persisted_signature_is_held_and_next_record_can_be_processed(self):
        earlier = dict(self.row, signature=b'malformed', attempts=1)
        self.assertEqual(self.deliver(self.fail, row=earlier), 'invalid')
        self.assertEqual(self.store.events, [('hold', 'invalid')])
        self.assertEqual(self.deliver(lambda: canonical(response(self.row)) + b'\n'), 'stored_pending')

    def test_missing_signer_defers_one_row(self):
        self.signable = False
        self.assertEqual(self.deliver(self.fail), 'signer_unavailable')
        self.assertEqual(self.store.events, [('defer', 30)])
        self.assertEqual(self.frames, [])

    def test_future_validity_defers_without_a_permanent_hold(self):
        self.assertEqual(self.deliver(self.fail, now=datetime(2025, 12, 31, 23, 59)), 'not_yet_current')
        self.assertEqual(self.store.events, [('defer', 60)])

    def test_bad_binary_signing_key_cannot_abort_discovery(self):
        row = self.row
        columns = [str(row['id'])]
        columns.extend(row[name].encode('ascii').hex() for name in ('effect_key', 'economic_sha256', 'payload_sha256'))
        broken = '\t'.join(columns).encode('ascii') + b'\tFF\t\tNULL\t0\n'
        store = MysqlStore([])
        store.run = lambda *_: broken + broken.replace(b'\tFF\t', b'\t6b\t')
        wrong, valid = list(store.pending(8, 5))
        self.assertIsNone(wrong['key_id'])
        self.assertEqual(valid['key_id'], 'k')


if __name__ == '__main__':
    unittest.main()
