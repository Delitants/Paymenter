import copy,json,pathlib,sys,unittest
sys.path.insert(0,str(pathlib.Path(__file__).parents[1]))
try:
 from paymenter_bind.protocol import decode_request,encode_result
except ImportError:
 decode_request=encode_result=None
RID='00000000-0000-4000-8000-000000000001';BID='00000000-0000-4000-8000-000000000002'
def request(op='inspect',payload=None):return dict(protocol='paymenter-bind-v1',request_id=RID,binding_id=BID,operation=op,payload={}if payload is None else payload)
class ProtocolTest(unittest.TestCase):
 def setUp(self):self.assertTrue(callable(decode_request),'strict request decoder is missing')
 def raw(self,r):return json.dumps(r,separators=(',',':')).encode()
 def test_five_valid_operations(self):
  for op,p in [('inspect',{}),('prepare_zone',{}),('prepare_secondary',{}),('inspect_request',{'request_id':RID}),('replace_records',{'expected_serial':0,'expected_revision':'a'*64,'changes':[{'owner':'www','type':'A','ttl':300,'records':['192.0.2.1']} ]})]:
   with self.subTest(op=op):self.assertEqual(decode_request(self.raw(request(op,p))),request(op,p))
 def test_rejects_invalid_wire(self):
  raw=self.raw(request())
  cases=[raw+b'{}',raw.replace(b'"payload":{}',b'"payload":{},"operation":"inspect"'),raw.replace(b'"payload":{}',b'"payload":{"a":1,"a":2}'),b'\xff',b' '*32769,raw.replace(b'"payload":{}',b'"payload":[]')]
  for x in cases:
   with self.subTest(raw=x[:80]),self.assertRaises(ValueError):decode_request(x)
 def test_rejects_unknown_fields_types_and_operations(self):
  for key,value in [('protocol','v2'),('request_id','other'),('binding_id',RID.upper().replace('a','A')+'x'),('operation','shell'),('extra',True),('payload',{'path':'/etc/shadow'})]:
   r=request();r[key]=value
   with self.subTest(key=key),self.assertRaises(ValueError):decode_request(self.raw(r))
 def test_replace_typed_bounds(self):
  good=request('replace_records',dict(expected_serial=1,expected_revision='b'*64,changes=[dict(owner='www',type='A',ttl=300,records=['192.0.2.1'])]))
  for key,val in [('expected_serial',True),('expected_serial',4294967296),('expected_revision','bad'),('changes',[]),('changes',[{}]),('changes',good['payload']['changes']*101)]:
   bad=copy.deepcopy(good);bad['payload'][key]=val
   with self.subTest(key=key,val=str(val)[:40]),self.assertRaises(ValueError):decode_request(self.raw(bad))
  for key,val in [('ttl',False),('type','SOA'),('records','text'),('records',[1]),('owner',''),('extra','x')]:
   bad=copy.deepcopy(good);bad['payload']['changes'][0][key]=val
   with self.subTest(key=key),self.assertRaises(ValueError):decode_request(self.raw(bad))
 def test_result_is_bounded_and_echoes_identity(self):
  result=json.loads(encode_result(request(),'observed','OK',{'serial':1}));self.assertEqual(result['binding_id'],BID);self.assertEqual(result['request_id'],RID)
  with self.assertRaises(ValueError):encode_result(request(),'unknown','OK',{})
  with self.assertRaises(ValueError):encode_result(request(),'observed','OK',{'x':'a'*1048576})
 def test_shared_fixtures(self):
  fixtures=pathlib.Path(__file__).parents[3]/'tests/Fixtures/Bind/protocol-v1.json'
  for r in json.loads(fixtures.read_text())['requests']:self.assertEqual(decode_request(self.raw(r)),r)
if __name__=='__main__':unittest.main()
