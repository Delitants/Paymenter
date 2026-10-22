import pathlib,sys,tempfile,unittest,os,json
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:from paymenter_bind.config import load_binding
except ImportError:load_binding=None
BID='00000000-0000-4000-8000-000000000002';OTHER='00000000-0000-4000-8000-000000000003'
class ConfigTest(unittest.TestCase):
 def setUp(self):
  self.assertTrue(callable(load_binding),'binding loader missing');self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.p=pathlib.Path(self.tmp.name).resolve();self.p.chmod(0o700);self.fd=os.open(self.p,os.O_RDONLY|os.O_DIRECTORY);self.addCleanup(os.close,self.fd)
  self.config=dict(version=1,zone_root=str(self.p),state_root=str(self.p),protected_zones=['infra.test.'],bindings={BID:dict(zone='example.test.',view='public',role='primary',file='zone',operations=['inspect','replace_records'])})
  self.save()
 def save(self):self.p.joinpath('server.json').write_text(json.dumps(self.config));self.p.joinpath('server.json').chmod(0o600)
 def test_same_domain_views_are_bound_by_id(self):
  self.config['bindings'][OTHER]=self.config['bindings'][BID]|{'view':'internal'};self.save();self.assertEqual(load_binding(self.fd,BID,'inspect',os.getuid()).view,'public');self.assertEqual(load_binding(self.fd,OTHER,'inspect',os.getuid()).view,'internal')
 def test_absent_operation_and_infrastructure_refuse(self):
  for bid,op in [(OTHER,'inspect'),(BID,'prepare_zone')]:
   with self.assertRaises(ValueError):load_binding(self.fd,bid,op,os.getuid())
  self.config['bindings'][BID]['zone']='child.infra.test.';self.save()
  with self.assertRaises(ValueError):load_binding(self.fd,BID,'inspect',os.getuid())
 def test_unsafe_config_link_mode_or_path_refuse(self):
  self.p.joinpath('server.json').chmod(0o666)
  with self.assertRaises(ValueError):load_binding(self.fd,BID,'inspect',os.getuid())
  self.save();os.link(self.p/'server.json',self.p/'alias')
  with self.assertRaises(ValueError):load_binding(self.fd,BID,'inspect',os.getuid())
if __name__=='__main__':unittest.main()
