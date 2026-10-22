"""Local pipes exercise byte collection; no native process is started or signalled."""
from dataclasses import replace
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from fixtures import case, encode, RUN, BOOT
from admission import AdmittedRun

try:
    import execution as api
except ModuleNotFoundError as exc:
    if exc.name != 'execution':
        raise
    api = None


class OperatorTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(api, 'bounded collector policies are not implemented')
        self.collector = api.BoundedCollector(RUN, 'F1', 'after')

    def test_real_local_pipe_receipt_decodes_only_after_both_eof_and_exit(self):
        child = subprocess.run([sys.executable, '-c',
                'import sys; sys.stdout.buffer.write(bytes.fromhex(sys.argv[1]))',
                encode(case()).hex()], capture_output=True, timeout=3, check=True)
        for offset in range(0, len(child.stdout), 7):
            self.collector.feed('stdout', child.stdout[offset:offset + 7])
        receipt = self.collector.finish(child.returncode, stdout_eof=True, stderr_eof=True,
                                        deadline_expired=False)
        self.assertEqual((RUN, 'F1', 'after'), (receipt.run_id, receipt.case_id, receipt.phase))
        self.assertEqual(child.stdout, self.collector.stdout)
        with self.assertRaises(api.AdmissionError):
            self.collector.finish(0, stdout_eof=True, stderr_eof=True, deadline_expired=False)

    def test_loss_timeout_partial_eof_error_exit_and_stderr_remain_failures(self):
        for fields, stderr in [(dict(stdout_eof=False), b''), (dict(stderr_eof=False), b''),
                               (dict(deadline_expired=True), b''), (dict(exitcode=2), b''),
                               ({}, b'private diagnostic')]:
            collector = api.BoundedCollector(RUN, 'F1', 'after')
            collector.feed('stdout', encode(case()))
            collector.feed('stderr', stderr)
            arguments = dict(exitcode=0, stdout_eof=True, stderr_eof=True, deadline_expired=False)
            arguments.update(fields)
            with self.subTest(fields=fields), self.assertRaises(api.AdmissionError):
                collector.finish(**arguments)
            self.assertEqual(encode(case()), collector.stdout)
        with self.assertRaises(api.AdmissionError):
            self.collector.finish(0, stdout_eof=True, stderr_eof=True, deadline_expired=False)

    def test_oversized_stdout_is_bounded_terminal_and_retains_prefix(self):
        self.collector.feed('stdout', b'x' * 16384)
        with self.assertRaises(api.AdmissionError):
            self.collector.feed('stdout', b'y')
        self.assertEqual(16384, len(self.collector.stdout))
        self.assertEqual('collector-output-limit', self.collector.failure)
        with self.assertRaises(api.AdmissionError):
            self.collector.feed('stdout', encode(case()))
        with self.assertRaises(api.AdmissionError):
            self.collector.finish(0, stdout_eof=True, stderr_eof=True, deadline_expired=False)

    def test_stderr_and_stdout_share_packet_budget(self):
        self.collector.feed('stdout', b'{}')
        self.collector.feed('stderr', b'x' * (1048576 - 2))
        with self.assertRaises(api.AdmissionError):
            self.collector.feed('stderr', b'y' * 10000)
        self.assertEqual(1048576, len(self.collector.stdout) + len(self.collector.stderr))

    def test_invalid_utf8_json_wrong_binding_and_failed_capture_cannot_retry(self):
        for raw in [b'\xff', b'{', b'{"purpose":1,"purpose":2}',
                    encode(dict(case(), run_id='b' * 64)), encode(case('F2'))]:
            collector = api.BoundedCollector(RUN, 'F1', 'after')
            collector.feed('stdout', raw)
            with self.subTest(length=len(raw)), self.assertRaises(api.AdmissionError):
                collector.finish(0, stdout_eof=True, stderr_eof=True, deadline_expired=False)
            self.assertEqual(raw, collector.stdout)
            with self.assertRaises(api.AdmissionError):
                collector.feed('stdout', encode(case()))

    def test_same_pid_with_different_start_boot_role_or_owner_is_not_same_process(self):
        original = api.ProcessIdentity(RUN, BOOT, 123, 456, 'caller')
        self.assertTrue(api.same_process(original, replace(original)))
        for fields in [dict(pid=124), dict(start_ticks=457), dict(boot_id='different'),
                       dict(run_id='b' * 64), dict(role='observer')]:
            self.assertFalse(api.same_process(original, replace(original, **fields)))
        for fields in [dict(pid=1), dict(pid=True), dict(start_ticks=0), dict(role='manager')]:
            with self.assertRaises(api.AdmissionError):
                replace(original, **fields)

    def test_native_execution_refuses_proposals_partial_install_and_cleanup_claims(self):
        for alleged_run in [None, object(), object.__new__(AdmittedRun),
                            dict(installed=True), dict(retired=True),
                            dict(installation='partial'), dict(cleanup='failed')]:
            with self.subTest(value=type(alleged_run).__name__), self.assertRaisesRegex(
                    api.AdmissionError, '^native-issuer-unavailable$'):
                api.Operator().execute(alleged_run)

    def test_harness_directory_does_not_replace_python_standard_operator(self):
        child = subprocess.run([sys.executable, '-S', '-c',
                'import operator; assert operator.eq(1, 1)'], cwd=ROOT,
                capture_output=True, timeout=3)
        self.assertEqual(0, child.returncode, 'harness shadows the standard operator module')


if __name__ == '__main__':
    unittest.main()
