"""The native OS fixture must compile only with its explicit authored DB API."""
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
HERE=Path(__file__).resolve().parent
class ResourceNativeFixtureTests(unittest.TestCase):
    def test_fixture_is_marked_and_refuses_missing_arguments_before_io(self):
        self.compile_fixture('fake_retirement_mysql',True)
    def test_unmarked_mysql_boundary_cannot_build_fixture(self):
        self.compile_fixture('fake_mysql',False)
    def compile_fixture(self,header,allowed):
        flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],capture_output=True,timeout=5,check=True).stdout.decode().split()
        if sys.platform.startswith('linux'):flags.append('-ldl')
        with tempfile.TemporaryDirectory(prefix='resource-native-fixture-build-',dir=HERE) as temp:
            binary=Path(temp)/'fixture'
            result=subprocess.run(['/usr/bin/c++','-std=c++11','-pthread','-Wall','-Wextra','-Werror','-I'+str(HERE/header),
                str(HERE/'resource_native_fixture_driver.cpp'),'-o',str(binary),*flags],capture_output=True,timeout=30)
            if allowed:
                self.assertEqual(0,result.returncode,result.stderr.decode(errors='replace'))
                run=subprocess.run([str(binary)],capture_output=True,timeout=3)
                self.assertEqual((1,b'',b'fixture-linux-root-required\n'),(run.returncode,run.stdout,run.stderr))
            else:
                self.assertNotEqual(0,result.returncode)
                self.assertIn(b'This fixture requires the authored MySQL boundary',result.stderr)
