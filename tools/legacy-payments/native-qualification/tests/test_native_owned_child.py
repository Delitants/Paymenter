"""Actual local fork/pipe/wait tests. Linux/root qualification remains separate."""
from pathlib import Path
import subprocess
import tempfile
import unittest

HERE=Path(__file__).resolve().parent
NATIVE=HERE.parent/'native'
CASES=('alias-relative-argv0','alias-binding-replaced','alias-missing-binding','alias-capture','alias-argv0','alias-replaced','link-count-changed','hardlink-capture','invalid-image','inherited-limits','fd-closed','capture','timeout','overflow','stderr-overflow','nonzero','exec-failure',
       'callback-failure','descendant-pipe','image-replaced','one-shot','closed-stdin')
class NativeOwnedChildTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='native-child-',dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary=Path(cls.temp.name)/'driver'
        cls.compiled=None
        if (NATIVE/'owned_child.hpp').exists():
            cls.compiled=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror',str(HERE/'native_owned_child_driver.cpp'),'-o',str(cls.binary)],capture_output=True,timeout=30)
    def scenario(self,name):
        self.assertIsNotNone(self.compiled,'native owned caller supervisor is missing')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))
        r=subprocess.run([str(self.binary),name],capture_output=True,timeout=4)
        self.assertEqual(0,r.returncode,r.stderr.decode(errors='replace'))
for name in CASES:
    def run(self,name=name):self.scenario(name)
    setattr(NativeOwnedChildTests,'test_'+name.replace('-','_'),run)
