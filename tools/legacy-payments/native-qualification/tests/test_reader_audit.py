from pathlib import Path
import subprocess,tempfile,unittest
HERE=Path(__file__).resolve().parent
class ReaderAuditTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.t=tempfile.TemporaryDirectory(prefix='audit-',dir=HERE);cls.addClassCleanup(cls.t.cleanup)
        cls.binary=Path(cls.t.name)/'test'
        cls.c=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror',str(HERE/'reader_audit_driver.cpp'),'-o',str(cls.binary)],capture_output=True,timeout=30)
    def scenario(self,m):
        self.assertEqual(0,self.c.returncode,self.c.stderr.decode(errors='replace'))
        r=subprocess.run([str(self.binary),m],capture_output=True,timeout=3)
        self.assertEqual(0,r.returncode,r.stderr.decode(errors='replace'))
for m in ('schema','trigger','link','empty','full','foreign','F5','wrong-facts','duplicate','oversize','reused','bad-registry','history'):
    def run(self,m=m):self.scenario(m)
    setattr(ReaderAuditTests,'test_'+m.replace('-','_'),run)
