import pathlib,sys,unittest,dataclasses
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:from paymenter_bind.convergence import Convergence
except ImportError:Convergence=None
import test_zone as fixtures
from paymenter_bind.zone import read_zone
from paymenter_bind.commands import BindCommands,LoadedState
class ConvergenceTest(unittest.TestCase):
 def setUp(self):fixtures.ZoneTest.setUp(self);self.assertIsNotNone(Convergence,'secondary convergence missing')
 def test_matching_and_lagging_are_observed_without_mutation(self):
  snap=read_zone(self.binding)
  class ReadOnly:
   def status(self,b):return LoadedState(snap.serial,b.view,False,False,False,snap.image.identity)
   def zone_snapshot(self,b):return snap
  observer=Convergence(ReadOnly());self.assertEqual(observer.observe(self.binding,snap.serial,snap.revision)['state'],'observed');self.assertEqual(observer.observe(self.binding,0,snap.revision)['state'],'pending_replication')
 def test_secondary_raw_conversion_uses_fixed_bounded_tool(self):
  self.path.write_bytes(b'\x00\x00\x00\x02raw-fixture');b=dataclasses.replace(self.binding,role='secondary');calls=[]
  def run(argv,t,l):calls.append(argv);return fixtures.ZONE
  commands=BindCommands(b,runner=run);snapshot=commands.zone_snapshot(b);self.assertEqual(snapshot.serial,4294967295);self.assertIn('-f',calls[0]);self.assertIn('raw',calls[0]);self.assertEqual(self.path.read_bytes(),b'\x00\x00\x00\x02raw-fixture')
if __name__=='__main__':unittest.main()
