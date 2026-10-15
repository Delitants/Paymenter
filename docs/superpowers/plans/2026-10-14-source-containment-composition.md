# Source containment composition and readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. The user's preserved native inline, author-only method overrides the subagent recommendation.

**Goal:** Combine qualified controls into an owned failure/recovery rehearsal and produce a blocked live-maintenance proposal until every real prerequisite is accepted.

**Architecture:** Independent observation evaluates exact control generations and preservation evidence, then exposes eligibility to the existing v2 capture/readiness protocol without issuing keys or leases. Target exclusion and exact cohort ownership remain distinct capabilities.

**Tech Stack:** Python 3.10+, standard-library unittest and fixed Linux/native runtime interfaces; source-version-matched disposable resources.

**Spec:** [Source boundary design](../specs/2026-10-07-source-boundary-enforcement-design.md), [capture/opening design](../specs/2026-10-14-source-capture-opening-readiness-design.md), and the approved private v2 runbook. Depends on the three sibling containment plans.

**Status:** Written implementation/rehearsal plan, awaiting review. No implementation or live enforcement has occurred under this plan.

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

- Nine operational control IDs are incompletely mapped to seven protocol requirements (Task 1).
- Controller loss, lease expiry or lost keeper silently releases an independently held gate (Task 2).
- Target refresh writer stays open during complete-baseline capture or cohort membership changes (Task 1).
- Custodian loss stops renewal but leaves an outstanding opening operator able to commit (Task 2).
- Actual-size history/custody/restore footprint or phase duration exceeds admission despite tiny fixture success (Task 3).

---

## File structure, exact mapping and dependency order

Create `containment_coordinator.py`, `target_writer_adapter.py`, `containment_qualification.py`, and corresponding unittest files. Update only the toolkit README for entry points and stage limits. No production apply CLI or native proof parser modification is introduced.

| Private operational control | Protocol requirement | Required implementation |
| --- | --- | --- |
| source-http | source-ingress | ingress adapter + raw custody |
| source-direct-manager | source-ingress and source-cli-sql-ddl | ingress + start + SQL |
| source-cron | source-scheduler | scheduler + start |
| source-process | source-process | complete v1 domain + start |
| source-sql | source-cli-sql-ddl | complete grants + session admission |
| secondary keeper (independent prerequisite) | secondary-lock-keeper | distinct lock-only connection |
| source-poller | source-scheduler and provider-custody-reconciliation | exact Wave pause owner + reconciliation |
| source-notifications | provider-custody-reconciliation | accepted protocols + raw durable arrivals |
| target-writers | target-and-cohort-deassignment | held writer isolation + refresh closure |
| legacy-billing-ownership | target-and-cohort-deassignment | cohort-only deassignment eligibility; later handover authority |

The nine operational IDs are retained, with a separate keeper dependency, not collapsed into nine fictional file flags. Every observed member is assigned exactly once within its accepted control coverage, while cross-control dependencies may refer to it. Missing/unknown/changed member or unsupported protocol blocks the corresponding requirement and aggregate acceptance.

Order: qualify custody/ingress, then dispatch/start, drain accepted in-flight work, then process/SQL and target exclusion, then secondary keeper and complete reconciliation. No capture epoch begins before all actual required gates pass. During preparation admit only the named refresh capability; close it before full baseline/cold restore and first readiness. Later cohort-only deassignment is never done to obtain a capture lease. Actual source billing ownership remains unchanged during all owned rehearsal and planning.

### Task 1: Independent coverage and target capability closure

**Files:**
- Create: `tools/opening-controls/containment_coordinator.py`
- Create: `tools/opening-controls/target_writer_adapter.py`
- Test: `tools/opening-controls/tests/test_containment_coordinator.py`
- Test: `tools/opening-controls/tests/test_target_writer_adapter.py`

**Interfaces:**
- Consumes: Shared `ControlContext`, `Admission`, `AdapterReadback`; exact native family results from sibling plans; target runtime/census/cohort manifests.
- Produces: `evaluate_controls(context: ControlContext, readbacks: tuple[AdapterReadback, ...], observer_boot_id: str, now_ns: int) -> Admission`; `establish_target(ctx: OwnedContext) -> AdapterReadback`; `admit_refresh(ctx: OwnedContext, capability: EvidenceRef) -> AdapterReadback`; `close_refresh(ctx: OwnedContext) -> AdapterReadback`; `check_cohort_deassignment(cohort: EvidenceRef, before: EvidenceRef, after: EvidenceRef) -> Admission`.

- [ ] **Step 1: Write the failing tests.** Add `test_all_nine_controls_and_keeper_required`, `test_mixed_generation_unknown_member_rejected`, `test_stale_or_future_or_cross_boot_readback_rejected`, `test_target_public_worker_queue_cron_mail_denied`, `test_only_named_refresh_then_capability_closed`, `test_complete_baseline_no_excluded_tables`, `test_cohort_change_or_lifecycle_side_effect_rejected`. Assert all holds retained and no cancellation/suspend/provider/VM action.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert result.real_execution_ready is False
assert result.blockers  # one missing operational control or keeper dependency
```

- [ ] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_target_writer_adapter.py -v` and then `python3 -m unittest discover -s tools/opening-controls/tests -p test_containment_coordinator.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [ ] **Step 3: Implement the interface.** Independent evaluator uses the observer boot/monotonic domain, requires current readback age from zero through 60 seconds and exact control generation, and consumes actual readbacks and lineage; it never accepts pass flags or fixture permits as physical proof. Target adapter uses only a new owned fictional database/runtime; preserve unrelated public/original-QA fingerprints. Check grant/socket/CLI/manual writer and pending queue coverage, not just web route status. Native cohort-deassignment qualification is diff-only under explicitly fictional data and must alter billing ownership for precisely approved membership with no service lifecycle effects. It does not expose a live deassign command.
- [ ] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [ ] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: compose source and target containment eligibility`. Record author-only review; do not claim independent review.

### Task 2: Actual composed loss and original-history recovery

**Files:**
- Create: `tools/opening-controls/containment_qualification.py`
- Test: `tools/opening-controls/tests/test_containment_qualification.py`

**Interfaces:**
- Consumes: Task 1 and qualified owned family factories; unchanged v2 capture/readiness implementation and fictional test keys only.
- Produces: `run_composed_qualification(ctx: OwnedContext, cases: tuple[str, ...]) -> EvidenceRef`; fixed case IDs are `controller-loss`, `keeper-loss`, `custody-full`, `observer-loss`, `reboot`, `partial-establish`, `partial-publish`, `concurrent-change`, `restore-before-commit`, `unknown-commit`, `late-arrival`, `operator-fence`.

- [ ] **Step 1: Write the failing tests.** Add `test_each_fixed_case_has_actual_boundary_attempts`, `test_no_automatic_release_on_loss`, `test_external_operator_fence_precedes_release`, `test_original_history_gap_cannot_be_rebased`, `test_unknown_commit_keeps_gates_closed`, `test_scoped_restore_preserves_unrelated_deltas`. Each native case must attempt protected work and unrelated work, retain original intent/readback and verify actual owned retirement.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert gate_state_after_controller_loss == "held"
assert source_writer_attempt == "denied"
assert unrelated_writer_attempt == "accepted"
```

- [ ] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_containment_qualification.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [ ] **Step 3: Implement the interface.** Supervise fixed native actions from outside the expendable controller. Bind actual original histories, monotonic/wall clocks and complete resource identities. On boundary loss independently stop/fence the owned opening operator before deliberate release; renewal stoppage alone is insufficient. Measure detection/fencing against actual transaction boundaries and existing protocol limits without inventing a new grace window. Before any commit only exact preimage restoration is eligible; after unknown/committed outcomes keep original receipt lineage and require explicit recovery/forward reconciliation. No auto rebaseline, whole-database rollback after live activity, real keys or trust installation.
- [ ] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [ ] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: qualify composed containment failure recovery`. Record author-only review; do not claim independent review.

### Task 3: Measured full-size budget and concrete live proposal

**Files:**
- Modify: `tools/opening-controls/containment_qualification.py`
- Modify: `tools/opening-controls/README.md`
- Test: `tools/opening-controls/tests/test_containment_qualification.py`

**Interfaces:**
- Consumes: All retained family/composed native originals; exact actual-cohort size/timing observations under separately authorized private data custody; current storage identities and independent retention acknowledgments.
- Produces: `evaluate_window_budget(observations: tuple[EvidenceRef, ...], limits: EvidenceRef) -> Admission`; `build_maintenance_proposal(context: ControlContext, controls: tuple[AdapterReadback, ...], budget: EvidenceRef) -> bytes` (non-executable private review document).

- [ ] **Step 1: Write the failing tests.** Add `test_full_history_rounding_and_coexistence_budget`, `test_growth_custody_cold_restore_peaks_included`, `test_fixture_size_cannot_accept_actual_cohort`, `test_changed_runtime_or_missing_protocol_blocks_proposal`, `test_no_financial_or_enforcement_authority_in_proposal`. Assert physical bytes, RAM/PIDs/swap/duration and recovery-reserve requirements; unknown capacity or retention is an explicit blocker.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert result.real_execution_ready is False
assert result.blockers  # fixture-only measurements cannot qualify the actual cohort
```

- [ ] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_containment_qualification.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [ ] **Step 3: Implement the interface.** Measure complete cohort capture, credential/OTP/support/attachment/history data, SQL originals, current+candidate runtimes, cold restore, all ten-second proof history generations, custody growth and retained failures. No customer-data staging or full-size native run is authorized by this planning approval: produce requirements and stop that part until exact data/resource scope is accepted. Prefer existing capacity or separately approved project-scoped duplicate reclaim, never unique history deletion. The resulting proposal names exact private adapter/preimage hashes, all affected reconnects/admin narrowing, maximum measured window, source-root/custodian authority, recovery and restoration order, installed release and expected deployment effects. All unresolved real gates remain listed. Publish only generic code/docs after exact privacy and tested/published byte checks; keep private originals independently verified.
- [ ] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [ ] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `docs: record measured containment maintenance prerequisites`. Record author-only review; do not claim independent review.

## Whole-package acceptance and handoff

Run the complete external toolkit on accepted owned Linux, with zero skips for claimed native families. Unsupported source kernel/vendor runtime/protocols are reported unqualified, not counted as passed. Preserve previous 179-test SQL toolkit acceptance under its original hashes; newer whole-package acceptance needs its own originals. Pure PHP proof parsing must reject every new admission, readback and qualification document for both real fence purposes. No changes to native proof purpose/trust/financial schemas are permitted.

Review exact immutable diff, closed input/interface consistency, physical failure/restore evidence, all five review-focus classes per plan, privacy and published original bytes. Record author-only review. Do not install code, release holds or merge the PR as part of implementation completion.

Only after this package, exact platform/provider qualification, separately scoped full-cohort/storage rehearsal and a concrete live proposal can live containment be considered. Held-only release installation, real custodian signing/trust, capture/final refresh, human inactive-opening approval, cohort billing deassignment, activation and final handover each retain their existing distinct acceptance/authority. The deferred offline timing redesign and WebMoney certificate refunds remain untouched.

Please review the four saved plans before implementation. The native inline method remains selected.
