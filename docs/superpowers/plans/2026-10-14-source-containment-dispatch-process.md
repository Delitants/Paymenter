# Source dispatch and process containment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. The user's preserved native inline, author-only method overrides the subagent recommendation.

**Goal:** Implement selective dispatch suppression and persistent start/process gates on owned version-matched resources.

**Architecture:** Keep scheduler byte transformation, installed manager semantics and kernel process control separate. All use the shared context and immutable physical readbacks; a failed platform or proprietary-runtime prerequisite leaves the affected family unqualified.

**Tech Stack:** Python 3.10+, standard-library unittest and fixed Linux/native runtime interfaces; source-version-matched disposable resources.

**Spec:** [Scheduler/process design](../specs/2026-10-07-scheduler-process-adapters-design.md) and [source boundary design](../specs/2026-10-07-source-boundary-enforcement-design.md). Depends on Task 1 of [admission/SQL](2026-10-14-source-containment-admission-sql.md).

**Status:** Approved for native inline, author-only implementation. A portable preflight subset is implemented; all three physical/native tasks below remain incomplete. No matching owned runtime is qualified and no live enforcement has occurred. The native step checkboxes remain unchecked intentionally.

## Implemented preflight subset and remaining gate

`source_scheduler_adapter.py` provides the exact whole-entry byte projection, reviewed scope checks and read-only multifile drift classification. Its narrow literal syntax subset rejects shell/continuation forms; it does not validate Cronie calendar semantics or prove wrapper ownership. `source_start_adapter.py` inventories closed candidate artifact/launcher obligations. `source_process_adapter.py` compares closed candidate boot/task/thread/ancestry/group identities and effective-state claims. All are pure planning helpers; caller-supplied facts, hashes, review flags and FROZEN strings cannot produce physical acceptance. Every physical API raises a specific runtime-unqualified error before resource access; these refusal endpoints are not implementations of physical control or recovery.

Portable unit tests exercise only these helpers and refusals. They are deliberately distinct from the native cases below, which have not run. No skipped or simulated case qualifies the plan. No scheduler replacements/fsync/reload, vendor suspend/resume, launch guard, task migration/freezer, independent observer, crash/reboot recovery or exact physical release is implemented or accepted by this subset. The existing owned-context factory still allocates SQL only. The fixture evaluator and application opening-proof contracts remain unchanged.

Next prerequisite: an independently owned disposable environment with the privately pinned source kernel, Cronie/service-manager packages, manager/wrapper/config/library bytes and an authorized fictional vendor installation/license. Provider egress must be denied, ownership and resource budgets accepted, and no real data, socket or workload attached. Environment acquisition/provisioning requires separate scope; an existing customer VM must not be reused. A modern target cgroup-v2 environment cannot qualify this work. Retained source observations are historical until refreshed under separately accepted scope.

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

- A wrapper name or no-selector entry hides shared work: reject suppression (Task 1).
- Concurrent spool edit or unsupported reload semantics: retain partial denial and never overwrite the edit (Task 1).
- Per-panel inhibition has untested exit/licensing/provider effects: no manager command is admitted (Task 2).
- PID reuse, multithreaded child race or FREEZING is mistaken for FROZEN: reject held state (Task 3).
- Reboot/controller death bypasses a local guard: startup denial must exist before dispatch resumes (Tasks 2–3).

---

## File structure and prerequisites

Create `source_scheduler_adapter.py`, `source_start_adapter.py` and `source_process_adapter.py` under `tools/opening-controls/`, with matching `tests/test_*.py`. No actual source paths or manager versions are compiled into the fork. Pin the exact Cronie, service manager, wrapper, manager runtime and cgroup-v1 kernel interfaces in private manifests.

Native acceptance requires an independently owned matching Linux environment. Existing target cgroup-v2 SQL QA cannot qualify source cgroup-v1 or proprietary mgrctl behavior. Preparing/provisioning an additional host or obtaining a manager license is outside this plan's authorization. If none is available, retain the exact missing dependency and complete unaffected unit work; do not replace the acceptance with a generic shell fixture.

### Task 1: Exact scheduler transformation and noticed generation

**Files:**
- Create: `tools/opening-controls/source_scheduler_adapter.py`
- Test: `tools/opening-controls/tests/test_source_scheduler_adapter.py`

**Interfaces:**
- Consumes: `ControlContext`, `EvidenceRef`, `AdapterReadback`; exact original bytes and reviewed whole-entry spans with entry hashes.
- Produces: `suppress_entries(original: bytes, entries: tuple[tuple[int, int, str], ...]) -> bytes`; `establish_scheduler(ctx: OwnedContext, originals: tuple[EvidenceRef, ...]) -> AdapterReadback`; `recover_scheduler(ctx: OwnedContext) -> AdapterReadback`; `release_scheduler(ctx: OwnedContext, retirement: EvidenceRef) -> AdapterReadback`.

- [ ] **Step 1: Write the failing tests.** Add `test_shared_and_unresolved_wrapper_block`, `test_unrelated_bytes_newlines_environment_preserved`, `test_concurrent_edit_not_overwritten`, `test_partial_multifile_change_retains_denial`, `test_native_cronie_noticed_generation_and_unrelated_dispatch`. Verify selected harmless dispatch is explicitly denied and unrelated dispatch succeeds under the pinned native scheduler.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert suppress_entries(original, ()) == original
assert transformed[:start] == original[:start]
assert transformed[start:] == original[end:]  # one accepted whole-entry deletion
```

- [ ] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_scheduler_adapter.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [ ] **Step 3: Implement the interface.** Reject overlap, changed original hash, ambiguous shell/continuation or shared entry. Retain/fsync originals and identity/security metadata; same-directory replacement is atomic per file only. Exclusive maintenance authority is a prerequisite, not an advisory-lock assumption. Physical acceptance observes the pinned scheduler consuming the new generation, pending jobs are handed to process accounting. Missing DNSSEC targets and unrelated entries remain untouched. Recovery never restores a competing edit.
- [ ] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [ ] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: add selective scheduler adapter`. Record author-only review; do not claim independent review.

### Task 2: Version-qualified per-panel and persistent start inhibition

**Files:**
- Create: `tools/opening-controls/source_start_adapter.py`
- Test: `tools/opening-controls/tests/test_source_start_adapter.py`

**Interfaces:**
- Consumes: Task 1 shared types and independent scheduler/ingress preconditions; private installed-runtime allowlist.
- Produces: `qualify_panel_runtime(ctx: OwnedContext, runtime: EvidenceRef) -> AdapterReadback`; `establish_start(ctx: OwnedContext) -> AdapterReadback`; `read_start(ctx: OwnedContext) -> AdapterReadback`; `release_start(ctx: OwnedContext, retirement: EvidenceRef) -> AdapterReadback`.

- [ ] **Step 1: Write the failing tests.** Add `test_unqualified_manager_never_executed`, `test_panel_stop_side_effects_and_unrelated_panel_preserved`, `test_direct_cli_alias_manual_and_boot_launch_denied`, `test_missing_guard_denies`, `test_reboot_guard_precedes_scheduler`. Require harmless instrumented writes/provider attempts remain zero; unrelated panel/launcher stays functional.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert result.state == "unproved"  # test_unqualified_manager_never_executed
assert executed_manager_commands == []
```

- [ ] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_start_adapter.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [ ] **Step 3: Implement the interface.** Finite runtime adapter registration binds exact reviewed binary/config/library hashes and installed behavior evidence. Candidate vendor suspend/resume operations are not allowed merely because documentation names them. Qualify only in an accepted owned installed-runtime environment with provider egress denied and fictional state. Unknown runtime, missing service, shared core/ihttpd or unproved manual/direct launcher returns unproved before execution. Persist selected start denial independently of the controller, covering all accepted launchers and recovery boot order. Do not stop shared core/web/database/cron services. Release requires exact original state and operator retirement.
- [ ] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [ ] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: qualify scoped manager start control`. Record author-only review; do not claim independent review.

### Task 3: Complete cgroup-v1 domain and fail-held recovery

**Files:**
- Create: `tools/opening-controls/source_process_adapter.py`
- Test: `tools/opening-controls/tests/test_source_process_adapter.py`

**Interfaces:**
- Consumes: Accepted native start/scheduler/ingress dependency readbacks; source-version-matched cgroup-v1 owned environment and complete task domain.
- Produces: `establish_process(ctx: OwnedContext) -> AdapterReadback`; `read_process(ctx: OwnedContext) -> AdapterReadback`; `recover_process(ctx: OwnedContext) -> AdapterReadback`; `release_process(ctx: OwnedContext, retirement: EvidenceRef) -> AdapterReadback`.

- [ ] **Step 1: Write the failing tests.** Add actual native `test_thread_pid_reuse_and_escaped_child_block`, `test_freezing_not_held`, `test_added_task_invalidates_readback`, `test_controller_death_keeps_start_denial`, `test_reboot_old_witness_rejected`, `test_unrelated_work_progresses`. Pin boot/PID/TID/start/executable/credentials/ancestry/cgroup before and after each step; assert complete effective FROZEN state and membership.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert result.state != "held"  # test_freezing_not_held
assert effective_freezer_state == "FREEZING"
```

- [ ] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_process_adapter.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [ ] **Step 3: Implement the interface.** Create only an owned non-root cgroup; retain original memberships. Shared/unattributed processes block rather than being chased/frozen. Check complete descendant and thread coverage, nested groups and identity at every migration step. No sleeping snapshot or SIGSTOP substitutes for freezer evidence. Controller death/expiry leaves durable dispatch/start gates closed; reboot invalidates witnesses and recovery must observe fresh boot and guard-before-dispatch. Do not auto-thaw. Record exact kernel/service-manager semantics and retirement of all owned tasks/groups.
- [ ] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [ ] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: add source-version process containment`. Record author-only review; do not claim independent review.
