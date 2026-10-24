import sys,pathlib,unittest,dataclasses
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:from paymenter_bind.operations import Operations
except ImportError:Operations=None
import test_zone as fixtures
ZONE=fixtures.ZONE
from test_protocol import request
from paymenter_bind.zone import read_zone
from paymenter_bind.custody import RootDirectory
from paymenter_bind.commands import LoadedState
class Commands:
 def guard_install(self):pass
 def __init__(self,b,fail=False):self.b=b;self.fail=fail;self.events=[];self.loaded=4294967295
 def status(self,b):
  self.events.append('status');image=read_zone(b).image
  return LoadedState(self.loaded,b.view,False,False,False,image.identity)
 def check(self,c):self.events.append('check')
 def reload(self,b):
  self.events.append('reload')
  if self.fail:self.fail=False;raise ValueError('injected reload failure')
  self.loaded=read_zone(b).serial
 def notify(self,b):self.events.append('notify')
class OperationTest(unittest.TestCase):
 change=fixtures.ZoneTest.change
 def setUp(self):fixtures.ZoneTest.setUp(self);self.assertIsNotNone(Operations,'primary operation coordinator missing')
 def wire(self):
  s=read_zone(self.binding);return request('replace_records',dict(expected_serial=s.serial,expected_revision=s.revision,changes=[self.change()]))
 def test_native_files_commit_and_no_replay(self):
  commands=Commands(self.binding);r=self.wire();op=Operations(self.binding,commands);result=op.replace(r,self.binding);self.assertEqual(result['state'],'committed');self.assertEqual(read_zone(self.binding).serial,0);count=len(commands.events);self.assertEqual(op.replace(r,self.binding),result);self.assertEqual(len(commands.events),count)
 def test_conflict_and_missing_runtime_flags_never_install(self):
  r=self.wire();r['payload']['expected_revision']='a'*64
  result=Operations(self.binding,Commands(self.binding)).replace(r,self.binding);self.assertEqual(result['state'],'rejected');self.assertEqual(result['code'],'REVISION_CONFLICT');self.assertEqual(self.path.read_bytes(),ZONE)
 def test_reload_failure_restores_rrsets_with_new_serial(self):
  before=read_zone(self.binding);result=Operations(self.binding,Commands(self.binding,True)).replace(self.wire(),self.binding);self.assertEqual(result['state'],'rejected');after=read_zone(self.binding);self.assertEqual(after.serial,1)
  self.assertEqual([r for r in before.rrsets if r[2]!='SOA'],[r for r in after.rrsets if r[2]!='SOA'])
 def test_crash_after_install_retains_uncertain_journal(self):
  class Crashing(Commands):
   def reload(self,b):raise KeyboardInterrupt()
  r=self.wire();op=Operations(self.binding,Crashing(self.binding))
  with self.assertRaises(KeyboardInterrupt):op.replace(r,self.binding)
  result=Operations(self.binding,Commands(self.binding)).replace(r,self.binding);self.assertEqual(result['state'],'uncertain');self.assertEqual(read_zone(self.binding).serial,0)
if __name__=='__main__':unittest.main()
