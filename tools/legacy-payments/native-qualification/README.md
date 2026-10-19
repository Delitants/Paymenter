# Source payment qualification contracts

This directory contains preparation contracts and generic probe/control code.
It includes a guarded opt-in SDK entry and standalone native control publisher,
but no full admitted-run controller, live observer/executor or acceptance summarizer.
Source installation and native framework/manual acceptance remain blocked;
no shipped Python command installs a module or changes a payment.

```sh
python3 -m unittest discover \
  -s tools/legacy-payments/native-qualification/tests -p 'test_*.py'
```

The tests use fictional documents and locally compile the real probe adapter
against small authored SDK doubles. They check parsing, proposal sequencing,
request guards and emitted calls. The doubles implement no database transactions
or vendor lifecycle. These tests do not qualify installed SDK/ABI compatibility,
framework transactions, native manual credit, provider validation or posting.

A separate private preparation on 2026-10-18 passed one syntax-only compilation
of this adapter against the installed SDK. It produced no object/library and
invoked no manager or SQL action. The installed compiler and selected SDK header
identities were pinned; the vendor string ABI was selected explicitly. This
establishes syntax compatibility for that observed build only, not ABI linking,
module loading or transaction behavior. Operational paths and originals remain
private. Subsequent component and guarded SDK candidate builds now pass static
symbol/dependency availability checks; the native publisher-to-bridge path passes
in an owned standalone Linux/root fixture. No SDK candidate has been installed or
loaded into BILLmanager. Recheck identities before any subsequent build/startup.

## Interfaces

- `admission.parse_profile` parses a closed, bounded profile with four original
  evidence references: runtime, build, resource registry and authority.
- `admission.assess` checks supplied original bytes against those references and
  checks proposal completeness. `ready=True` is **not execution authority**.
  Document labels such as `reviewed` are proposal assertions, not independently
  established native facts. All four acceptance flags remain false.
- `admission.build_operation_plan` produces a fixed, immutable proposal of
  operation enums and evidence references. It accepts no command, SQL or URL.
  It neither creates resources nor grants authority to do so.
- `admission.AdmittedRun` is reserved for the later protected native issuer.
  Ordinary construction refuses with `native-issuer-unavailable`. No live
  capability can be issued by this increment. Future consumers must validate
  issuer ownership, process identity and resource custody, never type alone.
- `protocol.parse_case` parses QA receipts that retain original evidence
  references. These receipts carry no success boolean or financial authority.
  Parsing a receipt does not read or qualify its referenced observations.
- `native_metadata.parse_action_catalog` parses bounded original `actionlist`
  XML and returns exact action names and an original-byte fingerprint. It rejects
  empty CLI output, role warnings, duplicate entries, wrong routes, financial
  receipts, DTDs and unknown shapes. Names (including group/`.one` companions)
  establish presence only. They grant no invocation, hook, settlement or commit
  authority. A wildcard catalog entry is never permission to register a wildcard
  hook. Protected custody and source identity remain separate issuer obligations.
- `observer.decode_observation` checks a supplied framework-only document against
  supplied expected runtime, boot, request, writer, facts and time-window data.
  It rejects a writer/read connection collision, reused reader IDs, claimed
  dirty/repeatable reads, an active transaction, and stale or mismatched data.
  Frozen QA rows preserve pair, absent and split outcomes for later analysis.
  Native payment/subaccount rows are unsupported. The decoder does not read the
  database or prove the supplied isolation, timing, identity or original custody.
- `execution.BoundedCollector` retains bounded stdout/stderr bytes, requires
  both EOFs, exit zero, no stderr and no declared deadline expiry before decoding
  a bound case receipt. Malformed, lost, oversized or partial output is terminal;
  its retained bounded prefix remains available. This is an in-memory capture
  check, not process supervision or durable evidence storage. Its 1 MiB combined
  per-capture limit does not establish the aggregate operation budget.
- `execution.same_process` compares supplied run/boot/PID/start/role metadata.
  It performs no OS lookup and grants no signalling authority. `Operator.execute`
  and `CommittedObserver.read` always refuse with `native-issuer-unavailable`,
  including for objects made by bypassing the reserved capability constructor.

The execution module is named `execution.py` because `operator.py` would shadow
Python's standard library when this directory is on the import path. Tests cover
fresh interpreter startup from the harness directory as well as actual local
pipe output. No native process is started or signalled by these modules.

The separate `owned_process.py` transport starts and supervises an explicitly
supplied direct child. It is compatible with source Python 2.7 and performs no
I/O at import. Its trusted single-threaded caller must pin arguments/environment,
provide execution authority and durably retain the returned original bytes.
It bounds stdin, both output streams and elapsed time, and checks actual Linux
boot/PID/start/PPID/session before signalling only its own unreaped child. Unknown
identity is held; process groups, descendants and manager processes are never
signal targets. Local macOS pipe tests do not qualify Linux identity checks.
The reserved `Operator` does not yet call this transport.

Ordinary `supervise_owned` calls retain their 30-second maximum. A separate
`supervise_billmanager_startup(authority_expires, env=None)` runs only the fixed
local `mgrctl -m billmgr -o xml actionlist` getter, with empty stdin, a 192 KiB
stdout limit, an 8 KiB stderr limit and a 120-second maximum. The trusted caller
must supply the absolute expiry from its existing protected authority; expired,
non-integer or more-than-600-seconds-distant expiry is refused before launch.
The monotonic deadline is capped by the remaining lifetime, and wall-clock
expiry is also checked while supervising the child. Moving the wall clock back
does not renew the monotonic budget. The helper never creates or refreshes an
authority, retries a getter, installs a library or requests a manager exit.
It retains the same EOF, output, exit and direct-child retirement checks as
ordinary calls. A successful transport result still requires separate catalog,
process, protected-authority and native acceptance checks. Recovery outside a
QA lease needs its own reviewed operational authority; this helper does not
invent or extend it. Offline child tests do not qualify vendor startup or the
source Python 2.7 runtime, and existing sealed execution packets are unchanged.

`native/committed_reader.cpp` is a standalone source-native C API collector,
compiled separately from the SDK module and without separately compiling its
amalgamated `authority_bridge.cpp`. It requires exact reviewed compile-time
`PAYMENTER_QA_CREDENTIAL_FILE`, `PAYMENTER_QA_DATABASE` and `PAYMENTER_QA_SOCKET`
strings. Its only argument is the protected operation directory; no SQL, remote
host, database or credential override comes from a request. It reads the existing
root-private password-only client file itself, refusing includes/extra options,
and never exports that value or loads client defaults. Local sockets only,
disabled reconnect/local infile, fixed per-I/O timeouts and the outer owned-child
supervisor bound the channel. The [reader protocol](native/reader_protocol.md)
defines exclusive permits, originals and failure precedence.

`native/reader_core.hpp` enforces single use, session-variable and C API
server-status checks, distinct writer/previous-reader identities, two InnoDB QA
tables and one fixed UNION ALL statement for the matching business/journal pair.
One statement avoids a misleading split caused by separate autocommit SELECTs
straddling a commit. Pair, absent and split observations remain observations,
never acceptance. Table-engine checks do not establish complete schema/resource
custody. Native payment/subaccount tables have no read interface here. Protected
read permits are separate from `AdmittedRun` and do not authorize module loading,
DDL, business writes, barrier release, case sequencing or retirement.

`native/case_evidence.cpp` binds complete protected reader originals to the
currently issued native authority, exact original intent and writer diagnostic,
and actual writer executable/module mapping. Seals retain file identities as
well as hashes. Its F1–F4 outcome checks refuse split/dirty/stale/replayed or
misbound data. A withheld response record must match the case nonce/facts/intent,
complete owned-caller capture and actual original caller retirement. Recovery
uses a new reader reserved after the withheld marker and compares the unchanged
row keys/facts without invoking the SDK. The tool verifies a withholding decision
recorded by the future launcher; it does not intercept an SDK response itself.
No seal grants framework/manual/provider or financial acceptance. See the
[case evidence and retirement protocol](native/case_evidence_protocol.md).

`native/qa_retirement.hpp` and `native/qa_retirement_archive.cpp` provide a guarded
fixed two-table policy and a protected durable original archive. The policy
archives/reloads all definitions and rows before any erasure, holds foreign or
changed resources, rechecks the archive before destructive steps, and requires
unchanged schemas, empty tables, fresh table-absence verification and confirmed
lock release. Unknown/partial cleanup never restores source data.
`native/qa_retirement_mysql.cpp` supplies the fixed native C API backend.
It binds immutable registry selectors, process/thread ownership and finite
connection/I/O/lock deadlines; checks table engines, triggers and incoming/
outgoing foreign keys; and uses one unchanged local session with explicit table
locks. It rechecks schemas before erasure/drop and closes uncertain channels
without reconnecting or retrying. `native/resource_custody.cpp` now supplies the
root-native fixed-table creator and concrete retirement guard. It binds actual
producer identity, original table-absence/creation/schema records, immutable
resource selectors, persistent revocation, actual old writer retirement and an
unmapped replacement runtime before cleanup can use SQL. Its only public modes
are explicitly approved creation/retirement; it performs no SDK activation or
manager action. See the [custody protocol](native/resource_custody_protocol.md)
and [backend contract](native/retirement_sql_protocol.md). Native lock/DDL,
SDK writer/startup behavior and the full run controller remain unqualified;
authored database tests and compilation do not qualify actual source cleanup.

References are canonical absolute POSIX paths with exact byte length and SHA256.
Pure parsing does not establish filesystem custody, source identity or original
actor authorization. Those checks belong to the future native issuer. A manual
proposal cannot be executed until the installed action, supported invocation,
actor permissions, original amount/currency mapping, per-fixture isolation and
retirement method are established from original evidence.

## Native prerequisites still open

The retained installed SDK declarations support an administrator access mask, an action,
an after-event, prepared statements and the existing global cache. They do not
prove that an after-event shares the original payment transaction or executes
before its commit. Native observations must prove both successful commit and
rollback behavior. Do not use `Final`, a paid status, an SDK counter, a successful
lookup or `ValidateAuth` as commit or provider-validation proof.

Compilation, loading, QA-table creation and native fixture operations remain
separately gated by the approved implementation plan's concrete operation
packets. Unknown installation/unload behavior blocks source execution. No
manager restart, direct balance repair, provider refund, customer-record reuse,
hold release or billing handover is a fallback.

Inspect version-matched vendor build targets before proposing installation.
An `install` target can invoke a manager restart even when its name suggests
only file installation. A load declaration, module destructor or global core
deinitialization function does not establish safe unloading of one owned module.
Keep vendor build files, native catalog originals and runtime evidence private.

The vendor packaging helper named `ReloadMgr` is not an established hot-unload
alternative: its retained implementation invokes the selected manager's `exit`
action. The [lifecycle decision](../../../docs/superpowers/specs/2026-10-18-source-qa-lifecycle-decision.md)
records the evidence limits and the maintenance approach approved for package
preparation. It is not approval to execute a restart or install a module.

## Framework probe preparation

`native/cache_probe.cpp` defines the exact own action `paymenter.qa.transaction`
and its after-event. The optional `native/module_entry.cpp` supplies a guarded
`LIBRARY_ENTRY` and database-dependent `MODULE_INIT`. It is excluded from the
component build and refuses compilation without an explicit reviewed
`PAYMENTER_QA_CONTROL_DIRECTORY` string; there is no environment/profile/HTTP
override. It must only run inside a separately qualified SDK startup, after a protected,
process-bound private issuer admits the exact operation. The root-backed
`FrameworkAuthority` adapter in `native/authority_bridge.cpp` implements protected
native control checks. The opt-in entry calls its Linux/root-only factory, which
verifies the actual executable and loaded image. It does not issue a Python
`AdmittedRun`, qualify source database facts or install a module. The
[protected control protocol](native/authority_protocol.md) defines its files and
original-evidence bindings. `authority_issuer.py` validates a distinct protected
approval and closed registry/build originals against actual image bytes before
publishing authority. Its case publisher checks the actual Linux manager identity
and retains one-time intent originals. Neither issues an `AdmittedRun`, invokes
a case, establishes DB facts or advances the native sequence. The alternative
`native/control_issuer.cpp` publishes the same bridge controls using the existing
native runtime and distinct closed protected approvals. The bridge's diagnostic recorder retains the writer's server
connection ID in the admitted source-private evidence file. A complete proposal,
a Python type, a boolean, an HTTP
parameter or a test double cannot establish its protected native facts.

`native/bootstrap.cpp` keeps request authority disabled until both SDK action and
after-event registration finish. Duplicate/reentrant initialization, revocation
during registration, exceptions, destruction and forked use refuse activation.
A partially registered action retains the disabled gate and cannot reach a SQL
write. SDK calls and protected reads happen outside the bootstrap mutex. Local
tests exercise the real bootstrap/probe against authored SDK/native facts; they
do not qualify actual manager startup, dependency ordering or SDK retirement.

The probe restricts requests to the admitted actor, run, case, nonce, process,
thread and session. It rechecks authority after protected reads, pins the supplied
cache and connection objects, and rejects reused cases and reentrant phases.
Only F1–F4 have write modes; F5 consists of refused requests. A request named F5
never writes. Fixed deadlines bound the case and the own-action observation
barrier. The private bridge's methods must themselves perform bounded local
reads; no unqualified blocking callback belongs in that bridge.

The action inserts a fictional `0.0100` USD business row; its after-event inserts
one matching QA row through the same supplied cache. Throws after either write
and a pre-return observer barrier are QA behaviors on this own action only.
`Final` removes memory context and provides no commit signal. The probe checks
cache/connection object identity and uses `SELECT CONNECTION_ID()` through that
same cache to detect server-connection replacement. That ID is retained only by
the private bridge; it proves no transaction or durable outcome. The barrier
does not poll SQL while waiting for the observer. Rollback and independent
visibility still require native observations.

`native/schema.sql` defines the two fixed InnoDB QA tables outside handlers and
module startup. Existing table names must refuse the operation. The schema has
no foreign keys or native customer/payment references. Its execution, engine and
schema verification, resource ownership, exact retirement, integrated native bridge execution,
action metadata and SDK startup all require the concrete native
packet; none is supplied by these local tests. Paid fixture records and accounting
audit are never cleanup targets of this probe.

The bridge uses POSIX file APIs, Linux process/mapping metadata and OpenSSL's
SHA256 API. No packages are installed by the harness. Local bridge tests compile
against an existing OpenSSL discovered through `pkg-config`; Linux builds also
link `libdl`. Installed-Linux syntax checks were followed by an actual component
library build against the installed SDK and static checks against the manager's
mapped libraries. All strong imported symbols and direct dependency names were
available; the component has no runtime search path or automatic module entry.
A subsequent complete SDK candidate includes the opt-in guarded entry and also
passes those static checks. Neither was installed or loaded into BILLmanager.

A separate owned standalone Linux/root fixture loaded only its own bridge DSO
and passed the public factory, actual process/image binding, protected file/grant
checks, fictional writer/barrier diagnostics, fork refusal and revocation. It
ran no SDK, SQL or manager action. This qualifies that standalone bridge path,
not the future combined module's startup/global symbol scope, source issuer,
controller integration or native transaction coupling. Original artifacts and
failed attempts remain private.

The issuer writes under a synchronized revocation fence and retains failed or
partial originals. Successful publication returns a file receipt, never native
acceptance or installation permission. Its explicit approvals are not generated
from preparation profiles. The full admitted-run controller, integration of the
standalone reader with original writer/case facts, case resolution and exact
retirement remain unavailable. Issuer tests use actual
fixture files and image hashes with authored boot/process facts; no source issuer
or process-check acceptance is claimed for the Python backend. Python 3 was absent
at the standard and previously proposed source paths checked, so the source path
now has a native publisher. It shares the exact protected bridge implementation
by amalgamation: compile `control_issuer.cpp` alone, not together with
`authority_bridge.cpp`. Its standalone Linux/root authority and case publication,
actual target/image checks, bridge consumption and replay refusal passed with
fictional records and no SDK/SQL. No replacement runtime was installed.

The SDK build uses `cache_probe.cpp`, `authority_bridge.cpp`, `bootstrap.cpp` and
`module_entry.cpp`, with the separately reviewed compile-time directory and
installed vendor ABI/link settings. The standalone publisher is a distinct binary;
never link it into the SDK module. See the protected protocol for exact native
approval formats. Neither backend is the full admitted-run controller: framework
QA-row observations, sequencing/resolution, retirement and SDK startup remain pending.

The standalone source reader separately passed two fresh local-socket sessions,
actual READ COMMITTED/autocommit/no-open-transaction checks and permit replay
refusal. It issued only metadata SELECTs and session settings, with no QA/customer
row reads, DDL or business writes. The owned transport passed source Python 2.7
stream/exit capture, bounded timeout/overflow, stdin/EOF and no-child spawn refusal.
The initial credential-spacing refusal remains retained; its syntax fix has a
regression test and qualified in a fresh reader-only source operation. These
results do not establish framework transaction coupling or complete Task 3.

The complete implementation remains tracked in
[the source qualification plan](../../../docs/superpowers/plans/2026-10-18-source-payment-native-qualification.md).

The native fixed framework controller and owned-child backend are documented in
[native/controller_protocol.md](native/controller_protocol.md). Their local tests
exercise actual macOS child pipes/waits and protected files, plus explicitly
authored SDK/DB/peer boundaries. They do not establish genuine source SDK or
financial acceptance. The runner consumes already admitted resource creation and
loaded-module state, retains failures, and leaves every completed run fenced with
retirement and qualification explicitly pending. The Python live capability and
operator remain unavailable; no profile or test success can enable them.
