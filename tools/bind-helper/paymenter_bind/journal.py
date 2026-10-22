"""Immutable claims and terminal outcomes. An interrupted claim is never replayed."""
import hashlib,json,time
from .custody import RootDirectory
from .protocol import need,uuid,VERSION,STATES

def encoded(value):return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()
class Attempt:
 def __init__(self,journal,request,fresh,result):self.journal=journal;self.request=request;self.fresh=fresh;self.result=result
 def record(self,state,data,code='OK'):
  need(self.fresh and state in STATES and type(data)is dict)
  result=dict(protocol=VERSION,request_id=self.request['request_id'],binding_id=self.request['binding_id'],state=state,code=code,data=data)
  with RootDirectory(self.journal.root,self.journal.uid,True)as root:root.save(self.request['request_id']+'.result.json',encoded(result))
  self.result=result;self.fresh=False;return result
class Journal:
 def __init__(self,root,uid=0):self.root=root;self.uid=uid
 def claim(self,request):
  need(uuid(request['request_id'])and uuid(request['binding_id']));name=request['request_id'];digest=hashlib.sha256(encoded(request)).hexdigest()
  with RootDirectory(self.root,self.uid,True)as root:
   claim=dict(request_sha256=digest,binding_id=request['binding_id'],operation=request['operation'],observed_unix=int(time.time()))
   try:root.save(name+'.claim.json',encoded(claim));fresh=True
   except FileExistsError:
    old=json.loads(root.read(name+'.claim.json',262144).raw);need(old['request_sha256']==digest and old['binding_id']==request['binding_id']);fresh=False
  return Attempt(self,request,fresh,None if fresh else self.inspect(name,request['binding_id']))
 def inspect(self,request_id,binding_id):
  need(uuid(request_id)and uuid(binding_id))
  with RootDirectory(self.root,self.uid,True)as root:
   try:claim=json.loads(root.read(request_id+'.claim.json',262144).raw)
   except FileNotFoundError:return None
   if claim['binding_id']!=binding_id:return None
   try:result=json.loads(root.read(request_id+'.result.json',1048576).raw);need(result['request_id']==request_id and result['binding_id']==binding_id and result['state']in STATES);return result
   except FileNotFoundError:return dict(protocol=VERSION,request_id=request_id,binding_id=binding_id,state='uncertain',code='OUTCOME_UNCONFIRMED',data={})
