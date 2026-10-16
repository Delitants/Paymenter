# Source boundary contracts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a local, private-file validator for fictional boundary plans and witnesses that identifies incomplete coverage and cannot authorize production enforcement.

**Architecture:** Add a separate closed-schema parser, an anchored reference loader, a pure coverage evaluator and a local-only CLI to the external opening toolkit. The only adapter is `fixture-boundary-v1`; all evaluations keep real readiness false. Existing observation, custody, fixture controller and native opening-proof interfaces remain unchanged.

**Tech Stack:** Python 3.10+ standard library, unittest, root Linux for private-file acceptance, isolated PHP for unchanged native-proof rejection.

**Spec:** [Approved source boundary enforcement design](../specs/2026-10-07-source-boundary-enforcement-design.md), SHA256 `f1b30ebbc81b770638c7e72d7b78696c2b2005b7a256c0d784a8585c6c205b0a`.

## Global Constraints

- This implements only the spec's first increment: schemas, original-byte references, fictional coverage and negative acceptance. Native execution remains selected.
- Every evaluation returns `enforcement_complete=false` and `real_execution_ready=false`. Successful syntax validation does not accept a plan or control.
- Only `fixture-boundary-v1` is registered. Production adapters, live apply, signing, freeze, restore and handover commands are unavailable.
- Keep schema 1 native opening proofs, the existing coverage profile/evaluator and rehearsal permit unchanged. No application entry point invokes these tools.
- Root-owned 0600 ordinary files, root-owned 0700 reference/evidence roots, no symlinks, hard links, stream wrappers, duplicate decoded JSON keys, unknown fields, reference cycles or path substitution.
- Limits: 4 MiB per document, 32 MiB total plan bytes, 10,000 entries, eight reference levels; use decimal strings for nanoseconds, no JSON floats. Reject booleans where integers are required.
- Require at least 1 GiB root and 512 MiB RAM reserve for native acceptance. No skipped case establishes acceptance.
- Publish generic code, fictional fixtures and documentation only. Host identities, source observations, credentials, principals, mappings and recovery material stay private and outside Git.
- BILLmanager remains billing authority. No source configuration/control changes, real openings, hold release, provider request, mail, callback execution, customer login, application activation or handover.

## Review Focus

1. Same-byte inode/ancestor substitution between inspection, reading and report publication must fail (Task 2).
2. Omitted, duplicated or newly added objects, ambiguous shared scope and unsupported capture cannot become accepted containment (Task 3).
3. Future time, a different observer boot or process identity, stale generations and mismatched sessions must reject witness claims (Tasks 1 and 4).
4. A forged `held` assertion, timeout or fixture gate must never become physical enforcement or a native proof (Tasks 4 and 6).
5. Malformed CLI inputs, reference failures and storage exhaustion must expose no private strings, emit no success receipt and perform no external operation (Task 5).

---

## File responsibilities and shared decisions

Create `tools/opening-controls/boundary_schema.py` (closed documents), `boundary_refs.py` (private original-byte loading), `boundary_coverage.py` (pure assessment) and `boundary_cli.py` (local command orchestration). Create `tests/boundary_fixtures.py` and four corresponding `tests/test_boundary_*.py` files. Modify only the external toolkit's `README.md` for usage and limitations. Do not modify `source_schema.py`, `source_coverage.py`, `source_cli.py`, `custody.py`, `control.py`, `ProofSchema.php` or the application.

Reuse `source_schema.canonical`, `digest`, bounds and `CONTROL_CODES`, and `custody.strict_json` for duplicate-key/nonfinite rejection. Add finite-float rejection in the new parser. Do not use `source_cli.load_private` or assume `custody.private_directory` meets this stronger root/anchoring contract. Imports must not create databases, start observers or execute anything.

### Closed document layouts

The approved spec defines the exact plan/control/member/unresolved/witness/identity/interval keys and roles; implement those without adding fields. Specify witness `observer_identity` keys as `boot_id`, `pid`, `start_ticks`, `exe_sha256`; PID is a positive integer, start ticks a positive decimal string. Hashes are lowercase 64-digit hex. IDs are bounded ASCII `[A-Za-z0-9_.-]{1,128}`. Nanoseconds are canonical unsigned decimal strings (zero permitted); boot IDs are bounded nonempty strings. `control_generation` is a positive integer. Dependencies name other controls, never themselves or a cycle. Unknown adapter IDs are retained as unsupported draft inputs, never dynamically imported.

Add these **fictional supporting contracts**, exclusively for this increment:

- `source-control-boundary-scope`: exact keys `schema_version`, `purpose`, `fixture_id`, `source_identity`, `target_identity`, `objects`, `unrelated`, `errors`. Object rows have `object_id`, `object_sha256`, `evidence_refs`; unrelated rows have `object_id`, `object_sha256`, `evidence_refs`. Errors have `object_id` (nullable), `reason_code`. The one scope document appears in `observation_refs`; remaining observation references are fixture evidence of kind `observation`. All observed objects, including PHP dependencies and file-backed state, are enumerated in `objects`. `unrelated` is an explicit proposed assignment, not additional inventory.
- `source-control-boundary-fixture-evidence`: exact keys `schema_version`, `purpose`, `fixture_id`, `kind`, `context`, `records`, `refs`, `errors`. Kinds are `observation`, `adapter-manifest`, `cohort`, `baseline`, `precondition`, `blocking-check`, `rollback`, `containment`, `unrelated-scope`, `acceptance`, `gate-readback`, `attempt-result`, `unrelated-result`, `failure`. Records have exactly `object_id` (nullable), `object_sha256` (nullable), `outcome` (`pass`, `blocked`, `unknown`), `reason_code` (nullable). Errors use the scope error layout; `refs` contains only typed references to this fixture-evidence purpose. No arbitrary payload, command, SQL, socket, financial value or executable field exists.
- `context` is null for pre-plan evidence kinds. For `gate-readback`, `attempt-result`, `unrelated-result` and `failure`, it has exactly `plan_sha256`, `control_id`, `control_generation`, `session_id`, `freeze_id`, `source_boot_id`, `observer_identity`, `interval`, `adapter_manifest_sha256`, `member_manifest_sha256`. Its values match the witness, including its interval. This avoids a self-hash cycle in pre-plan manifests.
- References have exactly `path`, `sha256`; paths are absolute normalized local POSIX paths beneath an explicitly selected reference root. The fixture builder creates paths under its temporary private root, never live paths. String labels or a `fixture-` prefix are not a production trust decision: even relabeled documents remain unaccepted.

Every referenced document must have a registered purpose and slot-compatible kind. Empty `records` cannot satisfy an evidentiary check. A nullable record ID is allowed for control-wide gate/failure facts only, not member coverage. `acceptance` evidence can retain a fictional review statement; it cannot set production acceptance. Unknown evidence kinds/fields fail syntax validation. Known adverse reason codes remain blockers.

Entry limits count every row in `controls`, `members`, `unresolved`, `objects`, `unrelated`, `records`, `errors`, `dependencies` and reference lists across the graph, including witness documents; enforce one combined 10,000-entry budget. Count each distinct loaded file's original bytes once toward 32 MiB, including the plan, scope and supplied witnesses. Repeated references are permitted when they bind the same file/hash; an active recursion stack detects cycles, with root plan at level one and a maximum depth of eight. These rules are shared by parser and resolver tests.

The private report purpose is `source-control-boundary-report`, schema 1. Exact fields: `schema_version`, `purpose`, `plan_sha256`, `scope_sha256`, `witness_sha256s`, `structurally_valid`, `coverage_complete`, `fictional_witnesses_complete`, `plan_accepted`, `blockers`, `enforcement_complete`, `real_execution_ready`. Blocker rows contain `code`, `control_id` (nullable), `object_id` (nullable), `evidence_sha256` (nullable). Reports contain no raw paths, document bodies or credentials. `plan_accepted`, `enforcement_complete`, `real_execution_ready` are always false; private object IDs remain private. Sort blockers by these four fields with null ordered before strings; sort witness hashes and set-like manifests for deterministic replay.

### Task 1: Closed plans, witnesses and fictional support schemas

**Files:** Create `tools/opening-controls/boundary_schema.py`, `tools/opening-controls/tests/boundary_fixtures.py`, `tools/opening-controls/tests/test_boundary_schema.py`.

**Interfaces:**
- Consumes: the existing constants and strict JSON/canonical helpers above.
- Produces: `BoundaryError(RuntimeError)` with public `code: str`; `parse_boundary_document(raw: bytes, purpose: str) -> dict`; `validate_boundary_plan(value: dict) -> dict`; `validate_boundary_witness(value: dict) -> dict`; `validate_boundary_report(value: dict) -> dict`; `member_manifest_sha256(members: list[dict]) -> str`.
- Test helper: `build_boundary_fixture(root: Path) -> dict` returns `plan_ref`, `scope_ref`, `witness_refs`, `documents` for a seven-control fictional bundle, with temporary-root paths and no live identities. `documents` maps logical fixture names to parsed dictionaries; changes are saved by `save_fixture_document(root: Path, name: str, value: dict) -> dict`, which returns its new original-byte reference. Fixtures never implicitly update referring hashes.

- [ ] **Step 1: Write failing tests with exact assertions.** Name tests `test_closed_plan_and_witness`, `test_decoded_duplicate_unknown_and_float_rejected`, `test_identity_interval_and_generation_types`, `test_dependency_cycle_and_bounds`, `test_support_slot_kinds_and_report_flags`. Accept the complete fixture documents; reject duplicate decoded `purpose`, unknown fields at every object level, any float/nonfinite, bool generation/PID, invalid hash, malformed nanoseconds, inverted interval, missing fields, dependency cycles and unsupported support purpose. Assert `BoundaryError.code` equals respectively `duplicate-key`, `unknown-field`, `number-form`, `identity-form`, `interval-form`, `dependency-cycle` or `document-purpose`, not merely any exception. Assert report validation rejects readiness/acceptance true as `readiness-forbidden`.

```python
def test_member_manifest_is_role_and_content_bound(self):
    h = member_manifest_sha256(self.members)
    self.assertEqual(h, member_manifest_sha256(list(reversed(self.members))))
    changed = deepcopy(self.members)
    changed[0]['role'] = 'contained-unknown'
    self.assertNotEqual(h, member_manifest_sha256(changed))
```

- [ ] **Step 2: Run RED.** `python3 -m unittest discover -s tools/opening-controls/tests -p test_boundary_schema.py -v`. Before code exists, only the new module import is the expected failure; stop on unrelated failures. Subsequent behavioral additions must show their exact expected rejection/assertion failure before the fix.
- [ ] **Step 3: Implement the interfaces.** Validate all exact key sets and kinds before returning parsed dictionaries; reject oversized raw bytes before decoding. Unknown adapter identifiers are syntactically valid IDs and get semantic blockers in Task 3; arbitrary executable fields are rejected here. The member hash is `digest(sorted([{object_id, object_sha256, role}, ...], key=object_id))`, not a hash of evidence paths. Report flags cannot be overridden by inputs.
- [ ] **Step 4: Run GREEN.** Repeat the focused command; require all named tests discovered, zero failures/errors/skips. Test exact 4 MiB boundary and 4 MiB + 1 without populating Git with large fixtures; Task 2 checks the combined graph entry budget.
- [ ] **Step 5: Commit.** `git add tools/opening-controls/boundary_schema.py tools/opening-controls/tests/boundary_fixtures.py tools/opening-controls/tests/test_boundary_schema.py` then `git commit -m "feat: define closed fictional boundary contracts"`.

### Task 2: Anchored private references and immutable readback

**Files:** Create `tools/opening-controls/boundary_refs.py`, `tools/opening-controls/tests/test_boundary_refs.py`; extend only the fixture helper as needed.

**Interfaces:**
- Consumes: `parse_boundary_document`, `BoundaryError`, typed reference slots from Task 1.
- Produces: immutable `LoadedDocument` fields `path: str`, `sha256: str`, `purpose: str`, `value: dict`; `ResolvedBoundary` fields `plan: LoadedDocument`, `scope: LoadedDocument`, `witnesses: tuple[LoadedDocument, ...]`, `documents: dict[str, LoadedDocument]` keyed by original-byte hash. `ReferenceSet(root: Path)` is a context manager; `load_plan(plan_ref: dict, witness_refs: list[dict]) -> ResolvedBoundary`; `verify_unchanged() -> None` revalidates every directory/file identity, mode, owner and original bytes before publication.

- [ ] **Step 1: Write failing tests.** `test_original_bytes_not_canonical_hash` accepts a correctly hashed whitespace variation but rejects its canonical hash as `reference-hash`; `test_root_owner_modes_and_link_rejection` rejects wrong UID, modes, hard links and symlink ancestors/leaves as `private-reference`; `test_stream_escape_and_same_byte_substitution` rejects wrappers/outside roots, and same-byte inode or ancestor replacement as `reference-substitution`; `test_cycle_depth_and_combined_budgets` accepts eight levels, rejects nine and a cycle with `reference-depth`/`reference-cycle`, and rejects 32 MiB + 1 or 10,001 combined entries as `reference-budget`. Shared DAG references remain valid and counted once for bytes. `test_slot_kind_mismatch` rejects misplaced cohort/baseline/manifest evidence as `reference-kind`.

Use real private temporary files for link/mode/hash tests on root Linux. For substitution, deterministically replace an inspected file or directory at the open/read/publication boundary using a controlled test hook; assert the original descriptor and current path mismatch is rejected even when bytes match. Assert a sentinel outside the reference root is never read. No test acquires live sockets or imports Paymenter. Non-root invocation fails the acceptance preflight; it is not a skip-based success.

- [ ] **Step 2: Run RED.** `unshare --net python3 -m unittest discover -s tools/opening-controls/tests -p test_boundary_refs.py -v` in an owned root Linux test environment. Expect missing resolver first; later cases must fail their exact code/identity assertion, never because `unshare` or a fixture setup failed.
- [ ] **Step 3: Implement the interfaces.** Require Linux and effective UID 0. Anchor root/ancestors using directory descriptors, `O_DIRECTORY|O_NOFOLLOW`, `dir_fd`, `fstat` and no-follow relative traversal; reject writable non-root ancestors (a root-owned sticky `/tmp` ancestor is permitted, never the private root itself). Open each leaf with `O_NOFOLLOW`, require UID/GID 0, regular mode 0600 and link count one, compare pre/open/post device/inode identity and original bytes. Keep anchored identities until final verification; do not rely on path resolution or a pre-open `lstat` alone. The root is 0700. Traverse only declared typed reference slots, not arbitrary dictionaries containing `path`. Close descriptors on all outcomes. Reject the whole graph on any parsing/reference failure; never silently drop a failed document.
- [ ] **Step 4: Run GREEN.** Repeat focused root-isolated tests, then Task 1 tests. Require zero skips, exact code assertions and original-byte hashes; retain failed race evidence privately, not in the public fixture set.
- [ ] **Step 5: Commit.** Stage only resolver, its tests and fixture-helper changes; `git commit -m "feat: bind boundary references to private original bytes"`.

### Task 3: Exact coverage, visible uncertainty and finite adapters

**Files:** Create `tools/opening-controls/boundary_coverage.py`, `tools/opening-controls/tests/test_boundary_coverage.py`.

**Interfaces:**
- Consumes: `ResolvedBoundary`, `member_manifest_sha256`, `CONTROL_CODES`.
- Produces: `evaluate_boundary(bundle: ResolvedBoundary, observer_identities: dict[int, dict], observer_boot_id: str, now_ns: int) -> dict`, returning the closed report. Task 3 initially returns `fictional_witnesses_complete=false`; Task 4 adds witness assessment without changing the signature. `observer_identities` maps witness PIDs to freshly read local process identities in Task 1's layout; `observer_boot_id` and `now_ns` come from the current local Linux boot and monotonic clock. Neither is supplied by plan input. A missing PID identity remains a witness blocker. Tests inject fictional identities/clock explicitly.

- [ ] **Step 1: Write failing tests.** `test_exact_once_coverage` matches every observed object to one control member or one explicitly evidenced unrelated assignment, sets `coverage_complete=true` for the complete fixture, and expects `object-missing`, `object-duplicate`, `object-added`, `object-hash-changed` for the corresponding mutations. Assert the expected blocker is present and `coverage_complete=false`, with no unexpected structural error. An unrelated declaration without matching exact scope and a passing preservation record gets `unrelated-unproved`. Members owned by one control may be referenced by another control's dependency/evidence, but must not be duplicated as owned members.

`test_unknown_containment_requires_complete_scope` keeps a fully enumerated contained-unknown member visible; missing broader-domain/precondition/blocking records yields `containment-unproved`. Shared worker/principal or unapproved collateral scope yields `shared-scope-unapproved`, not an unrelated classification. Scope errors `capture-invalid`, `capture-incomplete`, `mixed-generation` and `inventory-unsupported` always produce `capture-unacceptable`, even with passing containment records. Every unresolved row yields `unresolved-member`; none disappears because an ID resembles a known module. Changed source/target identity, cohort/baseline evidence and cyclic/missing control dependencies produce exact identity/reference/dependency blockers.

```python
def test_complete_fixture_never_accepts_production(self):
    report = evaluate_boundary(self.bundle, self.observers, self.boot_id, self.now_ns)
    self.assertTrue(report['coverage_complete'])
    self.assertFalse(report['plan_accepted'])
    self.assertFalse(report['enforcement_complete'])
    self.assertFalse(report['real_execution_ready'])
    codes = {b['code'] for b in report['blockers']}
    self.assertIn('fixture-adapter-not-production', codes)
    self.assertIn('native-signing-unavailable', codes)
```

`test_adapter_and_acceptance_inputs_cannot_escalate` expects `adapter-unavailable` for an unknown production adapter ID; fixture manifest hash/kind mismatch gets `adapter-manifest-mismatch`. A non-null acceptance reference, relabeled fixtures or a fabricated acceptance record never changes the three false flags. Assert all seven requirements are represented; omission gives `requirement-missing`.

- [ ] **Step 2: Run RED.** `python3 -m unittest discover -s tools/opening-controls/tests -p test_boundary_coverage.py -v`; expect the missing evaluator first, then exact behavioral failures for added cases.
- [ ] **Step 3: Implement `evaluate_boundary`.** Use a compiled adapter table containing only `fixture-boundary-v1` and its fixed evidence-kind/coverage requirements; no import, command, SQL or executable evaluation. Check manifest reference/hash and bind selected scope identities and evidence hashes. For each member, require passing precondition and blocking-check records for its exact ID/hash; contained-unknown additionally requires containment records for that ID/hash and no adverse shared-scope evidence. Exact unrelated records require both scope and preservation evidence. The fixture table describes fictional expectations only, not a production adapter. Sort report output deterministically. Append `fixture-adapter-not-production` for each fixture control and `native-signing-unavailable` once regardless of fictional success. Never infer acceptance from capture age, absent writers or scalar names. Preserve all unresolved/adverse claims in the private report.
- [ ] **Step 4: Run GREEN.** Run the new coverage tests and schema tests; require exact rejection codes and false readiness for every result, including a complete fixture and a non-null acceptance reference.
- [ ] **Step 5: Commit.** Stage evaluator/tests; `git commit -m "feat: assess fictional boundary scope without enforcement authority"`.

### Task 4: Witness bindings, freshness and adverse-state denial

**Files:** Modify `tools/opening-controls/boundary_coverage.py`; extend `tools/opening-controls/tests/test_boundary_coverage.py` and fixture helper.

**Interfaces:**
- Consumes: the same `evaluate_boundary` signature and complete resolved witness/support documents.
- Produces: `assess_witness(bundle: ResolvedBoundary, witness: LoadedDocument, observer_identities: dict[int, dict], observer_boot_id: str, now_ns: int) -> list[dict]` with report-layout blockers; `evaluate_boundary` sets `fictional_witnesses_complete=true` only when every control has one matching, fresh, fully evidenced fictional held witness. This flag does not set production acceptance.

- [ ] **Step 1: Write failing tests.** `test_witness_plan_members_manifest_session_and_generation` expects `witness-binding` on wrong original plan hash, members/roles hash, adapter manifest, source boot or context/session/freeze/generation mismatch. Reject duplicate witnesses for a control as `witness-duplicate`; missing control witnesses as `witness-missing`. Within one evaluation require one common session/freeze/generation across all seven controls, never select the newest input implicitly.

`test_observer_boot_process_and_monotonic_freshness` requires exact observer identity and observer boot agreement. With `MAX_AGE_NS=60*10**9`, accept age exactly 60 seconds, reject 60 seconds + 1 ns, future end/start, changed observer boot/PID/start ticks/executable hash as `witness-stale`, `witness-time` or `witness-observer`. There is no wall-clock or refreshed-receipt fallback.

`test_held_requires_actual_fixture_attempts` expects `witness-evidence-missing` for missing gate/readback, empty attempt results or incomplete unrelated checks; a timeout/transport/idle-absence record gets `witness-attempt-unproved`. Passing gate readback and blocked fictional writer attempts must cover every member's exact hash, with passing unrelated checks. `unproved`/`lost` state or any failure record gives `witness-state`/`witness-failure`; missing witness does not become held because coverage is complete.

`test_adverse_physical_claim_matrix_remains_blocked` parametrizes exact adverse `reason_code` values: `escaped-child`, `restart-outside-domain`, `existing-request`, `direct-socket`, `alternate-ingress`, `internal-error-ingress`, `privileged-writer`, `ddl-writer`, `keeper-death`, `keeper-transaction`, `schema-added`, `custody-disk-full`, `callback-unauthenticated`, `callback-conflict`, `verifier-lost`, `controller-killed`, `source-reboot`, `partial-establishment`, `stale-generation`, `unsafe-operator-release`, `target-changed`, `cohort-changed`. Each must yield `witness-failure` carrying the adverse evidence hash and keep `fictional_witnesses_complete=false`, `real_execution_ready=false`. Assert this code, not an unrelated parse error. These are contract rejection tests, not actual kernel/network/database/custody experiments.

- [ ] **Step 2: Run RED.** Run the coverage test command; require the new witness assertions to fail for absent assessment, while Task 3 scope assertions continue to pass.
- [ ] **Step 3: Implement `assess_witness` and integrate it.** Bind every witness context field to the control/plan, current observer boot and independently read identity for its PID. Multiple observer processes may supply witnesses on one boot; do not compare their PID to the CLI's own PID. A dead/reused PID or unreadable identity is a blocker, never an absent-writer proof. Age derives from the original interval end; any nested error/failure propagates denial. Attempts count as fictional blocking evidence only for exact member records with outcome `blocked` and reason `fixture-writer-denied`; gate/unrelated outcomes must be `pass`. Exclude timeout, transport and absence as proof. A complete fictional set can set only its explicitly fictional flag; do not issue a permit, update an existing controller, journal mutation intent or renew a genuine lease.
- [ ] **Step 4: Run GREEN.** Run focused coverage and schema tests; verify all matrix cases execute without skips and complete fixtures still contain the production-adapter/signing blockers.
- [ ] **Step 5: Commit.** Stage only evaluator/tests/fixtures; `git commit -m "feat: reject stale and unsupported boundary witness claims"`.

### Task 5: Local CLI and exclusive private reports

**Files:** Create `tools/opening-controls/boundary_cli.py`, `tools/opening-controls/tests/test_boundary_cli.py`; modify `tools/opening-controls/README.md` by adding a final section.

**Interfaces:**
- Consumes: `ReferenceSet`, `evaluate_boundary`, `validate_boundary_report`.
- Produces: `current_observer_identity(pid: int) -> dict`, reading only local Linux boot and that PID's start ticks/executable hash; `save_boundary_report(root: Path, raw: bytes) -> str`, returning a lowercase SHA256; `main(argv: list[str] | None = None) -> int`.
- Commands are exactly `validate` and `evaluate`. Both require `--plan PATH --plan-sha256 HASH --reference-root DIRECTORY --evidence DIRECTORY`; `evaluate` additionally accepts repeated `--witness PATH HASH`. No adapter operation, source destination, URL, socket, executable, SQL, shell or arbitrary import argument exists.
- Exit 0 means a syntax-only `validate` report was durably stored. Exit 3 means an `evaluate` report was stored but production readiness is false, including a complete fictional bundle. Exit 2 means any input/private-file/storage error; no success report. Stdout is one aggregate JSON object: `schema_version`, `purpose` (`source-control-boundary-cli-result`), `operation`, `document_count`, `blocker_count`, `report_sha256`, `real_execution_ready` (always false). Stderr contains only `boundary: CODE`; no supplied paths/values/exception text.

- [ ] **Step 1: Write failing tests.** `test_validate_is_syntax_only_and_evaluate_exits_not_ready` expects the above statuses/closed stdout and false flags; `validate` stores a closed report with all completeness booleans false and blocker `syntax-only`. `test_errors_never_echo_private_arguments` submits secret-like unknown args, malformed hashes, oversized files and invalid references; expect exit 2 and fixed `argument-form`/reference code, no private value on either stream. `test_live_observer_identity_not_cli_pid` checks an owned separate live observer is read by its supplied PID, detects PID/start/executable replacement before publication, and rejects an exited observer as `witness-observer`. `test_no_external_or_application_operation` fails test execution on any socket connection, subprocess start, app import, custody/controller construction or write outside its evidence directory. Allow read-only reference/local identity access only.

`test_exclusive_fsynced_report_and_failure_cleanup` requires evidence root UID/GID 0 mode0700, report mode0600/nlink1 and exact original bytes. Use an exclusive unique report filename, not an overwrite or reuse of an earlier success; retain no success acknowledgement on write/fsync/directory-fsync failure. Inject ENOSPC at write and fsync, and fail if report_sha256 is emitted. `test_reference_substitution_before_publication` changes a consumed file or ancestor after evaluation; require final reference revalidation to fail before any success receipt. An error leaves only a clearly failed owned artifact, never deletes or replaces someone else's file.

- [ ] **Step 2: Run RED.** `unshare --net python3 -m unittest discover -s tools/opening-controls/tests -p test_boundary_cli.py -v`; expect missing CLI first and exact new status/output assertions thereafter.
- [ ] **Step 3: Implement the interfaces.** Use a closed argument parser whose error override emits only `argument-form`. Validate reference/evidence roots with the anchored root rules; initialize no external runtime. Call `verify_unchanged` immediately before storing a report. Open a new ordinary file `O_CREAT|O_EXCL|O_NOFOLLOW` mode0600, write exact canonical report bytes, fsync file then anchored directory, verify identity/hash and return its hash only after success. Post-publication reference or identity failure withholds the aggregate receipt; do not label the file successful. Retire only files/descriptors this invocation owns. Read current boot/monotonic time and each supplied witness observer PID locally without subprocesses; build the identity map from those reads, not document assertions. Revalidate these identities before success receipt as well as files. Unreadable/dead observers are missing identities and produce denial blockers. A live observer passing identity checks still establishes no physical gate. Clock/identity injection belongs only to pure tests.
- [ ] **Step 4: Run GREEN and document usage.** Run CLI and resolver tests on root Linux with zero skips. Add README examples using placeholders `"$BOUNDARY_PLAN"`, `"$BOUNDARY_PLAN_SHA256"`, `"$BOUNDARY_REFERENCE_ROOT"`, `"$BOUNDARY_PRIVATE_EVIDENCE"`, `"$BOUNDARY_WITNESS"`, `"$BOUNDARY_WITNESS_SHA256"`; explain original-byte binding, private ownership, syntax-only exit0, evaluate exit3 and unavailable production adapters. No real endpoint, identity or private file path in examples. State that this CLI does not establish or restore controls and is not a native proof.
- [ ] **Step 5: Commit.** Stage CLI/tests/README; `git commit -m "feat: add private local boundary validation commands"`.

### Task 6: Native contract acceptance, independent review and publication

**Files:** No product changes; fix only newly added boundary files/tests and their README section if acceptance or review finds defects. Save operator scripts, candidate manifests, reports and receipts in a new private 0700 directory; none is a public repository file.

**Interfaces:**
- Consumes: the immutable generic candidate from Tasks 1–5, approved spec/plan hashes, unchanged native `ProofSchema::attestation(array $value, string $purpose): void`, original protected checkpoint.
- Produces: private candidate/test/rejection/privacy/preservation/publication receipts with false readiness; generic code published to the existing draft PR only. No deployment.

- [ ] **Step 1: Pin and stage the exact candidate.** Record hashes of every added/modified generic file, commit, spec and plan. Verify no generated cache, private value or unintended diff. Copy only these generic files into an exclusively owned Linux staging tree, record copied byte equality, and keep fixture/evidence roots root-owned0700. Check 1 GiB root/512 MiB RAM reserve before and after. Run without outbound connectivity; never use source configuration, callbacks, live database or application bootstrap in the test tree.
- [ ] **Step 2: Execute native tests.** In that owned tree run `unshare --net python3 -m unittest discover -s tools/opening-controls/tests -p 'test_boundary_*.py' -v`, then the existing full toolkit command `unshare --net python3 -m unittest discover -s tools/opening-controls/tests -v`. Require every expected test discovered, zero failures/errors/skips, focused names/matrix counts checked and successful teardown. A harness/preflight error is not RED or acceptance. If fixes change bytes, rerun the affected focused suite and full suite once on the final candidate; retain prior failures rather than rewriting evidence.
- [ ] **Step 3: Verify unchanged native proof separation.** In an outbound-isolated standalone PHP process, require only the existing pure `ProofSchema.php`. Feed one structurally valid BoundaryPlan, one BoundaryWitness and the actual generated BoundaryReport to `ProofSchema::attestation($value, 'account-opening-fence')`. Require exactly three `RuntimeException` rejections and zero acceptances; record the unchanged parser hash. Do not bootstrap Paymenter, supply a signing key, convert documents, install trust or run the opening command.
- [ ] **Step 4: Request one fresh whole-candidate review.** Preserve native execution: implementation is inline, then use the required fresh independent reviewer on the available inherited model. Give the immutable candidate, spec, plan, native evidence and explicit contract-only limitations. Reproduce consequential findings with exact-code failing tests, fix them and repeat affected/full acceptance. Document any remaining limitation; no Important/Critical finding may be waved through as fixture-only if it can promote readiness, expose private bytes or escape local scope.
- [ ] **Step 5: Run private-value and preservation checks.** Check the final complete publication diff against protected values on their host in an isolated read-only process; export counts/hashes only, require zero matches. Verify the complete protected runtime inventory and full held/QA/current-production database fingerprints against the pinned checkpoint, retaining the established historical session-only exception without rebasing. If anything unexpected changes, stop publication and reconcile. Verify copied/tested/publication files are byte-identical; no tests of physical containment or actual-cohort timing are claimed.
- [ ] **Step 6: Publish and retain the reviewable result.** Commit any reviewed fixes with exact files, copy identical generic changes to the existing public draft branch, push without force and read back its head/draft state. Attach the existing PR to this task. Retain root-private original-byte evidence, native transcripts, full candidate map, ownership/mode/hash readbacks, teardown and publication receipt. Update local continuation/readiness metadata only, preserving the original financial checkpoint. Report contract completion and the next physical-adapter design gate, never live readiness.

## Deferred requirements and next plans

The seven physical boundaries, durable mutation journal/controller, independent running verifier, external fencing and real signer are intentionally not implemented in this increment. Task 4 exercises their adverse claims as fictional rejection inputs; it proves no physical denial. Separate reviewed plans must implement fixed, versioned adapters and native acceptance for: scheduler/process lifetime on the exact cgroup-v1 platform; selective raw/normalized/internal/direct/persistent ingress and shared PHP scope; effective CLI/SQL/DDL authority plus the separate source-matched keeper; provider-specific durable custody/authentication and financial reconciliation; and target/cohort exclusion, establishment/recovery/operator retirement and signing isolation. Controller crash/reboot, actual boundary loss/external-fencing timing, deliberate release and forward recovery must be natively demonstrated there. A live source freeze, current frozen package, actual-cohort timing, signing/trust installation, openings, deassignment, activation and billing handover remain separately authorized operational gates.

Completing this plan leaves source ownership and persistent enforcement unaccepted. A fictional `held` witness is useful for validating contracts, but it cannot authorize production renewal or any native opening.
