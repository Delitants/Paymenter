import hashlib,io,json,os,pathlib,shutil,struct,sys,tarfile,tempfile,unittest,zipfile
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:from paymenter_bind.runtime_bundle import elf_metadata,verify_archive,build_bundle
except ImportError:elf_metadata=verify_archive=build_bundle=None
class RuntimeBundleTest(unittest.TestCase):
 def setUp(self):self.assertIsNotNone(elf_metadata,'runtime package verifier missing')
 def test_wrong_architecture_and_non_elf_refuse(self):
  for raw in [b'not ELF',b'\x7fELF'+b'\0'*100]:
   with self.assertRaises(ValueError):elf_metadata(raw)
 def test_static_abi_refuses_foreign_machine_and_newer_glibc(self):
  header=struct.pack('<16sHHIQQQIHHHHHH',b'\x7fELF\x02\x01\x01'+b'\0'*9,2,62,1,0,64,0,0,64,56,1,0,0,0)
  interpreter=b'/lib64/ld-linux-x86-64.so.2\0';elf=header+struct.pack('<IIQQQQQQ',3,4,120,0,0,len(interpreter),len(interpreter),1)+interpreter
  self.assertEqual(elf_metadata(elf)['architecture'],'x86_64')
  for raw in [elf[:18]+struct.pack('<H',183)+elf[20:],elf+b'GLIBC_2.18\0']:
   with self.assertRaises(ValueError):elf_metadata(raw)
 def test_archive_requires_manifest_and_has_no_execution_authority(self):
  with tempfile.TemporaryDirectory() as d:
   path=pathlib.Path(d)/'bad.tar.gz'
   with tarfile.open(path,'w:gz') as t:
    data=b'{}';i=tarfile.TarInfo('bundle-manifest.json');i.size=len(data);t.addfile(i,io.BytesIO(data))
   with self.assertRaises(ValueError):verify_archive(path)
 def test_source_digest_checked_before_output_or_unpack(self):
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d);source=root/'source.tgz';source.write_bytes(b'wrong');output=root/'bundle.tgz'
   with self.assertRaises(ValueError):build_bundle(source,'a'*64,'opt/runtime/python',[],output,pathlib.Path(__file__).parents[1])
   self.assertFalse(output.exists());self.assertEqual(sorted(x.name for x in root.iterdir()),['source.tgz'])
 def test_bad_source_member_refuses_without_writing_payload(self):
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d);source=root/'source.tgz'
   with tarfile.open(source,'w:gz') as t:
    for name in ['opt/runtime/python/../../escape','opt/runtime/python/bin/python3.11']:
     i=tarfile.TarInfo(name);i.size=1;t.addfile(i,io.BytesIO(b'x'))
   with self.assertRaises(ValueError):build_bundle(source,hashlib.sha256(source.read_bytes()).hexdigest(),'opt/runtime/python',[],root/'bundle.tgz',pathlib.Path(__file__).parents[1])
   self.assertFalse((root/'escape').exists())
 def test_build_verify_and_tamper_inert_candidate(self):
  with tempfile.TemporaryDirectory(dir=pathlib.Path.cwd()) as d:
   root=pathlib.Path(d);helper=root/'helper';shutil.copytree(pathlib.Path(__file__).parents[1],helper)
   wheels=[];lock=[]
   for name,package in [('dnspython','dns'),('idna','idna')]:
    wheel=root/(name+'-1.0-py3-none-any.whl')
    with zipfile.ZipFile(wheel,'w') as z:z.writestr(package+'/__init__.py',b'')
    sha=hashlib.sha256(wheel.read_bytes()).hexdigest();wheels.append(wheel);lock.append(name+'==1.0 --hash=sha256:'+sha)
   (helper/'requirements.lock').write_text('\n'.join(lock)+'\n');manifest=json.loads((helper/'package-manifest.json').read_text());manifest['files']['requirements.lock']=hashlib.sha256((helper/'requirements.lock').read_bytes()).hexdigest();(helper/'package-manifest.json').write_text(json.dumps(manifest))
   source=root/'source.tgz';header=struct.pack('<16sHHIQQQIHHHHHH',b'\x7fELF\x02\x01\x01'+b'\0'*9,2,62,1,0,64,0,0,64,56,1,0,0,0)
   interpreter=b'/lib64/ld-linux-x86-64.so.2\0';elf=header+struct.pack('<IIQQQQQQ',3,4,120,0,0,len(interpreter),len(interpreter),1)+interpreter
   members={'bin/python3.11':elf,'lib/python3.11/os.py':b'','lib/python3.11/encodings/__init__.py':b'','include/python3.11/patchlevel.h':b'#define PY_VERSION "3.11.14"\n'}
   with tarfile.open(source,'w:gz') as t:
    for name,data in members.items():
     i=tarfile.TarInfo('opt/runtime/python/'+name);i.size=len(data);i.mode=0o755 if name.startswith('bin/') else 0o644;t.addfile(i,io.BytesIO(data))
   output=root/'bundle.tgz';result=build_bundle(source,hashlib.sha256(source.read_bytes()).hexdigest(),'opt/runtime/python',wheels,output,helper)
   self.assertFalse(result['native_accepted']);self.assertFalse(result['installation_authorized']);self.assertFalse(result['elf']['runtime/bin/python3.11']['relocation_verified'])
   self.assertEqual(result,verify_archive(output))
   staged=root/'staged';staged.mkdir()
   # Extraction is confined to this synthetic, freshly verified test fixture.
   with tarfile.open(output) as t:t.extractall(staged,filter='data')
   from paymenter_bind.runtime_admission import verify_tree
   self.assertEqual(verify_tree(staged,os.getuid()),result)
   extra=staged/'unexpected';extra.write_bytes(b'extra')
   with self.assertRaises(ValueError):verify_tree(staged,os.getuid())
   extra.unlink();link=staged/'venv/bin/python3';link.unlink();link.symlink_to('/usr/bin/python3')
   with self.assertRaises(ValueError):verify_tree(staged,os.getuid())
   link.unlink();link.symlink_to('../../runtime/bin/python3.11')
   (staged/'runtime/bin/python3.11').write_bytes(b'tampered')
   with self.assertRaises(ValueError):verify_tree(staged,os.getuid())
   altered=root/'tampered.tgz'
   with tarfile.open(output) as src,tarfile.open(altered,'w:gz') as dst:
    for item in src:
     data=src.extractfile(item).read() if item.isfile() else None
     if item.name=='runtime/bin/python3.11':data=b'tampered';item.size=len(data)
     dst.addfile(item,io.BytesIO(data) if data is not None else None)
   with self.assertRaises(ValueError):verify_archive(altered)
if __name__=='__main__':unittest.main()
