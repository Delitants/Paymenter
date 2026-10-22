import dataclasses
import json
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from fixtures import RUN, case, encode, packet, timestamp
try:
    import admission as api
except ModuleNotFoundError as exc:
    if exc.name != 'admission':
        raise
    api = None


class AdmissionTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(api, 'closed admission module is not implemented')

    def parsed(self, mode='framework', mutate=None):
        profile, originals = packet(mode, mutate)
        return api.parse_profile(encode(profile)), originals

    def blocked(self, code, mode='framework', mutate=None):
        profile, originals = self.parsed(mode, mutate)
        result = api.assess(profile, originals)
        self.assertFalse(result.ready)
        self.assertIn(code, result.blockers)
        self.assertFalse(any((result.framework_atomicity, result.manual_atomicity,
                              result.provider_validation, result.financial_posting_ready)))
        with self.assertRaises(api.AdmissionError):
            api.build_operation_plan(profile, originals)

    def test_complete_fictional_framework_proposal_has_no_runtime_authority(self):
        profile, originals = self.parsed()
        result = api.assess(profile, originals)
        self.assertTrue(result.ready)
        self.assertEqual((), result.blockers)
        self.assertFalse(any((result.framework_atomicity, result.manual_atomicity,
                              result.provider_validation, result.financial_posting_ready)))
        self.assertFalse(hasattr(api, 'execute'))
        with self.assertRaises(api.AdmissionError):
            api.AdmittedRun()

    def test_complete_manual_proposal_still_has_no_runtime_acceptance(self):
        profile, originals = self.parsed('manual')
        result = api.assess(profile, originals)
        self.assertTrue(result.ready)
        self.assertFalse(result.manual_atomicity or result.provider_validation or result.financial_posting_ready)

    def test_profile_rejects_unsafe_json_and_unknown_override_fields(self):
        valid, _ = packet()
        malformed = [b'{}', b'[]', b'{"schema_version":1,"schema_version":1}',
                     b'{' + b' ' * 65536 + b'}', b'\xff', b'[[[[[[[[[0]]]]]]]]]',
                     encode(dict(valid, command='arbitrary')), encode(dict(valid, ready=True)),
                     encode(dict(valid, schema_version=True)), encode(dict(valid, schema_version=1.0)),
                     encode(dict(valid, schema_version=2)), encode(dict(valid, mode='live')),
                     encode(dict(valid, expires_at='2026-02-30T00:00:00Z'))]
        for raw in malformed:
            with self.subTest(raw_length=len(raw)):
                with self.assertRaises(api.AdmissionError):
                    api.parse_profile(raw)

    def test_reference_rejects_aliases_uri_parent_traversal_and_unbounded_bytes(self):
        for path in ['relative', '/a/../b', '/a//b', '//a/b', '/a/./b', '/', 'https://example.test/x', '/a\x00b']:
            raw, _ = packet()
            raw['runtime']['path'] = path
            with self.subTest(path=repr(path)), self.assertRaises(api.AdmissionError):
                api.parse_profile(encode(raw))
        for size in [True, 0, -1, 65537]:
            raw, _ = packet()
            raw['build']['bytes'] = size
            with self.subTest(size=size), self.assertRaises(api.AdmissionError):
                api.parse_profile(encode(raw))

    def test_frozen_profile_cannot_be_modified(self):
        profile, _ = self.parsed()
        with self.assertRaises(dataclasses.FrozenInstanceError):
            profile.mode = 'manual'

    def test_reference_invariants_are_rechecked_after_low_level_forgery(self):
        profile, originals = self.parsed()
        object.__setattr__(profile.runtime, 'path', 'relative')
        with self.assertRaises(api.AdmissionError):
            api.assess(profile, originals)

    def test_same_length_changed_original_is_rejected_by_digest(self):
        profile, originals = self.parsed()
        originals[profile.runtime.path] = originals[profile.runtime.path].replace(b'linux', b'other')
        self.assertIn('evidence-hash', api.assess(profile, originals).blockers)

    def test_hash_bound_duplicate_original_key_is_rejected(self):
        import hashlib
        raw, originals = packet()
        path = raw['runtime']['path']
        original = originals[path][:-1] + b',"engine":"InnoDB"}'
        originals[path] = original
        raw['runtime'].update(sha256=hashlib.sha256(original).hexdigest(), bytes=len(original))
        self.assertIn('duplicate-key', api.assess(api.parse_profile(encode(raw)), originals).blockers)

    def test_missing_changed_or_extra_originals_refuse_before_planning(self):
        profile, originals = self.parsed()
        for changed in [{}, dict(originals, **{'/fictional/extra': b'x'})]:
            self.assertFalse(api.assess(profile, changed).ready)
        changed = originals.copy()
        changed[profile.runtime.path] += b' '
        self.assertIn('evidence-bytes', api.assess(profile, changed).blockers)

    def test_original_duplicate_keys_and_claimed_readiness_are_not_evidence(self):
        self.blocked('evidence-fields', mutate=lambda d: d['runtime'].update(ready=True))
        self.blocked('evidence-fields', mutate=lambda d: d['authority'].update(execute=True))

    def test_stale_or_changed_runtime_and_build_bindings_refuse(self):
        self.blocked('runtime-stale', mutate=lambda d: d['runtime'].update(observed_at=timestamp(-601)))
        self.blocked('runtime-future', mutate=lambda d: d['runtime'].update(observed_at=timestamp(60)))
        self.blocked('runtime-binding', mutate=lambda d: d['build'].update(boot_id='another-boot'))
        self.blocked('runtime-binding', mutate=lambda d: d['registry'].update(runtime_id='another-runtime'))
        self.blocked('authority-expired', mutate=lambda d: d['authority'].update(expires_at=timestamp(-1)))

    def test_expired_profile_and_excess_lifetime_refuse(self):
        raw, originals = packet()
        for expiry, code in [(timestamp(-1), 'profile-expired'), (timestamp(1000), 'profile-lifetime')]:
            with self.subTest(code=code):
                raw['expires_at'] = expiry
                self.assertIn(code, api.assess(api.parse_profile(encode(raw)), originals).blockers)

    def test_unqualified_native_registration_loading_or_access_refuse(self):
        for field in ['registration', 'install', 'unload', 'access']:
            self.blocked('build-' + field, mutate=lambda d, f=field: d['build'].update({f: 'unqualified'}))

    def test_existing_tables_wrong_engine_and_wrong_header_namespace_refuse(self):
        self.blocked('table-collision', mutate=lambda d: d['registry'].update(tables_initially_absent=False))
        self.blocked('database-engine', mutate=lambda d: d['runtime'].update(engine='MyISAM'))
        self.blocked('sdk-namespace', mutate=lambda d: d['runtime'].update(header_namespace='payment'))

    def test_customer_cohort_service_or_non_usd_fixture_refuses(self):
        for field, value, code in [('created_by_run', 'f' * 64, 'fixture-owner'),
                                   ('migration_selected', True, 'fixture-cohort'),
                                   ('services', 1, 'fixture-services'),
                                   ('currency', 'EUR', 'fixture-currency'),
                                   ('initial_balance', '1.0000', 'fixture-balance')]:
            self.blocked(code, 'manual', lambda d, f=field, v=value: d['registry']['objects'][0].update({f: v}))

    def test_reused_or_cross_parent_ids_and_retired_registry_refuse(self):
        self.blocked('fixture-reused', 'manual', lambda d: d['registry'].update(used_ids=['payment:3']))
        self.blocked('fixture-duplicate', 'manual', lambda d: d['registry']['objects'].append(d['registry']['objects'][-1].copy()))
        self.blocked('fixture-parent', 'manual', lambda d: d['registry']['objects'][2].update(parent='999'))
        self.blocked('registry-retired', mutate=lambda d: d['registry'].update(retired=True))

    def test_manual_actor_lookup_calls_retirement_and_each_isolation_are_required(self):
        for field in ['actor_permission', 'manual_lookup', 'manual_calls', 'retirement']:
            self.blocked('manual-' + field, 'manual', lambda d, f=field: d['authority'].update({f: 'unqualified'}))
        for field in ['mail', 'queue', 'provider']:
            self.blocked('manual-isolation-' + field, 'manual', lambda d, f=field: d['authority']['isolation'].update({f: 'unqualified'}))

    def test_status_signature_pages_and_sdk_counters_never_grant_acceptance(self):
        for claim in ['ValidateAuth', 'capability', 'SetInfo', 'GetSuccessPage', 'paid', 'TransactionCount', 'payment.info']:
            with self.subTest(claim=claim):
                self.blocked('evidence-fields', mutate=lambda d, c=claim: d['authority'].update(commit_proof=c))

    def test_fixed_plans_include_fresh_framework_cases_before_manual_cases(self):
        for mode, cases in [('framework', ['F' + str(n) for n in range(1, 6)]),
                            ('manual', ['F' + str(n) for n in range(1, 6)] + ['M' + str(n) for n in range(1, 6)])]:
            profile, originals = self.parsed(mode)
            plan = api.build_operation_plan(profile, originals)
            self.assertEqual(cases, [op.case_id for op in plan.operations if op.kind == 'run_case'])
            self.assertEqual('retire_owned_resources', plan.operations[-1].kind)
            self.assertTrue(all(type(op.resource) is api.EvidenceRef for op in plan.operations))
            self.assertFalse(any(hasattr(op, 'command') or hasattr(op, 'sql') for op in plan.operations))

    def test_assessment_has_no_transport_or_file_access(self):
        import unittest.mock as mock
        profile, originals = self.parsed()
        with mock.patch('builtins.open', side_effect=AssertionError('unexpected file access')), \
             mock.patch('subprocess.Popen', side_effect=AssertionError('unexpected process')):
            self.assertTrue(api.assess(profile, originals).ready)
            api.build_operation_plan(profile, originals)


if __name__ == '__main__':
    unittest.main()
