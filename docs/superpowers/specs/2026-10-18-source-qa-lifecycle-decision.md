# Source QA module lifecycle decision

Status: operator approved the two-restart approach for preparing the installation
and rollback package on 2026-10-18. This document authorizes no source deployment
or restart. Native inline execution remains the selected implementation method.

## Finding

The [vendor C++ plugin guide](https://www.ispsystem.com/docs/coremanager/developer-section/documentation-for-developers/lower-level-interaction-c%2B%2B-plug-ins)
explicitly says its installation target restarts the selected product. Its
packaging examples call `ReloadMgr` after installation and removal. The retained
version-matched package implements `ReloadMgr` by invoking the named manager's
`exit` action. This is manager-wide lifecycle activity, not unloading one module.
The installed SDK build target independently invokes the manager's `exit` action
and performs core installation work. Do not use `make install` as a file-copy
operation or source the packaging helper merely to inspect it.

No supported way to unload only the owned native QA library without restarting
the manager has been established from the inspected evidence. This does not
assert that no such method exists. `LoadModule`, component destructors, global
module deinitialization and XML cache refresh do not establish that method.

The installed client API additionally documents that an `exit` request does not
guarantee subsequent requests execute after the restart. Its `Client::Restart`
routine waits for that boundary. Static analysis of the matching installed CLI
and vendor library establishes that the CLI special command `restart` delegates
to this routine. It initially requests `func=exit`, retains the returned PID,
then polls `func=exit&pid=` with increasing sleeps. Do not count that helper as
one internal manager request or assume it fits a short native-case deadline.
It has not been executed in this preparation.

Private evidence retains the vendor archive fingerprint, selected helper bytes,
installed-read originals and failure analysis. Captured installed helper bytes
match the retained vendor package. The one-shot SSH collector nevertheless
failed its strict empty-stderr condition: stderr contains the known OpenSSH
transport warning. The runner did not preserve the SSH exit code separately, so
the original attempt remains failed and was not retried. Offline byte comparison
does not qualify native lifecycle or process recovery.

## Approved preparation decision

Prepare a revision of the current no-restart framework-test constraint to use a
maintenance procedure with two planned BILLmanager manager restarts: one to load
the owned framework QA module, one after removing its registration to unload it.
This decision permits preparing the revised implementation and concrete operation
packet. It does not approve executing an unbuilt binary or an unqualified restart
procedure. No downtime estimate is established yet.

The following limits belong in that revised packet:

1. Compile the candidate in a separate owned staging location first. Record its
   actual hash, SDK/library identities and complete file inventory before any
   install approval. No unknown future binary hash or no-op compile step.
2. Qualify the installed startup/recovery path and read-only health checks.
   Preserve file preimages and fresh manager PID/start/boot identity. A zero CLI
   exit or action-list presence alone is not health acceptance.
3. Admit only the framework module, its private evidence/registry directory and
   the two fixed QA tables. No native customer, subaccount, payment or service
   fixture belongs to this packet. All provider, mail and target-posting gates
   stay closed.
4. Replace the old requirement to unload only one module with an explicit
   acknowledgment that restarting the manager also reloads its ordinary modules.
   Do not claim other billing requests or callbacks are unaffected. The packet
   must establish request/worker recovery and evidence of unrelated module
   preservation before scheduling the interruption.
5. Use only the exact reviewed graceful manager exit/startup procedure. No global
   service restart, database restart, process kill, customer workload operation,
   package upgrade, `make install`, or broad packaging-helper call is a fallback.
6. After verified startup, run only the admitted F1–F5 sequence through the
   qualified private issuer and independent reader. Any unknown outcome stops
   further cases and preserves evidence. Normal billing remains authoritative.
7. Retire exact owned registration/library files only after rechecking original
   registry ownership and hashes. Verify the module absent after the second
   restart before retiring its QA tables. Retain and hash-verify QA row originals
   before deleting only this run's rows and dropping the empty owned tables.
8. If the first load fails, the second planned lifecycle attempt is reserved for
   recovery with the owned registration removed; skip all QA cases. A failed or
   ambiguous recovery stops automatic work. No unbounded restart loop, database
   restore, direct balance repair or unrelated-file rollback is authorized.

## Preparation progress

The installed startup libraries and wrapper/CLI fingerprints match the retained
vendor package. The compiler launcher resolves to a separately installed GCC
toolchain; its actual target was fingerprinted instead of treating the launcher
symlink as a qualified executable. A narrowed compiler-link check refused an
out-of-scope target; that failure is retained, not overwritten by the subsequent
metadata-only link read and exact-target fingerprint.

The generic adapter passed one syntax-only check against the installed SDK on
2026-10-18. That check used the observed compiler, the vendor-compatible legacy
libstdc++ string ABI setting, pinned SDK headers, CPU/address-space/output limits,
and a separate owned compiler session. It exited zero in one second and the owned
caller was reaped. Its only compiler diagnostic was the expected pragma warning
from combining the owned header and source through stdin. File-size allowance
was zero; no source files, object, library or module entry were produced, and no
manager or SQL action ran. Syntax compatibility is not ABI linking, native
transaction behavior or startup/recovery qualification.

The prepared private sequence uses one admitted `exit` request per lifecycle
phase, waits independently for the original PID/start identity to disappear,
then permits one fixed read-only `actionlist` getter to warm the manager and
observes the replacement identity and required modules. CLI completion is never
the restart-completion test. A PID reused with a different start time is handled
as a different process, not signalled. The startup/worker recovery assumptions
still require native qualification before the package can be executed.

The private package retains original evidence hashes and candidate source hashes.
Its module-binary and private-issuer entries remain absent, with explicit blockers;
it cannot be admitted as an install packet. It includes the two planned lifecycle
phases, the recovery reservation, exact QA-table names and prohibited operations.
The protected C++ authority bridge and closed control protocol are now implemented,
with local filesystem/refusal tests and a syntax-only check of the final source
bytes. Python authority/case file issuance is also implemented and locally tested:
it uses distinct root approvals, actual image bytes and durable revocation fences.
It does not issue an AdmittedRun, load a module or resolve a case. The actual Linux
root factory and issuer have not run. Next work is an actual candidate build,
native factory/issuer qualification, the full admitted-run controller and exact
owned file preimages. There is no placeholder module hash or
usable install capability.

The private issuer, installed-SDK object compilation/linking, actual independent read channel,
supervision and exact retirement remain separate implementation prerequisites.
A maintenance decision does not make any of them qualified. The later manual
payment packet needs its own fresh resources and separately reviewed lifecycle;
this proposal does not authorize it.

Until the concrete restart operation is separately accepted, native installation
stays blocked. Leaving
an expired module installed, external event handlers, or a successful synthetic
suite cannot substitute for the required native transaction and retirement
evidence.
