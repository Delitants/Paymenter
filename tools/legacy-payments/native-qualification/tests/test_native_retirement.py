"""Native C API calls against a strict authored boundary, never live SQL."""
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

HERE=Path(__file__).resolve().parent
NATIVE=HERE.parent/'native'
SCENARIOS=('complete','guard-before-connect','guard-before-delete','trigger','incoming-fk',
           'outgoing-fk','wrong-engine','view','dirty-session','connection-change',
           'lock-timeout','timeout-not-set','table-locks-disabled','wrong-database','wrong-socket',
           'lock-reuse','query-before-lock','invalid-run','invalid-case','invalid-facts',
           'delete-second-failure','commit-failure','remaining-rows','drop-failure','drop-not-absent',
           'schema-wrong-name','rows-overflow','row-null','expired','fork-refused','foreign-run','unregistered-case','foreign-facts','fork-destructor','thread-refused','changed-delete-schema','changed-drop-schema','complete-durable','after-release','metadata-deadline')

class NativeRetirementTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='native-retirement-build-',dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup);cls.binary=Path(cls.temp.name)/'driver';cls.compiled=None
        if (NATIVE/'qa_retirement_mysql.cpp').exists():
            flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],capture_output=True,timeout=5,check=True).stdout.decode().split()
            if sys.platform.startswith('linux'):flags.append('-ldl')
            cls.compiled=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror','-pthread',
                '-I'+str(HERE/'fake_retirement_mysql'),str(HERE/'native_retirement_driver.cpp'),'-o',str(cls.binary),*flags],capture_output=True,timeout=30)
    def scenario(self,name):
        self.assertIsNotNone(self.compiled,'fixed native retirement SQL adapter is not implemented')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='native-retirement-archive-',dir=HERE) as temp:
            result=subprocess.run([str(self.binary),name,str(Path(temp).resolve())],capture_output=True,timeout=3)
            self.assertEqual(0,result.returncode,result.stderr.decode(errors='replace'))
            self.assertNotIn(b'fictional-secret',result.stdout+result.stderr)
            if name=='complete-durable':
                original=(Path(temp)/'qa.retirement.original').read_bytes()
                self.assertIn(b'run='+b'a'*64+b'\n',original)
                self.assertIn(b'row_hex=',original)

for name in SCENARIOS:
    def run(self,name=name):self.scenario(name)
    setattr(NativeRetirementTests,'test_'+name.replace('-','_'),run)
