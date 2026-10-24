import pathlib,sys,tempfile,unittest,os,dataclasses
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:
 from paymenter_bind.config import Binding
 from paymenter_bind.custody import locked_zone,RootDirectory
except ImportError:Binding=None
class CustodyTest(unittest.TestCase):
 def setUp(self):
  self.assertIsNotNone(Binding,'custody implementation missing');self.t=tempfile.TemporaryDirectory();self.addCleanup(self.t.cleanup);self.root=pathlib.Path(self.t.name).resolve();(self.root/'state').mkdir(mode=0o700);(self.root/'zone').write_bytes(b'before');(self.root/'zone').chmod(0o600)
  self.b=Binding(id='00000000-0000-4000-8000-000000000002',zone='example.test.',view='public',role='primary',file='zone',zone_root=str(self.root),state_root=str(self.root/'state'),uid=os.getuid(),gid=os.getgid())
 def test_descriptor_relative_atomic_replace(self):
  with locked_zone(self.b)as owned:
   before=owned.read();owned.install(b'after','00000000-0000-4000-8000-000000000001',before);self.assertEqual(owned.read().raw,b'after')
  self.assertEqual((self.root/'zone').read_bytes(),b'after')
 def test_leaf_symlink_hardlink_or_parent_substitution_refuse(self):
  (self.root/'zone').unlink();(self.root/'foreign').write_bytes(b'foreign');(self.root/'zone').symlink_to('foreign')
  with self.assertRaises((ValueError,OSError)):
   with locked_zone(self.b)as owned:owned.read()
  (self.root/'zone').unlink();os.link(self.root/'foreign',self.root/'zone')
  with self.assertRaises(ValueError):
   with locked_zone(self.b)as owned:owned.read()
 def test_replaced_file_and_root_are_not_overwritten(self):
  with locked_zone(self.b)as owned:
   before=owned.read();(self.root/'swap').write_bytes(b'foreign');os.replace(self.root/'swap',self.root/'zone')
   with self.assertRaises(ValueError):owned.install(b'after','00000000-0000-4000-8000-000000000001',before)
  self.assertEqual((self.root/'zone').read_bytes(),b'foreign')
 def test_bad_lock_closes_owned_descriptors(self):
  (self.root/'state'/ (self.b.id+'.lock')).write_bytes(b'bad');(self.root/'state'/(self.b.id+'.lock')).chmod(0o666)
  owned=locked_zone(self.b)
  with self.assertRaises(ValueError):owned.__enter__()
  self.assertIsNone(owned.root.fd);self.assertIsNone(owned.state.fd)
 def test_root_substitution_refuses_before_foreign_write(self):
  moved=self.root.with_name(self.root.name+'-moved')
  with locked_zone(self.b)as owned:
   before=owned.read();os.rename(self.root,moved);self.root.mkdir(mode=0o700);(self.root/'zone').write_bytes(b'foreign')
   try:
    with self.assertRaises(ValueError):owned.install(b'after','00000000-0000-4000-8000-000000000001',before)
    self.assertEqual((self.root/'zone').read_bytes(),b'foreign')
   finally:(self.root/'zone').unlink();self.root.rmdir();os.rename(moved,self.root)
 def test_nested_mount_identity_refuses(self):
  from unittest.mock import patch
  from types import SimpleNamespace
  sub=self.root/'sub';sub.mkdir();(sub/'file').write_bytes(b'text');ino=sub.stat().st_ino;real=os.fstat
  def altered(fd):
   s=real(fd)
   return SimpleNamespace(st_dev=s.st_dev+1,st_ino=s.st_ino,st_uid=s.st_uid,st_gid=s.st_gid,st_mode=s.st_mode)if s.st_ino==ino else s
  with RootDirectory(str(self.root),os.getuid())as root:
   with patch('paymenter_bind.custody.os.fstat',side_effect=altered),self.assertRaises(ValueError):root.read('sub/file')
 def test_unsafe_directory_refuses(self):
  self.root.chmod(0o777)
  with self.assertRaises(ValueError):RootDirectory(str(self.root),os.getuid())
if __name__=='__main__':unittest.main()
