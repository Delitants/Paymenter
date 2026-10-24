"""Fixed root entrypoint. No path, UID, executable or operation arguments."""
import json,os,signal,sys,time,dataclasses
from .protocol import decode_request,encode_result,need,INPUT_LIMIT
from .config import load_binding
from .custody import RootDirectory
from .commands import BindCommands
from .operations import Operations
from .lifecycle import Lifecycle
from .journal import Journal

def handle(raw,config_root='/etc/paymenter-bind',expected_uid=0,commands_factory=BindCommands,started=None):
 request=decode_request(raw);start=time.monotonic()if started is None else started
 with RootDirectory(config_root,expected_uid,True)as config:
  image=config.read('server.json',262144)
  baseline=load_binding(config.fd,request['binding_id'],request['operation'],expected_uid)
  need(config.read('server.json',262144)==image)
  def authorize():
   config.recheck();need(config.read('server.json',262144)==image)
   need(load_binding(config.fd,request['binding_id'],request['operation'],expected_uid)==baseline)
  binding=dataclasses.replace(baseline,authority=authorize)
  op=request['operation'];budget=60 if op in {'prepare_zone','prepare_secondary'}else 30;left=budget-(time.monotonic()-start);need(left>5)
  commands=commands_factory(binding,budget=left)
  if op=='inspect':
   loaded=commands.status(binding);zone=commands.zone_snapshot(binding);need(loaded.serial==zone.serial and loaded.view==binding.view and loaded.file_identity==zone.image.identity and loaded.dynamic is False and loaded.secure is False and loaded.inline is False)
   result=dict(state='observed',code='OK',data={'serial':zone.serial,'revision':zone.revision,'records':[dict(owner=r[0],ttl=r[1],type=r[2],records=list(r[3]))for r in zone.rrsets if r[2]in {'A','AAAA','CNAME','MX','TXT','CAA','SRV'}]})
  elif op=='inspect_request':
   prior=Journal(binding.state_root,binding.owner_uid).inspect(request['payload']['request_id'],binding.id)
   result=dict(state='observed',code='REQUEST_NOT_FOUND',data={})if prior is None else dict(state=prior['state'],code=prior['code'],data=prior['data'])
  elif op=='replace_records':result=Operations(binding,commands).replace(request,binding)
  elif op=='prepare_zone':result=Lifecycle(binding,commands).prepare_primary(request,binding)
  else:result=Lifecycle(binding,commands).prepare_secondary(request,binding)
  need(time.monotonic()-start<budget);return encode_result(request,result['state'],result['code'],result['data'])

def main():
 if len(sys.argv)!=1:return 64
 if os.geteuid()!=0:return 77
 os.umask(0o077);start=time.monotonic();phase=['read'];request=None
 def expired(*_):
  if phase[0]=='retirement':
   from .commands import ACTIVE_CHILDREN
   for child in tuple(ACTIVE_CHILDREN):
    if child.returncode is None:
     try:os.killpg(child.pid,signal.SIGKILL)
     except ProcessLookupError:pass
   os._exit(124)
  phase[0]='retirement';signal.setitimer(signal.ITIMER_REAL,5);raise TimeoutError('Helper deadline')
 signal.signal(signal.SIGALRM,expired);signal.setitimer(signal.ITIMER_REAL,5)
 try:
  # Input admission has a short bound; the operation's outer deadline still
  # starts before reading, so choosing lifecycle never resets spent time.
  raw=sys.stdin.buffer.read(INPUT_LIMIT+1);request=decode_request(raw);lease=60 if request['operation']in {'prepare_zone','prepare_secondary'}else 30
  phase[0]='work';signal.setitimer(signal.ITIMER_REAL,max(0.001,lease-5-(time.monotonic()-start)))
  result=handle(raw,started=start);sys.stdout.buffer.write(result+b'\n');sys.stdout.buffer.flush();return 0
 except Exception:
  if request is not None:
   try:sys.stdout.buffer.write(encode_result(request,'uncertain','OUTCOME_UNCONFIRMED',{})+b'\n');sys.stdout.buffer.flush()
   except Exception:pass
  else:sys.stderr.write('Invalid BIND request\n')
  return 1
 finally:signal.setitimer(signal.ITIMER_REAL,0)
if __name__=='__main__':raise SystemExit(main())
