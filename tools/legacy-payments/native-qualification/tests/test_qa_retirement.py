"""Real protected archive; authored DB/retirement guard, no native SQL writes."""
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

HERE=Path(__file__).resolve().parent
NATIVE=HERE.parent/'native'
SCENARIOS=('complete','empty','split-owned','foreign-run','unknown-case','wrong-facts',
           'wrong-amount','duplicate-row','changed-schema','archive-failure','archive-corrupt',
           'delete-failure','drop-failure','unlock-failure','guard-held','guard-changed',
           'remaining-row','schema-after-delete','lock-failure','archive-replay',
           'invalid-run','invalid-case','duplicate-case','invalid-facts',
           'archive-after-delete','drop-not-absent')


class QaRetirementTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='qa-retirement-build-',dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup);cls.binary=Path(cls.temp.name)/'driver';cls.compiled=None
        if (NATIVE/'qa_retirement.hpp').exists() and (NATIVE/'qa_retirement_archive.cpp').exists():
            flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],capture_output=True,timeout=5,check=True).stdout.decode().split()
            if sys.platform.startswith('linux'):flags.append('-ldl')
            cls.compiled=subprocess.run(['/usr/bin/c++','-std=c++11','-pthread','-Wall','-Wextra','-Werror',
                str(HERE/'qa_retirement_driver.cpp'),'-o',str(cls.binary),*flags],capture_output=True,timeout=30)

    def scenario(self,name):
        self.assertIsNotNone(self.compiled,'owned QA retirement policy/archive is not implemented')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='qa-retirement-',dir=HERE) as tmp:
            r=subprocess.run([str(self.binary),name,str(Path(tmp).resolve())],capture_output=True,timeout=3)
            self.assertEqual(0,r.returncode,r.stderr.decode(errors='replace'))


for name in SCENARIOS:
    def run(self,name=name):self.scenario(name)
    setattr(QaRetirementTests,'test_'+name.replace('-','_'),run)
