"""Compile our real adapter against authored doubles. This is not native QA."""
from pathlib import Path
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
NATIVE = HERE.parent / 'native'
SCENARIOS = (
    'cache-query-entry','cache-query-F2','cache-query-guard','cache-query-failure',
    'sdk-error-F2','sdk-error-F3','sdk-error-guard',
    'native-sdk-action','native-sdk-after','foreign-action','foreign-after',
    'service-action','service-after',
    'checkpoint-normal','checkpoint-F2','checkpoint-F3','checkpoint-sql-failed',
    'missing-authority', 'normal', 'wrong-run', 'wrong-case', 'wrong-nonce',
    'wrong-actor', 'wrong-role', 'expired', 'unbounded-expiry', 'missing-registry',
    'wrong-grant', 'phase-override', 'throw-after-journal', 'mode-mismatch',
    'reused-case', 'nested', 'connection-change', 'cache-change',
    'revoked-after-business', 'expired-after-business', 'wrong-session',
    'wrong-thread', 'final-is-not-commit', 'observer-release', 'observer-timeout',
    'process-bound', 'registration-collision', 'injected-table',
    'invalid-case-data', 'guard-case', 'inert-link', 'nested-connection-callback',
    'reentrant-after', 'role-revoked-after-business', 'expiry-during-authority-read',
    'observer-late-release', 'observer-revoked-on-release',
    'server-connection-change', 'invalid-server-connection', 'connection-diagnostic', 'missing-cache',
)


class FrameworkContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory(prefix='paymenter-qa-framework-')
        cls.addClassCleanup(cls.directory.cleanup)
        cls.binary = Path(cls.directory.name) / 'framework-contract'
        cls.compile_result = None
        if (NATIVE / 'cache_probe.cpp').exists() and (NATIVE / 'cache_probe.hpp').exists():
            cls.compile_result = subprocess.run(
                ['/usr/bin/c++', '-std=c++11', '-Wall', '-Wextra', '-Werror', '-pthread',
                 '-I', str(HERE / 'fake_sdk'), '-I', str(NATIVE),
                 str(HERE / 'framework_driver.cpp'), str(NATIVE / 'cache_probe.cpp'),
                 '-o', str(cls.binary)], capture_output=True, timeout=30)

    def scenario(self, scenario):
        self.assertIsNotNone(self.compile_result, 'framework probe implementation missing')
        self.assertEqual(0, self.compile_result.returncode,
                         self.compile_result.stderr.decode('utf8', errors='replace'))
        result = subprocess.run([str(self.binary), scenario], capture_output=True, timeout=8)
        self.assertEqual(0, result.returncode, result.stderr.decode('utf8', errors='replace'))


def make_test(scenario):
    def run(self):
        self.scenario(scenario)
    return run


for name in SCENARIOS:
    setattr(FrameworkContractTests, 'test_' + name.replace('-', '_'), make_test(name))
