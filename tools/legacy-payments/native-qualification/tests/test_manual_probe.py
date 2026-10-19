"""Real adapter, authored SDK/DB boundaries: never native payment acceptance."""
from pathlib import Path
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
NATIVE = HERE.parent / 'native'
SCENARIOS = (
    'normal', 'original-error', 'missing-callback', 'before-journal', 'after-journal',
    'foreign-callback', 'owned-no-context', 'wrong-actor', 'wrong-role', 'wrong-run',
    'wrong-nonce', 'wrong-payment', 'M5-read-only', 'unknown-slot', 'inherited-selector',
    'inherited-su', 'phase-override', 'format-retry', 'wrong-func-param',
    'enabled-user', 'direct-notifier', 'queue-not-isolated', 'linked-service',
    'provider-link', 'wrong-native-owner', 'wrong-native-amount', 'wrong-native-currency',
    'wrong-delta', 'wrong-status', 'missing-cache', 'native-cache-change',
    'native-connection-change', 'native-server-change', 'native-actor-change',
    'native-role-change', 'native-revocation', 'revoked-after-read', 'expiry-during-read',
    'nested-inspect-caught', 'nested-callback-caught', 'double-callback',
    'reused-case', 'reused-nonce', 'preliminary-commit', 'duplicate-native-error',
    'duplicate-native-noop', 'duplicate-callback-noop', 'duplicate-baseline-mismatch',
    'missing-authority', 'missing-access', 'missing-action', 'duplicate-registration',
    'store-registration-failure', 'action-registration-failure', 'registration-reentry',
    'action-registration-reentry', 'same-thread-after-native-error', 'fork',
)


class ManualProbeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory(prefix='paymenter-manual-probe-')
        cls.addClassCleanup(cls.directory.cleanup)
        cls.binary = Path(cls.directory.name) / 'manual-probe'
        cls.compilation = None
        if (NATIVE / 'manual_probe.cpp').exists():
            cls.compilation = subprocess.run(
                ['/usr/bin/c++', '-std=c++11', '-Wall', '-Wextra', '-Werror', '-pthread',
                 '-I', str(HERE / 'fake_manual_sdk'), '-I', str(NATIVE),
                 str(HERE / 'manual_probe_driver.cpp'), str(NATIVE / 'manual_context.cpp'),
                 str(NATIVE / 'manual_probe.cpp'), '-o', str(cls.binary)],
                capture_output=True, timeout=30)

    def scenario(self, name):
        self.assertIsNotNone(self.compilation, 'manual SDK adapter missing')
        self.assertEqual(0, self.compilation.returncode,
                         self.compilation.stderr.decode(errors='replace'))
        r = subprocess.run([str(self.binary), name], capture_output=True, timeout=8)
        self.assertEqual(0, r.returncode, r.stderr.decode(errors='replace'))


def case(name):
    def run(self):
        self.scenario(name)
    return run


for name in SCENARIOS:
    setattr(ManualProbeTests, 'test_' + name.replace('-', '_'), case(name))
