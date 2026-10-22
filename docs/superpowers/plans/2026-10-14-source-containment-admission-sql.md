# Source containment admission and SQL adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. The user's preserved native inline, author-only method overrides the subagent recommendation.

**Goal:** Build a closed real-source admission report and a separately owned SQL adapter rehearsal without touching live grants.

**Architecture:** Separate observed facts, accepted authority and actual physical evidence. Reuse the qualified pure privilege primitives, but implement a distinct full-census compiler and owned transport for the source graph; never promote the fixed fictional seed result. Shared typed context is consumed by the three dependent plans.

**Tech Stack:** Python 3.10+, standard-library unittest and fixed Linux/native runtime interfaces; source-version-matched disposable resources.

**Spec:** [Source boundary design](../specs/2026-10-07-source-boundary-enforcement-design.md), [SQL feasibility design](../specs/2026-10-14-sql-privilege-feasibility-design.md). The approved private physical-containment proposal supplies exact host bindings outside Git.

**Status:** All three tasks are implemented. Seven owned native cases and the complete 205-test toolkit passed with zero skips. Actual source enforcement, deployment, financial opening and handover remain unaccepted.

## Global Constraints

- Python 3.10+ and standard library; no application bootstrap, extension route, scheduler, real signer or trust installation.
- Native inline, author-only execution is already selected. Do not dispatch subagents or ask for the method again.
- Preserve BILLmanager billing authority, every migration hold and the separately elected exclusion. No financial opening, activation, collection, mail, provider mutation, VM action or handover.
- All qualification documents have `real_execution_ready=false`. Owned-native qualification, deployed control acceptance and financial authority are separate records.
- Only independently owned fictional resources may be mutated during implementation. Real metadata remains observation; its age and unresolved findings remain visible. No production adapter enters the existing fixture evaluator's allowlist.
- Fixed typed operations only: no profile-provided shell, SQL, import path or executable snippet. Never adapt the fictional SQL driver by supplying a real socket.
- Ordinary root-owned 0600 inputs under root-owned 0700 directories; reject symlink/hardlink substitution and changed pre/post-read identities. Bound each document to 4 MiB, the complete graph to 32 MiB, 10,000 entries and depth eight.
- Root reserve is at least 1 GiB + 128 MiB; retain at least 512 MiB available RAM after admitted budgets. Existing backups, failed evidence and immutable history are retained. Full migration coexistence needs its own measured acceptance.
- Controller, keeper, signer, network or clock loss does not restore gates. Unknown outcomes require readback and reconciliation, never automatic thaw, cron restore, SQL restore or replay.
- No hostile-root guarantee. Every privileged automated consumer is attributed or blocked; exclusive human maintenance authority is explicit and separately accepted.

## Review Focus

- Old or mixed observation, omitted dependency or substituted file: admission remains blocked (Task 1).
- Trusted-root exception hides an automatic root writer or proxy reachability: reject the graph (Task 2).
- Business preservation relies on global administration or new arbitrary schemas: reject, rather than silently narrow the contract (Task 2).
- Cached authenticated session or restart admits a writer before grants are verified: no held readback (Task 3).
- Crash after native completion but before acknowledgment: preserve actual rows and recovery-required, never predict native metadata (Task 3).

---

## File structure and shared contracts

Create `tools/opening-controls/containment_schema.py` for immutable dataclasses and closed documents, `containment_admission.py` for pure admission, `source_sql_projection.py` for full source graph compilation, `containment_runtime.py` for bounded owned-resource factories, and `source_sql_adapter.py` for owned-only physical operations. Keep existing `sql_fence_*`, fictional boundary schemas/allowlists and native PHP proof schemas unchanged.

Shared immutable types (defined in Task 1):

```python
EvidenceRef(path: str, sha256: str, size: int)
ControlContext(session_id: str, freeze_id: str, generation: int,
               source_boot_id: str, profile_sha256: str,
               adapter_manifest_sha256: str, authority_ref: EvidenceRef,
               budget_ref: EvidenceRef)
Admission(blockers: tuple[str, ...], observation_refs: tuple[EvidenceRef, ...],
          real_execution_ready: bool = False)
AdapterReadback(control_id: str, state: str, generation: int,
                context_sha256: str, observer_ref: EvidenceRef,
                observer_boot_id: str, start_ns: int, end_ns: int,
                originals: tuple[EvidenceRef, ...],
                attempts: tuple[EvidenceRef, ...], failures: tuple[str, ...])
```

`state` is exactly `unproved`, `held`, `lost` or `recovery-required`; every held record needs successful physical-denial and unrelated-preservation attempts, fresh generation/boot and immutable readback references. It is not a `source-control-boundary-witness` or native lease. `OwnedContext` is factory-issued and nonserializable; callers cannot construct it from a profile, external socket or path. Each factory must prove resource ownership, runtime and budget, independent of these dataclasses. Public APIs accept these types, never arbitrary operations. Canonical serialization uses UTF-8 sorted-key compact JSON with exact dataclass field names, decimal-string monotonic values, no floats or implicit coercions, and SHA-256 over original bytes. Evidence references always identify original bytes. Observer references bind boot/PID/start/executable/UID; readback intervals use that observer's monotonic clock, never cross-host subtraction. A readback is current only in the same observer boot, with a nonnegative age no greater than 60 seconds; changed control generations invalidate it immediately.

The admission document has exactly `schema_version=1`, `purpose=source-containment-admission`, `context`, `observation_refs`, `blockers`, `real_execution_ready=false`. Context contains exactly the eight `ControlContext` fields above. Reference limits apply to the entire reachable graph, including authority, originals and budget. Duplicate JSON keys, NaN, unknown fields, nonboolean readiness, forged passed flags and stale/mixed boot generations reject parsing.

### Task 1: Closed admission and exact identity binding

**Files:**
- Create: `tools/opening-controls/containment_schema.py`
- Create: `tools/opening-controls/containment_admission.py`
- Create: `tools/opening-controls/containment_runtime.py`
- Test: `tools/opening-controls/tests/test_containment_admission.py`

**Interfaces:**
- Consumes: Original source observation/registry and the approved private authority/budget references; existing bounded reference-reader policy.
- Produces: `parse_admission(raw: bytes) -> Admission`; `evaluate_admission(context: ControlContext, observations: tuple[EvidenceRef, ...], accepted: tuple[EvidenceRef, ...]) -> Admission`; the four shared dataclasses; factory-issued `OwnedContext`; `create_owned_context(kind: str, context: ControlContext, runtime: EvidenceRef) -> OwnedContext` and `retire_owned_context(ctx: OwnedContext) -> EvidenceRef` in `containment_runtime.py`. `kind` is exactly sql, scheduler, start, process, ingress, target or composed; each kind has a finite compiled factory, with no external resource paths admitted from a profile.

- [x] **Step 1: Write the failing tests.** Add `test_mixed_boot_and_stale_capture_block`, `test_complete_graph_budget_and_substitution`, `test_unknown_writer_and_missing_dependency_block`, `test_readonly_observation_never_accepts_controls`, `test_no_command_or_ready_override`, `test_owned_factory_rejects_external_socket_root_and_forged_token`, and `test_owned_retirement_checks_identity`. Assert exact blocker codes, false readiness and no write/transport call.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert result.real_execution_ready is False
assert "unknown-writer" in result.blockers  # test_unknown_writer_and_missing_dependency_block
```

- [x] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_containment_admission.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [x] **Step 3: Implement the interface.** Validate complete object assignment against original observation hashes, including referenced wrapper/library/configuration dependencies. Do not treat lexical cron selectors, process names or metadata age as semantics. Authority must list all privileged automated actors and separately trusted manual actors; missing attribution blocks. Storage reports retain physical allocation and coexistence gaps. The admission evaluator has no SQL connection, manager command, request or provider query. The separate owned factory verifies platform/runtime, budgets and all newly allocated object identities, and registers exact retirement obligations before allocating; unsupported platform or resource ownership denies admission. Its mutating smoke test uses disposable directories/tasks only, never a real socket/service/cron root. SQL/manager runtime launch remains in its owning later task, after that task's checks. No reuse of the source host boot as a fictional owned boot is permitted.
- [x] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [x] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: add closed containment admission`. Record author-only review; do not claim independent review.

### Task 2: Full source grant graph and exact contraction

**Files:**
- Create: `tools/opening-controls/source_sql_projection.py`
- Test: `tools/opening-controls/tests/test_source_sql_projection.py`

**Interfaces:**
- Consumes: Task 1 types; existing `GrantModel`, `BusinessRequirement`, `GrantOperation`, `Projection` and pure identifier/database-pattern escaping.
- Produces: `compile_source_projection(census: bytes, context: ControlContext, requirements: tuple[BusinessRequirement, ...]) -> Projection`. Closed census includes exact seven grant-table originals, every schema/object, connection paths and authenticated actor attribution; authentication bytes remain private.

- [x] **Step 1: Write the failing tests.** Add `test_global_and_column_escape_removed`, `test_literal_pattern_collision`, `test_proxy_reaches_writer_rejected`, `test_admin_proxy_requires_exclusive_attribution`, `test_shared_global_admin_requirement_rejected`, `test_omitted_schema_and_definer_rejected`, `test_auth_tls_resource_metadata_unchanged`. Assert protected denial and explicit business CRUD/DDL preservation, unchanged auth/resource/TLS and complete inverse grant operations.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert result.before == model
assert semantic_hash(restored) == semantic_hash(model)  # exact inverse, not business-data rollback
```

- [x] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_sql_projection.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [x] **Step 3: Implement the interface.** Use a separately closed full-census parser, not the fictional plan parser. Support only source-runtime-qualified grant row shapes/vocabulary. A proxy row wholly inside accepted exclusive manual administration may remain unchanged only if the complete proxy/host-precedence graph proves no automated actor can reach it; every other proxy shape rejects compilation. Add native source-shaped proxy cases in Task 3 before enabling that shape. Do not discard proxy rows to make the old compiler pass. Restrict role expansion to the exact accepted existing unrelated schemas. Admin escape requirements, unknown privileges, definers and ambiguous host precedence block. Preserve before/held/restore metadata without emitting SQL supplied by a profile.
- [x] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [x] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: compile scoped source grant contraction`. Record author-only review; do not claim independent review.

### Task 3: Owned SQL session barrier, keeper and recovery

**Files:**
- Create: `tools/opening-controls/source_sql_adapter.py`
- Test: `tools/opening-controls/tests/test_source_sql_adapter.py`

**Interfaces:**
- Consumes: Tasks 1–2; source-matched runtime archive/manifest; existing owned supervisor and original grant metadata techniques.
- Produces: `establish_sql(ctx: OwnedContext, projection: Projection) -> AdapterReadback`; `read_sql(ctx: OwnedContext) -> AdapterReadback`; `recover_sql(ctx: OwnedContext) -> AdapterReadback`; `release_sql(ctx: OwnedContext, retirement: EvidenceRef) -> AdapterReadback`. Control ID is `source-sql`; keeper has separate control ID `secondary-lock-keeper`.

- [x] **Step 1: Write the failing tests.** Add actual native `test_source_shaped_proxy_and_precedence`, `test_cached_session_requires_owned_idle_reconnect`, `test_active_or_unowned_session_blocks_without_kill`, `test_seven_grant_tables_check_rows_before_readmission`, `test_keeper_loss_primary_denial_persists`, `test_partial_grant_restore_preserves_other_schema_deltas`, `test_restart_and_unacknowledged_operation_recovery`. Attempt DML/DDL/new object and allowed unrelated MyISAM/InnoDB work; generic 1045 is not privilege-denial acceptance.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert result.state == "recovery-required"  # completed but unacknowledged native grant operation
assert result.generation == context.generation
```

- [x] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_sql_adapter.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [x] **Step 3: Implement the interface.** Create new datadir/socket only through an owned factory. Keep full physical grant originals separately from semantic Projection. Never change authentication or global read_only to stand in for scoped denial. Journal/fsync intent before each fixed grant operation and native rows afterward. Deny readmission until actual grants, all seven CHECK TABLE status rows and current sessions pass. Drain only owned idle completed sessions, never KILL active customer/unrelated work. Separate keeper issues only complete-schema READ locks and no transaction or snapshot. Restart stays behind independent admission denial. Recovery reads actual state and leaves ambiguous prefixes recovery-required; restore is grant-only, exact-precondition and operator-retirement gated. On root Linux run under outbound isolation; on unsupported runtime return an explicit unqualified result, never a skipped acceptance.
- [x] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [x] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: rehearse durable source SQL control`. Record author-only review; do not claim independent review.

## Completion and dependent work

Require admission rejection tests plus actual source-shaped owned SQL denial/restore results. Preserve the original fixed SQL feasibility evidence unchanged. This does not accept real source credentials, effective principals, privileged maintenance authority or deployment.

Continue with [dispatch/process](2026-10-14-source-containment-dispatch-process.md), then [ingress/custody](2026-10-14-source-containment-ingress-custody.md), then [composition/readiness](2026-10-14-source-containment-composition.md). Each is independently testable; their actual platform/protocol prerequisites are blockers, not fictional substitutions.
