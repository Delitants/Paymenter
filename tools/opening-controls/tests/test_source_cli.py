import importlib
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from source_fixtures import raw_capture,capture_profile,FixtureClock,observation,file_record
from source_schema import canonical,SourceError

class Transport:
    def capture(self,program,profile):return 0,canonical(raw_capture()),b''

class CliTest(unittest.TestCase):
    def setUp(self):
        try:self.m=importlib.import_module('source_cli')
        except ModuleNotFoundError:self.fail('source_cli functionality missing')
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name).resolve()
    def test_observation_retains_private_raw_evidence_and_verified_receipt(self):
        obs=self.m.observe(capture_profile(),Transport(),self.root,FixtureClock());self.assertEqual(obs['receipt']['controller_boot_id'],'fixture-controller-boot');self.assertTrue(list(self.root.glob('*.raw.json')))
        self.assertTrue(all(f.stat().st_mode&0o777==0o600 for f in self.root.iterdir()))
    def test_cli_never_overwrites_or_exposes_raw_private_evidence(self):
        r={'purpose':'source-control-coverage-report','real_execution_ready':False};f=self.m.save_report(self.root,r);before=f.read_bytes()
        with self.assertRaises(SourceError):self.m.save_report(self.root,r)
        self.assertEqual(f.read_bytes(),before)
        self.root.chmod(0o755)
        with self.assertRaises(SourceError):self.m.observe(capture_profile(),Transport(),self.root,FixtureClock())
    def test_shell_destination_and_apply_subcommands_are_rejected(self):
        with self.assertRaises(SourceError):self.m.SSHTransport('root@example.invalid; dangerous')
        r=subprocess.run([sys.executable,self.m.__file__,'freeze'],capture_output=True);self.assertNotEqual(r.returncode,0)
    def test_unproved_routing_is_retained_for_coverage_denial(self):
        class Dynamic:
            def capture(self,program,profile):
                raw=raw_capture();raw['files'][0]=file_record('/fixture/main.conf','nginx-root',b'http { server { location / { proxy_pass $upstream; } } }')
                return 0,canonical(raw),b''
        obs=self.m.observe(capture_profile(),Dynamic(),self.root,FixtureClock())
        self.assertIn('routing-expression-unproved',{e['code'] for e in obs['errors']})

    def test_nonzero_transport_retains_failure_and_does_not_return_observation(self):
        class Bad:
            def capture(self,program,profile):return 1,b'fictional private failure',b'fixture-error'
        with self.assertRaises(SourceError):self.m.observe(capture_profile(),Bad(),self.root,FixtureClock())
        self.assertTrue(list(self.root.glob('*.raw.json')))

if __name__=='__main__':unittest.main()
