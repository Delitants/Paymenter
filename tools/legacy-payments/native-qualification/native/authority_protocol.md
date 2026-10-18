# Protected framework control protocol

This is native QA control, not a proposal profile, financial receipt or Python
admitted-run issuer. The private issuer must validate explicit approval and
original runtime/build/resource facts before writing it. `authority_issuer.py`
implements protected file issuance and one-case publication. A standalone native
publisher and opt-in guarded SDK entry are also implemented; the full admitted-run
executor and actual SDK startup remain unqualified. Never create these controls from
`assess.ready`, `reviewed` labels, HTTP parameters or a fictional test fixture.
Root file custody and matching hashes do not prove a database commit.

`OpenProtectedFrameworkAuthority(directory)` is the only public bridge factory.
It accepts no owner, clock, runtime-check, process-ID or hash override. It requires
Linux and effective UID/GID zero. The root-owned operation directory must be mode
0700 on ext4, XFS or tmpfs. Its ancestors must be root-owned directories without
group/other write permission; no symlink is followed. Directory replacement
revokes access even though the old descriptor remains open.

Inputs are mode-0600 root-owned regular files with one link. Original bytes and
identity/nanosecond metadata remain bound, including across identical-content
replacement. Controls are ordered printable ASCII `key=value` lines, preceded
by their exact purpose and ending with a newline. Unknown, missing, duplicate
or reordered fields refuse. Control size is at most 4096 bytes, each value at
most 1024 bytes. Paths are canonical absolute ASCII paths without whitespace,
backslashes, doubled separators or `.`/`..` components. Digests/nonces are 64
lowercase hex characters. Positive decimals have no leading zeroes and fit
unsigned 64 bits.

## Immutable authority

`issue_framework_authority(operation_directory)` requires Linux/root, the actual
kernel boot, and three already protected originals. It has no public fact/owner/
clock override and does not generate approval:

| Original | Exact fields and role |
| --- | --- |
| `approval.original` | `purpose intent run boot actor issued expires registry_sha256 build_sha256 cases`; purpose `source-framework-authority-approval-v1`, intent `issue-framework-authority`. |
| `registry.original` | `purpose run tables objects diagnostics`; purpose `source-framework-control-registry-v1`, the two fixed QA table names, no native objects, and exactly the four writer diagnostic names. |
| `build.original` | `purpose run module runtime`; purpose `source-framework-control-build-v1`; each image has only `path sha256 bytes`. |

These originals are closed bounded JSON documents, separate from preparation
profiles. Every `cases` entry F1–F4 has exactly `nonce facts_hash`; all four nonces
are distinct. Approval binds original registry/build bytes and a current window
of at most 600 seconds. Image references bind canonical paths, SHA256 and at most
16 MiB of actual non-writable, singly linked root-owned ELF file bytes. ELF magic
is a file-format guard, not linking or loader compatibility proof. Runtime and
module must be distinct images. Registry names are an allowed-resource list,
not a claim that SQL schema/ownership/absence has been independently observed.

Issuance first creates and synchronizes a `revoked.v1` fence, then the exclusive
`issuance.original` receipt and `authority.v1`. It rechecks original file identities,
image identities/content and wall/monotonic deadlines before removing only that
exact fence. Any interrupted write retains the fence and original/partial files;
a failed sync after fence removal attempts to restore and synchronize revocation.
There is no overwrite, automatic retry or cleanup of failed evidence. Consumers
must wait for successful issuance before any separately authorized module load.
The receipt returned by Python is not an `AdmittedRun` or native acceptance.

`authority.v1` starts with `paymenter-source-qa-authority-v1`, then these keys in
this exact order:

| Keys | Constraint |
| --- | --- |
| `intent` | Exactly `framework-qa-execute`; preparation intent refuses. |
| `run` | 64-character operation identifier. |
| `boot` | Actual kernel boot identifier. |
| `actor_hex` | Lowercase hex encoding of the exact SDK actor, at most 128 decoded bytes; control bytes refuse. |
| `issued`, `expires` | Current epoch window of at most 600 seconds; future issue and expired windows refuse. |
| `module_path`, `module_sha256` | Actual loaded bridge image and original-byte hash. |
| `runtime_path`, `runtime_sha256` | Actual kernel-reported executable and original-byte hash. |
| `registry_sha256`, `build_sha256` | Hashes of the two protected originals below. |
| `nonce_F1`, `facts_F1`, `nonce_F2`, `facts_F2`, `nonce_F3`, `facts_F3`, `nonce_F4`, `facts_F4` | Immutable case bindings; four distinct nonces. |

The native factory checks actual boot, PID/start, executable and loaded-image
mapping. Mapping path/device/inode and executable/private permissions must match
the opened module. The module must differ from the process executable. Both
image hashes are checked initially; their identities/metadata are rechecked for
each grant validation. A forked process refuses before taking the bridge mutex.

`registry.original` and `build.original` are immutable hash-bound originals of at
most 65536 bytes each. Their semantic qualification belongs to the private issuer:
the bridge performs no SQL, installation or independent source-fact discovery.
The registry must register all four fixed diagnostic names before activation.
Any existing diagnostic at factory open refuses operation reuse.

## Current case

`issue_framework_case(operation_directory, case_id)` publishes exactly one
explicitly approved F1–F4 intent. Its protected JSON original
`case.F1.approval.original` (or corresponding F2–F4 name) has exactly:
`purpose intent run case nonce facts_hash pid start_ticks boot issued expires authority_sha256`.
Purpose is `source-framework-case-approval-v1`, intent `issue-framework-case`.
It must match the original issued authority, case binding and current window of
at most 30 seconds within the authority lifetime. The public entry has no manager
checker, process-root or kernel-fact override.

Before and during publication it checks the actual selected Linux process: root
UIDs, PID/start, boot, executable path/device/inode, and the exact executable
module mapping's path/device/inode. The bridge independently checks its own
process/function mapping. Case publication uses a synchronized revocation fence,
retains an exclusive `intent.F1.original` (or F2–F4), and refuses any outstanding
`intent.v1`, reused case diagnostic/original or existing barrier/release. It never
removes an earlier intent, advances a case sequence, invokes the manager, releases
F4, resolves a result or retires resources. Those require the separate live
observer/supervisor. Case approval is explicit protected policy, not SQL evidence.

`intent.v1` starts with `paymenter-source-qa-intent-v1`, with ordered keys:
`run case nonce facts pid start issued expires`.

Only F1–F4 are grantable. They map to normal, throw-after-business,
throw-after-journal and observe-before-return. There is no mode override. Nonce
and facts must match the immutable authority. PID/start must match the actual
factory process. Issue/expiry must be current, within the authority window, and
at most 30 seconds apart. Both wall-clock and monotonic deadlines apply.

Case issue is single-use. The originating session identity and thread remain
bound: another session cannot record its diagnostic and another thread cannot
use the grant. The SDK probe separately enforces actual administrator permission
and native session/context. Intent replacement invalidates the outstanding grant;
the controller may publish the next one only after independent case resolution.
Any `revoked.v1` or `retired.v1` directory entry revokes the operation.

## Diagnostics and barrier

Before the business insert, exclusive non-following creation writes exactly one
of `writer.F1.original` through `writer.F4.original`. It records run/case/nonce,
facts, canonical server connection ID, PID/start and original intent hash. The
file and directory are synchronized. Write, sync or reuse failure holds the
bridge, retains the partial/failed original and performs no cleanup or retry.
This diagnostic is not a commit token; the framework still owns all SQL.

Only F4 reads `release.F4.v1`. Its purpose is `paymenter-source-qa-release-v1` and
ordered keys are `run case nonce original_sha256`. Absence means wait; malformed
or mismatched release throws. It binds current F4 identity and the exact bytes of
`barrier.original` (at most 65536 bytes). The observer/issuer must first qualify
that original as the correct independent committed-reader result. Hashing
arbitrary bytes does not establish independence or visibility. No SQL poll,
network request or SDK callback belongs in these methods; the probe owns the
five-second wait ceiling.

## Verification boundary

Local tests use real file operations with the test user's fixture ownership and
authored runtime/clock facts inside the implementation test unit. Those overrides
are absent from the public factory. The sync-failure double wraps only `fsync`;
the written/failed bytes remain real fixture files. These tests do not qualify
Linux root acceptance, loader ABI, source evidence semantics, transaction coupling
or retirement. Syntax-only checks establish none of those native outcomes.
Python issuer tests likewise use actual files/image bytes with fictional boot and
test-user ownership; case tests use an authored process check. A cross-language
test feeds Python-issued authority bytes into the actual C++ bridge parser. This
qualifies neither Linux/root issuer execution nor actual process/loader behavior.

A separate source-native fixture now exercises the public C++ factory in an owned
standalone Linux/root process. It uses real kernel boot/PID/start, executable and
loaded-function image facts with protected root-owned files, and passes grant,
fictional writer/barrier, fork refusal and revocation checks. Its registry/build
originals and connection number are explicitly fictional. It loads only its own
bridge DSO, never BILLmanager or vendor SDK libraries. This does not qualify the
Python issuer, SDK module startup, database observations or transaction coupling.

## Standalone native publisher

`native/control_issuer.cpp` is a separate Linux/root executable for hosts without
a qualified Python 3 interpreter. It amalgamates `authority_bridge.cpp` to share
the exact protected filesystem, parser, image and clock implementation; do not
compile both translation units into one executable. It has no owner, boot, clock,
target-check, SQL, shell or process-root override. It does not generate approvals,
invoke the manager, install anything or grant an `AdmittedRun`.

Select one publisher backend per fresh operation directory. Native originals are
closed ordered ASCII records, not the Python JSON originals; `authority.v1` and
`intent.v1` output formats are identical. Root approval is explicit policy, not DB
schema, independent visibility, actor authentication or transaction evidence.

| File | Purpose line and exact ordered keys |
| --- | --- |
| `approval.native.original` | `paymenter-source-qa-approve-authority-native-v1`; the same ordered keys as `authority.v1`, but `intent=issue-framework-authority`. |
| `registry.original` | `paymenter-source-qa-registry-native-v1`; `run tables objects diagnostics`. |
| `build.original` | `paymenter-source-qa-build-native-v1`; `run module_path module_sha256 runtime_path runtime_sha256`. |
| `case.F1.approval.native.original` (or F2–F4) | `paymenter-source-qa-approve-case-native-v1`; `intent run case nonce facts pid start boot issued expires authority_sha256`, with `intent=issue-framework-case`. |

The native registry requires exactly
`tables=paymenter_qa_business_v1,paymenter_qa_journal_v1`, `objects=none`, and
`diagnostics=writer.F1.original,writer.F2.original,writer.F3.original,writer.F4.original`.
Its build values must match the authority approval and actual protected ELF bytes.
Publication uses a durable `revoked.v1` fence and retains
`issuance.native.original`; any failure preserves originals and refuses reuse.
Case issuance checks the actual target's root UIDs, boot/PID/start, executable
identity and executable module mapping before and during publication. It retains
`intent.F1.original` (or F2–F4), refuses any outstanding intent and never advances
or retires cases automatically. F5 and manual cases cannot be issued.

Its only commands are `authority <protected-directory>` and
`case <protected-directory> F1` (or F2–F4). These publish files only. A source-native
standalone fixture has passed authority issuance, actual case-target checks,
bridge grant consumption, revocation and repeated-issuance refusal without
overwriting the original. Fixture resources and actor/connection/barrier facts
are fictional; it loads no vendor SDK and performs no SQL. This qualifies the
native control path, not SDK startup, independent observation or financial effects.

## Guarded SDK entry

`module_entry.cpp` is opt-in and requires the exact reviewed compile-time
`PAYMENTER_QA_CONTROL_DIRECTORY`. `LIBRARY_ENTRY` opens protected authority;
`MODULE_INIT` depends on `database` and calls the registration gate. The gate
enables requests only after both own action and after-event registration succeed
and authority is rechecked. Any failure, duplicate/reentrant initialization,
destruction or forked use leaves requests disabled, including on a partially
registered own action. No bootstrap failure is propagated into other SDK modules.
Actual SDK ordering, component lifetime and startup/recovery still require their
separate approved native operation; local SDK doubles and static linking do not
establish them.

Post-effect checkpoint originals use purpose `paymenter-source-qa-checkpoint-v1`
and fields `run case nonce facts stage writer_sha256 intent_sha256`. The fixed
stages are `business`, `journal`, `inject-business` (F2), `inject-journal` (F3)
and `barrier` (F4). Each records a successful Execute return or the immediately
following own-action control point, not a transaction/commit. Publication checks
request/session/thread/process/grant binding, predecessor order and unchanged
prior checkpoint identities. Sync failure holds the bridge. The native controller
requires the mode's exact set and rejects preexisting checkpoints before running.
These retained control diagnostics add no SQL or financial authority.

F5 has no business grant. The guarded own-action entry alone can write
`refusal.F5.original`, purpose `paymenter-source-qa-refusal-v1`, fields
`run case nonce pid start authority_sha256`. Its nonce is SHA256 of authority
bytes followed by `\nF5\n`. The bridge verifies actor/action/run/process/runtime,
current original authority and absence of an active intent before refusing the
action. Independent before/after full-table audits are still required; this
refusal file alone proves no database outcome.
