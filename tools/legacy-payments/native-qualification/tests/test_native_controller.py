from pathlib import Path
import subprocess,tempfile,unittest,shutil,sys
HERE=Path(__file__).resolve().parent
class NativeControllerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.t=tempfile.TemporaryDirectory(prefix='controller-',dir=HERE);cls.addClassCleanup(cls.t.cleanup)
        cls.binary=Path(cls.t.name)/'test'
        flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],capture_output=True,timeout=5,check=True).stdout.decode().split()
        if sys.platform.startswith('linux'):flags.append('-ldl')
        cls.c=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror','-pthread','-I'+str(HERE/'fake_retirement_mysql'),str(HERE/'native_controller_compile_driver.cpp'),'-o',str(cls.binary),*flags],capture_output=True,timeout=30)
    def test_missing_approval_refuses_without_launch_or_files(self):
        self.assertEqual(0,self.c.returncode,self.c.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='controller-refusal-',dir=HERE) as tmp:
            r=subprocess.run([str(self.binary),tmp],capture_output=True,timeout=3)
            self.assertEqual(0,r.returncode,r.stderr.decode(errors='replace'))
            self.assertEqual([],list(Path(tmp).iterdir()))

class NativeControllerBindingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.t=tempfile.TemporaryDirectory(prefix='controller-binding-',dir=HERE);cls.addClassCleanup(cls.t.cleanup)
        cls.binary=Path(cls.t.name)/'test'
        flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],capture_output=True,timeout=5,check=True).stdout.decode().split()
        if sys.platform.startswith('linux'):flags.append('-ldl')
        cls.c=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror','-pthread','-I'+str(HERE/'fake_retirement_mysql'),str(HERE/'native_controller_fixture.cpp'),'-o',str(cls.binary),*flags],capture_output=True,timeout=30)
    def scenario(self,m):
        self.assertEqual(0,self.c.returncode,self.c.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='controller-operation-',dir=HERE) as tmp:
            r=subprocess.run([str(self.binary),m,tmp],capture_output=True,timeout=25)
            self.assertEqual(0,r.returncode,r.stderr.decode(errors='replace'))
for m in ('caller-alias','normal','large-reply','wrong-producer','reused-reader','injection-not-reached','visible-barrier','missing-barrier','F5-mutated','capture-overflow','lost-native-child'):
    def run(self,m=m):self.scenario(m)
    setattr(NativeControllerBindingTests,'test_'+m.replace('-','_'),run)
