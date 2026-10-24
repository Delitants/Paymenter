"""Typed static DNS zones; no includes, generated records or signing workflows."""
import hashlib,json,os
from dataclasses import dataclass
import dns.zone,dns.name,dns.rdata,dns.rdataclass,dns.rdatatype,dns.rdataset
from .custody import RootDirectory,FileImage,LIMIT
from .config import Binding
from .protocol import need,TYPES
SIGNED={'DNSKEY','RRSIG','NSEC','NSEC3','NSEC3PARAM','CDS','CDNSKEY'}
@dataclass(frozen=True)
class ZoneSnapshot:
 binding:Binding;raw:bytes;serial:int;revision:str;rrsets:tuple;image:FileImage|None=None
ZoneCandidate=ZoneSnapshot
def serial_newer(a,b):
 need(type(a)is int and type(b)is int and 0<=a<=4294967295 and 0<=b<=4294967295);diff=(a-b)&0xffffffff;need(diff!=2147483648);return 0<diff<2147483648
def next_serial(current):need(type(current)is int and 0<=current<=4294967295);return(current+1)&0xffffffff
def parse(raw,binding):
 need(type(raw)is bytes and len(raw)<=LIMIT)
 try:
  z=dns.zone.from_text(raw.decode('utf-8'),origin=binding.zone,relativize=False,allow_include=False,allow_directives=['$ORIGIN','$TTL'],check_origin=True)
  rows=[];count=0
  for owner,node in z.nodes.items():
   need(owner.is_subdomain(z.origin))
   for rd in node.rdatasets:
    kind=dns.rdatatype.to_text(rd.rdtype);need(kind not in SIGNED and rd.rdclass==dns.rdataclass.IN);count+=len(rd)
    rows.append((owner.to_text(),rd.ttl,kind,tuple(sorted(r.to_text(origin=z.origin,relativize=False)for r in rd))))
  need(count<=500);rows=tuple(sorted(rows));soa=z.get_rdataset(z.origin,'SOA');need(soa is not None and len(soa)==1)
  return z,rows,soa[0].serial
 except (ValueError,UnicodeError,dns.exception.DNSException)as e:raise ValueError('Unsupported or invalid static zone')from e
def snapshot(raw,binding,image=None):
 _,rows,serial=parse(raw,binding);revision=hashlib.sha256(json.dumps(rows,separators=(',',':'),ensure_ascii=True).encode()).hexdigest();return ZoneSnapshot(binding,raw,serial,revision,rows,image)
def read_zone(binding):
 with RootDirectory(binding.zone_root,binding.owner_uid)as root:
  for suffix in ['.jnl','.signed','.signed.jnl']:need(not root.exists(binding.file+suffix))
  image=root.read(binding.file,uid=binding.uid)
 return snapshot(image.raw,binding,image)
def owner_name(owner,origin):
 need(type(owner)is str and owner and not any(ord(c)<32 or ord(c)==127 or c in '/\\'for c in owner))
 try:name=origin if owner=='@'else dns.name.from_unicode(owner,origin=origin,idna_codec=dns.name.IDNA_2008_Practical)
 except (ValueError,dns.exception.DNSException,UnicodeError)as e:raise ValueError('Invalid owner')from e
 need(name.is_subdomain(origin)and all(all(32<=v<127 for v in label)for label in name.labels));return name
def apply_rrsets(before,changes,serial):
 need(type(changes)is list and 1<=len(changes)<=100 and serial_newer(serial,before.serial));b=before.binding;z,old,_=parse(before.raw,b)
 changed=set();prepared=[]
 try:
  for c in changes:
   need(type(c)is dict and set(c)=={'owner','type','ttl','records'}and c['type']in TYPES and type(c['ttl'])is int and b.ttl_min<=c['ttl']<=b.ttl_max and type(c['records'])is list and len(c['records'])<=500)
   name=owner_name(c['owner'],z.origin);kind=c['type'];need((name,kind)not in changed);changed.add((name,kind));need(not(name==z.origin and kind=='CNAME'))
   if kind=='CNAME':need(len(c['records'])<=1)
   prepared.append((name,kind,c))
  for name,kind,c in prepared:z.delete_rdataset(name,kind)
  for name,kind,c in prepared:
   if not c['records']:continue
   rd=dns.rdataset.Rdataset(dns.rdataclass.IN,dns.rdatatype.from_text(kind));rd.ttl=c['ttl']
   for text in c['records']:
    need(type(text)is str and text and '\x00'not in text);data=dns.rdata.from_text(rd.rdclass,rd.rdtype,text,origin=z.origin,relativize=False);need(data not in rd);rd.add(data)
   if kind=='CNAME':need(not any(r.rdtype!=dns.rdatatype.CNAME for r in z.get_node(name).rdatasets)if z.get_node(name)is not None else True)
   elif z.get_rdataset(name,'CNAME')is not None:need(False)
   z.replace_rdataset(name,rd)
  soa=z.get_rdataset(z.origin,'SOA');soa_data=soa[0].replace(serial=serial);newsoa=dns.rdataset.Rdataset(soa.rdclass,soa.rdtype);newsoa.ttl=soa.ttl;newsoa.add(soa_data);z.replace_rdataset(z.origin,newsoa)
  raw=z.to_text(relativize=False,sorted=True).encode();after=snapshot(raw,b)
  excluded={(name.to_text(),kind)for name,kind in changed}|{(z.origin.to_text(),'SOA')}
  need(tuple(r for r in old if(r[0],r[2])not in excluded)==tuple(r for r in after.rrsets if(r[0],r[2])not in excluded));return after
 except (ValueError,UnicodeError,dns.exception.DNSException)as e:raise ValueError('Invalid record change')from e
