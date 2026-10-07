# Source Control Adapters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build read-only legacy source observation, explicit coverage validation and a reproducible exact-version disposable database lock harness without claiming a live source freeze.

**Architecture:** The source-compatible collector emits private raw evidence; Python 3 parsers construct a strict observation that the coverage evaluator compares against an accepted private profile. A separate root Linux harness tests fictional schemas using an explicitly supplied runtime. Neither path changes the existing rehearsal controller or issues native opening proofs.

**Tech Stack:** Python 2.7-compatible source collector; Python 3.10+ external tools; standard library and unittest; Linux namespaces/chroot; MariaDB 5.5.68 binaries supplied privately; existing PHP native proof parser for rejection acceptance.

**Spec:** [approved source-control design](../specs/2026-10-07-source-control-adapters-design.md), source commit `bde24f4cef2d1f711f1d7ce3a84a7c411f614c22`, SHA256 `fcd221aa3c0704522b9a5c24a2f106ff321551e84f856ede0f4c6082b9d73f7f`.

## Global Constraints

- The external orchestrator uses Python 3.10+; the collector uses only standard-library facilities supported by Python 2.7 and Python 3.
- `real_execution_ready=false`; `enforcement_complete` remains false in this increment. No command freezes the real source, edits its cron, changes ingress, issues a genuine lease, applies funds or releases holds.
- Maintain at least 1 GiB root and 512 MiB RAM reserve. Copied runtime, credentials, configuration bytes, process identities and raw evidence are private; no runtime downloads or product dependency installation.
- Use a unique owned RAM root, new network/PID namespace and chroot, no option files, networking disabled and a socket inside that root. Only fictional schemas/users are created.
- Preserve existing rehearsal permit purpose/controller recovery and the native proof schema. A v2 rehearsal cannot prove source v1 behavior.
- Source billing remains authoritative. No provider transaction, customer login, callback replay, collection activation, source lock or live deployment is in scope.
- Native execution is already selected. Implement tasks sequentially in the existing isolated worktree after plan review; use one fresh whole-candidate reviewer before publication.

## Review Focus

1. A quoted or commented nginx token must not invent an active include/route; a missing glob match or unsupported construct must produce a coverage blocker (Task 3).
2. Process disappearance, PID reuse or an executable/configuration change during capture must not silently remove a writer from the inventory (Task 2).
3. Duplicate JSON fields, boolean integers and duplicate cron lines must not collapse distinct ownership evidence (Tasks 1 and 3).
4. Source/controller wall-clock skew or a restarted controller must not make an old observation appear fresh; use controller boot identity and monotonic receipt age (Tasks 1 and 4).
5. Archive links/path traversal, runtime replacement and cleanup identity reuse must not target a live filesystem/socket/process (Task 5).

## Files and stable contracts

All generic code lives under `tools/opening-controls/`, following its existing flat-module imports and unittest style. No application, extension, migration, route or existing controller/custody implementation changes are planned.

| File | Responsibility |
| --- | --- |
| `source_schema.py` | Strict versioned JSON contracts and canonical hashes |
| `source_collect.py` | Standalone source-compatible fixed read-only capture |
| `source_inventory.py` | Cron, process, active configuration and SQL-object inventory |
| `source_coverage.py` | Exact ownership/coverage evaluation; no enforcement |
| `source_cli.py` | Local observe/evaluate orchestration and private evidence writes |
| `lock_runtime.py` | Closed runtime manifest/archive validation and owned staging |
| `lock_harness.py` | Exact-version fictional lock experiment and owned cleanup |
| `tests/source_fixtures.py` | Fictional snapshots/profiles/runtime fixture helpers |
| `tests/test_source_schema.py`, `test_source_collect.py`, `test_source_inventory.py`, `test_source_coverage.py`, `test_source_cli.py` | Source adapter regressions |
| `tests/test_lock_runtime.py`, `test_lock_harness.py` | Harness validation/failure regressions |
| `README.md` | Commands, limitations, private evidence and native acceptance |

JSON types below are validated dictionaries, not generated classes. `ObjectRecord` has exactly `id`, `kind`, `identity`, `sha256`, `details`; supported kinds are `file`, `job`, `service`, `process`, `include`, `listener`, `route`, `sql-object`, `sql-principal`, `sql-session`. File hashes bind raw bytes; other hashes bind canonical identity/details. Stable IDs include kind and identity; job identity includes path, line number and raw-line hash, preserving duplicates.

All document schema versions are integer `1`. Fixed purposes are `source-control-capture-profile`, `source-control-raw-capture`, `source-control-observation`, `source-control-coverage-profile`, `source-control-coverage-report`, and `mariadb55-disposable-runtime`, respectively. Unknown purpose or fields fail validation; no purpose aliases map to opening attestations.

`CaptureProfile` contains schema version/purpose, expected host identity, explicitly allowed absolute file roots/read paths, manager executable/module selectors, and one validated database schema identifier. It cannot contain shell, SQL or mutation commands. The collector's command/query IDs come from constants in its audited source, not profile text. Unknown fields are rejected.

`RawCapture` contains schema version/purpose, observation ID, host identity, source capture interval, files, process samples, fixed operation results and capture errors. Each operation retains return status and bounded private stdout/stderr. `Observation` contains schema version/purpose, observation ID, host identity, capture interval, object records, errors, raw-capture hash and `CaptureReceipt`. The receipt binds the canonical observation payload **excluding its receipt** and the raw-capture hash to controller boot ID and capture-start/end monotonic nanoseconds; the completed document has a separate canonical hash for profile binding, avoiding a self-referential hash.

`CoverageProfile` contains schema version/purpose, accepted observation hash, expected host identity, accepted object assignments (`object_id`, exact object hash, `selected` or `unrelated`, nonempty justification/evidence hash) and the seven future control requirements from the spec. No profile field enables live enforcement. `CoverageReport` binds observation/profile hashes, blocker records (`code`, `object_id`, `evidence_ref`), `inventory_complete`, `enforcement_complete=false`, `real_execution_ready=false`. Missing profile yields `profile-unaccepted`, never implicit acceptance.

The required control codes are exactly `source-scheduler`, `source-process`, `source-ingress`, `source-cli-sql-ddl`, `secondary-lock-keeper`, `provider-custody-reconciliation`, `target-and-cohort-deassignment`. Profiles cannot omit one; reports include an `enforcement-unproved` blocker for each. An otherwise complete inventory can have `inventory_complete=true` while all these enforcement blockers remain and readiness is false.

`RuntimeManifest` contains schema version/purpose, `expected_version=5.5.68-MariaDB`, archive hash, role-to-relative-path mapping for loader/mysqld/mysql/mysqladmin/bootstrap SQL/error/charset resources, library directories, and per-file size/hash/mode/type. Only regular, explicitly listed runtime resources are allowed; no datadir, credential, option-file or external socket field exists.

---

### Task 1: Strict contracts and reusable fictional fixtures

**Files:** Create `source_schema.py`, `tests/source_fixtures.py`, `tests/test_source_schema.py`.

**Interfaces:** Produce `SourceError(RuntimeError)`, `canonical(value: dict) -> bytes`, `parse_document(raw: bytes, purpose: str) -> dict`, `validate_capture_profile(value: dict) -> dict`, `validate_raw_capture(value: dict) -> dict`, `validate_observation(value: dict) -> dict`, `validate_coverage_profile(value: dict) -> dict`, `validate_runtime_manifest(value: dict) -> dict`. Reuse `custody.strict_json` on the external side only; the standalone collector cannot import that Python 3 module. Fixtures produce `capture_profile()`, `raw_capture()`, `observation()`, `coverage_profile(observation)` and `runtime_archive(root)` with all dictionary contracts above, plus `FixtureClock` exposing `boot_id()`, `now_ns()` and `monotonic_seconds()`. Its boot ID is `fixture-controller-boot`; observation receipt ends at `1_000_000_000` ns, and normal evaluation time is `2_000_000_000` ns.

- [ ] **Step 1:** Write `test_documents_reject_duplicate_unknown_and_invalid_fields`, `test_object_identity_preserves_duplicate_jobs`, `test_profile_cannot_supply_commands`, and `test_receipt_requires_exact_hash_boot_and_integer_times`. Assert duplicate keys/nonfinite JSON raise `SourceError`; unknown purpose/keys, `schema_version=True`, negative/backward monotonic intervals and mismatched hashes are rejected; identical cron bytes at two positions remain two IDs.

```python
with self.assertRaises(SourceError):
    parse_document(b'{"schema_version":1,"schema_version":1}', 'source-control-observation')
bad = observation()
bad['schema_version'] = True
with self.assertRaises(SourceError):
    validate_observation(bad)
```
- [ ] **Step 2:** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_schema.py -v`. Confirm failure is missing schema functionality, not a broken test import unrelated to the new module.
- [ ] **Step 3:** Implement the exact closed dictionaries above, canonical SHA256 and strict scalar/collection validation. Use SHA256 lowercase 64 hex, positive integer PIDs, numeric start ticks, finite bounded collection sizes and nonempty evidence references. Define limits once: 4 MiB per captured file/operation, 32 MiB total serialized capture, 10,000 objects and a 60-second maximum controller capture/receipt age. Reject rather than truncate accepted documents; base64 overhead counts toward the serialized limit.
- [ ] **Step 4:** Rerun the Task 1 command; require all tests pass. Record fixtures contain only fictional values and no real host/profile data.
- [ ] **Step 5:** Commit only the three Task 1 files: `feat: define strict source control observation contracts`.

### Task 2: Source-compatible read-only collector

**Files:** Create `source_collect.py`, `tests/test_source_collect.py`; extend `tests/source_fixtures.py` with `FakeReadOps`.

**Interfaces:** Consume `CaptureProfile` from Task 1. Produce standalone `CaptureError(ValueError)`, `validate_profile(profile) -> dict`, `collect(profile: dict, ops, clock) -> dict` without annotations in the source-compatible file. Its `ReadOps` interface exposes `read_regular(path, limit)`, `sample_processes(selectors)`, `run_operation(operation_id, schema, byte_limit, timeout_seconds)` and `host_identity()`; elapsed time comes from `clock.monotonic_seconds()`. `main()` reads one bounded profile from stdin and emits one raw JSON result; no source-side evidence file is written. `FakeReadOps(raw_capture, faults=None)` records calls and permits named mutation/error fixtures; standalone validation must match Task 1's contract on the same invalid-profile cases.

- [ ] **Step 1:** Write `test_collector_runs_only_fixed_read_operations`, `test_changed_config_or_reused_pid_invalidates_capture`, `test_disappearing_writer_is_not_silent_success`, `test_failed_or_oversize_operation_is_explicit`, and `test_profile_shell_and_sql_text_never_executes`. Fake operations record every call; assert no discovered cron or profile string becomes a subprocess command, errors persist, PID start/executable changes create errors, and symlink/out-of-root files fail. A disappearance of an initially selected PID is an explicit capture-change error; unrelated short-lived process churn is not silently promoted to selected-writer exclusion.

```python
ops = FakeReadOps(raw_capture(), faults={'replace_pid_start': True})
result = collect(capture_profile(), ops, FixtureClock())
self.assertTrue(result['capture_errors'])
bad = capture_profile()
bad['shell_command'] = 'fixture-command-that-must-not-run'
with self.assertRaises(CaptureError):
    validate_profile(bad)
```
- [ ] **Step 2:** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_collect.py -v`; verify the named missing collector behavior fails.
- [ ] **Step 3:** Implement only fixed `/proc`/file reads and fixed argv for listener, service and database metadata operations, `shell=False`, with bounded owned-client timeout/retirement. The source-compatible elapsed clock reads `/proc/uptime`; it must not require Python 3's monotonic APIs or `communicate(timeout=...)`. Bound both output pipes with select/owned-client termination. SQL is fixed SELECT metadata for version/settings, schema objects/engines, relevant connection metadata excluding SQL text, and privileged principals. Accept a schema matching `[A-Za-z_][A-Za-z0-9_]{0,63}` only. Read raw configuration privately; capture object identities before/after and reject mixed-generation evidence. Return errors for unreadable selected objects and unsupported operations. Do not execute daemon config tests/reloads, discovered commands, LOCK/SET GLOBAL/DDL/GRANT/KILL or billing code. Existing authorized source-local database authentication may be used but never returned in output.
- [ ] **Step 4:** Rerun the Task 2 command on Python 3; test collector parsing/JSON output as a subprocess with fictional `ReadOps`. Later Task 6 exercises the same audited bytes on the actual source Python 2.7, using `python -c` and stdin profile; no product install or compile-cache write on source.
- [ ] **Step 5:** Commit Task 2 files: `feat: add source compatible read-only control collector`.

### Task 3: Actual boundary inventory and conservative parsing

**Files:** Create `source_inventory.py`, `tests/test_source_inventory.py`; extend fictional fixtures.

**Interfaces:** Consume validated `RawCapture`/`CaptureReceipt`. Produce `parse_cron(raw: bytes, path: str) -> list[dict]`, `parse_nginx(files: dict, entrypoint: str) -> dict`, `inventory(raw: dict, receipt: dict) -> dict` returning validated `Observation`. Parser errors are structured blockers; they do not vanish into empty lists.

- [ ] **Step 1:** Write `test_duplicate_jobs_and_positional_selectors_remain_distinct`, `test_quoted_comments_and_include_cycles_do_not_invent_routes`, `test_missing_glob_or_unsupported_nginx_syntax_blocks`, `test_alternate_direct_ipv6_php_and_error_routes_are_inventory`, and `test_sql_objects_and_unexplained_privileged_sessions_are_preserved`. Assert two identical cron commands yield two IDs; shared/positional selectors remain unresolved; case variants and error handlers are distinct; direct IPv4/IPv6 listeners and alternate/default hosts remain objects; missing/cyclic/unavailable includes and partial metadata prevent complete inventory.

```python
line = b'* * * * * /fixture/cron-billmgr daily\n'
jobs = parse_cron(line + line, '/fixture/crontab')
self.assertEqual(len(jobs), 2)
self.assertNotEqual(jobs[0]['id'], jobs[1]['id'])
parsed = parse_nginx({'/fixture/main.conf': b'include missing/*.conf;'}, '/fixture/main.conf')
self.assertTrue(parsed['errors'])
```
- [ ] **Step 2:** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_inventory.py -v`; confirm failures assert the intended unsupported/missing inventory behavior.
- [ ] **Step 3:** Implement a bounded nginx token/block parser preserving quoted strings, comments, nesting and active include edges, not regex-only route coverage. It records listen/server-name/location/proxy/fastcgi/error-page branches and leaves dynamic routing/expression semantics explicitly unproved. Include expansion uses only explicit file names/bytes already captured under the private profile; the parser performs no late reads or profile acceptance. Expansion is bounded to 16 nesting levels and 1,000 files. Unknown/uncaptured include, cycle, unsupported syntax or missing glob match denies inventory completeness; any supplemental files require a new explicit read profile and fresh capture. Cron parsing records raw job/environment context and exact selectors; never evaluate shell expansions. SQL names with malformed row framing/control characters are unsupported rather than misparsed.
- [ ] **Step 4:** Rerun Task 3 tests and Tasks 1–2 tests. Confirm fixture direct/alternate/error cases survive normalization and no request was sent to an actual HTTP listener.
- [ ] **Step 5:** Commit Task 3 files: `feat: inventory source writers and alternate ingress boundaries`.

### Task 4: Coverage evaluation and private observer CLI

**Files:** Create `source_coverage.py`, `source_cli.py`, `tests/test_source_coverage.py`, `tests/test_source_cli.py`; modify `README.md` with these commands.

**Interfaces:** Consume `Observation`, optional `CoverageProfile`, controller boot and monotonic time. Produce `evaluate(observation: dict, profile: dict | None, controller_boot_id: str, now_ns: int) -> dict`; `observe(profile: dict, transport, evidence_root: Path, clock) -> dict`; `save_report(root: Path, report: dict) -> Path`; `main(argv: list[str] | None) -> int`; `ControllerClock.boot_id() -> str` and `ControllerClock.now_ns() -> int`; `SSHTransport.capture(program: bytes, profile: dict) -> tuple[int, bytes, bytes]`. CLI has only `observe` and `evaluate`; capture/profile/report files use existing private-directory/file requirements and exclusive fsynced creation.

- [ ] **Step 1:** Write `test_complete_inventory_still_has_no_enforcement`, `test_unknown_duplicate_missing_and_changed_assignments_block`, `test_stale_skewed_or_restarted_receipt_blocks`, `test_unaccepted_profile_is_not_generated_implicitly`, and `test_cli_never_overwrites_or_exposes_raw_private_evidence`. Assert even exact complete fixture ownership reports false readiness/enforcement and blockers for all seven future control requirements; missing profile returns `profile-unaccepted`; unknown object/duplicate assignment/hash change deny inventory acceptance. Future source wall-clock text cannot override controller monotonic freshness; changed controller boot, future/backward time, capture duration/age over 60 seconds block. Unsafe evidence path/existing file denies writes; summary stdout never contains raw source configuration.

```python
obs = observation()
report = evaluate(obs, coverage_profile(obs), 'fixture-controller-boot', 2_000_000_000)
self.assertTrue(report['inventory_complete'])
self.assertFalse(report['enforcement_complete'])
self.assertFalse(report['real_execution_ready'])
old = evaluate(obs, coverage_profile(obs), 'fixture-controller-boot', 62_000_000_000)
self.assertIn('stale-observation', {b['code'] for b in old['blockers']})
```
- [ ] **Step 2:** Run both modules with `python3 -m unittest discover -s tools/opening-controls/tests -p 'test_source_*.py' -v`; verify newly named evaluator/CLI cases fail for missing functionality.
- [ ] **Step 3:** Implement exact coverage against the accepted observation/object hashes; independently justified unrelated assignments are required, never inferred. Emit immutable hashed reports using purpose `source-control-coverage-report`; every report has explicit false real-ready/enforcement fields. `observe` transports only the audited collector program and validated JSON profile to a validated SSH destination using argv/shlex quoting, not interpolated shell/profile text. SSH uses BatchMode, no new trust prompts/options and no remote file upload. Transport/status/output errors remain errors. Source clock values are evidence only; freshness uses the controller boot/monotonic receipt. Linux boot identity reads `/proc/sys/kernel/random/boot_id`; on Darwin hash only the parsed sec/usec values from fixed `sysctl -n kern.boottime`, not its localized trailing date. Unavailable boot identity or other platforms fail explicitly; use `time.monotonic_ns()` for elapsed age. No auto-accept profile command or apply/freeze/lease subcommand exists.
- [ ] **Step 4:** Rerun Task 4 command. CLI success for observation means capture retained only. Evaluation exits nonzero whenever blockers remain; this increment's report cannot be passed off as a successful frozen-source check.
- [ ] **Step 5:** Commit Task 4 files: `feat: add fail closed source coverage evaluation`.

### Task 5: Version-matched owned database harness

**Files:** Create `lock_runtime.py`, `lock_harness.py`, `tests/test_lock_runtime.py`, `tests/test_lock_harness.py`; extend runtime fixtures; update `README.md`.

**Interfaces:** Consume `RuntimeManifest` from Task 1 and an explicitly supplied private runtime archive. Produce `validate_archive(archive: Path, manifest: dict) -> dict`; `stage_runtime(archive: Path, manifest: dict, owned_root: Path) -> dict`; `run_lock_rehearsal(archive: Path, manifest: dict, evidence_root: Path) -> dict`; `retire_owned(processes: list[dict], runtime: dict, evidence_root: Path) -> dict`; harness `main(argv: list[str] | None) -> int`. Process records pin PID/start ticks/boot and namespace/runtime identity. Native report contains the ten named checks, fictional transcript, runtime hashes and retirement status, with `real_execution_ready=false`.

- [ ] **Step 1:** Write `test_archive_rejects_paths_links_duplicates_and_replacement`, `test_no_external_socket_or_existing_runtime_root_is_accepted`, `test_namespace_version_and_reserve_checks_precede_sql`, `test_wrong_sql_error_or_dead_writer_is_not_successful_blocking`, `test_failed_shutdown_and_reused_pid_preserve_failure_evidence`, and `test_cleanup_failure_preserves_owned_root`. Assert traversal/absolute/duplicate/link/special entries, missing mysqladmin, corrupt/changed runtime and unknown role fail before SQL; existing/live datadir/socket arguments are rejected. Reserve below 1 GiB root or 512 MiB RAM fails. Wrong namespace/version/error, timeout without observed lock wait or writer exit fail. Reused/unverifiable PID is never signalled; incomplete retirement retains RAM root and failing status.

```python
fixture = runtime_archive(self.root)
bad = copy.deepcopy(fixture['manifest'])
bad['roles'].pop('mysqladmin')
with self.assertRaises(SourceError):
    validate_archive(fixture['archive'], bad)
with self.assertRaises(SourceError):
    stage_runtime(fixture['archive'], fixture['manifest'], self.root)  # already exists
```
- [ ] **Step 2:** Run `python3 -m unittest discover -s tools/opening-controls/tests -p 'test_lock_*.py' -v`; verify exact missing validation/ownership behavior fails.
- [ ] **Step 3:** Implement bounded archive validation (256 MiB expanded total, 64 MiB per file, no links/escape/duplicate entries), preflight reserve including staging/fictional datadir allowance, and exclusive owned staging with all hashes/modes verified. Pin the input descriptor/path identity and archive hash across validation/extraction; never extract from unchecked reopened bytes. Pin staging identity and recheck runtime before execution. Spawn an owned PID/network namespace and chroot with the manifest loader/libraries. Check namespaces, expected version and owned socket/datadir before SQL; no defaults/networking. Bootstrap stock system files into a new fictional datadir, explicit tmpdir, separate statements for the legacy bootstrap parser. Retain failed attempts with fresh datadir names. Reject unsupported platform/missing binaries instead of skipping acceptance.
- [ ] **Step 4:** Implement the ten-check experiment from the spec with 300 fictional InnoDB tables, a fictional ordinary user, and an unrelated fictional schema. Observe exact lock-wait state and live writer identity; assert counter 1 after read-only bypass, blocked at 1 under READ locks, 2 after keeper START TRANSACTION, blocked at 2 after re-lock, 3 after keeper loss, and 301 tables after deliberate new-table escape. A separate consistent-snapshot reader must complete while the writer remains blocked. Test unrelated schema and the new table retain successful independent writes. Transcript stores SQL hashes/results and required expected errors; all ten must pass.
- [ ] **Step 5:** Implement shutdown using the exact-version `mysqladmin --no-defaults` against only the verified owned socket. Record any graceful failure, then use bounded identity-checked termination of owned processes/namespace. Retain/hash evidence before removal and independently verify no owned process remains. No removal when retirement is unproved. Failed behavioral tests stay failed even if cleanup succeeds; failed graceful shutdown is explicit and final cleanup acceptance requires verified retirement. Never SQL SHUTDOWN as a substitute for the accepted administrative client.
- [ ] **Step 6:** Rerun Task 5 unit tests and new source tests. Do not label mock validation as native database acceptance; Task 6 runs the exact binaries.
- [ ] **Step 7:** Commit Task 5 files: `feat: add owned MariaDB 5.5 lock rehearsal harness`.

### Task 6: Native acceptance, fresh review and generic publication

**Files:** Modify `README.md` with acceptance limits and commands; retain raw receipts/profile/runtime only in a new operator-private increment directory. Do not commit evidence or copied executables. Update local migration readiness/controller records after acceptance; preserve the operational held checkpoint.

**Interfaces:** Consume committed generic candidate, Task 4 observer/report and Task 5 harness, accepted protected checkpoint, private source profile and exact runtime including mysqladmin. Produce immutable candidate hash manifest, native evidence, independent review receipt, privacy/published byte comparison and final preservation/retirement record.

- [ ] **Step 1:** Record the immutable approved plan/spec/candidate and protected checkpoint. Hold the existing exclusive QA lock; verify production/held/original-QA full SQL, every held runtime file, root/RAM reserve and holds/disabled funding/collection before native staging. Stage only reviewed generic bytes into an owned private runtime; no app installation or credentials export.
- [ ] **Step 2:** Prepare an explicit private runtime manifest/archive by reading only allowed source binary/dependency/stock-resource files, adding the matching mysqladmin executable/dependencies. Reverify the previously accepted mysqld hash and version. Do not copy source datadir, grant database, option file or credentials. Verify archive hash/member allowlist before use. This is a read-only source operation, not a package install.
- [ ] **Step 3:** Run new focused tests under root Linux/network isolation:

```sh
unshare --net python3 -m unittest discover -s tools/opening-controls/tests -p 'test_source_*.py' -v
unshare --net python3 -m unittest discover -s tools/opening-controls/tests -p 'test_lock_*.py' -v
unshare --net python3 tools/opening-controls/lock_harness.py --archive "$LOCK_RUNTIME_ARCHIVE" --manifest "$LOCK_RUNTIME_MANIFEST" --evidence "$LOCK_PRIVATE_EVIDENCE"
```

The three variables above must be existing validated absolute private paths chosen during native staging, never defaults pointing at a live socket/datadir. Require focused tests to pass with zero skips; native report must show exactly ten true behavioral checks, matching raw results and all owned resources retired. No unrelated application suite, payment transaction or cgroup-v2 freeze is required.

- [ ] **Step 4:** Run one fresh actual-source read-only `observe` with the audited collector on its Python 2.7 runtime, surrounded by source configuration/core/schema/settings readbacks. Use a private unaccepted coverage profile or none: preserve actual unresolved controls, including shared cron selectors/direct listener/SUPER writers, and do not manufacture accepted ownership. Verify normal billing remains running. Evaluate and independently check structured blockers; every real report remains false. A failed/changed capture is retained and cannot be promoted as successful acceptance; repeat only after resolving the actual reason.
- [ ] **Step 5:** Run a standalone network-isolated PHP check requiring only the existing `ProofSchema.php`, with no application bootstrap/database access. Pass the actual coverage report and an existing rehearsal permit to `ProofSchema::attestation($value, 'account-opening-fence')`; require RuntimeException for each. Record the unmodified proof-parser file hash. Rejection, not conversion or signing, is acceptance.
- [ ] **Step 6:** Invoke requesting-code-review for one fresh whole immutable candidate review using native execution's preserved review method. Review the spec/plan, raw native results and boundaries. Reproduce and fix consequential findings with focused regressions; review/verify the changed candidate and rerun only affected checks. Require no unresolved consequential finding before publication.
- [ ] **Step 7:** Verify whitespace, candidate hash/evidence agreement and a protected-value scan against final generic diff/docs; require zero matches. Publish exact reviewed generic bytes to the existing Paymenter draft PR, update its description around final scope and attach if needed. No merge, product deployment or real controls installation. Read back the draft head and compare every changed published file to the accepted candidate.
- [ ] **Step 8:** Retain raw successful/failure diagnostics and immutable hashes in root-private durable evidence. Verify every held runtime file and all three protected databases remain equal to baseline, holds/disabled funding/collection/provider bindings unchanged, source configuration/core/settings unchanged and all owned processes/RAM retired. Update the local continuation controller with implementation acceptance and outstanding enforcement gates; no new operational handover checkpoint. Record final root/RAM reserve and distinguish this completed increment from live readiness.

## Plan self-review and handoff

Spec coverage: private observation/uncertainty is implemented by Tasks 1–4; exact-version experiment and cleanup by Task 5; native rejection, protected boundaries, independent review/privacy/fork publication by Task 6. Each future enforcement requirement stays a named blocker; none is an omitted promised implementation. All five Review Focus classes have named tests in their owning tasks. Interface names and JSON fields are defined above and consumed consistently.

Plan review is the next gate. Native execution remains selected; there is no execution-method question to repeat. After written-plan approval, use executing-plans and implement these tasks in the existing isolated worktree. No current approval authorizes live source freeze or billing handover.
