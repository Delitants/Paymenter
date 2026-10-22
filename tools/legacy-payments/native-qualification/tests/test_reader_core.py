"""Real fixed-query/read validation; DB boundary doubles are not native evidence."""
from pathlib import Path
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
NATIVE = HERE.parent/'native'
SCENARIOS = ('pair','absent','split','duplicate','dirty','repeatable','no-autocommit',
             'same-writer','reused-reader','changed-connection','wrong-row','wrong-amount',
             'nontransactional-table','missing-table','wrong-schema','wrong-socket',
             'active-before-configure','expired','reused-session','query-failure',
             'invalid-run','invalid-case','invalid-facts','invalid-writer','duplicate-history')


class ReaderCoreTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='reader-build-', dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary = Path(cls.temp.name)/'driver'
        cls.compiled = None
        if (NATIVE/'reader_core.hpp').exists():
            cls.compiled = subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror',
                str(HERE/'reader_core_driver.cpp'),'-o',str(cls.binary)], capture_output=True, timeout=30)

    def scenario(self, name):
        self.assertIsNotNone(self.compiled, 'independent reader core is not implemented')
        self.assertEqual(0, self.compiled.returncode, self.compiled.stderr.decode(errors='replace'))
        result = subprocess.run([str(self.binary), name], capture_output=True, timeout=3)
        self.assertEqual(0, result.returncode, result.stderr.decode(errors='replace'))


for name in SCENARIOS:
    def run(self, name=name): self.scenario(name)
    setattr(ReaderCoreTests, 'test_'+name.replace('-','_'), run)
