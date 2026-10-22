"""One bounded observation; repetition is read-only and cannot replay a mutation."""
class Convergence:
 def __init__(self,commands):self.commands=commands
 def observe(self,binding,expected_serial,expected_revision):
  try:
   loaded=self.commands.status(binding);zone=self.commands.zone_snapshot(binding)
   matches=(loaded.serial==expected_serial==zone.serial and zone.revision==expected_revision and loaded.view==binding.view and loaded.file_identity==zone.image.identity and loaded.dynamic is False and loaded.secure is False and loaded.inline is False)
   return {'state':'observed'if matches else'pending_replication','code':'IN_SYNC'if matches else'REPLICATION_PENDING','data':{'serial':zone.serial,'revision':zone.revision}}
  except Exception:return {'state':'pending_replication','code':'REPLICATION_UNCONFIRMED','data':{}}
