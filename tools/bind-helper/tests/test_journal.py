import unittest,tempfile,pathlib,sys,os
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:from paymenter_bind.journal import Journal
except ImportError:Journal=None
from test_protocol import request
class JournalTest(unittest.TestCase):
 def setUp(self):
  self.assertIsNotNone(Journal,'durable request journal missing');self.t=tempfile.TemporaryDirectory();self.addCleanup(self.t.cleanup);self.p=pathlib.Path(self.t.name).resolve();self.p.chmod(0o700);self.j=Journal(str(self.p),os.getuid())
 def test_request_claim_receipt_and_replay(self):
  r=request();a=self.j.claim(r);self.assertTrue(a.fresh);a.record('committed',{'serial':1});again=self.j.claim(r);self.assertFalse(again.fresh);self.assertEqual(again.result['state'],'committed')
 def test_different_payload_and_interrupted_claim(self):
  r=request();self.j.claim(r);self.assertEqual(self.j.claim(r).result['state'],'uncertain')
  with self.assertRaises(ValueError):self.j.claim(r|{'operation':'prepare_zone'})
 def test_foreign_binding_lookup_does_not_leak(self):
  r=request();self.j.claim(r).record('committed',{'serial':9});self.assertIsNone(self.j.inspect(r['request_id'],'00000000-0000-4000-8000-000000000003'))
if __name__=='__main__':unittest.main()
