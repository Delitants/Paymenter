"""Bounded wire protocol shared with the native PHP extension."""
import json,re
VERSION='paymenter-bind-v1';INPUT_LIMIT=32768;OUTPUT_LIMIT=1048576
OPS={'inspect','replace_records','prepare_zone','prepare_secondary','inspect_request'}
STATES={'observed','committed','pending_replication','rejected','uncertain'}
TYPES={'A','AAAA','CNAME','MX','TXT','CAA','SRV'}
UUID=re.compile(r'[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z')
def need(v):
 if not v:raise ValueError('Invalid BIND protocol')
def uuid(v):return type(v)is str and UUID.fullmatch(v)is not None
def keys(v,expected):need(type(v)is dict and set(v)==set(expected))
def pairs(items):
 r={}
 for k,v in items:need(k not in r);r[k]=v
 return r
def validate_request(r):
 keys(r,['protocol','request_id','binding_id','operation','payload']);need(r['protocol']==VERSION and uuid(r['request_id'])and uuid(r['binding_id'])and type(r['operation'])is str and r['operation']in OPS)
 p=r['payload'];op=r['operation']
 if op in {'inspect','prepare_zone','prepare_secondary'}:keys(p,[])
 elif op=='inspect_request':keys(p,['request_id']);need(uuid(p['request_id']))
 else:
  keys(p,['expected_serial','expected_revision','changes']);need(type(p['expected_serial'])is int and 0<=p['expected_serial']<=4294967295 and type(p['expected_revision'])is str and re.fullmatch('[a-f0-9]{64}',p['expected_revision'])is not None)
  need(type(p['changes'])is list and 1<=len(p['changes'])<=100)
  seen=set()
  for c in p['changes']:
   keys(c,['owner','type','ttl','records']);need(type(c['owner'])is str and 0<len(c['owner'])<=253 and type(c['type'])is str and c['type']in TYPES and type(c['ttl'])is int and 1<=c['ttl']<=2147483647 and type(c['records'])is list and len(c['records'])<=500 and all(type(x)is str and x for x in c['records']))
   ident=(c['owner'].lower(),c['type']);need(ident not in seen);seen.add(ident)
 return r
def decode_request(raw):
 need(type(raw)is bytes and 0<len(raw)<=INPUT_LIMIT)
 try:r=json.loads(raw.decode('utf-8'),object_pairs_hook=pairs,parse_constant=lambda _:need(False))
 except (UnicodeError,json.JSONDecodeError,RecursionError)as e:raise ValueError('Invalid BIND JSON')from e
 return validate_request(r)
def encode_result(request,state,code,data):
 need(state in STATES and type(code)is str and re.fullmatch('[A-Z][A-Z0-9_]{0,63}',code)is not None and type(data)is dict)
 out=dict(protocol=VERSION,request_id=request['request_id'],binding_id=request['binding_id'],state=state,code=code,data=data)
 try:raw=json.dumps(out,separators=(',',':'),ensure_ascii=False,allow_nan=False).encode()
 except (UnicodeError,ValueError,RecursionError)as e:raise ValueError('Invalid BIND result')from e
 need(len(raw)<=OUTPUT_LIMIT);return raw
