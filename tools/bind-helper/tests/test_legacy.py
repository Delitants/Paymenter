import dataclasses
import hashlib
import os
import pathlib
import sys
import unittest
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
import test_zone as fixtures
from test_commands import STATUS
from paymenter_bind.commands import BindCommands
from paymenter_bind.operations import Operations
from test_protocol import request
from paymenter_bind.zone import read_zone
VERSION='BIND 9.11.4-P2-TestBuild <id:fixture>'
OLD_STATUS=STATUS.replace('inline signing: no\n','').replace('key maintenance: none\n','')

class LegacyTest(unittest.TestCase):
 def setUp(self):
  fixtures.ZoneTest.setUp(self)
  paths={}
  for name in ['named','rndc','checkconf','checkzone','compilezone']:
   path=self.root/name;path.write_bytes((name+' fixture executable').encode());path.chmod(0o700);paths[name]=str(path)
  self.pins=tuple((name,hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest())for name,path in sorted(paths.items()))
  self.binding=dataclasses.replace(self.binding,**paths,status_profile='bind-9.11.4-p2',expected_version=VERSION,binary_pins=self.pins)
  self.config='options { directory "'+str(self.root)+'"; }; view "public" IN { zone "example.test" IN { type master; file "'+str(self.path)+'"; }; };'
  self.calls=[];main=self.root/'named.conf';main.write_text(self.config);main.chmod(0o600);self.binding=dataclasses.replace(self.binding,main_config=str(main))
 def runner(self,argv,timeout,limit):
  self.calls.append(argv)
  if argv==[self.binding.named,'-v']:return (VERSION+'\n').encode()
  if argv==[self.binding.rndc,'status']:return ('version: '+VERSION.removeprefix('BIND ')+'\nserver is up and running\n').encode()
  if argv[0]==self.binding.checkconf:return self.config.encode() if '-p'in argv else b''
  if argv[1]=='zonestatus':return OLD_STATUS.encode()
  return b''
 def test_explicit_legacy_profile_proves_missing_flags_from_pinned_config(self):
  s=BindCommands(self.binding,self.runner).status(self.binding);self.assertFalse(s.inline);self.assertFalse(s.dynamic);self.assertEqual(s.serial,4294967295)
  self.assertIn([self.binding.checkconf,'-p','-x',self.binding.main_config],self.calls)
 def test_wrong_binary_version_or_running_version_rejects(self):
  for which in ['binary','disk-version','running-version']:
   with self.subTest(which=which):
    if which=='binary':pathlib.Path(self.binding.named).write_bytes(b'changed')
    def runner(argv,*args):
     if which=='disk-version' and argv==[self.binding.named,'-v']:return b'BIND 9.20.0\n'
     if which=='running-version' and argv==[self.binding.rndc,'status']:return b'version: 9.11.4-P2-Different\n'
     return self.runner(argv,*args)
    with self.assertRaises(ValueError):BindCommands(self.binding,runner).status(self.binding)
    pathlib.Path(self.binding.named).write_bytes(b'named fixture executable')
 def test_binary_mismatch_prevents_even_initial_status_execution(self):
  pathlib.Path(self.binding.rndc).write_bytes(b'changed')
  with self.assertRaises(ValueError):BindCommands(self.binding,self.runner).status(self.binding)
  self.assertEqual(self.calls,[])
 def test_inherited_signing_and_unredacted_keys_refuse(self):
  original=self.config
  for text in [original.replace('directory ', 'inline-signing yes; directory '),original.replace('type master;', 'type master;')+' key "test" { algorithm hmac-sha256; secret "not-redacted"; };']:
   self.config=text
   with self.assertRaises(ValueError):BindCommands(self.binding,self.runner).status(self.binding)
 def test_signing_dynamic_ambiguous_wrong_zone_and_view_reject(self):
  original=self.config
  for body in ['inline-signing yes;','auto-dnssec maintain;','allow-update { 192.0.2.1; };','update-policy local;','inline-signing no; inline-signing no;']:
   self.config=original.replace('type master;','type master; '+body)
   with self.subTest(body=body),self.assertRaises(ValueError):BindCommands(self.binding,self.runner).status(self.binding)
  for text in [original.replace('"public"','"other"'),original.replace('"example.test"','"foreign.test"'),original.replace('type master;','type slave;'),original.replace('view "public" IN','view "public" CH'),original+original]:
   self.config=text
   with self.subTest(text=text),self.assertRaises(ValueError):BindCommands(self.binding,self.runner).status(self.binding)
 def test_unsafe_reported_flags_never_overridden_by_config(self):
  for status in [OLD_STATUS.replace('secure: no','secure: yes'),OLD_STATUS+'inline signing: yes\n',OLD_STATUS+'key maintenance: maintain\n']:
   def runner(argv,*args):return status.encode() if len(argv)>1 and argv[1]=='zonestatus' else self.runner(argv,*args)
   with self.assertRaises(ValueError):BindCommands(self.binding,runner).status(self.binding)
 def test_config_change_during_validation_cannot_install(self):
  before=read_zone(self.binding)
  def runner(argv,*args):
   if argv[0]==self.binding.checkzone:self.config=self.config.replace('type master;','type master; inline-signing yes;')
   return self.runner(argv,*args)
  r=request('replace_records',{'expected_serial':before.serial,'expected_revision':before.revision,'changes':[fixtures.ZoneTest.change(self)]})
  result=Operations(self.binding,BindCommands(self.binding,runner)).replace(r,self.binding)
  self.assertEqual(result['state'],'rejected');self.assertEqual(self.path.read_bytes(),fixtures.ZONE)
 def test_late_policy_change_is_checked_at_install_boundary(self):
  commands=BindCommands(self.binding,self.runner);commands.status(self.binding);self.config=self.config.replace('type master;','type master; auto-dnssec allow;')
  with self.assertRaises(ValueError):commands.guard_install()
 def test_unsafe_canonical_input_and_inline_marker_refuse(self):
  pathlib.Path(self.binding.main_config).chmod(0o666)
  with self.assertRaises(ValueError):BindCommands(self.binding,self.runner).status(self.binding)
  pathlib.Path(self.binding.main_config).chmod(0o600)
  for extra in ['signed serial: 10\n','key maintenance: none\n']:
   def runner(argv,*args):return (OLD_STATUS+extra).encode() if len(argv)>1 and argv[1]=='zonestatus' else self.runner(argv,*args)
   with self.assertRaises(ValueError):BindCommands(self.binding,runner).status(self.binding)
 def test_legacy_lifecycle_validates_prospective_policy_before_creation(self):
  from paymenter_bind.lifecycle import Lifecycle
  self.path.unlink();include=self.root/'managed.conf';include.write_text('// managed\n');include.chmod(0o600)
  main=pathlib.Path(self.binding.main_config);main.write_text('options { directory "'+str(self.root)+'"; }; view "public" { include "'+str(include)+'"; };')
  b=dataclasses.replace(self.binding,managed_include=str(include),primary='192.0.2.10',secondary='192.0.2.20',nameservers=('ns1.example.test.','ns2.example.test.'))
  def runner(argv,*args):
   if argv[0]==b.checkconf and '-p'in argv:
    import re
    text=pathlib.Path(argv[-1]).read_text();return re.sub(r'include "([^"]+)";',lambda m:pathlib.Path(m[1]).read_text(),text).encode()
   if len(argv)>1 and argv[1]=='zonestatus':return OLD_STATUS.replace('4294967295',str(read_zone(b).serial)).encode()
   return self.runner(argv,*args)
  result=Lifecycle(b,BindCommands(b,runner)).prepare_primary(request('prepare_zone'),b)
  self.assertEqual(result['state'],'committed');self.assertEqual(read_zone(b).serial,1);self.assertIn([b.rndc,'reconfig'],self.calls)
 def test_root_owned_executable_alias_and_hardlink_are_pinned(self):
  target=pathlib.Path(self.binding.named);alias=self.root/'named-alias';alias.symlink_to(target.name)
  b=dataclasses.replace(self.binding,named=str(alias))
  def runner(argv,*args):return (VERSION+'\n').encode() if argv==[b.named,'-v'] else self.runner(argv,*args)
  BindCommands(b,runner).status(b);alias.unlink();alias.write_bytes(b'wrong');alias.chmod(0o700)
  with self.assertRaises(ValueError):BindCommands(b,runner).status(b)
  alias.unlink();os.link(target,alias);BindCommands(b,runner).status(b)
if __name__=='__main__':unittest.main()
