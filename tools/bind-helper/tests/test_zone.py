import pathlib,sys,tempfile,unittest,os,dataclasses
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:
 from paymenter_bind.config import Binding
 from paymenter_bind.zone import read_zone,apply_rrsets,next_serial,serial_newer
except ImportError:Binding=None
ZONE=b'''$ORIGIN example.test.
$TTL 300
@ IN SOA ns1.example.test. hostmaster.example.test. ( 4294967295 3600 600 86400 300 )
@ IN NS ns1.example.test.
@ IN NS ns2.example.test.
www IN A 192.0.2.1
mail IN MX 10 outside.invalid.
text IN TXT "hello; $INCLUDE" "second line"
key IN TYPE65280 \\# 2 ABCD
'''
class ZoneTest(unittest.TestCase):
 def setUp(self):
  self.assertIsNotNone(Binding,'zone implementation missing');self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=pathlib.Path(self.tmp.name).resolve();self.path=self.root/'zone';self.path.write_bytes(ZONE);self.path.chmod(0o600)
  self.binding=Binding(id='00000000-0000-4000-8000-000000000002',zone='example.test.',view='public',role='primary',file='zone',zone_root=str(self.root),state_root=str(self.root),uid=os.getuid(),gid=os.getgid())
 def change(self,owner='www',kind='A',records=None):return dict(owner=owner,type=kind,ttl=300,records=['192.0.2.2']if records is None else records)
 def test_typed_update_preserves_unknown_and_quoted_txt(self):
  before=read_zone(self.binding);after=apply_rrsets(before,[self.change()],0);self.assertEqual(after.serial,0);self.assertIn('ABCD',after.raw.decode().upper());self.assertIn('$INCLUDE',after.raw.decode());self.assertEqual(self.path.read_bytes(),ZONE)
 def test_idna_and_multiple_addresses(self):
  before=read_zone(self.binding);after=apply_rrsets(before,[self.change('bücher',records=['192.0.2.2','192.0.2.3'])],0);self.assertIn(b'xn--bcher-kva',after.raw)
 def test_invalid_or_unsupported_changes_reject(self):
  for c in [self.change('foreign.invalid.'),self.change('@','CNAME',['else.invalid.']),self.change('www','CNAME',['else.invalid.']),self.change('bad/name'),self.change('bad\\010name'),self.change('www',records=['bad']),self.change('@','NS',['ns.invalid.']),self.change('www','A',[])|{'ttl':0}]:
   with self.subTest(c=c),self.assertRaises(ValueError):apply_rrsets(read_zone(self.binding),[c],0)
 def test_external_directives_journals_and_signing_refuse(self):
  for extra in [b'$INCLUDE /etc/shadow\n',b'$GENERATE 1-5 host$ A 192.0.2.$\n',b'@ IN DNSKEY 256 3 8 AwEAAQ==\n']:
   self.path.write_bytes(ZONE+extra)
   with self.subTest(extra=extra),self.assertRaises(ValueError):read_zone(self.binding)
  self.path.write_bytes(ZONE);self.root.joinpath('zone.jnl').touch()
  with self.assertRaises(ValueError):read_zone(self.binding)
 def test_atomic_cname_conversion_does_not_depend_on_patch_order(self):
  result=apply_rrsets(read_zone(self.binding),[self.change('www','CNAME',['else.invalid.']),self.change('www','A',[])],0)
  self.assertTrue(any(row[2]=='CNAME' for row in result.rrsets))
 def test_serial_wrap_and_ambiguous_half_range(self):
  self.assertEqual(next_serial(4294967295),0);self.assertTrue(serial_newer(0,4294967295));self.assertFalse(serial_newer(1,1))
  with self.assertRaises(ValueError):serial_newer(2147483648,0)
 def test_zone_bound_and_record_limit(self):
  self.path.write_bytes(ZONE+b'x IN TXT "'+b'a'*2097152+b'"\n')
  with self.assertRaises(ValueError):read_zone(self.binding)
  self.path.write_bytes(ZONE+b''.join(('x%d A 192.0.2.1\n'%i).encode()for i in range(501)))
  with self.assertRaises(ValueError):read_zone(self.binding)
if __name__=='__main__':unittest.main()
