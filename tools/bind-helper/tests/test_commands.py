import unittest,pathlib,sys,tempfile,os,time
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:from paymenter_bind.commands import BindCommands,run_bounded
except ImportError:BindCommands=run_bounded=None
import test_zone as fixtures
ZONE=fixtures.ZONE
STATUS='name: example.test\ntype: master\nfiles: zone\nserial: 4294967295\nsecure: no\ninline signing: no\nkey maintenance: none\ndynamic: no\n'
class CommandsTest(unittest.TestCase):
 change=fixtures.ZoneTest.change
 def setUp(self):fixtures.ZoneTest.setUp(self);self.assertIsNotNone(BindCommands,'BIND command adapter missing')
 def test_view_qualified_status_and_fixed_arguments(self):
  calls=[]
  def runner(argv,timeout,limit):calls.append(argv);return STATUS.encode()
  command=BindCommands(self.binding,runner=runner);s=command.status(self.binding);self.assertEqual(s.serial,4294967295);self.assertEqual(calls[0][-3:],['example.test.','IN','public']);self.assertFalse(s.dynamic)
 def test_unknown_or_signed_status_rejects(self):
  for text in [STATUS.replace('inline signing: no\n',''),STATUS.replace('secure: no','secure: yes'),STATUS.replace('dynamic: no','dynamic: maybe'),STATUS.replace('files: zone','files: /etc/shadow')]:
   with self.subTest(text=text),self.assertRaises(ValueError):BindCommands(self.binding,runner=lambda*a:text.encode()).status(self.binding)
 def test_completed_child_is_not_signalled_after_reaping(self):
  from unittest.mock import patch
  with patch('paymenter_bind.commands.os.killpg',wraps=os.killpg)as kill:
   self.assertEqual(run_bounded([sys.executable,'-c','print("ok")'],2,1024),b'ok\n');kill.assert_not_called()
 def test_actual_child_timeout_and_output_bound(self):
  for code in ['import time;time.sleep(5)','print("x"*4096)']:
   start=time.monotonic()
   with self.assertRaises((TimeoutError,ValueError)):run_bounded([sys.executable,'-c',code],0.2,1024)
   self.assertLess(time.monotonic()-start,3)
if __name__=='__main__':unittest.main()
