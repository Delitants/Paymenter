# Selective scheduler and cgroup-v1 process adapters

Status: written specification for review following approval of the conversational design. This is a documentation increment. It installs no adapter, changes no scheduler or process, and authorizes no source freeze or financial handover.

## Outcome and scope

Prepare two cooperating adapter families for the `source-scheduler` and `source-process` requirements in the [source boundary architecture](2026-10-07-source-boundary-enforcement-design.md). They must suppress only accepted billing dispatch, contain the complete dedicated billing execution domain, and preserve unrelated workloads. Recovery must retain denial through controller loss and reboot until separately authorized release.

The first implementation increment runs fixed operations only on independently owned fictional resources in a disposable, version-matched Linux guest. It cannot operate on the real source or target. It does not add production adapters to the existing fictional boundary evaluator, change native opening proof acceptance, install a signer, issue a genuine lease, or satisfy the other five physical requirements. Every aggregate result keeps `enforcement_complete=false` and `real_execution_ready=false`.

Source billing remains authoritative during preparation. Actual scheduler changes, process migration/freezing, installation of restart guards, source freeze, account openings, deassignment and handover each retain their separate operational acceptance and authority requirements. Private identities, credentials, observations, paths, original configurations and recovery bytes stay outside the repository. Only generic contracts, tools, fictional fixtures and documentation belong in the Paymenter fork.

## Selected approach

| Approach | Benefit | Limitation | Decision |
| --- | --- | --- | --- |
| Selected scheduler edits, a dedicated freezer domain, and durable dispatch/start denial | Preserves unrelated workloads and covers restart paths as well as existing workers | Requires accepted ownership, exact platform behavior and independent exclusion of bypass paths | Selected |
| Suppress cron and stop a saved PID list | Small implementation | Does not establish complete descendant coverage, alternate dispatch or durable restart exclusion | Insufficient |
| Stop shared cron, manager, PHP or database daemons | Broad interruption | Exceeds selective scope and interrupts unrelated services | Outside this specification |

Scheduler suppression and process containment are separate controls. No scheduler acknowledgement implies that already-started work has stopped. No frozen process implies that an alternate launcher or privileged writer is excluded. Shared notification, DNS, wrapper dispatch and dynamically selected modules stay blocked until classified or contained within an independently proved and authorized domain.

## Platform and rehearsal identity

The accepted source metadata establishes a legacy Linux cgroup-v1 freezer and legacy systemd/Cronie runtime. Exact release strings, distribution backports, boot identity, package identities and capture time remain in the private platform observation. Metadata is not enforcement evidence.

The rehearsal manifest pins the kernel build, freezer controller, mount topology, systemd and Cronie packages, adapter/tool hashes, fictional fixture hashes and guest image digest. A modern kernel with v1 enabled is exploratory unless equivalence to the accepted source behavior is independently established; an ordinary cgroup-v2 Docker run cannot accept deployment to v1. The initial native acceptance requires a guest matching the accepted source kernel and scheduler/service packages, including distribution revisions.

Guest resources have an explicit ownership receipt. No source disk, production socket, provider credential, real cron/service root or customer VM is attached. The guest has no outward network access, and only its identified resources may be altered or retired. Root privilege is confined to that guest; repository code is not executed as host root on a production server. Maintain at least 1 GiB root and 512 MiB RAM reserve outside staging. Unavailable matching runtime is a recorded blocker, never a skipped acceptance test.

## Components and interfaces

Use the existing flat external toolkit under `tools/opening-controls/`; do not place privileged adapters in Paymenter application code. Separate pure inventory/transformation logic from the fixed guest operations, durable journal, independent observer and owned-resource harness. A profile never supplies a command, import path, SQL, executable snippet or user-defined adapter implementation.

The implementation uses two versioned rehearsal identities: `scheduler-selective-v1-rehearsal` and `process-freezer-v1-rehearsal`. These belong to a separate finite rehearsal dispatcher. They are not registered in `boundary_coverage.py`, whose only supported adapter remains `fixture-boundary-v1`. Production identities and registration are deferred until separate implementation and deployment acceptance.

Fixed operation names are `inspect`, `prepare`, `establish`, `readback`, `recover`, and `release`. `prepare` records accepted scope and originals without changing a gate. `establish` requires a durable intent and current exact preconditions. `recover` inspects current physical state and preserves denial; it does not automatically restore execution. `release` requires separately accepted release authority and independently proved operator retirement or external fencing. The first dispatcher accepts only an owned fictional rehearsal session and rejects real-resource profiles before opening a mutation target.

All operation inputs bind a boundary-plan digest, adapter implementation/manifest digest, member-manifest digest, session ID, freeze ID, positive control generation and expected source boot. Exact typed inputs and closed supporting schemas are specified in the reviewed implementation plan before code is written. Inputs and outputs remain distinct from the existing fixture-evidence purpose; no conversion to a fixture `pass` is permitted.

Reuse private reference safety: ordinary root-owned 0600 files beneath root-owned 0700 directories inside the guest, original-byte SHA-256 binding, no symlink/hard-link traversal, no existing-file overwrite, and pre/post-read identity checks. Apply the existing 4 MiB per-document, 32 MiB combined, 10,000-entry and eight-level limits to the complete input graph, including external witness references and rollback data. Bind rollback bytes through references rather than inserting configuration contents into public fixtures or logs.

## Scheduler adapter

Inventory exact scheduler files, spool entries, ownership/modes, include topology, environment and executable/wrapper dependencies. Account separately for cron, anacron, systemd timers, boot dispatch, service restart, manual/API dispatch and work already dequeued or running. Changed or incomplete inventory rejects establishment. A cron selector or filename does not independently prove ownership.

The pure transformation accepts exact original bytes and explicit accepted entry spans with original-byte digests and object IDs. It suppresses only whole accepted entries; unrelated bytes, comments, environment, newline form and ordering are preserved. Unknown syntax, overlapping spans, unsupported continuation forms, multiple ambiguous selectors or shared entries reject transformation. The adapter does not rewrite a shell command based on a guessed module name. An accepted entry that can dispatch both billing and unrelated work is blocked unless a separately accepted selective dispatch boundary exists.

Before changing a file, the adapter verifies pinned parent/leaf identities, original bytes, owner, mode and required security metadata. It records and fsyncs originals and intent, uses an owned same-directory staging file, performs the exact replacement, fsyncs the directory and verifies installed bytes and metadata. Changes across multiple files are separate journaled steps; there is no claim of a filesystem-wide atomic transaction. Failure after one replacement retains partial denial and reports `recovery-required`.

The adapter requires proved exclusive maintenance authority over scheduler configuration during compare-and-replace. An advisory lock alone cannot prevent an uncooperative editor racing the replacement. If exclusive authority or version-matched spool update semantics cannot be established, mutation is blocked. Competing edits discovered afterward invalidate acknowledgement and must not be overwritten during recovery.

Hash readback alone is insufficient: prove the pinned scheduler noticed the installed generation and denied the selected fictional dispatch while unrelated jobs still executed. Cronie watches/reload details must be tested against the exact package rather than assumed from a newer manual. Previously queued work is assigned to the process control or remains an unresolved blocker. A timeout or absence of a process does not prove dispatch denial.

Scheduler suppression persists in durable configuration. An independent start guard covers accepted service, boot and other launch paths. Restoring jobs, starting a service or permitting dispatch is never a response to witness expiry, controller death or SSH loss.

## Process adapter and start guard

Accept a dedicated execution domain only with evidence of its complete code/configuration/working-directory scope, launch paths, descendants and collateral ownership. Do not infer dedication from a service name, existing systemd cgroup or current activity. Shared PHP pools, shared manager workers and unrelated database/web services cannot be attached to the selected freezer domain.

Before admitting an existing task, pin boot ID, PID/TID, process start ticks, executable digest, credentials, ancestry, existing controller membership and accepted domain identity. Resolve all threads and nested freezer groups; a single leader PID or a single `tasks` read is insufficient. Task identities are checked again immediately before and after operations. Disappearance, PID reuse, inconsistent ancestry, unaccepted descendant or changed executable rejects acknowledgement.

Establishment starts only after accepted ingress/custody and dispatch/start gates deny new work. Those dependency readbacks remain independently required; a rehearsal substitutes only instrumented fictional gates and never claims live ingress or SQL acceptance. Already-started asynchronous side effects remain reconciliation blockers until separately resolved.

Within the owned guest, create a dedicated non-root freezer cgroup, retain the exact original memberships, and admit only proved selected tasks using fixed cgroup-v1 interfaces. Migration is not atomic across tasks. Repeat identity, complete domain and membership checks around each step; discovered outside descendants or launch races stop establishment. The adapter may not chase an unknown process into containment merely to obtain a passing result.

Write the desired freeze and verify effective `FROZEN` state for the controlled subtree, complete membership and domain coverage. `FREEZING`, a successful write, a stopped-looking process, or an empty group while selected work is outside it cannot produce `held`. The [kernel freezer documentation](https://docs.kernel.org/admin-guide/cgroup-v1/freezer-subsystem.html) describes effective state, hierarchical freezing and renewed freezing when a task is added. Any task addition or membership change invalidates the old readback and requires identity, membership and completed-state verification again.

The durable start guard denies accepted launchers before execution or outside-domain admission. Its reviewed installation binds exact launcher/service bytes and dependencies. It defaults to denial if its state is missing, malformed, stale, from another generation or unreadable. It does not stop an unrelated shared launcher. Each service alias, timer, boot path and alternate dispatcher is either independently denied or remains blocked. A unit mask alone is not proof against direct executable or privileged launch paths. Use only features supported and rehearsed on the pinned legacy service manager; do not assume modern `systemctl freeze` behavior.

The freezer state is not persistent across reboot. Durable guard configuration must be active before any accepted billing startup, including cron and alternate launch paths. The guest boot test attempts each fictional launcher before controller recovery and verifies explicit denial plus unrelated startup success. Old-boot witnesses are unusable. Recovery observes the new boot, rebuilds only owned control resources if necessary under retained denial, and requires fresh readbacks before any new acknowledgement. Inability to establish this ordering keeps the process adapter blocked.

Root or equivalent authority can move tasks, change cgroups or bypass local launchers. This design does not claim to withstand hostile host root. Exclusive maintenance authority and independently controlled privilege/CLI/SQL exclusions remain prerequisites for later live acceptance. Cgroup membership controls do not replace those boundaries or external fencing of the opening operator.

## Journal, observer and state

Use the architecture's states: `draft`, `prepared`, `establishing`, `held`, `lost`, `recovery-required`, `released`. There is no direct prepared-to-held transition. Record an immutable fsynced intent before each gate mutation and an immutable physical readback after it, with the exact generation, resource identities, expected previous state, original-byte references and result. A partial write, disk-full condition or missing readback cannot be acknowledged as success.

The observer is a distinct process outside the frozen subtree. Bind its own boot ID, PID, start ticks, executable hash and monotonic interval. It independently reads physical gates, actual membership, start denial, selected attempt results and unrelated workload results. It cannot endorse a controller assertion file as gate evidence. A stale interval, changed boot, mixed generation, vanished observer or unreadable member denies renewal. Freshness uses the observer's own boot and monotonic clock with the existing at-most-60-second limit; historical receipts cannot be timestamp-refreshed.

Rehearsal results have a distinct purpose and explicitly identify their fictional resource scope and runtime. They may report that these two families passed native rehearsal; they never report that a production boundary plan was accepted or that all seven requirements are held. Existing plan/witness/reference schemas and native `ProofSchema` remain unchanged. Any later production evidence contract requires its own review and verifier integration.

On loss of a boundary, latch failure, stop acknowledgements and request immediate independent external fencing of the opening operator. Renewal stoppage alone is insufficient while a previous lease could still be used. The rehearsal records detection and fictional operator-fence timing at transaction boundaries; it does not implement or accept the genuine signer/operator fence. If either is unavailable, live execution remains blocked. Witness expiry with the physical gate intact also denies renewal and retains the gate.

## Recovery and release

Controller replacement reads the journal and physical resources before continuing. Ambiguous outcome, generation mismatch, substituted file, unexpected task or competing edit means `recovery-required`; retain established denial and original evidence. Never infer that a process is safe to thaw from a controller exit code or the absence of a recorded success.

Before any financial commit, a separately approved release may restore exact original scheduler bytes, metadata and original task memberships only after the opening operator is proved retired or externally fenced and custody remains accepted. Compare current installed bytes/state to the recorded generation before each reversal; drift blocks restoration rather than being clobbered. An originally absent file may be removed only when its identity and bytes are the exact owned creation.

Rollback accounts for original freezer state and frozen ancestors; moving or thawing a task must not override an unrelated preexisting freeze. Process death or reboot does not authorize reuse of its PID or replay of stale memberships. Restoration after those events requires a separately accepted current recovery disposition, never automatic process restart. Release ordering keeps independent ingress, dispatch/start and privilege gates denied until the entire approved rollback has verified readback. Partial release stays in recovery-required with remaining denial retained.

After any opening commit, old billing must not simply resume. Unknown transaction outcome requires reconciliation; committed receipts remain authoritative. Forward recovery or an accepted preactivation restore is a separate financial procedure outside these adapters. Successful rehearsal cleanup can retire only fictional owned resources and cannot authorize live release.

## Native acceptance matrix

Each case must assert the intended outcome and retain raw diagnostics privately. A timeout, unrelated setup failure, silently omitted case or skip cannot establish acceptance. Fictional selected work has instrumented dispatch/work acknowledgements and an independent side-effect counter; unrelated work has successful acknowledgements before, during and after the gate. Repeated stable snapshots alone are not proof against an unexcluded launcher.

| Cases | Required evidence |
| --- | --- |
| Selected entry suppression; environment and unrelated preservation; scheduler reload | Exact before/after bytes and metadata, effective reload generation, explicit denied selected attempt and successful unrelated dispatch |
| Shared/unknown job; alternate/timer/boot dispatch; unsupported syntax; changed original or concurrent edit | Exact coded rejection without changing unaccepted resources; unresolved scope stays visible |
| Work dequeued before suppression; worker born during establishment | Complete assigned domain or blocked establishment; no scheduler-only held claim |
| Multi-threaded worker; nested group; fork during admission; task added to frozen domain | Exact task identities and memberships, invalidated prior readback, effective subtree FROZEN before renewed acknowledgement |
| Unfrozen or permanently FREEZING task; shared worker; PID reuse; escaped or restarted worker | Specific loss/rejection; no successful timeout; independent fictional operator fencing |
| Controller/observer death; disconnect; stale generation; changed executable; disk full between intent and readback | No automatic resume, loss latched, physical recovery inspection and preserved evidence |
| Guest reboot; guard state missing/corrupt; early cron/service/alias/alternate start | Old witnesses rejected, explicit startup denial before recovery, unrelated startup still functional |
| Guard bypass attempt; membership tamper; privilege boundary unavailable | Bypass detected and operator fenced; missing exclusion blocks live eligibility rather than claiming hostile-root resistance |
| Release while operator lives; exact rollback; original frozen ancestry; drift or partial restoration | Unsafe release denied; only accepted original bytes/membership restored; independent gates retained until readback |
| Wrong guest/runtime/controller; real-resource profile; forged rehearsal purpose | Refusal before mutation; no fixture or native opening-proof acceptance |

The harness records identity-checked guest retirement and original local artifact hashes. If guest ownership or retirement cannot be proved, retain its exact resources and diagnostics and report cleanup required. Do not signal unrecorded PIDs, remove another session's files or reuse an unrelated VM.

## Acceptance handoff

The written implementation plan follows separate review of this specification. It must define the closed rehearsal contracts, fixed guest operations, exact platform acquisition/ownership, negative test expectations, observer and crash/reboot evidence, preservation/privacy checks, and independently reviewed immutable candidate. Native inline execution remains the selected preference.

The first increment is complete only when both families pass every required matching-runtime fictional rehearsal, generic published bytes match tested bytes, private data scanning passes, and all owned-resource retirement is accounted for. If platform acquisition or a required test cannot be completed, report the exact remaining blocker and keep the increment incomplete. No live source adapter, real lease or handover readiness follows automatically. Separate acceptance of all seven boundaries, fresh reconciliation and explicit financial authority remain required.
