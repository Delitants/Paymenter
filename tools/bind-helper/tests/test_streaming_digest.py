import hashlib,os,pathlib,sys,tempfile,unittest
from unittest.mock import patch
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
from paymenter_bind.custody import RootDirectory

class StreamingDigestTest(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory(dir=pathlib.Path(__file__).parents[1]);self.addCleanup(self.tmp.cleanup);self.root=pathlib.Path(self.tmp.name);(self.root/'runtime').mkdir();self.path=self.root/'runtime/file';self.data=b'public bytes'*20000;self.path.write_bytes(self.data);self.path.chmod(0o640)
 def digest(self,limit=1024*1024):
  with RootDirectory(str(self.root),os.getuid()) as root:
   fn=getattr(root,'digest',None);self.assertTrue(callable(fn),'bounded custody digest missing');return fn('runtime/file',limit)
 def test_digest_and_metadata_match_actual_file(self):
  digest=self.digest();self.assertEqual(digest.sha256,hashlib.sha256(self.data).hexdigest());self.assertEqual(digest.size,len(self.data));self.assertEqual(digest.mode,0o640);self.assertEqual(digest.uid,os.getuid());self.assertFalse(hasattr(digest,'raw'))
 def test_oversize_symlink_and_hardlink_refuse(self):
  with self.assertRaises(ValueError):self.digest(len(self.data)-1)
  self.path.rename(self.root/'original');self.path.symlink_to('../original')
  with self.assertRaises((ValueError,OSError)):self.digest()
  self.path.unlink();os.link(self.root/'original',self.path)
  with self.assertRaises(ValueError):self.digest()
 def test_same_name_substitution_during_read_refuses(self):
  read=os.read;changed=False
  def replaced(fd,size):
   nonlocal changed
   value=read(fd,size)
   if not changed:
    changed=True;self.path.rename(self.root/'original');self.path.write_bytes(self.data);self.path.chmod(0o640)
   return value
  with patch('paymenter_bind.custody.os.read',replaced):
   with self.assertRaises(ValueError):self.digest()
 def test_nested_parent_substitution_during_read_refuses(self):
  read=os.read;changed=False
  def replaced(fd,size):
   nonlocal changed
   value=read(fd,size)
   if not changed:
    changed=True;(self.root/'runtime').rename(self.root/'retained');(self.root/'runtime').mkdir();self.path.write_bytes(self.data);self.path.chmod(0o640)
   return value
  with patch('paymenter_bind.custody.os.read',replaced):
   with self.assertRaises(ValueError):self.digest()
 def test_content_growth_during_read_refuses(self):
  read=os.read;changed=False
  def extended(fd,size):
   nonlocal changed
   value=read(fd,size)
   if not changed:
    changed=True
    with self.path.open('ab') as f:f.write(b'changed')
   return value
  with patch('paymenter_bind.custody.os.read',extended):
   with self.assertRaises(ValueError):self.digest()
if __name__=='__main__':unittest.main()
