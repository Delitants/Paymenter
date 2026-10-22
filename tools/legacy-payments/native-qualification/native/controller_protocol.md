# Source-native framework run controller

`run_controller.cpp` is a standalone C++ runner for the fixed F1–F5 sequence.
It consumes **already created, installed, loaded and separately admitted** QA
resources. It does not install/load/unload a module, restart or signal a manager,
change billing, invoke a payment action, or create customer/provider resources.
Its only manager action is `paymenter.qa.transaction` through the approved
`mgrctl` image, using fixed `-m billmgr -o xml` arguments and closed QA parameters.
Actual SDK worker/startup/caller semantics still require the reviewed native
packet; a successful authored-boundary test does not qualify those semantics.

Build this translation unit alone with the same reviewed C++11, ABI, OpenSSL and
MySQL dependencies as the resource tool. Its unity includes share the protected
bridge, resource custody and case evidence implementations exactly once. Never
link it into the SDK, or link the included implementation files a second time.
CLI: `native-controller <protected-operation-directory>`. The public entry point
requires actual Linux/root identity and a supported protected filesystem. There
are no command, SQL, credential, database, owner, target or clock CLI overrides.
Internal fixture hooks are not public native factories.

## Run approval and original resource custody

`run.approval.native.original` is an original root-owned, singly linked 0600 file.
Its purpose is `paymenter-source-qa-run-approval-native-v1`, followed by these
exact fields in order:

```
intent run boot issued expires self_path self_sha256 caller_path caller_sha256 reader_path reader_sha256 resource_path resource_sha256 manager_pid manager_start authority_sha256 created_sha256 created_identity complete_sha256 complete_identity
```

Intent is exactly `run-framework-F1-F5`. The window is current, at most 600
seconds, and contained in the authority grant. The source boot, manager PID/start
and original loaded runtime/module mappings must match throughout. The four
programs are distinct original ELF files: this controller, caller, independent
reader and resource producer. Their bytes and nanosecond file identities are
pinned and rechecked. The self path/start comes from the actual kernel in the
public factory. The resource image must be the **original creation producer**;
a differently built controller never substitutes itself for that producer.

Original authority approval, registry, build, authority issuance, resource create
approval, absence proof, schemas, creation receipt and completion must form the
same protected custody chain. The run approval binds both creation and completion
bytes **and identities**. The controller refuses existing run/case grants,
writers, checkpoints, refusal/barrier/release controls and reused run originals.
It reserves `controller.started.original` before the first collector.

The outer lifecycle packet must explicitly invoke the exact approved resource
tool for creation/retirement. This runner does not silently grant those lifecycle
operations, substitute fictional resource proofs, perform compensating writes,
or clear an incomplete retirement. Installation and the separately approved
manager lifecycle still need fresh preimages and their own verification.

## Owned execution and evidence

`owned_child.hpp` launches only fresh direct children, without a shell or inherited
environment. It pins an original executable descriptor against the approved
nanosecond identity before execution; Linux uses `fexecve`. A pre-exec gate lets
the parent capture actual PID/start, parent, process group, session and boot.
An exec-error pipe distinguishes a failed exec from a successful exec's EOF.
The parent inventories descriptors before fork, verifies a single Linux thread,
and closes every unrelated inherited descriptor in the child. Child stdin is
`/dev/null`. Only async-signal-safe setup/exec calls run after fork.

The fixed environment selects C locale, a minimal PATH and the existing vendor
library directory; no credential reaches arguments or environment. Core dumps
are disabled; CPU, address-space, file-size and descriptor limits are fixed.
Caller stdout/stderr ceilings are 16/8 KiB. Each operation is at most 30 seconds,
the aggregate native output ceiling is 1 MiB, the fixed reader count is bounded,
and at most two owned children coexist for the F4 caller/reader overlap.

Deadline, overflow or callback failure may send SIGKILL only to the same owned,
unreaped direct child after kernel identity verification. No process-group,
descendant or manager signal is permitted. Unknown identity/reaping/EOF stays
held. A descendant holding a pipe cannot extend the deadline and is never
signalled by the launcher. Failure streams, original command/image/argv/environment
bindings, actual exit/EOF/exec state and retirement facts are retained under
`capture.*`. Framed stream originals preserve empty streams without inventing
bytes. All publications are exclusive and fsynced; errors never overwrite or
retry a previous operation.

## Fixed observations and sequencing

- The first fresh whole-table audit must prove both original QA tables empty.
- F1–F4 run once in order with a fresh case approval/intent bound to the original
  manager. There is no input-provided operation list or case retry.
- Protected post-effect diagnostics record successful business/journal Execute
  returns. F2/F3 also require their respective **injection checkpoint** immediately
  before the deliberate exception. Missing rows or a generic caller failure alone
  cannot establish the injected rollback case. These diagnostics never claim commit.
- F4 requires the module's protected `barrier` checkpoint. While that owned caller
  waits in its own five-second action barrier, a different committed reader must
  observe neither row. The runner seals the observation, publishes a release bound
  to that original, then requires the exact pair after the caller returns.
- F1's original caller result stays source-private and is withheld from the
  collector interface. After independent pair visibility is sealed, a newly
  reserved reader reconciles the same rows on a distinct connection. No action
  is repeated and no native/provider acknowledgment is fabricated.
- Each post-case whole-table audit must equal the accumulated expected rows,
  including unchanged earlier cases. A protected advance record binds the exact
  active intent; it is removed only under a durable revocation fence after the
  caller is reaped and observations/checkpoints are complete. Archived originals
  remain. Any uncertain unlink/fsync/identity outcome leaves the fence in place.
- F5 receives no grant and writes no QA SQL. Its guarded entry must retain a
  refusal original bound to the source runtime, authority and a derived F5 nonce.
  Fresh whole-table audits before/after must match the full expected state;
  merely checking an unused F5 key is insufficient.

Every reader runs as its own owned child, with an exclusive protected directory,
new request, actual fresh connection, READ COMMITTED/autocommit and no-open-
transaction checks. Writer and previous reader identities cannot masquerade as
independent readers. Audit mode also verifies original SHOW CREATE definitions
and absence of triggers/incoming/outgoing foreign keys. Rows come from one fixed
UNION ALL statement/read view, never two SELECTs that could straddle a commit.
Read approvals, starts, raw selected rows and completions are hash/identity-bound
and retained, with failures dominating successful-looking files.

On any failure, no new case starts; `controller.failure.original` and revocation
remain. Original evidence is retained for a separately scoped recovery. A finished
run retains `controller.complete.original`, then fences the authority. The report
explicitly says `retired=0` and `qualification=not-issued`. Actual framework
qualification still needs native SDK/caller/session evidence, the approved
unload/restart/retirement checks and full review. Manual coupling, provider
validation, financial posting and billing handover remain separate gates.

Executable references also bind the original logical leaf, target, link count
and protected directory identities. The original logical argv[0] is preserved
for a vendor CLI implemented by a shared wrapper; Linux executes its pinned
resolved descriptor. `capture.image.<role>.original` retains each genuine
reference, and command originals bind its hash. See
[program reference custody](program_image_protocol.md). Root module files and
all private control originals retain their stricter single-link/no-alias checks.
