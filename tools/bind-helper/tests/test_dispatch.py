import pathlib,sys,unittest,subprocess,os,json
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:from paymenter_bind.cli import handle
except ImportError:handle=None
import test_config as fixtures
from test_protocol import request
class DispatchTest(unittest.TestCase):
 save=fixtures.ConfigTest.save
 def setUp(self):
  fixtures.ConfigTest.setUp(self);self.assertTrue(callable(handle),'one-request dispatcher missing')
 def test_bound_inspection_and_unknown_operation(self):
  from types import SimpleNamespace
  snap=SimpleNamespace(serial=1,revision='a'*64,rrsets=(('www.example.test.',300,'A',('192.0.2.1',)),),image=SimpleNamespace(identity=(1,)))
  class Reader:
   def __init__(self,b,budget=30):pass
   def status(self,b):return SimpleNamespace(serial=1,view='public',dynamic=False,secure=False,inline=False,file_identity=(1,))
   def zone_snapshot(self,b):return snap
  result=json.loads(handle(json.dumps(request()).encode(),str(self.p),os.getuid(),Reader));self.assertEqual(result['state'],'observed');self.assertEqual(result['data']['serial'],1)
  with self.assertRaises(ValueError):handle(json.dumps(request('shell')).encode(),str(self.p),os.getuid(),Reader)
 def test_config_replacement_or_removal_during_validation_never_installs(self):
  import dataclasses,tempfile
  import test_zone
  from paymenter_bind.commands import LoadedState
  from paymenter_bind.zone import read_zone
  for mutation in ['replace', 'remove']:
   with self.subTest(mutation=mutation),tempfile.TemporaryDirectory(dir=pathlib.Path(__file__).resolve().parents[4]) as directory:
    root=pathlib.Path(directory);root.chmod(0o700);(root/'zone').write_bytes(test_zone.ZONE);(root/'zone').chmod(0o600)
    self.config['zone_root']=str(root);self.config['state_root']=str(root);self.save();config_path=self.p/'server.json'
    class Revoking:
     def __init__(self,b,budget=30):self.b=b
     def status(self,b):
      snap=read_zone(b);return LoadedState(snap.serial,b.view,False,False,False,snap.image.identity)
     def check(self,c):
      if mutation=='remove':config_path.unlink()
      else:
       candidate=config_path.with_suffix('.replacement');candidate.write_text(json.dumps(dict(version=1,bindings={})));candidate.chmod(0o600);candidate.replace(config_path)
     def reload(self,b):raise AssertionError('revoked request reached reload')
     def notify(self,b):raise AssertionError('revoked request reached notify')
    from paymenter_bind.config import load_binding
    binding=load_binding(self.fd,request()['binding_id'],'replace_records',os.getuid());before=read_zone(binding)
    wire=request('replace_records',dict(expected_serial=before.serial,expected_revision=before.revision,changes=[test_zone.ZoneTest.change(self)]))
    result=json.loads(handle(json.dumps(wire).encode(),str(self.p),os.getuid(),Revoking));self.assertEqual(result['state'],'rejected');self.assertEqual((root/'zone').read_bytes(),test_zone.ZONE)
 def test_forced_dispatch_rejects_unexpected_command(self):
  script=pathlib.Path(__file__).parents[1]/'install/ssh-dispatch';self.assertTrue(script.exists())
  for value in ['','sh','paymenter-bind-v1 extra','sftp']:
   r=subprocess.run(['/bin/sh',str(script)],env={'SSH_ORIGINAL_COMMAND':value},input=b'',capture_output=True,timeout=2);self.assertEqual(r.returncode,126)
 def test_cli_refuses_arguments_before_opening_config(self):
  r=subprocess.run([sys.executable,'-m','paymenter_bind.cli','/etc/shadow'],env=dict(os.environ,PYTHONPATH=str(pathlib.Path(__file__).parents[1])),capture_output=True,timeout=3);self.assertEqual(r.returncode,64)
if __name__=='__main__':unittest.main()
