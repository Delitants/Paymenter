"""Closed live admission with the new opt-in adapter linked but unactivated."""
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
NATIVE = HERE.parent / 'native'
sys.path.insert(0, str(HERE.parent))
import admission
import execution
import observer
from fixtures import packet, encode, RUN, RUNTIME, BOOT
from test_observer import observation


class ManualReadinessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory(prefix='manual-readiness-')
        cls.addClassCleanup(cls.directory.cleanup)
        cls.binary = Path(cls.directory.name) / 'readiness'
        cls.compiled = subprocess.run([
            '/usr/bin/c++', '-std=c++11', '-Wall', '-Wextra', '-Werror', '-pthread',
            '-DPAYMENTER_QA_CONTROL_DIRECTORY="/fictional/operation"',
            '-I', str(HERE / 'fake_manual_sdk'), '-I', str(HERE / 'fake_sdk'), '-I', str(NATIVE),
            str(HERE / 'manual_readiness_driver.cpp'),
            *[str(NATIVE / n) for n in ('bootstrap.cpp', 'cache_probe.cpp', 'module_entry.cpp',
                                       'manual_context.cpp', 'manual_probe.cpp')],
            '-o', str(cls.binary)], capture_output=True, timeout=30)

    def run_boundary(self, scenario):
        self.assertEqual(0, self.compiled.returncode, self.compiled.stderr.decode(errors='replace'))
        result = subprocess.run([str(self.binary), scenario], capture_output=True, timeout=8)
        self.assertEqual(0, result.returncode, result.stderr.decode(errors='replace'))

    def test_framework_entry_leaves_manual_action_and_store_unregistered(self):
        self.run_boundary('framework')

    def test_missing_native_access_refuses_before_authority_registration_sql_or_dispatch(self):
        self.run_boundary('missing-access')

    def test_complete_fictional_manual_profile_cannot_issue_or_execute(self):
        raw, originals = packet('manual')
        profile = admission.parse_profile(encode(raw))
        assessment = admission.assess(profile, originals)
        self.assertTrue(assessment.ready)  # Proposal completeness only.
        self.assertFalse(assessment.manual_atomicity or assessment.financial_posting_ready)
        for args in ((), (profile, originals)):
            with self.assertRaisesRegex(admission.AdmissionError, '^native-issuer-unavailable$'):
                admission.AdmittedRun(*args)
        for alleged_run in (profile, assessment, object.__new__(admission.AdmittedRun)):
            with self.assertRaisesRegex(admission.AdmissionError, '^native-issuer-unavailable$'):
                execution.Operator().execute(alleged_run)

    def test_isolation_labels_cannot_issue_live_authority(self):
        raw, originals = packet('manual')
        for field in ('all_users_disabled', 'no_notifications', 'native_callback_ready'):
            candidate = dict(raw, **{field: True})
            with self.subTest(field=field), self.assertRaises(admission.AdmissionError):
                admission.parse_profile(encode(candidate))
        with self.assertRaisesRegex(admission.AdmissionError, '^native-issuer-unavailable$'):
            admission.AdmittedRun({'all_users_disabled': True, 'no_notifications': True})

    def test_manual_rows_and_cases_remain_unsupported_by_observer(self):
        with self.assertRaisesRegex(admission.AdmissionError, '^observer-framework-only$'):
            observer.ReadExpectation(
                RUN, RUNTIME, BOOT, 'b' * 64, 'M1', 'after', '11',
                'c' * 64, '2026-10-18T00:00:00Z', '2026-10-18T00:00:30Z', ())
        expected = observer.ReadExpectation(
            RUN, RUNTIME, BOOT, 'b' * 64, 'F1', 'after', '11',
            'c' * 64, '2026-10-18T00:00:00Z', '2026-10-18T00:00:30Z', ())
        for rows in ({'payment_rows': [{'id': '101', 'status': 4}]},
                     {'subaccount_rows': [{'id': '12', 'balance': '0.0100'}]}):
            with self.subTest(rows=rows), self.assertRaises(admission.AdmissionError):
                observer.decode_observation(encode(dict(observation(), **rows)), expected)
