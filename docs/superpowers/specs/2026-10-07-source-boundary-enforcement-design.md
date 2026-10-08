# Source boundary containment and persistent enforcement

Status: written architecture for review. This document installs no control and authorizes no source freeze, account opening, hold release or billing handover. It extends the future enforcement contract in [source control adapters](2026-10-07-source-control-adapters-design.md); it does not replace its accepted observer or version-matched lock rehearsal.

## Outcome and authority

The controller must be able to establish a bounded, persistent financial freeze without allowing an unknown writer, callback or privileged connection to escape coverage. Source billing remains authoritative during preparation. Source freeze, financial application and billing handover each require their own operational authority and accepted evidence. Unrelated applications and accounts outside the approved migration cohort retain their existing ownership.

Keep installation-specific hosts, routes, credentials, principal names, process identities, account scope, observations and recovery bytes in private operator files. Publish only generic contracts, tools, fictional fixtures and documentation in the Paymenter fork. No private signer or secret is installed in the application.

The current tools deliberately separate inventory from enforcement. Static scalar candidates, matching names, configuration parsing, idle sessions and missing callers do not prove effective execution or absence of authority. They remain unaccepted unless independent evidence resolves them or a larger, explicitly authorized boundary physically contains them.

## Selected approach

| Approach | Benefit | Limitation | Decision |
| --- | --- | --- | --- |
| Layered selective controls with explicit containment of unknown paths | Preserves unrelated workloads and closes multiple writer channels | Requires independent proof of each boundary and its collateral scope | Selected |
| Endpoint and job allowlists alone | Small changes | Misses dynamic includes, direct listeners, child workers, CLI and privileged SQL | Insufficient |
| Stop shared daemons or freeze the entire host | Broad containment | Interrupts unrelated applications and defeats the required selective scope | Outside this design |

A wider boundary can contain an unknown member without claiming to understand that member. For example, a proved dedicated execution domain can cover every dynamically loaded script within it. This does not authorize stopping a shared PHP pool, manager core or database. If the wider boundary overlaps an unapproved workload, its control is blocked. The design never silently labels uncertain members unrelated.

## Contracts and separation

Introduce a generic planning contract before implementing live adapters. It is separate from schema-1 `source-control-coverage-profile`, the existing fixture controller and the native signed opening proofs. The current coverage evaluator continues to return enforcement unproved and real readiness false. There is no backward-compatible interpretation that turns an inventory report into a fence.

### Boundary plan

`source-control-boundary-plan`, schema 1, is a closed private document with these required fields:

- `schema_version`, `purpose`, `plan_id`, `source_identity`, `target_identity`.
- `observation_refs`, `cohort_ref`, `baseline_ref`, `controls`, `unresolved`, `acceptance_ref`.

References contain only `path` and `sha256`, binding original bytes of ordinary private files. Require root-owned 0600 files under root-owned 0700 directories; reject symlinks, hard links, stream wrappers, duplicate decoded JSON keys, unknown fields, cyclic references and path substitution. Limit individual referenced documents to 4 MiB, total plan bytes to 32 MiB, entries to 10,000 and reference traversal to eight levels. No financial value is a JSON float. Source and target identities contain `host_identity_sha256` and `boot_id`. `acceptance_ref` is null until separate review accepts the exact plan; a non-null reference alone does not prove acceptance.

Each `controls` entry has exactly `control_id`, `requirement`, `adapter_id`, `adapter_manifest_ref`, `members`, `unrelated_scope_refs`, `precondition_refs`, `blocking_check_refs`, `rollback_ref` and `dependencies`. `requirement` is one of the seven existing control codes. Adapter IDs are a finite compiled allowlist with a versioned implementation manifest; profiles cannot supply shell, SQL, executable snippets or arbitrary import paths.

Each member has `object_id`, `object_sha256`, `role` and `evidence_refs`. Roles are `selected` and `contained-unknown`. A `contained-unknown` member requires proof of the containing domain and every applicable ingress or authority boundary, not a static name match. Unrelated objects require separate exact scope references and preservation checks. Every observed object is assigned once; duplicates, omissions, added objects, changed bytes and unsupported forms reject the plan. The scope explicitly includes referenced PHP dependencies and file-backed state; the original observer's object list is not assumed to contain them automatically.

Each unresolved entry has `object_id`, `reason_code` and `evidence_refs`. All must remain visible. A plan with unresolved effective containment is valid as a draft but ineligible for enforcement acceptance. Binding an old observation preserves lineage; it never refreshes its capture time or proves current state. Invalid, incomplete or mixed-generation capture and unsupported inventory syntax cannot be overridden by containment. Only fully enumerated members with unresolved execution semantics can be represented as contained unknowns.

### Control witness

`source-control-boundary-witness`, schema 1, contains exactly `schema_version`, `purpose`, `witness_id`, `plan_sha256`, `control_id`, `control_generation`, `session_id`, `freeze_id`, `source_boot_id`, `observer_identity`, `interval`, `adapter_manifest_sha256`, `state`, `member_manifest_sha256`, `gate_readback_ref`, `attempt_result_refs`, `unrelated_result_refs` and `failure_refs`.

`observer_identity` binds its boot ID, PID, process start ticks and executable hash. `interval` contains `observer_boot_id`, `start_ns` and `end_ns`; nanosecond values are decimal strings compared using the observer's monotonic clock, not wall time. State is `unproved`, `held` or `lost`. Missing required checks or any failed read cannot produce `held`.

A witness binds actual gate readback, exact attempted-writer results and unrelated workload checks. A configuration hash, assertion file, timeout, successful transport or absence in a process snapshot is insufficient. Witnesses are immutable and journaled before any acknowledgement. Later renewal requires current adapter readback, not indefinite reuse of an accepted rehearsal result. These documents cannot be supplied as native opening proofs.

### Session, verifier and signer

A controller journals intent before each gate change and records readback after it. It pins the plan, adapter manifests, original rollback bytes, process identities, schema and callback custody. Mutation uses adapter-specific fixed operations with exact preconditions; unknown state, substituted files or competing changes fail before mutation.

An independently running verifier evaluates the seven requirements, coverage, fresh witnesses, unresolved containment, preservation and reconciliation. It is not the process holding the secondary database locks or the opening operator. The real signer remains separately isolated and unimplemented in the first increment. It may later issue the existing at-most-60-second native lease only from accepted current enforcement evidence and explicit opening authority. No new document here weakens the native proof schema, trust allowlist or revocation checks.

The fixture controller continues to issue only its separate rehearsal purpose. The first implementation increment adds plan/witness validation and negative acceptance tests; it has no live apply, signing, freeze, restore or handover command. Its only registered adapter is `fixture-boundary-v1`, restricted to fictional references. Proposed production adapters remain unavailable and block deployment acceptance until their separate versioned implementations and native proof are reviewed. A fixture witness cannot satisfy production control requirements.

## Seven physical boundaries

| Existing requirement | Required containment and readback | Condition that prevents acceptance |
| --- | --- | --- |
| `source-scheduler` | Compare exact accepted scheduler bytes; retain rollback; suppress only selected entries; account for timers, already-started workers and alternate dispatch paths | Unknown shared job, changed crontab, unexplained wrapper/module dispatch or newly spawned worker |
| `source-process` | Pin selected core and proven descendants; control the complete accepted execution domain; verify identities, membership and completed frozen state | Shared/unowned process, escaped descendant, restart outside the domain, PID reuse or incomplete freeze |
| `source-ingress` | Contain canonical, alternate, default-host, direct-manager, PHP/FastCGI, internal redirect, error-handler and existing-connection branches; retain provider events in accepted custody | Unproved route selection, reachable alternate listener, shared pool ambiguity, unaccounted active request or unauthenticated custody acknowledgement |
| `source-cli-sql-ddl` | Exclude accepted application, manual, API, CLI, event, privileged and DDL writers; drain existing sessions and verify durable authority restrictions | Unexplained principal/session, uncontrolled privileged access, new schema object or bypass through a local socket |
| `secondary-lock-keeper` | Separate keeper holds a complete version-matched schema READ-lock list; separate reader captures; monitor connection, manifest and waits | Keeper transaction, keeper loss, changed table/view/trigger/event manifest or primary exclusion failure |
| `provider-custody-reconciliation` | Durable authenticated arrival custody precedes acknowledgement; retain original headers/body and arrival IDs; reconcile legacy references and late financial effects | Unsupported protocol, lost/disk-full custody, conflicting event, unknown reference, outstanding settlement or unexplained source movement |
| `target-and-cohort-deassignment` | Independently exclude target financial writers; preserve holds; later accept native source billing deassignment for exactly the approved cohort without lifecycle calls | Target jobs/mail/provider activity, changed cohort or held state, premature deassignment or duplicate billing authority |

### Scheduler and process lifetime

The process adapter must cover child creation, service restart and scheduler races; pinning the initial PIDs alone cannot provide containment. On the legacy cgroup-v1 path, acceptance requires the effective state to reach `FROZEN`, and every added task forces membership/state revalidation. [The kernel documents that a newly added task can change a frozen group back to `FREEZING`](https://docs.kernel.org/admin-guide/cgroup-v1/freezer-subsystem.html). A cgroup-v2 rehearsal does not accept a cgroup-v1 deployment.

Freezer state is a process gate, not an ingress, privilege or reboot gate. Durable start inhibition and external gates must prevent billing processes from restarting outside the controlled domain. Reboot or changed boot identity invalidates all prior witnesses; startup remains denied until separate recovery readback. No automatic thaw, cron restoration or restart accompanies lease expiry or controller death. If the accepted platform cannot preserve denial through those cases, the process adapter remains blocked.

### PHP, direct ingress and broader containment

Do not execute real callback scripts merely to test routing. Use a version-matched isolated ingress harness with harmless instrumented backends first. Verify exact raw and normalized request matrices, hosts/SNI, default and direct addresses, encoded paths, case/slash variants, internal and named locations, error handling, FastCGI parameters and persistent connections. [nginx permits internal redirection through `error_page`](https://nginx.org/en/docs/http/ngx_http_core_module.html#error_page); a denial at one public path is not proof that its internal branch is closed. A separately reachable [FastCGI upstream](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_pass) also needs coverage.

Unresolved includes and parameterized DSNs may be accepted only as contained unknowns within a proved execution/authority boundary. A dedicated domain must have fixed code/configuration/working-directory scope, no escape through aliases or include paths, controlled ingress and durable restriction of relevant database and external side effects. Shared PHP workers or principals cannot be treated as dedicated by their name or current activity. If selection cannot safely distinguish the billing workload, keep the whole requirement unproved rather than stop the shared service.

The isolated matrix cannot establish live configuration installation, session drain or collateral preservation. Those are later, separately authorized acceptance steps. A read-only source report is never upgraded by replaying it through a newer parser.

### Database authority and secondary locks

Use the prior exact-runtime native findings for the supported adapter's assumptions. Global read-only is not the primary fence. Table locks protect only the accepted object list and vanish when their session ends; DDL/new objects and privileged actors need independent primary exclusion. [MariaDB documents that starting a transaction releases locks acquired with `LOCK TABLES`](https://mariadb.com/docs/server/reference/sql-statements/transactions/lock-tables). The keeper must not start a transaction or run the snapshot.

Record and verify SQL account/host precedence, effective principals, existing sessions, socket/TCP paths, events, routines, grants and administrative writers. Static credential candidates and negative grant flags cannot establish those identities. Neither this document nor the first increment issues GRANT/REVOKE, kills sessions or changes global server settings. An operational adapter must establish exact durable exclusions, prove they cannot be bypassed through the controlled entry points, and preserve approved unrelated database access.

Same-host privileged administrators can override kernel, service and database gates. Do not claim that a local gate file fences them. Distinct privileged actors outside the accepted maintenance authority require an independently controlled exclusion boundary; if that cannot be demonstrated, `source-cli-sql-ddl` remains blocked and no genuine lease is issued. Exclusive maintenance authority is an explicit operational trust boundary, not a claim to withstand malicious host root.

### Callback custody and financial reconciliation

Custody must be installed and accepted before changing callback ingress. Account for requests already delivered to old handlers and provider retries during transition. Unknown in-flight arrivals prevent a frozen financial baseline from being accepted. No raw arrival is discarded merely because it duplicates a logical event.

Accept authentication, success/precheck response, retry and legacy-reference ownership separately for the actual integration. The current generic Authorize.net custody code does not accept WebMoney, Klarna or Wave protocols. Unsupported deliveries remain unacknowledged or receive only an independently accepted failure response. Never guess a provider acknowledgement. Custody receipts prove arrival, not settlement, capture or refund. Reconciliation must enumerate outcomes against the frozen population and explain every material source change before package eligibility. Replay and payment-state changes require separate authority and idempotency acceptance.

## Establishment, failure and recovery

The design defines required ordering, not a runnable live procedure:

1. Review and accept exact plan, scope, rollback and provider custody; install no financial authorization implicitly.
2. Establish independently durable ingress/custody and scheduler gates, accounting for active requests/workers.
3. Contain process, CLI, SQL and DDL authority; verify unrelated workload preservation and absence of escapes.
4. Establish target exclusion and the secondary keeper; verify all seven physical boundaries and reconcile pending events.
5. Capture a fresh full source package within the original proved fence window. Pin schema/population/financial lineage; independently verify exact target, backup/cold restore and cohort timing.
6. Only under separate financial authority may a genuine signer issue opening leases. Openings remain inactive; deassignment, activation and billing handover are separate accepted decisions.

States are `draft`, `prepared`, `establishing`, `held`, `lost`, `recovery-required` and `released`. There is no direct draft/prepared-to-held transition. Any changed gate, schema, boot, member, custody failure, lost keeper, missing verifier or stale readback ends lease renewal and moves to `lost` or `recovery-required`. Durable gates remain in place. If an actual containment boundary is lost, the custodian must immediately externally fence the opening operator; stopping renewal alone leaves an outstanding lease window and is insufficient. Acceptance must prove detection and external-fencing timing against the native operator transaction boundaries. Existing committed receipts remain authoritative. The opening operator must be proved exited or externally fenced before any deliberate release.

A crash between intent and readback is recovered by inspecting the physical gate and exact generation; journal text alone cannot assert its state. Loss of SSH/controller connectivity does not restore scheduler, ingress, grants or service starts. Independent gate state and custody must survive controller replacement. Inability to verify denial prevents renewal, even if service availability suffers.

Before any opening commit, the separately approved rollback may restore only exact prior accepted bytes/state after operator retirement and preserved custody. If financial application committed or live activity began, the old source cannot simply resume billing: retain receipts and follow a separately reviewed forward recovery or accepted preactivation full restore. Unknown outcome requires reconciliation, not automatic restore. Whole-database rollback after live payments is ineligible.

## Acceptance and implementation boundaries

The implementation plan is written only after review of this spec. Its first independently reviewable increment covers closed plan/witness schemas, references, coverage/unknown-member representation and rejection behavior, using fictional inputs. Every output continues to have `real_execution_ready=false`. Future increments separately implement and natively rehearse adapter families and recovery. They must not smuggle live operations into the planning CLI.

Required tests include duplicate/missing assignments; unsupported adapter or executable input; changed original bytes/boot/clock; forged held state with no gate attempts; unknown broader scope; shared principal/worker; escaped child/restart; existing request/socket; alternate/internal/error ingress; DDL and privileged writer; keeper death/transaction; schema addition; disk-full custody; unauthenticated/conflicting callback; lost verifier; controller kill/reboot; partial establishment; stale generation; unsafe release while operator lives; and changed target/cohort. Each must assert its expected rejection, not an unrelated error or timeout. Unrelated workloads must demonstrably remain functional.

Native rehearsals use independently owned Linux resources, harmless backends, fictional data, outbound isolation and the required exact runtime/kernel interface. Source cgroup-v1 behavior requires an accepted matching v1 environment. Fixtures cannot point at live sockets, real cron/service roots, real grants, source data or customer callbacks. Preserve failures, exact attempted writer results and owned-resource retirement; no skipped case proves acceptance. Keep at least 1 GiB root and 512 MiB RAM reserve.

Independent review, immutable candidate checks, protected-value scanning and tested/published byte equality precede generic tool publication. Private evidence stays root-private on its host and outside Git. Actual deployment acceptance, ownership decisions, real signer/trust installation, provider protocol acceptance, actual-cohort timing and separate handover authority remain outstanding. Completion of this design means a reviewable contract exists; it does not mean enforcement is implemented or migration is ready.
