"""Actual program aliases and file identities; no SDK/manager acceptance."""
from pathlib import Path
import subprocess,tempfile,unittest,shutil
HERE=Path(__file__).resolve().parent
CASES=('alias-cancelled-symlink','alias-cancelled-missing','alias-file-dot','alias-trailing-slash','alias-removed-parent-change','target-same-bytes','target-parent-symlink','writable-target-parent','alias-hardlinked','regular','hardlinked','alias-relative','alias-absolute','alias-replaced','retarget','link-count-change','target-change','parent-change','target-mode-change','writable-target','writable-parent','wrong-owner','noncanonical','dangling','loop','target-symlink','parent-symlink')
class ProgramImageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.t=tempfile.TemporaryDirectory(prefix='program-build-',dir=HERE);cls.addClassCleanup(cls.t.cleanup)
        cls.binary=Path(cls.t.name)/'driver'
        cls.c=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror',str(HERE/'program_image_driver.cpp'),'-o',str(cls.binary)],capture_output=True,timeout=30)
    def scenario(self,name):
        self.assertEqual(0,self.c.returncode,self.c.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='program-operation-',dir=HERE) as tmp:
            try:
                r=subprocess.run([str(self.binary),name,tmp],capture_output=True,timeout=3)
                self.assertEqual(0,r.returncode,r.stderr.decode(errors='replace'))
            finally:
                shutil.rmtree(tmp+'.old',ignore_errors=True)
for name in CASES:
    def run(self,name=name):self.scenario(name)
    setattr(ProgramImageTests,'test_'+name.replace('-','_'),run)
