# Owned SQL Privilege Feasibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with the preserved native inline method. Steps use checkbox (`- [ ]`) syntax. Implement/review inline within the established no-subagent boundary and report author-only review accurately; do not ask for an execution-method choice again.

**Goal:** Prove or reject durable protected-schema privilege contraction and exact recovery using fictional data on an independently owned MariaDB 5.5.68 runtime.

**Architecture:** Separate closed private contracts/reference loading, pure grant projection, fixed native execution and independently supervised case/recovery orchestration. Reuse runtime validation/private-reference primitives without changing existing source-boundary purposes. Produce only fictional feasibility evidence with false production readiness.

**Tech Stack:** Python 3.10+ standard library; Linux root namespaces, tmpfs and newly owned cgroup-v2 memory domain; explicitly supplied 5.5.68 loader/server/client/mysqladmin/bootstrap archive; native PHP only for pure proof-schema rejection.

**Spec:** [Owned MariaDB privilege-fence feasibility](../specs/2026-10-14-sql-privilege-feasibility-design.md), the generic SQL-only extraction of the approved private operational proposal. No private operational identities belong in this plan.

## Global Constraints

- No live adapter, source transport, existing socket/datadir, real credentials/customer data, provider call, runtime/trust deployment, opening, activation, hold release or handover. Preserve source billing authority and every existing hold/exclusion/financial policy.
- No VM, new host, package download, app bootstrap or deferred timing/scheduler implementation. Use only the separately accepted owned Linux QA stage; do not reuse a retired launcher.
- JSON: 4 MiB/document, 32 MiB unique combined bytes, 10,000 entries, eight levels; ordinary root:root 0600 files in anchored 0700 private directories. Duplicate keys, floats, unknown fields/purposes and unsafe/replaced links deny.
- Binary archive: existing `lock_runtime.validate_archive` separate 256 MiB packed/unpacked contract. Do not route it through the JSON reader.
- Root recovery reserve 1 GiB + 128 MiB after at most 16 MiB retained evidence; tmpfs cap 256 MiB; process allowance 256 MiB; 512 MiB free RAM/MemAvailable after budgets. New QA-only cgroup `memory.max=536870912`, `memory.swap.max=0`, `pids.max=64`; no ancestor/existing cgroup changes.
- Preserve exact original grant/account metadata, all protected schema/data and accepted unrelated deltas. Never use whole-database restore to undo grant changes. Trusted fixture administration is not resistance to host root.
- Every result has `real_execution_ready=false`. Require all ten native cases, exact expected effects, complete originals and owned retirement; zero skips. Mock/unit acceptance is separate from native acceptance.
- Generic changes belong in the fork; private inputs/runtime/originals/credentials/evidence stay outside Git. Do not weaken opening parsers, financial deltas, source ownership or preservation to make a case pass.

## Review Focus

1. An old or unowned authenticated session can outlive privilege changes; complete admission must remain denied without killing active/shared work (Tasks 3–5).
2. Global/proxy/grant-table/credential/definer escape and host precedence can bypass apparently narrow grants; deny unknown graphs before mutation (Tasks 1–2, 5).
3. Grant-pattern escaping differs from identifier quoting; protected and neighboring underscore/percent names must not acquire unintended access (Tasks 2, 5).
4. Recovery can erase grant metadata or concurrent unrelated work; restore exact grant/authentication originals while retaining allowed data deltas and denying competing changes (Tasks 3–5).
5. Namespace/worker/cgroup/resource/output failure can masquerade as successful denial or cleanup; physical owned identities and retained acknowledged evidence govern success (Tasks 3–6).

## File structure and shared types

Create `tools/opening-controls/sql_fence_schema.py` for closed records, immutable dataclasses and a focused SQL reference loader; `sql_fence_projection.py` for pure semantic contraction/restore plans; `sql_fence_driver.py` for owned identity, fixed SQL codec, sessions and snapshots; `sql_fence_cases.py` for the ten fixed native scenarios; `sql_fence_harness.py` for resource/isolation supervision, journal/evidence, CLI and retirement. Add matching `tests/test_sql_fence_{schema,projection,driver,cases,harness}.py` plus `tests/sql_fence_fixtures.py`. Modify only `tools/opening-controls/README.md` for as-built invocation/status. Existing production/legacy helpers and schemas remain unchanged.

`sql_fence_schema` owns `SqlFenceError(code: str)`, `Principal(user: str, host: str, role: str)`, `GrantRow(principal: tuple[str,str], level: str, target: tuple[str,...], privileges: tuple[str,...])`, `DefinerObject(schema: str, kind: str, definer: tuple[str,str], definition_sha256: str)`, `GrantModel(schemas: tuple[str,...], principals: tuple[Principal,...], grants: tuple[GrantRow,...], definitions: tuple[DefinerObject,...])`, `BusinessRequirement(schema: str, role: str, privileges: tuple[str,...])`, and `FixturePlan(value: Mapping[str,object], seed: GrantModel, required_business: tuple[BusinessRequirement,...], cases: tuple[str,...])`, and `model_record(model: GrantModel) -> dict`. Dataclasses are frozen; `FixturePlan.value` is recursively frozen mappings/tuples, not mutable input aliases. `model_record` reconstructs fresh JSON-compatible containers; seed/raw-parser dictionaries never alias accepted objects.

Grant levels are `global`, `database`, `table`, `column`, `routine`, `proxy`; each has a closed target shape. Roles are `writer`, `shared_writer`, `collector`, `keeper`, `administrator`. Privileges use explicit 5.5.68 names, never `ALL`, modern roles or DENY. Include every privilege column in the archived stock user/db/grant definitions, including SHOW DATABASES and CREATE TABLESPACE. Native raw grant/authentication row cells and timestamps remain in `SqlSnapshot`, not externally supplied SQL or predicted seed bytes.

All test commands below run from the repository root. Native case invocation uses operator-defined private references; no real host/path defaults are shipped.

### Task 1: Closed fictional input, grant model and private references

**Files:** Create `sql_fence_schema.py`, `tests/sql_fence_fixtures.py`, `tests/test_sql_fence_schema.py` under the paths above.

**Interfaces:** Produce `parse_plan(raw: bytes) -> dict`, `parse_seed(raw: bytes) -> GrantModel`, `model_record(model) -> dict`, and `SqlDocuments(root: Path)` as a context manager with `load(plan_ref: dict) -> FixturePlan` and `verify_unchanged() -> None`, `runtime_manifest_record() -> dict` (fresh detached JSON-compatible copy), and `archive_reference() -> dict`. `CASE_IDS` is defined here from the ten IDs listed in Task 5, so Task 1 fixtures never import a not-yet-created cases module. Reuse `boundary_refs.ReferenceSet` anchored `_read`/final identity verification in a focused subclass; use a SQL-only cache and explicit slot walk, not its boundary-purpose `_visit`. Read each original once; close all inherited descriptors. Validate runtime manifest with `source_schema.validate_runtime_manifest`; archive validation stays with `lock_runtime`.

- [ ] Write `test_closed_plan_and_seed`, `test_rejects_live_targets_commands_credentials_and_unknown_fields`, `test_legacy_principal_length_and_privilege_vocabulary`, `test_normalization_detaches_mutable_inputs`, and `test_original_private_reference_limits_and_replacement`. Assert the exact schema-1 purposes/fields from the spec; names are fictional and <=16 user characters; reject bool-as-int, duplicate JSON keys, float/nonfinite/unknown privileges, extra references, wrong purpose/hash/owner/mode/link/ancestor/replacement and over-bound inputs. Positive schema tests use five fixture roles, not source identities.
```python
self.assertEqual(parse_plan(canonical(plan_record()))['purpose'], 'fictional-sql-privilege-rehearsal')
bad = plan_record(); bad['socket'] = '/fixture/external.sock'
with self.assertRaises(SqlFenceError):
    parse_plan(canonical(bad))
```

- [ ] Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_sql_fence_schema.py -v`. Expected: FAIL because the new module/contracts are absent; retain the actual failure.
- [ ] Implement the interfaces and fixed two-level plan/seed/manifest graph. `runtime_archive` is a checksum-bound binary reference inside the accepted reference root, validated separately before staging. Count unique JSON bytes and all declared array entries, cache repeated references without descriptor leaks, and reverify all pinned originals at authority boundaries. Fixture helper `make_private_plan(root: Path, archive: Path, manifest: Path) -> Path` creates only exclusive fictional input/seed files and references existing operator-selected archive/manifest. Also export fixture helpers `plan_record() -> dict`, `seed_record() -> dict`, `model() -> GrantModel`, `preserved() -> tuple[str,...]`, and `requirements() -> tuple[BusinessRequirement,...]`; every returned value is fresh and contains no native identity.
- [ ] Run the same command. Expected: every schema/reference case PASS with no skips. Native root Linux must also run actual positive filesystem checks; mock ownership is not acceptance.
- [ ] Commit only these files with `test: define closed fictional SQL rehearsal inputs`.

### Task 2: Pure contraction and exact semantic restore projection

**Files:** Create `sql_fence_projection.py`, `tests/test_sql_fence_projection.py`; extend only fixture helpers if required.

**Interfaces:** Consume Task 1 types. Produce frozen `GrantOperation(kind: str, principal: tuple[str,str], target: tuple[str,...], privileges: tuple[str,...])` and `Projection(before: GrantModel, held: GrantModel, establish: tuple[GrantOperation,...], restore: tuple[GrantOperation,...])`. Export `compile_projection(model: GrantModel, protected: str, preserved: tuple[str,...], required: tuple[BusinessRequirement,...]) -> Projection`, `apply_operation(model: GrantModel, op: GrantOperation) -> GrantModel`, `identifier(value: str) -> str`, `database_pattern(value: str) -> str`, and `semantic_hash(model: GrantModel) -> str`.

- [ ] Write tests `test_denies_protected_and_global_escape_capabilities`, `test_preserves_exact_named_business_privileges`, `test_unsafe_proxy_definer_mysql_admin_and_unknown_graph_rejected`, `test_host_pattern_override_and_missing_scope_rejected`, `test_identifier_and_database_pattern_escape_are_distinct`, and `test_restore_model_is_exact_and_idempotent`. Check every intermediate typed operation, not just the final hash. Include table/column overrides, `%`/underscore/escape collisions, CREATE TABLESPACE, FILE, GRANT OPTION, grant-table/credential reads, absent preserved schema and ambiguous account identity. Only the declared administrator may retain broad trusted administration; any proxy touching automated writers or unsupported definer rejects.
```python
projection = compile_projection(model(), 'fixture_source', preserved(), requirements())
state = projection.before
for op in projection.establish: state = apply_operation(state, op)
self.assertEqual(state, projection.held)
for op in projection.restore: state = apply_operation(state, op)
self.assertEqual(state, projection.before)
```

- [ ] Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_sql_fence_projection.py -v`. Expected: FAIL on absent projection interface.
- [ ] Implement deterministic typed operations: materialize required nonprotected business privileges, contract unsafe global/direct privileges, and preserve exact original semantic grant rows for reverse planning. Known initial global escape privileges are contracted; a requirement to retain them, an unsafe proxy/definer relation or an unmodeled escape fails before any operation is returned. Do not emit SQL strings, copy arbitrary global administration into mysql grants, grant on wildcard neighbors or invent new business requirements. No prefix is acknowledged as complete until the full model equals `held`.
- [ ] Run the same command plus Task 1 group. Expected: both complete groups PASS, no skips; `apply_operation` over establish/restore produces the exact declared models without mutating the input objects.
- [ ] Commit with `feat: plan fictional SQL privilege contraction`.

### Task 3: Owned native context, fixed SQL codec and session barriers

**Files:** Create `sql_fence_driver.py`, `tests/test_sql_fence_driver.py`.

**Interfaces:** Consume Task 1–2 types. The supervisor port used here has exact methods `assert_admitted(runtime: dict) -> None`, `spawn(argv: tuple[str,...], label: str, interactive: bool) -> object`, and `set_client_admission(allow: bool, expected_snapshot_sha256: str | None) -> None`; Task 3 tests supply a fake, and Task 4 supplies `OwnedSupervisor`. `spawn` is internal fixed-driver infrastructure, never profile/CLI input. Produce opaque factory-created `OwnedSqlContext`, `AttemptResult(case_id: str, status: int, error_code: int | None, rows: tuple[tuple[str,...],...])`, and `SqlSnapshot(grants: GrantModel, raw_grant_rows: tuple[dict,...], account_nonprivilege_sha256: str, protected_sha256: str, unrelated_sha256: str)`. Export `create_fixture(runtime: dict, supervisor: object) -> OwnedSqlContext`, `snapshot(context) -> SqlSnapshot`, `execute_operation(context, op: GrantOperation) -> SqlSnapshot`, `attempt(context, case_id: str, role: str) -> AttemptResult`, `assert_denial(attempt, expected_codes: frozenset[int]) -> None`, and `drain_barrier(context, expected_sessions: tuple[dict,...]) -> None`. Public `attempt` accepts only declared case IDs/roles, never SQL text or host options.

- [ ] Write `test_context_cannot_be_constructed_from_profile_paths`, `test_actual_server_namespace_socket_and_marker_guard_precedes_sql`, `test_wrong_sql_error_dead_actor_or_missing_object_is_not_denial`, `test_active_unowned_and_cached_sessions_block_admission`, and `test_snapshot_preserves_raw_grant_and_nonprivilege_bytes`. Reject PID/start/boot/namespace/runtime/socket replacement, host default files, user-supplied transport, mismatched marker/version, connection/auth/syntax/missing-object/timeout errors and active/unknown sessions before mutation. Use mocked native transport only for units and label it accordingly.
```python
bad = AttemptResult('protected_dml_ddl_denied', 1, 1064, ())
with self.assertRaises(SqlFenceError):
    assert_denial(bad, frozenset({1044, 1142}))
```

- [ ] Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_sql_fence_driver.py -v`. Expected: FAIL on missing driver interfaces.
- [ ] Implement only fixed SQL generated from validated typed operations/case IDs; encode data values as hex with closed column lists so no SQL token comes from a raw fixture string. Bootstrap the matched archive in a new namespace/chroot with explicit options, small InnoDB settings and private owned datadir. Factory-create <=16-character `fx_` users/fictional passwords and private client option files; remove only known stock bootstrap accounts on this owned server before original capture. Verify CURRENT_USER per transport. Use only own Unix socket and fixed QA loopback port 33071 inside the new network namespace for host-precedence cases; bring up only `lo`, and verify no external interface/route/listener.
- [ ] Capture logical fixture seed equality after initialization, then actual full raw grant rows and all nonprivilege account cells using type-preserving hex fields. Preserve `Grantor`, `Timestamp`, column summaries, Password/plugin/authentication/TLS/resource attributes by hash/readback; never print those values. Fixed statement-specific privilege errors may be 1044/1142/1227 as applicable; every case pins its finite allowed set and verifies intended objects/data via the administrator. Do not accept arbitrary nonzero exit or timeout. Existing session identity and row-zero authorization probes do not change protected data; active/unowned sessions cause rejection, not KILL.
- [ ] Run Task 1–3 unit groups. Expected: all PASS with zero skips, and no unit test opens a host database. Exact native behavior is still pending Task 5.
- [ ] Commit with `feat: restrict SQL rehearsal to owned native sessions`.

### Task 4: Bounded supervision, durable operation journal and recovery

**Files:** Create `sql_fence_harness.py`, `tests/test_sql_fence_harness.py`; extend driver only with `restore_original(context, original: SqlSnapshot, expected_current: SqlSnapshot) -> SqlSnapshot` and `restart_owned_server(context, hard: bool) -> None`.

**Interfaces:** Produce `OwnedSupervisor`, `OperationJournal(root: Path, generation: str)` with `intent(op, before: SqlSnapshot) -> str`, `readback(intent_id: str, after: SqlSnapshot) -> None`, `verify_prefix() -> tuple[dict,...]`, and `close() -> None`. Export `check_budget(resources: dict) -> None` (exact integer keys `root_free`, `shm_free`, `mem_available`), `establish(context, projection: Projection, journal: OperationJournal) -> SqlSnapshot`, `recover(context, original: SqlSnapshot, projection: Projection, journal: OperationJournal) -> str`, and `retire(supervisor, evidence_root: Path) -> dict`. Recovery states are `held`, `establishing`, `recovery-required`, `restored`; no journal record alone selects one.

- [ ] Write `test_exact_budget_and_limit_plus_one`, `test_child_cannot_bootstrap_before_owned_admission`, `test_controller_death_does_not_restore_grants`, `test_intent_without_readback_never_claims_held`, `test_competing_row_or_auth_change_denies_restore`, `test_restore_retains_unrelated_deltas_and_exact_grant_metadata`, and `test_retirement_never_signals_or_deletes_reused_objects`. Assert all numeric limits from Global Constraints, broken pipes, disk-full/fsync failure, replacement journals, truncated/duplicate/gapped records, PID/mount/cgroup identity reuse and partial publication. Inject failure after each typed operation and before/after acknowledgment.
```python
with self.assertRaises(SqlFenceError):
    check_budget({'root_free': 1024**3 + 128*1024**2 + 16*1024**2 - 1,
                  'shm_free': 2*1024**3, 'mem_available': 3*1024**3})
```

- [ ] Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_sql_fence_harness.py -v`. Expected: FAIL on absent supervision/journal interfaces.
- [ ] Implement a parent startup barrier, new root-owned unique `/dev/shm/opening-sql-*` parent and a private 256 MiB tmpfs mount. Create only a unique QA cgroup beneath an already memory-enabled root, set/read back the fixed combined memory/swap/PID limits, then admit the child before bootstrap or bulk archive loading. Archive validation and staging run inside the admitted memory domain; the outer retainer reads only bounded input/status before admission. Do not enable controllers on ancestors or reuse an existing group. Parent admission verifies the child PID/start plus actual namespace/mount/cgroup membership. Independent supervisor owns server/sessions; interruptible controller children cannot restore gates on exit. Enforce the 16 MiB evidence budget across logs, journal, snapshots and final artifacts; report limits/failures with retained originals, not unbounded output.
- [ ] Journal actual original hashes/identities and typed intent before execution; fsync readback before acknowledgment. Fresh controllers compare physical rows with exact permitted semantic prefixes and actual stored originals; ambiguous state is `recovery-required`. Restore only matching owned grant rows, their original metadata and absence of generated rows; native raw-field restoration uses fixed column/identity codecs. Authentication drift or competing rows denies recovery. Preserve accepted unrelated counters/data and all protected bytes; never reload an old full SQL dump. Restart only the owned server under supervisor admission denial, verify grant-table integrity/counters, then reopen fixture clients. No server-restart failure can report held.
- [ ] Implement/test graceful mysqladmin shutdown, identity-bound fallback signaling and final PID/socket/mount/cgroup/evidence checks in the retirement interface. Failed retirement keeps staging/diagnostics; actual native/Mac preservation is exercised in Tasks 5–6.
- [ ] Run Task 1–4 unit groups. Expected: all PASS, no skips; tests prove no ancestor/existing cgroup, live PID or unrelated file mutation.
- [ ] Commit with `feat: journal and bound owned SQL fence recovery`.

### Task 5: Ten actual native feasibility cases and closed CLI

**Files:** Create `sql_fence_cases.py`, `tests/test_sql_fence_cases.py`; finish CLI in `sql_fence_harness.py` and its unit tests.

**Interfaces:** Export immutable `CHECKS=frozenset(CASE_IDS)` with the ten IDs below, `run_case(case_id: str, context: OwnedSqlContext, supervisor: OwnedSupervisor) -> dict`, `run_rehearsal(plan: FixturePlan, documents: SqlDocuments, evidence_root: Path) -> dict`, and `main(argv: list[str] | None = None) -> int`. Case IDs are `broader_grant_and_super_bypass`, `protected_dml_ddl_denied`, `unrelated_work_preserved`, `cached_session_admission`, `host_pattern_proxy_grants`, `admin_definer_escape_denied`, `keeper_controller_loss`, `owned_server_restart`, `partial_operation_recovery`, `exact_restore_and_retirement`.

- [ ] Write `test_exact_ten_cases_required_no_subset`, `test_unknown_case_or_runtime_input_fails_before_sql`, `test_case_requires_its_own_expected_native_effect`, `test_false_readiness_in_every_report`, and `test_partial_report_or_unacknowledged_storage_is_not_success`. The CLI accepts exactly `--plan`, `--plan-sha256`, `--reference-root`, `--evidence`; no source/host/socket/datadir/SQL/credential/worker-path options. Root input must contain exactly all ten cases, no duplicates/omissions. Every case has an eight-second per-statement limit, 30-second bootstrap limit, ten-second server-readiness limit and five-second owned-process retirement limit; the complete owned invocation has a 600-second deadline. Deadline failures are failures, not fencing evidence.
```python
self.assertEqual(len(CHECKS), 10)
self.assertEqual(main(['--socket', '/fixture/external.sock']), 1)
# A patched factory must record zero invocations for the rejected option.
```

- [ ] Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_sql_fence_cases.py -v`. Expected: FAIL on absent cases/CLI contract.
- [ ] Implement the cases using Task 3 fixed operations and Task 4 supervisor/journal, with a complete snapshot at each admission/recovery boundary. Use separate demonstration data for real successful global/read-only bypass, 300 unchanged protected InnoDB tables, three preserved schemas with MyISAM plus pattern neighbors, and exact unrelated workload counters. Native account/host tests verify actual CURRENT_USER. Unsafe graph/definer/proxy cases reject before projection mutation; restore temporary unsafe fixture setup only through checked owned administration. Old-session row-zero update must succeed before drain while a fresh identical attempt is denied, with protected snapshot unchanged. Keeper transaction/loss and controller interruption cannot reopen primary privileges; hard server restart is only an owned PID interruption. Retain every failed attempt/original and all acknowledged state transitions.
- [ ] Implement closed aggregate schema-1 purpose `fictional-sql-privilege-result`: `schema_version`, `purpose`, `plan_sha256`, `runtime_manifest_sha256`, `checks`, `native_passed`, `retirement`, `evidence_manifest`, `real_execution_ready`. Set the last field false on success/failure. Create/fsync immutable originals and manifest before aggregate acknowledgment. CLI exit 0 requires ten true cases plus complete retirement/evidence; exit 1 retains failure and false readiness; stdout contains counts/result hash only. A prior artifact or partial report is not success.
- [ ] Run Task 1–5 units on native root Linux. Expected: every new unit group PASS with zero skips; this completes the Task 5 RED/GREEN contract before native case execution.
- [ ] Invoke the CLI once with the accepted private plan/archive bindings and fixed budgets. Expected: all ten actual cases true, exit 0, zero skipped/incomplete cases, exact complete protected and expected unrelated results, accepted memory/storage peaks, owned PID/socket/mount/cgroup absent. Preserve first native failures and causal fixes; do not rerun unrelated application suites. The supplied source runtime is used only on owned fictional data, never executed on source.
- [ ] Commit with `test: qualify ten native SQL privilege fence scenarios` only after all required actual outputs are read.

### Task 6: Regression, author review, privacy and exact publication

**Files:** Modify `tools/opening-controls/README.md` with the as-built invocation and acceptance limits; retain all execution/review artifacts privately. No application/schema or old contract changes.

**Interfaces:** Consume the original schema-1 result/manifest and exact committed candidate from Task 5. Produce private complete QA, recovery, publication and retirement receipts; a public generic README/status update, never a real fence or installed service.

- [ ] Run `python3 -m unittest discover -s tools/opening-controls/tests -v` on native root Linux. Expected: all existing/new toolkit units pass with zero skips; accepted old observation/lock/boundary contracts remain unchanged. Run `python3 -m compileall -q tools/opening-controls` only in the owned QA copy. Expected: exit 0; generated caches remain outside publication. Do not rerun PHP/application suites whose bytes and interfaces were not changed.
- [ ] Validate the original fictional native result is rejected by the current generic pure PHP proof schema for both `account-opening-fence` and `account-opening-capture-fence`: require only `ProofSchema.php`, decode the private result and call `ProofSchema::attestation` in a new outbound-disabled process. Expected: both RuntimeException rejections with exit 0 from the assertion wrapper; no Laravel/bootstrap/environment/DB/trust/signing load. Do not change the parser to add a fictional result purpose.
- [ ] Perform a separate inline whole-candidate review against this spec, exact immutable diff and five Review Focus conditions. Record author-only review and every ruling. Important defects require causal RED/GREEN and affected native cases plus full toolkit units; no independent review claim. Confirm phase originals and admitted resource/domain identities, evidence acknowledgment, raw metadata recovery and unrelated data preservation. Do not promote a green SQL-only result to real source readiness.
- [ ] Compare complete protected target state and all holds before/after the owned rehearsal and retirement. Verify exact current source runtime/configuration identities only by approved read-only metadata scope; execute no source command/job/provider action. Retain native originals and independently hash-verified Mac copies before removing only owned QA scratch. Expected: no protected changes, no owned resources left, reserves satisfied; failures remain retained.
- [ ] Update README only to the final as-built scope/status. Scan the exact generic candidate in place against protected values without exporting them or bootstrapping the application; inspect the candidate diff for private identities/artifacts. Expected: zero matches; no archive, SQL, private input, compiled cache, fixture credential or operational constant tracked. Compare final candidate bytes to tested native source; format-only corrections need explicit byte/token provenance, not an unqualified full-test claim.
- [ ] Commit only approved generic paths with `docs: record SQL-only feasibility and operational limits`, publish to the existing draft fork branch/PR, and read back every changed original byte and exact Git/PR head. Expected: clean fork, exact published/tested match, draft/unmerged status, no installed runtime/trust/financial change. Keep selected execution method and all deferred boundaries in the private continuation checkpoint.

## Execution handoff

This plan prepares one SQL-only feasibility increment. Review this written plan before implementation; retain the native inline method. The accepted private execution packet supplies exact runtime/archive hashes, allowed native host/boot, protected target baseline and evidence destinations; none is hardcoded here. Implementation approval covers only owned QA resources explicitly named by this plan. Real source controls, deployment, signer/trust, actual-cohort freeze, openings, activation, selected hold release and billing handover remain separate decisions.
