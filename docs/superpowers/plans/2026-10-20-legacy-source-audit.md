# Audit-only Legacy Source Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first offline source-audit increment, proving complete bounded collection and change/exception reporting with fictional data, without financial or production authority.

**Architecture:** A strict descriptor and fixed query planner feed a transport-injected collector, pure analyzer and private evidence writer. The offline command uses only fictional transport input; no actual database/network adapter ships in this phase. The collector contract is exercised now so a later separately reviewed native adapter cannot bypass coverage, cleanup or authority checks.

**Tech Stack:** Python 3.11+, standard library, `unittest`, POSIX descriptor-based filesystem operations; no new dependencies, PHP/application bootstrap or database driver.

**Spec:** `docs/superpowers/specs/2026-10-20-legacy-source-audit-design.md`, approved conversationally after local commit `736e3e656c186ee7c7ed7ebaccbdb2498f1d4177`. Read that full document with this plan.

## Global Constraints

- First implementation phase uses fictional inputs and a fake transport for collector failures. A live adapter must pass a later exact-source compatibility/read review before invocation.
- No target database client, application bootstrap, web route, cron entry, callback registration, provider SDK, mail sender, ledger call or receipt-inbox ingestion.
- Maximum 2,048 cohort accounts, 4,096 subaccounts, 50,000 payments, 256 referenced methods, 256 currencies; 256 rows per page.
- 32 MiB total raw business-result bytes; 4 KiB maximum encoded row; metadata/diagnostics have a separate 64 KiB cap.
- 30 seconds from snapshot start through explicit rollback, 3 seconds per statement, 10 further seconds for bounded connection/child cleanup; transport setup has a 10-second bound.
- Authority interval is at most 30 minutes and must remain valid through finalization. Consume the attempt ID once before connection; failure or response loss does not restore it.
- One fresh connection and one read-only consistent snapshot; complete cohort sweeps, no increasing-ID/timestamp incremental watermark, no reconnect/resume/retry.
- Owner-only directories and no symlink traversal; files mode 0600 beneath a mode-0700 root; no overwrite or mutation of previous evidence.
- Every payment has `financial_authority=unqualified`; provider validation, manual authorization, allocation, source-transaction business atomicity and opening membership are `not_evaluated`.
- Detailed real rows stay server-side by default; this phase uses fictional rows only. No private identifiers, operational paths, hosts or credentials in repository files or general logs.
- Preserve existing native callback tools, application code, financial holds and billing authority. This plan authorizes no source/target connection, native fixture setup, deployment or handover.

## Review Focus

1. Duplicate decoded JSON keys, Unicode/numeric ambiguity or unexpected fields must not change the cohort/profile silently — Task 1 rejects them before claiming an attempt.
2. A source row reassigned between complete sweeps must appear missing from the old scope, without inferring deletion or reversing money — Tasks 3/4 preserve that distinction.
3. A byte cap reached inside a multibyte row or a final empty page lost after an exact row cap must leave the collection incomplete — Task 3 counts raw bytes before decoding and requires exhaustion.
4. Wall-clock expiry while monotonic deadlines remain valid, or a clock rollback, must prevent final success — Tasks 1/5 recheck authority and reject backwards UTC observations.
5. Symlink swaps, concurrent attempt claims and manifest publication/durability failure must retain failure evidence without overwriting an earlier attempt — Tasks 1/5 pin directory descriptors and use exclusive no-clobber finalization.

---

## File map and common types

Create all code below `tools/legacy-payments/source-audit/`. Keep sibling application/native qualification files unchanged. Use snake-case imports from this directory, consistent with the existing standalone tools; no packaging/install step.

- `models.py`: immutable records, enums, hard limits, `AuditError(code)` and clock protocol.
- `private_io.py`: bounded strict JSON/secure file primitives; no business rules.
- `descriptor.py`: schema/profile/cohort/authority validation.
- `query_plan.py`: closed five-table projection/count/keyset query definitions; no executor.
- `collect.py`: one-attempt bounded collection state machine and injected transport protocol.
- `analyze.py`: exact decimals, canonical row digests, comparison and fixed exceptions.
- `evidence.py`: attempt claim, immutable artifacts, sealing, prior-complete validation and safe summary.
- `audit.py`: offline-only composition/CLI.
- `README.md`: limits, fictional example, commands and native/live admission boundaries.
- `tests/fixtures.py`, `tests/test_descriptor.py`, `tests/test_query_plan.py`, `tests/test_collect.py`, `tests/test_analyze.py`, `tests/test_evidence.py`, `tests/test_audit.py`: generated fictional fixtures and task-owned tests.

`models.py` defines frozen `Descriptor` with `attempt_id, approval_scope_ref, not_before_utc, expires_utc, source_instance, database_identity, socket_identity, server_profile_hash, query_profile_hash, schema_hash, cohort_ref, account_ids, held_account_ids, previous_ref, output_root`; `Profile` with `profile_id, origin, hashes, field_specs, capabilities`; `Query` with `kind, dataset, sql, params, limit`; `Row = Mapping[str, str | None]`; `Dataset` enum (`accounts, subaccounts, payments, methods, currencies`); `Collection` with `state, origin, profile_binding, rows, coverage, lifecycle, reasons`; `Analysis` with `changes, exceptions, row_digests, payment_authority`; `EvidenceRef` with `attempt_id, manifest_hash, origin, collection_state, completion_token`; `PublicSummary` with only opaque attempt ID, explicit `origin=fictional`, fixed reason counts, coverage/completion flags and evidence integrity references. A completion token is a random delivery receipt for this offline artifact, never source/financial authority. IDs are decimal strings; no floats. `origin` is `fictional` in the shipped profile and CLI; no `native_verified` producer exists.

`Clock` supplies `utc_now() -> datetime` and `monotonic() -> float`; `SystemClock` is the CLI implementation. UTC timestamps are timezone-aware; tests use `FixtureClock`. `FieldSpec` pins scalar kind, numeric precision/scale and nullability per required field; the fictional profile is expressly not a compatibility claim about the source. Later live profiles need reviewed literal metadata/session statements, identity and cancellation behavior; none are admitted by this phase.

### Task 1: Strict inputs, private paths and authority

**Files:** Create `models.py`, `private_io.py`, `descriptor.py`, `tests/fixtures.py`, `tests/test_descriptor.py`.

**Interfaces:** Produce `read_private_json(path: Path, max_bytes: int) -> object`, `load_descriptor(path: Path, profile: Profile, clock: Clock) -> Descriptor`, `assert_current(descriptor: Descriptor, clock: Clock, last_utc: datetime | None) -> datetime`, and `open_private_root(path: Path) -> PrivateRoot` (context-managed directory FD). `PrivateRoot` offers exclusive relative file creation/read and directory fsync; validates ownership/modes and no symlink components without path re-resolution. `AuditError.code` is a closed safe alias; raw input/error text never becomes its message.

- [ ] Write `test_descriptor.py` failures for duplicate/unknown keys, BOM/invalid UTF-8, JSON numbers instead of string IDs, unsorted/duplicate/empty/2,049-account cohorts, held IDs outside the cohort, invalid hashes/profile, insecure/swapped paths, output equal to prior root, authority wider than 1,800 seconds, expired/not-yet-valid/final expiry and backwards UTC. Assert input files remain byte-identical.

```python
def test_descriptor_cohort_and_authority(self):
    self.assertEqual(load_descriptor(valid_path, profile, clock).account_ids, ("101", "102"))
    self.assertRaisesRegex(AuditError, "descriptor-cohort", load_descriptor, duplicate_path, profile, clock)
    self.assertRaisesRegex(AuditError, "authority-expired", assert_current, descriptor, expired_clock, None)
```

- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p test_descriptor.py -v`; require RED due to the absent new interface, not a malformed fixture/import path.
- [ ] Implement the frozen records and exact descriptor schema; validate the spec's source/cohort/profile binding. Bound descriptor JSON at 256 KiB; reject booleans/floats as numbers, duplicate decoded keys and noncanonical ID strings. `tests/fixtures.py` adds only its resolved parent tool directory to the import path; every test imports this helper before the new modules. Do not depend on caller PYTHONPATH or working-directory imports. IDs must fit the profile's declared source identifier precision. Use descriptor-relative POSIX no-follow FD operations; unsupported required filesystem primitives refuse safely.
- [ ] Run the same command; require all Task 1 tests PASS, including Review Focus 1/4/5 cases.
- [ ] Commit only these files: `feat: validate private source audit descriptors`.

### Task 2: Closed query planner

**Files:** Create `query_plan.py`, `tests/test_query_plan.py`; extend only fictional profile definitions in `tests/fixtures.py`.

**Interfaces:** Consume Task 1 types. Produce `build_plan(descriptor: Descriptor, profile: Profile) -> QueryPlan`; `QueryPlan.count(dataset: Dataset) -> Query` and `.page(dataset: Dataset, after_id: str | None) -> Query`. Profile/query hashes bind exact templates. Query parameters contain validated ID tuples/cursors and fixed limits, never SQL identifiers. No database imports or arbitrary query option.

- [ ] Write failures asserting exact projection fields and cohort-derived predicates for all five datasets; methods/currencies use distinct references; count/page predicates match. Pin generated SQL and bound parameters for a two-account fictional cohort. Assert no wildcard, history/contact/XML-content read, supplied table/column/function, status/time watermark or unlocked profile override. The payment projection contains all 11 presence flags and three nullable Boolean flags from the spec, never their original sensitive values.

```python
def test_fixed_projection_parameters(self):
    self.assertEqual(plan.page(Dataset.PAYMENTS, "7").limit, 256)
    self.assertEqual(plan.page(Dataset.PAYMENTS, "7").params, expected_payment_params)
    self.assertEqual(plan.count(Dataset.METHODS).sql, expected_distinct_method_count_sql)
```

- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p test_query_plan.py -v`; require RED for missing planner behavior.
- [ ] Implement fixed backtick-quoted table/column templates and parameter binding. Compile `CASE` predicates for link presence and nullable Boolean encodings from the closed fictional profile only. Unsupported/null/invalid Boolean outcomes remain distinct; no absent field defaults. Counts and keyset ordering use unique IDs and the exact same ownership predicates; do not interpolate runtime identifiers or reuse the broad exporter.
- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p test_query_plan.py -v`; require PASS.
- [ ] Commit: `feat: define bounded legacy audit query plans`.

### Task 3: Bounded collection with a fictional transport

**Files:** Create `collect.py`, `tests/test_collect.py`; extend `tests/fixtures.py` with `ScriptedTransport` (and reuse Task 1 FixtureClock).

**Interfaces:** Produce `collect(descriptor: Descriptor, profile: Profile, plan: QueryPlan, transport: AuditTransport, clock: Clock) -> Collection`. `AuditTransport` defines `open(timeout_s) -> SessionFacts`, `begin_snapshot(timeout_s) -> SnapshotFacts`, `execute(query: Query, timeout_s: float) -> ResultFrame`, `rollback(timeout_s) -> LifecycleFact`, `postflight(timeout_s) -> SessionFacts`, `close(timeout_s) -> LifecycleFact`. Postflight runs after rollback within the additional 10-second cleanup budget, using the same owned session and pinned profile only; close always runs even if postflight fails. `ResultFrame(raw_bytes: bytes, query_hash: str, session_id: str, eof: bool)` contains bounded canonical NDJSON with a closed header (dataset and ordered fields), row objects and final count; decode only inside the collector, counting the entire frame before decoding. Count-query frames use a single decimal-string count and the same query/session binding. Define `SessionFacts(origin, session_id, database_identity, socket_identity, server_profile_hash, schema_hash, engines, capabilities)`, `SnapshotFacts(origin, session_id, schema_hash, snapshot_id, captured_at_utc, database_timezone, isolation, read_only)` and `LifecycleFact(operation, session_id, state)` in `collect.py`; lifecycle state is exactly `observed`, `failed` or `unknown`. No timestamp or test label alone establishes any fact. No implicit retries or adapter-discovered SQL.

- [ ] Add failures for every hard cap/deadline; use generated rows rather than huge committed fixtures. Include exact cap plus empty exhaustion page, cap-plus-one, lost final EOF, multibyte cap, duplicate/missing/extra/reordered results, wrong ownership, dangling references, reassigned payment/subaccount, count mismatch, engine/schema/session changes, unsupported isolation/cancellation, setup/statement/snapshot timeouts, failure before/after begin, rollback/close uncertainty and response loss. Count every transport call and assert no retry or unrelated cleanup.

```python
def test_cap_exhaustion_and_no_retry(self):
    self.assertEqual(collect(*exact_cap_case).state, "complete")
    self.assertEqual(collect(*missing_final_page_case).state, "incomplete")
    self.assertEqual(failing_transport.open_count, 1)
    self.assertEqual(failing_transport.manager_operations, [])
```

- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p test_collect.py -v`; require RED for the missing state machine.
- [ ] Implement raw-byte/row accounting before decode; require exact row schemas and monotonic keyset IDs, same-snapshot counts/exhaustion and full cohort/reference coverage. Enforce 10-second setup, 3-second statement, 30-second snapshot-through-rollback and 10-second cleanup bounds via the injected clock and transport contract. Attempt rollback only on the owned begun snapshot; always close that owned transport. Postflight identity/schema/engine/capability disagreement or unknown cleanup makes incomplete. Preserve bounded partial rows/reasons; never fabricate postflight facts. The fake's declared observations are labeled fictional throughout.
- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p 'test_*.py' -v`; require PASS including Review Focus 2/3. No native process/database is started.
- [ ] Commit: `feat: collect complete bounded fictional audit snapshots`.

### Task 4: Exact changes and financially unqualified exceptions

**Files:** Create `analyze.py`, `tests/test_analyze.py`.

**Interfaces:** Produce `parse_decimal(raw: str, field: FieldSpec) -> ExactValue` (`original, canonical`), `canonical_row(dataset: Dataset, row: Row, profile: Profile) -> bytes`, `compare(current: Collection, previous: Collection | None, descriptor: Descriptor, profile: Profile) -> Analysis`. A supplied previous collection must be complete and share source/cohort/schema/profile/origin bindings; incomplete input is not analyzed as a complete audit.

- [ ] Write failures for raw/canonical decimal preservation and precision limits, excess scale/exponent/NaN/whitespace/float rejection, wallet four places, provider independent precision/currency, negative and signed-zero handling. Add lower-ID/status-only/unchanged-timestamp changes; first baseline, deleted-or-reassigned ambiguity, null flags, unknown status/method/currency, changed economics and elected holds. Hash both original and canonical values; a representation-only change remains observable without inventing economic effects.

```python
def test_exact_changes_never_authorize_money(self):
    self.assertEqual(parse_decimal("1.2300", wallet_field).canonical, "1.2300")
    self.assertEqual(report.changes[payment_key].kind, "changed")
    self.assertEqual(report.payment_authority[payment_key], "unqualified")
    self.assertEqual(missing_report.changes[payment_key].kind, "missing_since_previous_complete")
```

- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p test_analyze.py -v`; require RED.
- [ ] Implement integer/Decimal arithmetic without binary floats and deterministic UTF-8 canonical JSON hashes. Use exactly `first_seen, unchanged, changed, missing_since_previous_complete`; preserve old/new digests and changed field names. Fixed exception codes: `provenance_not_evaluated, elected_hold, link_present, link_unknown, refund_related, recurring_related, method_unknown, currency_unknown, currency_mismatch, source_status_unmapped, economics_changed, record_missing`. Malformed rows/decimals fail collection validation; valid but unsupported semantics remain complete with exceptions. Every payment keeps all five provenance fields `not_evaluated` and authority `unqualified`; no eligible state, FX, price/tax recalculation or aggregate-derived credit.
- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p test_analyze.py -v`; require PASS and byte-identical original previous evidence. All known/unknown source status codes remain informational only.
- [ ] Commit: `feat: report exact legacy audit changes without money authority`.

### Task 5: One-use claims and durable private evidence

**Files:** Create `evidence.py`, `tests/test_evidence.py`; reuse Task 1 private I/O.

**Interfaces:** Produce `claim_attempt(root: PrivateRoot, descriptor: Descriptor, clock: Clock) -> AttemptClaim`, `load_previous(root: PrivateRoot, ref: EvidenceRef, descriptor: Descriptor) -> Collection`, `seal_attempt(claim: AttemptClaim, collection: Collection, analysis: Analysis | None, clock: Clock) -> EvidenceRef`, and `summarize(ref: EvidenceRef, collection: Collection, analysis: Analysis | None) -> PublicSummary`. `AttemptClaim` owns the exclusive attempt directory/claim and pinned FDs; a failure never unclaims it. `EvidenceRef.completion_token` is present only for a successful complete seal. A caller-supplied previous reference must include it; incomplete references are refused.

- [ ] Write failing concurrent-process claim, symlink-swap and descriptor replacement tests, final expiry/backwards clock, prior manifest/hash/origin mismatch, exclusive publication, fsync/ENOSPC failure, partial result and lost-success-response tests. Assert no previous file overwrite/delete, no promotion of incomplete evidence, no customer/low-entropy per-record digest/raw exception in summary. Prior complete references are explicit inputs; there is no mutable automatic baseline pointer.

```python
def test_spent_claim_and_unacknowledged_baseline(self):
    self.assertRaisesRegex(AuditError, "attempt-spent", claim_attempt, root, descriptor, clock)
    self.assertGreaterEqual(summary.reason_counts.get("collection_incomplete", 0), 1)
    self.assertNotIn("account_ids", summary.as_dict())
    self.assertRaisesRegex(AuditError, "prior-completion", load_previous, root, unacknowledged_ref, descriptor)
```

- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p test_evidence.py -v`; require RED.
- [ ] Implement exclusive mode-0600 claim before transport open, retained partial artifacts, canonical per-dataset rows/digests, lifecycle/coverage/descriptor-and-query hashes and immutable manifest. Redact credential fields from descriptor fingerprint input; phase-one descriptors accept no credential values. Recheck authority and nondecreasing UTC before sealing and again after fsync immediately before returning a completion token. Generate a random 32-byte completion token in memory; put only its domain-separated SHA-256 commitment in the candidate manifest. Publish the manifest with an atomic no-replace primitive (exclusive same-directory hard link plus directory fsync is acceptable); clean only the owned temporary link after durable publication. Return the token in EvidenceRef only after every required file/directory fsync succeeds, never store or log it earlier. If publication/durability fails, return failure and no token; a manifest or acknowledgement file alone cannot admit a previous audit. `load_previous` requires a complete matching manifest, full content/hash/permission verification and a supplied token matching the commitment. A lost successful response leaves the attempt spent and cannot auto-advance a baseline. This token acknowledges local artifact finalization only, not human/source/provider/financial authority. Never report a failure as native acceptance or delete old attempts.
- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p 'test_*.py' -v`; require PASS including Review Focus 4/5. Disk-failure paths may retain only the spent claim/partial data when no further write is possible; never promise an impossible complete failure receipt.
- [ ] Commit: `feat: seal immutable private audit attempt evidence`.

### Task 6: Offline command, whole-increment verification and handoff

**Files:** Create `audit.py`, `README.md`, `tests/test_audit.py`; extend `tests/fixtures.py` with `make_demo(root: Path, clock: Clock) -> tuple[Path, Path]` for the documented descriptor/transcript example. Update only the new audit-tool files for integration defects.

**Interfaces:** Produce `run_offline(descriptor_path: Path, fixture_path: Path, clock: Clock) -> PublicSummary` and `main(argv: Sequence[str]) -> int`. CLI accepts only `--descriptor` and `--fixture`; output root/prior reference come from the descriptor. It loads a bounded fictional transcript and the closed fictional profile, claims the attempt, runs Tasks 2–5 and prints only canonical PublicSummary. `--host`, `--socket`, arbitrary SQL, live/profile override, scheduler and resume options are rejected. No real adapter is present or dynamically importable.

- [ ] Add end-to-end failures for all task error categories, incomplete/complete reports, second run of a spent ID, a changed older payment in a later fresh full sweep, held account and prior baseline mismatch. Patch socket/HTTP/SMTP/database-driver/subprocess/application/ledger entry points to fail if called; exercise successes and failures. Assert deterministic analysis and row digests for identical fictional inputs, allowing fresh attempt IDs, timestamps and completion receipts, no raw errors/paths/identifiers in stdout/stderr and no completion when evidence finalization fails. `origin=fictional` survives every manifest and summary; fixture success never claims source qualification.
- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p test_audit.py -v`; require RED.
- [ ] Implement composition only; put no business parsing in the CLI. Bound fictional transcript input to 33 MiB (32 MiB business plus metadata/framing), enforce decoded business caps independently and create no implicit output directories outside the validated root. Exit 0 only for durably complete fictional audit; exit 2 for refusal/incomplete evidence, with fixed diagnostics and no automatic retry. Document Python 3.11 floor, all caps, command, baseline rules, exact financial non-authority, retained failures and later native/live gates.
- [ ] Run `python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p 'test_*.py' -v`, then `git diff --check`. Require every new test PASS, zero warnings/unraisable shutdown failures and only intended new-tool paths in the diff. Do not rerun the unrelated callback suite without a new affected-path concern. Record the actual test count; do not predict it here.
- [ ] Run the documented fictional example in an exclusively owned temporary directory and inspect its manifest/completion reference, summary and exit status; no database fixture or network. Verify no private literals/fixtures or deployment data enter the staged diff. Commit: `feat: expose fictional offline legacy source audit command`.

## Review, publication and later admission

Use native execution in this session, preserving the user's earlier preference. Before starting, the owner must review this plan. During execution use `superpowers:executing-plans`; preserve task-by-task red/green evidence and commits. At completion obtain a fresh whole-change review under the execution workflow; do not spawn a team merely to review this plan.

A passing offline suite completes only the fictional audit-tool increment. Prepare the generic fork publication diff and review it for private data; do not merge/deploy or claim production readiness. The new generic files must eventually be reflected in the maintained fork, separately from private descriptors/evidence. Existing pinned callback worktrees remain untouched.

Native database semantics, driver output, cancellation, source interpreter availability, actual schema/version/engine identity and server-side durable storage remain unqualified. A later concrete proposal must identify an owned synthetic native database fixture and exact setup/cleanup authority before any native fixture is created. Fixture writes belong only to its isolated test setup, never the source adapter. A production reader needs an independently reviewed adapter/compatible profile and a fresh exact one-attempt execution/export packet; this plan contains neither. Do not replace them with a permissive stub or promote fictional profile facts to native authority.

No phase here posts credits, accepts provider validation, releases holds, sets financial openings, retires source billing or switches public access. Those remain independent reviewed migration work after the audit findings, with elected holds and full rollback requirements preserved.
