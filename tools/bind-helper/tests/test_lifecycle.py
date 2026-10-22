import pathlib,sys,unittest,dataclasses
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:from paymenter_bind.lifecycle import Lifecycle,replace_include
except ImportError:Lifecycle=None;replace_include=None
import test_zone as fixtures
from test_protocol import request
class LifecycleCommands:
 def __init__(self):self.validated=[];self.reconfigured=0
 def check(self,c):self.validated.append('zone')
 def check_config(self,p):self.validated.append(pathlib.Path(p).read_text())
 def reconfigure(self):self.reconfigured+=1
 def status(self,b):
  if b.role=='secondary':raise ValueError('not transferred yet')
  from paymenter_bind.zone import read_zone
  from paymenter_bind.commands import LoadedState
  snap=read_zone(b);return LoadedState(snap.serial,b.view,False,False,False,snap.image.identity)
class LifecycleTest(unittest.TestCase):
 def setUp(self):
  fixtures.ZoneTest.setUp(self);self.assertIsNotNone(Lifecycle,'zone lifecycle missing');self.path.unlink();self.include=self.root/'managed.conf';self.include.write_text('// managed zones\n');self.include.chmod(0o600);self.main=self.root/'named.conf';self.main.write_text('view "public" { include "'+str(self.include)+'"; };\n');self.main.chmod(0o600)
  self.binding=dataclasses.replace(self.binding,main_config=str(self.main),managed_include=str(self.include),primary='192.0.2.10',secondary='192.0.2.20',nameservers=('ns1.example.test.','ns2.example.test.'))
 def test_primary_create_is_validated_and_replayed_without_mutation(self):
  c=LifecycleCommands();r=request('prepare_zone');l=Lifecycle(self.binding,c);result=l.prepare_primary(r,self.binding);self.assertEqual(result['state'],'committed');self.assertTrue(self.path.exists());self.assertIn('allow-transfer { 192.0.2.20; };',self.include.read_text());count=c.reconfigured;self.assertEqual(l.prepare_primary(r,self.binding),result);self.assertEqual(c.reconfigured,count);self.assertIn('zone',c.validated)
 def test_existing_file_and_wrong_view_remain_untouched(self):
  self.path.write_bytes(b'foreign');result=Lifecycle(self.binding,LifecycleCommands()).prepare_primary(request('prepare_zone'),self.binding);self.assertEqual(result['state'],'rejected');self.assertEqual(self.path.read_bytes(),b'foreign')
  with self.assertRaises(ValueError):replace_include(self.main.read_text(),str(self.include),'/safe/candidate','other')
 def test_secondary_creates_only_narrow_declaration(self):
  b=dataclasses.replace(self.binding,role='secondary');result=Lifecycle(b,LifecycleCommands()).prepare_secondary(request('prepare_secondary'),b);self.assertEqual(result['state'],'pending_replication');self.assertFalse(self.path.exists());self.assertIn('masters { 192.0.2.10; };',self.include.read_text());self.assertIn('masterfile-format text;',self.include.read_text());self.assertIn('allow-transfer { none; };',self.include.read_text())
 def test_config_failure_leaves_files_unchanged(self):
  class Invalid(LifecycleCommands):
   def check_config(self,p):raise ValueError('bad config')
  before=self.include.read_bytes();result=Lifecycle(self.binding,Invalid()).prepare_primary(request('prepare_zone'),self.binding);self.assertEqual(result['state'],'rejected');self.assertFalse(self.path.exists());self.assertEqual(self.include.read_bytes(),before)
 def test_primary_loaded_serial_must_match_created_zone(self):
  from dataclasses import replace
  class Wrong(LifecycleCommands):
   def status(self,b):return replace(super().status(b),serial=7)
  result=Lifecycle(self.binding,Wrong()).prepare_primary(request('prepare_zone'),self.binding);self.assertEqual(result['state'],'uncertain')
 def test_unconfirmed_primary_load_is_uncertain_not_secondary_lag(self):
  class Missing(LifecycleCommands):
   def status(self,b):raise ValueError('primary load unconfirmed')
  result=Lifecycle(self.binding,Missing()).prepare_primary(request('prepare_zone'),self.binding);self.assertEqual(result['state'],'uncertain');self.assertTrue(self.path.exists())
 def test_failure_after_zone_rename_is_uncertain_and_retained(self):
  from unittest.mock import patch
  from paymenter_bind.custody import LockedZone
  original=LockedZone.install
  def install_then_fail(owned,*args):original(owned,*args);raise OSError('lost readback')
  with patch.object(LockedZone,'install',install_then_fail):result=Lifecycle(self.binding,LifecycleCommands()).prepare_primary(request('prepare_zone'),self.binding)
  self.assertTrue(self.path.exists());self.assertEqual(result['state'],'uncertain')
 def test_authority_revoked_during_config_check_preserves_zone_and_include(self):
  from paymenter_bind.protocol import need
  active=[True]
  b=dataclasses.replace(self.binding,authority=lambda:need(active[0]))
  class Revoking(LifecycleCommands):
   def check_config(self,p):active[0]=False
  before=self.include.read_bytes();result=Lifecycle(b,Revoking()).prepare_primary(request('prepare_zone'),b)
  self.assertEqual(result['state'],'rejected');self.assertFalse(self.path.exists());self.assertEqual(self.include.read_bytes(),before)
 def test_include_context_and_comments_are_parsed(self):
  text='// include "'+str(self.include)+'";\nview "other" { include "'+str(self.include)+'"; };'
  with self.assertRaises(ValueError):replace_include(text,str(self.include),'/safe/candidate','public')
if __name__=='__main__':unittest.main()
