# Protected QA observations and collector recovery

`case_evidence.cpp` is a standalone evidence tool. Compile it alone: it
amalgamates `control_issuer.cpp`, which in turn includes the protected bridge.
Do not link it into the SDK or compile those included translation units again.
Its Linux/root CLI is `native-case-evidence <operation-directory> <F1-F4> <mode>`;
modes are `after`, `barrier`, `withhold` and `recover`. No SQL, SDK invocation,
financial acceptance, barrier release, case advancement or cleanup is performed.

Each invocation validates the protected native approval/build/registry and
issuance originals, current authority and actual writer target executable/module
mapping. It checks both `intent.v1` and `intent.Fn.original`: these are distinct
files whose bytes must match, and each identity is retained separately. The
writer diagnostic must match that case's run, nonce, facts, PID/start and intent
SHA256. Wall and monotonic case deadlines remain at most 30 seconds. Public entry
points have no owner, clock, boot, image or process-check overrides; file tests
use private authored runtime seams and do not qualify native execution.

`read.Fn.after`, `read.Fn.barrier` and `read.Fn.reconcile` are protected child
directories inside the operation directory. Each contains the unchanged
[reader protocol](reader_protocol.md) approval, started, result and completion
originals. A failure/revocation/retirement entry refuses the read. The tool checks
all request, boot, writer, connection, facts, phase, timestamp and hash bindings,
including connection distinctness and declared READ COMMITTED/autocommit/no-open-
transaction state. The read and its permit must fit the current case window.
F1/F4 after-results require both exact fictional rows; F2/F3 require neither;
only F4 allows a barrier result, and it requires neither row. Unexpected or split
rows remain in their original files and do not acquire a successful bound seal.
F5 has no writer grant and is not handled by this writer-bound tool.

`after`/`barrier` exclusively create `observed.Fn.<phase>.original`. The seal binds
original hashes and actual device/inode/mode/owner/size/nanosecond time identities
of authority, intent, writer and reader files. Replacing an original with identical
bytes does not preserve its seal. A seal/publication failure is retained under
`evidence.failure.original`, which dominates any partial result. No existing file
is overwritten or automatically retried.

`withhold` records a transport decision already made by the owned launcher; it
does not intercept or withhold an SDK response by itself. The launcher must keep
the original reply source-private and must not return it to its consumer. The
tool requires a sealed independently visible F1/F4 pair, a protected
`caller.Fn.stdout` and `caller.Fn.original` with purpose
`paymenter-source-qa-caller-capture-v1` and exact ordered fields:

`run case boot pid start exit stdout_eof stderr_eof stdout_sha256 stderr_bytes delivery nonce facts intent_sha256`

The capture must match the exact case intent, exit zero, both EOFs, no stderr and
`delivery=withheld`; the caller must be distinct from the SDK writer, and its
actual original PID/start must be gone. Unknown process lookup is held. The reply
must be nonempty and at most 16 KiB. Empty or unqualified native replies are not
manufactured into success. The tool writes `withheld.Fn.original` with protected
observation/caller/reply hashes and identities. Launcher command/image/ownership
provenance remains an admitted-run obligation; a supplied capture document alone
cannot prove a native action was invoked.

`recover` requires that exact withheld record, the unchanged original case and
writer, and a fresh `read.Fn.reconcile` result for the identical row keys/facts.
Its request and connection must differ from the first reader, its started time
must not precede the first result, and its actual reservation-file ctime must not
precede the withheld marker. This rejects reusing an already prepared read even
when epoch timestamps fall in the same second. It exclusively writes
`recovered.Fn.original` with `scope=collector-response-only`. It never invokes the
SDK or repeats a credit. This is no proof of provider ACK timing, a manager crash,
manual/native payment coupling, framework atomicity or financial posting.

The full admitted-run factory/launcher must establish each reader's actual
binary/caller/exit/EOF provenance and fresh database observations. Protected text
and copied test artifacts are not native transaction proof. Runtime F1–F5 remain
separate accepted operations under the source qualification plan.

# QA archive and retirement policy

`qa_retirement.hpp` defines a fixed two-table policy. `qa_retirement_archive.cpp`
provides a process-bound protected original archive; it has no CLI or SQL adapter.
Compile the archive translation unit alone, since it includes the bridge. There
is no production permissive guard or executable source cleanup path in this
increment. A separate [native C API backend](retirement_sql_protocol.md) implements
the fixed SQL operations but supplies neither resource authority nor a CLI.
Database and guard doubles exercise policy ordering, not actual
MariaDB lock/schema/DDL or module-unload behavior.

A future native guard must establish original table absence/create receipts,
unchanged schema/resource custody, no triggers/native objects, actual SDK writer
retirement and a fresh bounded cleanup approval. A supplied boolean, empty table,
unload declaration or preparation profile supplies none of these facts. The
native adapter locks only the two fixed QA tables, sets and verifies finite
I/O/lock deadlines and keeps its explicit locks through archive/delete/drop.
Actual installed-server semantics still require separate qualification.

The policy snapshots both definitions and all bounded QA rows into
`qa.retirement.original`, fsyncs file/directory, reloads it, and requires exact
protected identity, bytes and hash verification. Length-bounded hex fields retain
definitions and cells without interpreting their contents. Changed schemas or
foreign/unregistered/malformed rows are archived and held before any deletion.
Only registered run/case/facts and the fictional `0.0100` amount can be erased.
Owned split rows may be archived and cleaned without claiming that their case
passed. No native payment/subaccount tables are exposed by the interface.

The archive is reverified before every destructive step. After erasure, both
schemas must be unchanged and all QA rows absent before an empty-table drop.
A fresh native metadata check must establish both tables absent; a DROP return
code alone is insufficient. Lock release must also succeed. Results retain erase
attempts separately from confirmed erasures, attempted drops, archive state and
an explicit blocker. Partial deletion/drop/unlock remains incomplete. Never
restore source data, recreate dropped tables, delete paid records, repair native
balances or issue a provider compensation as an automatic cleanup fallback.
