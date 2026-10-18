"""SDK boundary/registration gating only, not actual manager initialization."""
from pathlib import Path
import os
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
NATIVE = HERE.parent / 'native'
SCENARIOS = ('forward-checkpoints','refusal-active','refusal-closed','checkpoint-close-after-forward','normal', 'missing-authority', 'null-authority', 'bad-operation',
             'initialize-without-entry', 'duplicate-prepare', 'duplicate-initialize',
             'prepare-after-active', 'event-registration-failure', 'pending-action',
             'registration-reentrant', 'reentrant-prepare', 'revoked-before-register',
             'revoked-after-register', 'fork-refusal', 'destroyed-bootstrap')


class BootstrapTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='bootstrap-test-', dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary = Path(cls.temp.name) / 'bootstrap-driver'
        cls.compiled = None
        if (NATIVE / 'bootstrap.cpp').exists():
            cls.compiled = subprocess.run(['/usr/bin/c++', '-std=c++11', '-pthread',
                '-Wall', '-Wextra', '-Werror', '-I', str(HERE / 'fake_sdk'), '-I', str(NATIVE),
                str(HERE / 'bootstrap_driver.cpp'), str(NATIVE / 'bootstrap.cpp'),
                str(NATIVE / 'cache_probe.cpp'), '-o', str(cls.binary)], capture_output=True, timeout=30)

    def scenario(self, name):
        self.assertIsNotNone(self.compiled, 'guarded bootstrap is not implemented')
        self.assertEqual(0, self.compiled.returncode, self.compiled.stderr.decode(errors='replace'))
        result = subprocess.run([str(self.binary), name], capture_output=True, timeout=5)
        self.assertEqual(0, result.returncode, result.stderr.decode(errors='replace'))


for name in SCENARIOS:
    def run(self, name=name):
        self.scenario(name)
    setattr(BootstrapTests, 'test_' + name.replace('-', '_'), run)


class ModuleEntryTests(unittest.TestCase):
    def test_entry_uses_only_compiled_directory_and_routes_initialization(self):
        self.assertTrue((NATIVE / 'module_entry.cpp').exists(), 'opt-in entry is not implemented')
        with tempfile.TemporaryDirectory(prefix='entry-test-', dir=HERE) as temp:
            binary = Path(temp) / 'entry-driver'
            subprocess.run(['/usr/bin/c++', '-std=c++11', '-pthread', '-Wall', '-Wextra', '-Werror',
                '-DPAYMENTER_QA_CONTROL_DIRECTORY="/fictional/operation"',
                '-I', str(HERE / 'fake_sdk'), '-I', str(NATIVE), str(HERE / 'entry_driver.cpp'),
                str(NATIVE / 'bootstrap.cpp'), str(NATIVE / 'cache_probe.cpp'),
                str(NATIVE / 'module_entry.cpp'), '-o', str(binary)],
                capture_output=True, timeout=30, check=True)
            env = dict(os.environ, PAYMENTER_QA_CONTROL_DIRECTORY='/untrusted/environment/path')
            for scenario in ('normal', 'missing'):
                result = subprocess.run([str(binary), scenario], env=env, capture_output=True, timeout=5)
                self.assertEqual(0, result.returncode, result.stderr.decode(errors='replace'))

    def test_entry_cannot_compile_without_explicit_control_directory(self):
        self.assertTrue((NATIVE / 'module_entry.cpp').exists(), 'opt-in entry is not implemented')
        with tempfile.TemporaryDirectory(prefix='entry-refusal-', dir=HERE) as temp:
            result = subprocess.run(['/usr/bin/c++', '-std=c++11', '-pthread',
                '-I', str(HERE / 'fake_sdk'), '-I', str(NATIVE), '-c', str(NATIVE / 'module_entry.cpp'),
                '-o', str(Path(temp) / 'entry.o')], capture_output=True, timeout=15)
            self.assertNotEqual(0, result.returncode)
            self.assertFalse((Path(temp) / 'entry.o').exists())
