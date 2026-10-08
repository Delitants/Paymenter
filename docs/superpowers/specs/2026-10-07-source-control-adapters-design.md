# Source control adapters and version-matched lock rehearsal

Status: written design for review. Implementation, installation and live freeze are separate stages.

## Purpose and authority

Prepare the external opening controller to assess the actual legacy billing boundaries instead of accepting cooperative fixture gate files. The desired outcome is a trustworthy account migration: the source remains the billing authority until an explicitly approved handover, and an unknown writer or payment route cannot produce a genuine freeze claim.

This increment adds read-only source adapters, explicit coverage validation and a reproducible disposable database lock harness. It does not add a command that freezes the real source, edits its cron, changes ingress, issues a genuine lease, applies funds or releases holds. Those enforcement and deployment steps require the accepted inventory and separate operational authority. Previously selected native execution remains the execution preference.

Private host addresses, credentials, configuration bytes, process identities and database names belong in an operator-owned profile and evidence directory. Generic tools and documentation belong in the Paymenter fork. No private profile, copied runtime or raw evidence is published.

## Evidence that changes the design

The actual source uses MariaDB 5.5.68 and a legacy kernel with a cgroup v1 freezer. Shared manager and web processes serve more than BILLmanager. There are direct, alternate-host, PHP and error-handler ingress paths; the canonical portal alone does not cover them. Scheduled commands without an explicit BILLmanager selector require separate ownership evidence.

A disposable rehearsal using the exact source database executable and libraries passed ten checks against fictional schemas. A SUPER writer bypassed global read-only; explicit READ locks blocked it while a separate connection collected a consistent snapshot. An unrelated schema remained writable. Both starting a transaction on the lock keeper and losing that connection released the locks. A newly created table outside the original lock list remained writable.

These results establish database behavior, not source freeze acceptance, provider acceptance or a measured handover window. The harness has no source data, grants or credentials and cannot select a live database socket.

## Approaches

1. **Explicit coverage adapters plus a secondary database fence — recommended.** Read the real boundaries, require every observation to match an accepted control record, and reject uncertainty. Separately rehearse the exact database behavior. This preserves unrelated applications and makes missing enforcement visible.
2. **Database-lock-only controller.** Smaller, but the tested connection-loss and new-table cases leave gaps. It cannot control lifecycle calls, notifications or external payment activity and is rejected as primary freeze authority.
3. **Stop shared daemons or enable global read-only.** Broad disruption to unrelated applications; SUPER also defeats read-only on the tested version. This is rejected for this migration.

## Components and interfaces

### Read-only source observer

Run a small standard-library collector compatible with the source's existing Python runtime. The external orchestrator uses Python 3.10+. The collector executes only fixed, explicitly enumerated read-only operations; it never executes discovered cron commands, imports billing application code or accepts a shell command from a profile.

Collect:

- Host boot identity and kernel/runtime versions; resolved executable identity, PID, start ticks, UID, ancestry, module selector and cgroup membership for manager processes.
- Scheduler files and service definitions by resolved path, file type, owner, mode, size and SHA256. Retain raw configuration only in private evidence. Record each job by exact bytes and line position; identical duplicate jobs remain separate entries.
- Active web include relationships, listeners and configured upstreams. Record canonical, alternate, direct-manager, PHP, case variants and error-handler branches. Parsing a configuration is inventory evidence, not proof of effective request enforcement.
- Explicitly selected database metadata: server version, engine/object manifest, session table-lock settings, global read-only, relevant connection metadata without SQL text and privileged-principal inventory. Never run LOCK, SET GLOBAL, DDL, GRANT, KILL or mutations against the real server.

Output `Observation` with schema version, observation ID, capture interval, host identity, per-operation success, versioned object records and hashes. Errors remain explicit; a failed read never becomes an empty successful inventory. Limit output size, reject symlinks where ownership requires a regular file, and fail on unsupported object types. Read drift during collection invalidates the snapshot rather than combining unrelated generations.

### Coverage evaluator

Consume an observation and an explicitly accepted private profile. The profile lists writer classes, exact selected objects, permitted unrelated objects, expected ingress branches and required future enforcement records. It contains no executable commands and cannot silently classify a new object as unrelated.

Every observed object must map once to a selected or independently justified unrelated record. Unknown jobs, process selectors, privileged SQL writers, includes, listeners, aliases or database objects are blockers. One control record may cover multiple objects only when its declared coverage is explicit and evidence verifies that scope. Missing expected objects, stale observation, changed boot/executable/configuration/schema, duplicate ownership or partial reads also deny readiness.

Return `CoverageReport` with `inventory_complete`, `enforcement_complete`, `real_execution_ready=false`, exact observation/profile hashes, structured blockers and evidence references. In this increment `enforcement_complete` remains false: observing a PID, a cron file, a closed test listener or an idle SQL connection does not fence that writer. The report is not an attestation and the native opening operator must reject it.

Preserve the existing rehearsal permit purpose and controller recovery contract. Do not broaden the fixture controller into a real signer or alter the native proof schema to accept these reports.

### Disposable database harness

Accept an operator-supplied runtime manifest containing exact hashes for the executable, dependencies and stock initialization files. Copy only those allowed files, never a source data directory, option file, credential or grant database. No automatic runtime downloads or package installation.

On root Linux, create a unique owned RAM root, use a new network/PID namespace and chroot, load no option files, disable networking and use a socket inside that root. Check executable version/hash, socket/datadir ownership and filesystem reserve before any SQL. Bootstrap only fictional schemas and users. A supplied live socket, external datadir, symlink, existing root or mismatched runtime is rejected before SQL.

Reproduce the ten accepted checks, including a complete 300-table READ list, SUPER and ordinary writers, an independent snapshot reader, unrelated-schema writes, a newly created table, START TRANSACTION on the keeper and keeper death. Assert expected error/status and exact counter transitions. Mere timeout, unrelated SQL errors, an early worker exit or a skipped case is failure, not successful blocking.

Retain version/hash, owned process identities, full fictional query results, lock-wait states and failure diagnostics in a private manifest. The exploratory SQL SHUTDOWN command was unsupported by this runtime; cleanup succeeded by retiring only the owned namespace/processes. The reusable harness must include the version-matched administrative client in its allowed runtime manifest and use its shutdown operation through the verified owned socket. Record a failed graceful shutdown explicitly, then terminate only recorded owned processes after checking their identity. Verify retirement and retained evidence before removing the exact owned RAM root. Bootstrap failures retain diagnostics and use a new data directory on retry.

## Future enforcement contract

The read-only adapters must expose these requirements as unsatisfied evidence, never emulate them with a fixture gate:

- Suppress only accepted BILLmanager scheduler entries using exact-byte comparison and retained rollback bytes. Shared notification, DNS and processing commands must be classified first. Account for workers that started before scheduler suppression.
- Independently fence the selected BILLmanager core and proven children with a persistent, version-specific process control. Do not freeze shared manager/web/database processes. Confirm completed freezer state and exact membership; a v2 rehearsal cannot prove source v1 behavior. Controller loss, lease expiry or connectivity loss must retain the fence.
- Enforce every relevant public and direct ingress branch. A shared manager listener cannot be globally blocked without accepted evidence that unrelated applications retain service. A configuration listing alone does not establish safe selective routing. Raw and normalized route variants need actual acceptance before installation.
- Isolate manual/API/CLI and privileged SQL writers, including DDL. No privilege revocation or global daemon stop is implied. An unexplained privileged connection or an unproven exclusion is a blocker; absence in a process snapshot is not durable exclusion.
- Keep schema READ locks in an independent keeper after primary writer/ingress controls are established. The keeper must not start a transaction or collect the snapshot. Lost keeper, changed schema, or changed writer manifest denies renewal while independent gates remain held.
- Preserve payment custody outside the application and real signer. Provider authentication, acknowledgement and reference ownership require independent protocol acceptance. A retained notification means delivery, not payment settlement. Unreconciled late financial events prevent a frozen opening package from becoming eligible.
- Fence target financial writers as well as source writers. Native source billing deassignment for only the migrated cohort, without provider lifecycle calls, remains a distinct handover prerequisite.

Real signing/trust installation, genuine RSA leases, actual-cohort duration, late-payment reconciliation and release authority remain outside this implementation increment. A future design must close the selective direct-listener and privileged-writer enforcement questions before any live apply command is offered.

## Failure and recovery

Read-only observation failure changes no source state. Retain its private diagnostics and return a failing status. Coverage reports are immutable and keyed to exact inputs; a fresh observation needs a fresh evaluation.

The harness operates only on owned resources. Failures latch failure, retain evidence and attempt bounded identity-checked cleanup. If a process cannot be proved retired, preserve the root and report cleanup required; never claim cleanup or readiness. Maintain at least 1 GiB root and 512 MiB RAM reserve.

Do not automatically replay notifications, thaw real processes, resume billing, restore cron, remove ingress controls or repair financial state. Future live abort after any opening application needs the separately reviewed financial recovery path before source billing can resume.

## Acceptance and publication

1. Parser and evaluator regressions use private-shape fictional fixtures. Assert unknown and duplicate jobs, positional selectors, missing/changed includes, alternate/direct/error routes, partial capture, stale boot identity, PID reuse, schema drift and unexplained SUPER writers all deny readiness.
2. A fresh read-only source observation must reproduce actual versions and coverage gaps. Source configuration bytes, core identities and database settings are compared before/after; normal source billing data is expected to evolve.
3. Run the database harness natively with the accepted exact-version runtime. All ten checks must pass with no skips and all owned resources retired. Preserve failures as well as the accepted run. Do not rerun unrelated application suites or provider transactions for this increment.
4. Verify report/permit rejection by the existing native proof parser. Obtain independent review of the immutable generic candidate and any consequential defects before publication.
5. Compare every held runtime file and all protected full database hashes, retain private evidence and preserve holds, disabled collection/funding and provider bindings. Publish only reviewed generic bytes to the existing draft pull request after the protected-value scan passes.

Completion means these observer/evaluator/harness tools are reviewed and reproducible. It does not mean real source controls are installed, the source is frozen or the billing handover is ready.
