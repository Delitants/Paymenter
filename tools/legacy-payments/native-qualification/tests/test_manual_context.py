"""Compile the real manual guards; no vendor SDK, DB, or transaction model."""
from pathlib import Path
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
NATIVE = HERE.parent / 'native'
SCENARIOS = (
    'valid', 'bad-id', 'zero-id', 'negative-id', 'overflow-id', 'leading-zero',
    'group-selector', 'duplicate-payments', 'duplicate-users', 'empty-users',
    'wrong-actor', 'wrong-run', 'wrong-nonce', 'wrong-case', 'wrong-payment',
    'unknown-slot', 'second-slot-on-M1', 'single-slot-on-M4', 'M5-is-read-only',
    'wrong-phase', 'wrong-currency', 'wrong-amount', 'expired', 'unbounded-expiry',
    'missing-session', 'bad-facts', 'money-scale', 'money-overflow',
)


class ManualContextTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory(prefix='paymenter-manual-context-')
        cls.addClassCleanup(cls.directory.cleanup)
        cls.binary = Path(cls.directory.name) / 'manual-context'
        cls.compilation = None
        if (NATIVE / 'manual_contract.hpp').exists():
            sources = [str(HERE / 'manual_context_driver.cpp')]
            if (NATIVE / 'manual_context.cpp').exists():
                sources.append(str(NATIVE / 'manual_context.cpp'))
            cls.compilation = subprocess.run(
                ['/usr/bin/c++', '-std=c++11', '-Wall', '-Wextra', '-Werror',
                 '-pthread', '-I', str(NATIVE), *sources, '-o', str(cls.binary)],
                capture_output=True, timeout=30)

    def scenario(self, name):
        self.assertIsNotNone(self.compilation, 'manual contract implementation missing')
        self.assertEqual(0, self.compilation.returncode,
                         self.compilation.stderr.decode(errors='replace'))
        result = subprocess.run([str(self.binary), name], capture_output=True, timeout=8)
        self.assertEqual(0, result.returncode, result.stderr.decode(errors='replace'))


def case(name):
    def run(self):
        self.scenario(name)
    return run


for name in SCENARIOS:
    setattr(ManualContextTests, 'test_' + name.replace('-', '_'), case(name))
