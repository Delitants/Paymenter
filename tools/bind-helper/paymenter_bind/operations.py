"""Primary record mutation and guarded rollback. Claims make uncertain work non-replayable."""
import os
from .protocol import need
from .custody import locked_zone,RootDirectory
from .zone import snapshot,read_zone,apply_rrsets,next_serial,serial_newer
from .journal import Journal
class Operations:
 def __init__(self,binding,commands):self.binding=binding;self.commands=commands;self.journal=Journal(binding.state_root,binding.owner_uid)
 def replace(self,request,binding):
  need(binding==self.binding and binding.role=='primary'and request['binding_id']==binding.id and request['operation']=='replace_records')
  with locked_zone(binding)as owned:
   binding.assert_current();attempt=self.journal.claim(request)
   if not attempt.fresh:return attempt.result
   installed=None;before=None;candidate=None;conflict=False
   try:
    before=read_zone(binding);loaded=self.commands.status(binding)
    need(loaded.dynamic is False and loaded.secure is False and loaded.inline is False and loaded.view==binding.view and loaded.file_identity==before.image.identity and loaded.serial==before.serial)
    p=request['payload'];conflict=p['expected_serial']!=before.serial or p['expected_revision']!=before.revision;need(not conflict)
    candidate=apply_rrsets(before,p['changes'],next_serial(before.serial));self.commands.check(candidate)
    with RootDirectory(binding.state_root,binding.owner_uid,True)as state:
     free=os.statvfs(binding.state_root);need(free.f_bavail*free.f_frsize>33554432+len(before.raw)*4);state.save(request['request_id']+'.before.zone',before.raw);state.save(request['request_id']+'.candidate.zone',candidate.raw)
    # Recheck runtime serial immediately before installation, not only the file.
    again=self.commands.status(binding);need(again==loaded)
    binding.assert_current();self.commands.guard_install();installed=owned.install(candidate.raw,request['request_id'],before.image)
    self.commands.reload(binding);self.commands.notify(binding);observed=self.commands.status(binding);after=read_zone(binding)
    need(observed.serial==candidate.serial and after.revision==candidate.revision and observed.file_identity==after.image.identity)
    return attempt.record('committed',{'serial':after.serial,'revision':after.revision},'SAVED')
   except Exception:
    if installed is None:
     # An exception during rename/readback can occur after installation; only an
     # exact unchanged original proves that it is safe to call this rejected.
     try:unchanged=before is not None and owned.read()==before.image
     except Exception:unchanged=False
     return attempt.record('rejected'if unchanged else'uncertain',{},('REVISION_CONFLICT'if conflict else'NOT_CHANGED')if unchanged else'OUTCOME_UNCONFIRMED')
    try:
     need(owned.read()==installed);current=self.commands.status(binding);need(current.serial in {before.serial,candidate.serial})
     rollback_serial=next_serial(candidate.serial);need(serial_newer(rollback_serial,current.serial))
     rollback=self._rollback(before,rollback_serial)
     self.commands.check(rollback);binding.assert_current();self.commands.guard_install();owned.install(rollback.raw,request['request_id'],installed);self.commands.reload(binding);self.commands.notify(binding);observed=self.commands.status(binding);restored=read_zone(binding)
     need(observed.serial==rollback_serial and restored.revision==rollback.revision and observed.file_identity==restored.image.identity)
     return attempt.record('rejected',{'serial':restored.serial,'revision':restored.revision},'ROLLED_BACK')
    except Exception:return attempt.record('uncertain',{},'OUTCOME_UNCONFIRMED')
 def _rollback(self,before,serial):
  from .zone import parse
  import dns.rdataset
  z,_,_=parse(before.raw,before.binding);soa=z.get_rdataset(z.origin,'SOA');rd=dns.rdataset.Rdataset(soa.rdclass,soa.rdtype);rd.ttl=soa.ttl;rd.add(soa[0].replace(serial=serial));z.replace_rdataset(z.origin,rd);return snapshot(z.to_text(relativize=False,sorted=True).encode(),before.binding)
