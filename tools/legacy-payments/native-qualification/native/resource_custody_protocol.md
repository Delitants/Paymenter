# Native framework QA resource custody

`resource_custody.cpp` is a standalone Linux/root resource tool. It composes the
native control parsers, archive/retirement backend and constrained credential
channel. Compile it alone; do not compile its included implementation files again
into the same binary. It requires reviewed compile-time
`PAYMENTER_QA_CREDENTIAL_FILE`, `PAYMENTER_QA_DATABASE` and `PAYMENTER_QA_SOCKET`.
The only commands are `create <protected-directory>` and
`retire <protected-directory>`. No shell, SQL, table, database, credential,
actor, boot, process-check or owner override is accepted by the public entry.

The directory must have the existing root-0700/non-symlink/supported-filesystem
custody. This tool does not install/load/unload an SDK module, restart anything,
issue business grants, invoke a manager action or create an admitted financial
run. Its current real C API table-lifecycle behavior remains unqualified. A live
source packet must first be concretely reviewed and separately approved; neither
compilation nor fictional test controls clear that gate.

## Creation approval and actual producer

Before authority issuance/SDK activation, retain the native authority approval,
fixed resource registry and build originals from
[the control protocol](authority_protocol.md). The selected original manager must
have the expected root runtime image and no mapping of the proposed QA module.
Any existing authority, intent, writer, revocation or retirement entry refuses
creation. Both table names must actually be absent in the configured database.
There is no `IF NOT EXISTS`, replacement or automatic reconciliation.

`qa.create.approval.original` has purpose
`paymenter-source-qa-create-approval-v1` and exact ordered keys:

`intent run boot issued expires authority_approval_sha256 registry_sha256 build_sha256 manager_pid manager_start ddl_sha256 issuer_path issuer_sha256`

Intent is `create-owned-qa`. The current window is at most 30 seconds and fits
inside the original authority window. The three original hashes must match.
The DDL hash is SHA256 of the two compiled statements, joined by one newline;
[resource_schema.hpp](resource_schema.hpp) matches [schema.sql](schema.sql).
The issuer path/hash must match the actual producer executable. The operation is
bound to its current process/thread, kernel boot, executable, manager PID/start,
original images and original file identities. Proposed/reviewed flags supply none
of those facts.

The existing password-only root-private credential parser is shared with the
reader through `credential_channel.hpp`. No defaults/includes, remote TCP,
local infile, reconnect, multistatements or credential export is used. The fresh
local session must have the expected database/socket/connection ID, autocommit and
no open transaction. Native I/O and session lock waits are finite; table metadata
is read only after the two-second session waits have been configured/read back.
The outer controller must also enforce its total deadline and clean pinned
execution environment/library provenance.

One-shot creation retains, in order:

- `qa.resources.started.original`, binding the exact create approval;
- `qa.resources.absent.original`, produced only after a real empty name query;
- the two fixed CREATE statements, outside any SDK request or startup callback;
- original SHOW CREATE bytes in `qa.schema.business.original` and
  `qa.schema.journal.original`, after verifying both InnoDB base tables, exact
  column types/collations/keys/nullability, and no triggers or incoming/outgoing
  foreign keys;
- `qa.resources.created.original`, binding the actual session, original manager,
  producer PID/start, module device/inode, original hashes and nanosecond file
  identity fingerprints;
- `qa.resources.complete.original`, binding the created record's hash/identity.

Every retained file remains pinned through the final check; even a same-byte
replacement refuses. Exclusive writes are synchronized and never overwritten.
An unexpected failure retains `qa.resources.failure.original` and any partial
objects/originals. A collision or unknown partial creation does not grant cleanup
or retry authority. Such a fixture stays held for separately scoped recovery.
No source rows, balances, paid records or billing settings are restored.

## Fresh retirement approval

`qa.cleanup.approval.original` has purpose
`paymenter-source-qa-cleanup-approval-v1` and exact ordered keys:

`intent run boot issued expires created_sha256 created_identity revoked_sha256 revoked_identity manager_pid manager_start complete_sha256 complete_identity`

Intent is `retire-owned-qa`. The new at-most-30-second window is independent of
whether the historical execution authority has since expired. Historical creation
must still have occurred within its original bounded approvals. Both the created
and completed records, every original they bind, and the persistent `revoked.v1`
fence must remain unchanged. The current boot and actual producer image must
match the original producer; a real tool cannot consume a fictional fixture's
records from a differently built executable.

The concrete guard checks every registered F1-F4 intent/diagnostic, their original
nonces/facts/PID/start/hash bindings and any active-intent copy. Unknown F5 grants,
unregistered active intents, diagnostics without intents, malformed/replaced
originals or new writer records hold the operation. The actual original creation
manager and every intent-bound writer must be gone, or its original PID/start
must demonstrably have been replaced. A zombie with the original start remains
held. Unknown process lookup never means retired.

The fresh replacement manager must have the original root runtime path, image
hash and kernel executable device/inode, with a stable PID/start. Its maps must
contain neither the QA module path (including a deleted mapping) nor the original
module device/inode under another path. Only these fixed recorded processes are
inspected; the guard performs no process enumeration, signal or restart. This is
not a vendor health/worker-recovery qualification; the installation/lifecycle
packet still has to establish that separately.

The guard grants no SQL before binding an exclusive
`qa.cleanup.started.original` to this exact approval and producer PID/start.
It rechecks that record throughout cleanup. The existing
[retirement backend](retirement_sql_protocol.md) then locks only the two owned QA
tables, durably archives definitions/all bounded rows, verifies original schemas
and exact registered run/case/facts selectors, erases only owned fictional rows,
checks emptiness, drops the two names and freshly verifies absence. The fixed
archive may be up to 64 KiB; all control files remain limited to 4 KiB. Foreign
rows or uncertain locks/commit/drop/unlock remain held with the archive retained.

Only completed retirement publishes `qa.cleanup.complete.original` and
`retired.v1`. Publication failure retains an explicit incomplete result. No code
reconnects to repair uncertainty, restores rows, recreates dropped tables, deletes
native financial records or compensates through a provider. There is no automatic
retry after a started attempt.

## Verification boundary

Local tests use real protected files and authored C API/kernel facts. The
source-native OS fixture uses the actual Linux/root/boot/image/PID/start/map
checks against two sequential owned peers, with an explicitly authored MySQL
boundary. It cannot be compiled with an unmarked/real client header. Its table,
actor, module role and transaction facts are fictional; none qualifies a real
SDK writer or database lifecycle. The real C API executable must reject those
fixture records because its actual producer image differs, before opening
credentials or a database connection.

Native table creation/retirement, real writer quiescence, installed SDK startup,
case sequencing and financial/provider acceptance remain separate gates. This
resource tool is a prerequisite for the full run controller, not an implementation
of `AdmittedRun` or a financial qualification result.
