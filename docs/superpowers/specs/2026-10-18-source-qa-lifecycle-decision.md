# Source QA module lifecycle decision

Status: proposed constraint revision, awaiting the operator's decision. This
document authorizes no source command, deployment or restart. Native inline
execution remains the selected implementation method.

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

Private evidence retains the vendor archive fingerprint, selected helper bytes,
installed-read originals and failure analysis. Captured installed helper bytes
match the retained vendor package. The one-shot SSH collector nevertheless
failed its strict empty-stderr condition: stderr contains the known OpenSSH
transport warning. The runner did not preserve the SSH exit code separately, so
the original attempt remains failed and was not retried. Offline byte comparison
does not qualify native lifecycle or process recovery.

## Decision requested

Permit a revision of the current no-restart framework-test constraint to use a
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

The private issuer, installed-SDK compilation, actual independent read channel,
supervision and exact retirement remain separate implementation prerequisites.
A maintenance decision does not make any of them qualified. The later manual
payment packet needs its own fresh resources and separately reviewed lifecycle;
this proposal does not authorize it.

If the no-restart constraint remains, native installation stays blocked. Leaving
an expired module installed, external event handlers, or a successful synthetic
suite cannot substitute for the required native transaction and retirement
evidence.
