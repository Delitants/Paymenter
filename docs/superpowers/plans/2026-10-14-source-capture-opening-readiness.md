# Source Capture and Opening Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task by task with the already selected native inline method. Steps use checkbox (`- [ ]`) syntax for tracking. Do not dispatch subagents or ask for another execution-method choice.

**Goal:** Make inactive financial openings possible in truthful capture/import/readiness order without weakening custody, financial approval, legacy recovery or complete target verification.

**Architecture:** Pre-capture leases bind known runtime and control facts; signed capture acceptance binds the completed snapshot and population. A later version-2 ready lease binds the actual isolated operator and final refreshed baseline, joined by complete immutable signed history. Existing transaction-bound financial permissions, full SQL comparison, allocator journal and cold-restore acceptance remain authoritative.

**Tech Stack:** Existing PHP/Laravel application, PHPUnit, OpenSSL RSA-SHA256, Linux root CLI/network namespaces, local Unix-socket MariaDB, existing private proof and opening machinery. No new dependency, financial table or schema migration.

**Spec:** [Approved capture/readiness design](../specs/2026-10-14-source-capture-opening-readiness-design.md). Read its closed field lists together with this plan; they are normative. This document plans implementation; it reports no passing implementation or native acceptance.

## Global Constraints

- Keep the source as billing authority until separately approved handover. No real freeze, capture, signing/trust installation, application deployment, collection, activation, hold release or provider operation follows plan approval automatically.
- Preserve inactive-only openings, all migration holds and account exclusions, exact debt and credit limits, shared-account ownership, immutable payment/support history, complete target baseline comparison, journal-bound recovery and independent cold-restore acceptance.
- Private identities, addresses, credentials, selected account lists, database names and real proof bytes remain outside Git. Publish generic implementation to the existing Paymenter fork review.
- Detached signature schema 1 remains `rsa-sha256`, RSA at least 3072 bits, over original payload bytes, with current revocation checks. Unknown fields, duplicate decoded keys, floats, noncanonical timestamps and unsupported version/purpose combinations deny access. Financial values remain strings.
- Proof/reference files remain ordinary root-owned `0600`, with root-owned private `0700` parents, safe ancestors, one hard link and read-time identity checks. References are exactly `{path, sha256}` with absolute ordinary paths.
- Control documents: 64 KiB each payload/signature/epoch/collector receipt; 4 MiB each history index; 32 MiB combined original control-document bytes; 10,000 lease records; eight reference levels. Enforce bounds before allocation/read completion. Separate existing large-data contracts remain unchanged.
- Capture/ready leases have positive UTC and monotonic lifetimes at most 60 seconds. Renew externally around ten seconds; never issue retrospectively. Monotonic strings are canonical unsigned decimals no greater than 9223372036854775807, using checked integer arithmetic in one observer clock domain.
- Capture acceptance has a positive interval at most 21600 seconds. Source skew bound is an integer in 0..2000 ms. Each collector wall duration differs from its own positive monotonic duration by at most two seconds. Never compare source and observer monotonic counters directly.
- Runtime identity is exactly the five `runtimeTargetIdentity()` fields. Ready identity is exactly those five plus `network_namespace_inode` and `operator_uid`, matching the actual isolated root operator.
- Schema-1 non-executable preparation, historical verification and strictly validated recovery remain unchanged. V2 requires a v2 executable bundle, ready lease, current pointer and human financial approval; no downgrade, fixed-pair fallback or replacement of original recovery lineage.
- No physical source-control installer, signer, web/queue/cron approval API, arbitrary command runner, timing-redesign work or new runtime route/schedule is part of this increment.
- Native acceptance uses independently owned disposable fixtures only, with zero skipped/incomplete/risky tests and retained original artifacts. Source, production and original QA remain untouched; do not broaden financial deltas or exempt session/support tables.

## Review Focus

1. A private file grows after opening or is replaced with equal bytes: deny read/authorization before unbounded allocation; preserve file identity, not only its checksum (Task 1).
2. One valid document is reached through several indexes: charge it once within one verification graph; do not reset the aggregate budget or overlook a cycle (Task 1).
3. A pointer returns to an older still-unexpired generation between account commits: reject regression, including after fresh-process recovery (Tasks 5–6).
4. Renewal and source collection share a whole-second timestamp, or nanoseconds approach the integer ceiling: accept legal ties without admitting overflow, a zero duration or a cross-host clock comparison (Tasks 2–4).
5. Recovery evidence disappears after a partial commit: report missing original evidence and deny further application without replacing proofs, deleting receipts or thawing gates (Task 6).

## File and interface map

Paths below are repository-relative. `O/` means `app/Services/BillmanagerMigration/Opening/`; `U/` means `tests/Unit/BillmanagerMigration/`; `F/` means `tests/Feature/BillmanagerMigration/`; `X/` means `tests/Fixtures/Opening/`.

| Files | Responsibility |
| --- | --- |
| Modify `O/PrivateProofFile.php`; create `O/ControlProofGraph.php`, `U/ControlProofGraphTest.php` | Bounded ordinary reads and one bounded verification graph |
| Modify `O/ProofSchema.php`, `O/SignedAttestation.php`; create `U/CaptureProofSchemaTest.php` | Explicit version/purpose schemas and original-byte signature dispatch |
| Create `O/OpeningFenceHistoryVerifier.php`, `U/OpeningFenceHistoryTest.php`, `X/CaptureProofFixture.php` | Complete signed history, prefix and interval verification; fictional parser fixtures |
| Modify `O/OpeningBundle.php`, `O/InactiveOpeningAuthority.php`; create `O/CaptureLineageVerifier.php`, `F/CaptureLineageTest.php` | V2 static bundle and capture/collector/population/import/preparation bridge; intermediate authority denial |
| Modify `O/OpeningFenceReference.php`, `O/InactiveOpeningAuthority.php`; create `F/OpeningReadinessTest.php` | Live v2 selection and financial authority integration |
| Modify `O/OpeningReceiptVerifier.php`, `O/OpeningBatchOperator.php`, `O/InactiveOpeningAuthority.php`, `O/OpeningFenceHistoryVerifier.php`; create `F/OpeningReadinessRecoveryTest.php` | Persisted version-aware evidence and recovery |
| Modify `U/OpeningTestProfileTest.php`, `F/OpeningIsolationTest.php`, `F/OpeningHistoryMutationTest.php`; create `F/OpeningVersionCompatibilityTest.php` | Legacy and isolation regression contracts |
| Create `X/ChronologicalOpeningFixture.php`, `X/capture-custodian-worker.php`, `F/OpeningChronologyTest.php`; modify `docs/account-opening.md`, `tools/opening-controls/README.md` | Genuine disposable native chronology and operator documentation |

Keep existing `OpeningProcessContext`, backup verifier, target-state delta comparison, journal/store, source preparation, cold probe and command entry points intact unless a narrowly required call-site change below is proved necessary. No modification is planned to their financial permission or persistence schemas.

Shared PHPDoc contracts, defined beside their producer and repeated by name at consumers:

- `ProofFile`: existing `{bytes:string,sha256:string,device:int,inode:int}`.
- `ProofRef`: `{path:string,sha256:string}`.
- `LeaseEntry`: `{payload:array,signature:array,payload_ref:ProofRef,signature_ref:ProofRef,payload_bytes:string}`. References and decoded payload/signature are derived from the same checked original bytes.
- `LeaseHistory`: `{reference:ProofRef,entries:list<LeaseEntry>}`; verified nonempty, globally ordered sequence 1 through head. The last entry is the head; no separate independently mutable head field.
- `CaptureLineage`: `{epoch:array,collector:array,acceptance:array,capture_history:LeaseHistory,preparation_history:LeaseHistory,first_ready:LeaseEntry}`. All fields represent already checked original records, not a financial permit.

New schema, history-verifier, lineage-verifier and reference-selector methods are static. Graph methods and `OpeningBundle::referenceRecord()` are instance methods; authority methods remain instance methods. `ChronologicalOpeningFixture::start()` is static and its other lifecycle methods are instance methods.

Each live authority check gets a new graph; static and current control references share that graph. Large snapshot/population/SQL/inventory/manifest bytes retain separate verification paths. Do not eagerly read new control references through the old unbounded `OpeningBundle::reference()` path before bounded verification.

## Execution and test environment

At implementation time use the worktree skill to obtain an isolated checkout, preserving the selected base and local work. Run pure schema tests without application/database bootstrap using `vendor/bin/phpunit -c phpunit.xml tests/Unit/BillmanagerMigration/CaptureProofSchemaTest.php`.

Commands below prefixed `NATIVE:` mean **inside the already accepted owned Linux fixture environment**, not on this Mac or against a shared database. Invoke the command after explicit `APP_ENV=testing`, `DB_DATABASE=paymenter_test`, root-owned ordinary local `DB_SOCKET`, distinct loopback-only namespace and `AUDIT_HOST_NET_NS`, private `OPENING_FIXTURE_ROOT`, protected `RUNTIME_FIXTURE_ROOT`/`RUNTIME_FIXTURE_GID`, external `OPENING_COLD_RESTORE_PROBE`, disabled funding, array mail and synchronous jobs have passed `tests/Fixtures/Opening/native-bootstrap.php`. The database name alone never establishes ownership: pin the disposable server's PID/start identity, datadir, socket, namespace and fixture root first. Reuse the accepted private native launcher/probe; do not add a product launcher or new host access.

Native test classes use `#[Group('opening-native')]`, committed-database lifecycle where needed, and fixture ownership checks before writes. Create baseline preservation evidence before staging; after staging retain at least 1 GiB + 128 MiB root free and at least 512 MiB each `/dev/shm` and MemAvailable plus the measured process budget. If unavailable, stop fixture setup without touching existing databases or deleting retained backups. This is a prerequisite to native execution, not a claim that live source controls are accepted.

For every task run RED before implementation, GREEN afterward and commit only the task's exact changed files. A RED must fail for the missing/new contract, not a failed environment prerequisite. `NATIVE:` is a label, not part of the shell command. Native commands use `vendor/bin/phpunit -c phpunit.opening-native.xml --fail-on-skipped --fail-on-incomplete --fail-on-risky` with the stated test selection. No clock override is added to live authority APIs.

### Task 1: Bound private control reads before allocation

**Files:** Modify `O/PrivateProofFile.php`; create `O/ControlProofGraph.php`, `U/ControlProofGraphTest.php`.

**Interfaces:**
- Consumes: existing `PrivateProofFile::read(string $path, int $ownerUid): array` and `StrictProofJson::decode(string $bytes): array`.
- Produces: `PrivateProofFile::read(string $path, int $ownerUid, ?int $maxBytes = null): array` returning `ProofFile`; existing two-argument behavior stays compatible.
- Produces: `ControlProofGraph::read(array $reference, string $kind, array $ancestors = []): array` returning `ProofFile`. Allowed kinds are `control` and `history`; ancestors are the root-to-parent absolute paths, initially `[]`. Constructor takes no options; owner is root, limits are fixed. `readPath(string $path, string $kind): array` reads an unreferenced graph root. `usage(): array` returns exactly `{bytes:int,documents:int}` for tests/diagnostics only.

- [ ] **Step 1 — Write RED tests.** `test_bounded_read_accepts_exact_size_and_denies_one_more` asserts 65536 accepted/65537 denied for control and 4194304 accepted/4194305 denied for history. `test_growth_or_equal_byte_replacement_during_read_is_denied` uses an owned child and synchronization to change the opened/named file during chunked reading; both deny, never returning bytes. `test_shared_document_budget_is_not_reset_between_indexes` asserts rereading the same immutable file leaves usage unchanged; distinct originals totaling 33554432 are allowed, one additional byte denies. `test_reference_depth_and_cycle_are_bounded` allows depth eight, rejects nine and a repeated ancestor. Retain permission, symlink, hard-link, URL/wrapper and read-identity negatives; a separate large SQL read still succeeds without the new control cap.
Core assertions below use values prepared by that task’s named test cases; they are test snippets, not code to run during planning.

```php
self::assertSame(65536, strlen(PrivateProofFile::read($path, 0, 65536)['bytes']));
// The sibling 65537-byte case must throw before returning bytes.
```

- [ ] **Step 2 — Run RED.** NATIVE: `vendor/bin/phpunit -c phpunit.opening-native.xml --filter ControlProofGraphTest --fail-on-skipped --fail-on-incomplete --fail-on-risky`. Expected failure: absent bounded-reader/graph contract.
- [ ] **Step 3 — Implement the interfaces.** Reject an oversized `fstat` size before reading; read bounded chunks with a remaining-byte allowance and at most one overflow probe, preserving all before/after metadata/ancestry checks. Share one aggregate allowance across roots, indexes and repeated references. Identify a unique original by device/inode plus original SHA; equal-byte copies with different identities remain separate originals. Before reading a new document, cap it by both kind allowance and remaining aggregate allowance; revalidate identities on reuse. The graph root is level zero, each followed reference adds one, maximum eight. Reject a path already on its current ancestor stack, but permit safe reuse on independent branches. No persistent cross-check cache.
- [ ] **Step 4 — Run GREEN.** Repeat Step 2; expected all cases pass, zero skipped/incomplete/risky. Also run existing `RuntimeProofFileTest` and private-file cases in `OpeningProofTest` with the native profile to prove no weaker file rules.
- [ ] **Step 5 — Commit.** Stage only the three named files; commit `feat: bound opening control proof reads`.

### Task 2: Dispatch exact capture and v2 proof schemas

**Files:** Modify `O/ProofSchema.php`, `O/SignedAttestation.php`; create `U/CaptureProofSchemaTest.php`.

**Interfaces:**
- Consumes: existing exact-key/hash/date and strict JSON validators; Task 1 bounded reads for new file-based attestations.
- Produces: unchanged `ProofSchema::attestation(array $value, string $purpose): void`, dispatching on explicit `(purpose,schema_version)`; new `controlEpoch(array $value): void`, `collectorReceipt(array $value): void`, `leaseHistory(array $value): void`, `runtimeTargetIdentity(array $value): void`, `openingTargetIdentity(array $value): void`, and `monotonic(mixed $value): int`. `leaseHistory` checks only the closed index/list/reference syntax and nonempty count at most 10000; cryptographic continuity belongs to Task 3.
- Produces: existing `SignedAttestation::verify`, `verifyBytes`, `verifyRuntime` signatures unchanged. New purposes/versions are recognized without trust widening; existing runtime/funding purposes and legacy schemas keep their contracts.

- [ ] **Step 1 — Write RED tests.** Data providers cover the spec's exact required keys for epoch/collector, capture fence and acceptance schema 1, ready fence and financial approval schema 2; every missing/extra key and unsupported pairing denies. `test_capture_uses_only_runtime_identity` accepts exactly five runtime fields and rejects a capture `target_identity` or future population/baseline field. `test_ready_requires_complete_operator_identity` requires exactly seven fields. `test_monotonic_is_canonical_and_overflow_safe` accepts `'0'` and `'9223372036854775807'`, rejects leading zeros, signs, floats, integers, exponent form and `'9223372036854775808'`; checked duration subtraction never multiplies a near-limit counter. Assert lease lifetimes 60 seconds accepted/61 denied, zero/negative denied; acceptance 21600 accepted/21601 denied; finite integer skew 0/2000 accepted, -1/2001/string/float denied; ID byte lengths 128/129, source identity 45/46 and canonical boot/PID/start ticks. `test_capture_signature_needs_explicit_purpose_allowlisting` rejects a cryptographically valid capture signature under old-purpose-only trust and accepts exact explicit fictional allowlisting. Original-byte mutation and RSA below 3072 remain denied.
Core assertions below use values prepared by that task’s named test cases; they are test snippets, not code to run during planning.

```php
self::assertSame(9223372036854775807, ProofSchema::monotonic('9223372036854775807'));
$this->expectException(RuntimeException::class);
ProofSchema::monotonic('9223372036854775808');
```

- [ ] **Step 2 — Run RED.** `vendor/bin/phpunit -c phpunit.xml tests/Unit/BillmanagerMigration/CaptureProofSchemaTest.php`. Expected failure: unsupported new schema/purpose or absent scalar validator, without database use.
- [ ] **Step 3 — Implement schema dispatch and validators.** Copy the closed field lists verbatim from the approved spec. Validate nullable predecessor only for capture sequence 1; hashes otherwise stay exact. V2 ready status retains `fenced`, acceptance status is `captured`; `not_before=issued_at`, positive bounded durations. Apply new exact identity shape rules to new capture/v2 records only; preserve legacy accepted shape behavior. Capture purposes bypass only the obsolete unconditional complete-target check, not signature/trust validation. File-based new control verification uses the fixed 64-KiB bounded read; shared graph callers use checked original bytes. Keep current signature schema and verification-time semantics unchanged.
- [ ] **Step 4 — Run GREEN.** Repeat Step 2; expected pass. NATIVE: run `--filter 'OpeningProofTest|RuntimeProofFileTest'` with the native command flags; expected unchanged legacy signature/schema behavior.
- [ ] **Step 5 — Commit.** Stage only the three named files; commit `feat: validate capture and readiness proof schemas`.

### Task 3: Verify complete immutable lease history

**Files:** Create `O/OpeningFenceHistoryVerifier.php`, `U/OpeningFenceHistoryTest.php`, `X/CaptureProofFixture.php`.

**Interfaces:**
- Consumes: Task 1 `ControlProofGraph`; Task 2 validators and original-byte signature verification with current trust/revocations.
- Produces: `OpeningFenceHistoryVerifier::verify(array $historyRef, ControlProofGraph $graph, string $trustPath): array` returning `LeaseHistory`; `assertPrefix(array $prefix, array $extension): void`; `assertCoverage(array $history, string $startedAt, string $completedAt, ?string $startedMonotonicNs = null, ?string $completedMonotonicNs = null): void`.
- Produces test-only `CaptureProofFixture::history(array $overrides = []): array` returning exactly `{history_ref:ProofRef,trust_path:string,records:array}`. Records contain fictional epoch, collector and signed acceptance references for later structural tests. It produces deterministic structural fixtures, not native chronology evidence; mutation overrides are confined to tests. All created private artifacts register with `ProofFactory` cleanup and require the native fixture root.

- [ ] **Step 1 — Write RED tests.** `test_complete_capture_to_ready_chain_preserves_exact_original_pairs` asserts sequence 1 null parent, then exact prior payload SHA and sequence +1, one capture-to-ready transition and fixed capture/ready facts. `test_missing_duplicate_skipped_reordered_or_replaced_ancestor_denies` covers each class and a validly re-signed same-sequence alternative. `test_utc_and_observer_monotonic_intervals_both_cover_without_gaps` accepts touching intervals and equal UTC issue seconds with strictly increasing monotonic issue; rejects either-domain gap, reversed issue order, negative/overlong duration, capture after ready and changing stable facts. `test_prefix_requires_original_pair_references_and_bytes` rejects changed ancestor payload/signature/reference identity. `test_all_ancestors_use_current_trust_and_revocation` revokes an old key/nonce after a successful verification and requires the next call to deny. `test_history_count_bound` calls `ProofSchema::leaseHistory()` on 10000 unique syntactically valid entries, rejects 10001 before signature traversal, and separately verifies a representative valid complete chain cryptographically. Bounds tests must stay under the aggregate byte cap rather than claiming 10000 valid records necessarily fit it.
Core assertions below use values prepared by that task’s named test cases; they are test snippets, not code to run during planning.

```php
$p = CaptureProofFixture::history();
$history = OpeningFenceHistoryVerifier::verify($p['history_ref'], new ControlProofGraph, $p['trust_path']);
self::assertSame(1, $history['entries'][0]['payload']['sequence']);
```

- [ ] **Step 2 — Run RED.** NATIVE: `vendor/bin/phpunit -c phpunit.opening-native.xml --filter OpeningFenceHistoryTest --fail-on-skipped --fail-on-incomplete --fail-on-risky`. Expected failure: absent verifier/fixture.
- [ ] **Step 3 — Implement history parsing and checks.** Read exact history schema 1 through the shared graph; validate nonempty list/count before traversing. Every original pair has verified signature/purpose/current revocation and strict fields. Verify historical signatures at the record's internally parsed actual `issued_at`, never a caller-supplied live authority time. Enforce all spec adjacency invariants, immutable common facts and stage-specific facts; compare ordered original pair references and bytes for prefixes, not decoded-object summaries. UTC intervals and observer-monotonic intervals must each overlap or meet; union coverage is inclusive at shared boundaries, while later live expiry remains exclusive. Both monotonic coverage arguments must be present together and belong to the epoch observer; collector source counters never enter this API. No signer, issuance, rollover or gap repair API is added.
- [ ] **Step 4 — Run GREEN.** Repeat Step 2; expected all history/limit/revocation cases pass. Repeat Task 1 graph tests to prove this traversal shares its bounded reader rather than pre-reading references.
- [ ] **Step 5 — Commit.** Stage only the three named files; commit `feat: verify continuous opening lease history`.

### Task 4: Bind completed capture to exact import and preparation

**Files:** Modify `O/OpeningBundle.php`, `O/InactiveOpeningAuthority.php`; create `O/CaptureLineageVerifier.php`, `F/CaptureLineageTest.php`; extend `X/CaptureProofFixture.php` only for this task's static fixture inputs.

**Interfaces:**
- Consumes: Task 3 history/prefix/coverage APIs; existing `OpeningBundle::snapshot()`, `context()`, `OpeningPreparation::sourceFacts()` and backup/release evidence contracts.
- Produces: `CaptureLineageVerifier::verify(OpeningBundle $bundle, ControlProofGraph $graph, string $trustPath): array` returning `CaptureLineage`. It checks static historical lineage and does not assert a live current head, grant a permit or install controls.
- Produces: unchanged `OpeningBundle::load/data/reference` signatures with explicit v2 executable dispatch; add `referenceRecord(string $name): array` returning a strict `ProofRef` without reading its target. `reference()` remains checksum-verifying; for new control names it uses a bounded graph read rather than the legacy unlimited path. Bundle construction validates all new control references in one graph before returning. `referenceRecord()` checks exact reference keys/path/hash syntax and bundle identity only; it is not evidence verification.

- [ ] **Step 1 — Write RED tests.** `test_completed_capture_binds_snapshot_population_import_and_preparation` asserts matching original snapshot SHA, snapshot UTC, population SHA and its snapshot binding, exact import ID/source, retention cutoff, source timezone and rolling two-year activation cutoff; first ready follows preparation history and accepted backup. `test_collector_uses_each_hosts_own_clock_bracket` uses deliberately disjoint source/observer monotonic magnitudes, accepts legal same-second positive durations, and rejects outside-source-bracket snapshot, reversed brackets, wall/own-monotonic discrepancy above 2 seconds, skew above 2000 ms and collection before first lease. `test_capture_acceptance_and_first_ready_are_actually_ordered` rejects acceptance before collector completion, readiness before acceptance/backup acceptance, readiness at or after acceptance expiry and missing history coverage through acceptance/preparation/ready. `test_changed_capture_epoch_or_import_facts_deny` mutates each epoch identity/boot/profile/fingerprint/runtime field, capture ID, receipt SHA, snapshot/population bytes, import ID and preparation prefix. Assert the complete fixture state is unchanged after each denial.
Core assertions below use values prepared by that task’s named test cases; they are test snippets, not code to run during planning.

```php
$lineage = CaptureLineageVerifier::verify($bundle, new ControlProofGraph, $trustPath);
self::assertSame($bundle->snapshot()->checksum(), $lineage['acceptance']['snapshot_sha256']);
self::assertSame($bundle->data()['preparation_history_sha256'], $lineage['preparation_history']['reference']['sha256']);
```

- [ ] **Step 2 — Run RED.** NATIVE: `vendor/bin/phpunit -c phpunit.opening-native.xml --filter CaptureLineageTest --fail-on-skipped --fail-on-incomplete --fail-on-risky`. Expected failure: absent lineage verifier.
- [ ] **Step 3 — Implement static bundle dispatch.** Keep schema-1 input/executable keys unchanged; v2 executable adds exactly the five fields/eight references from the spec. `freeze_receipt` is the first v2 ready pair. Enforce bounded new control reads during construction; do not eagerly call the unlimited legacy reference reader for them. Put an explicit v2-denial guard at `InactiveOpeningAuthority::assertFenceCurrent()` before its legacy branch for this intermediate commit, with `test_static_v2_bundle_has_no_legacy_execution_authority` proving denial before writes. Task 5 replaces that guard with full v2 verification; the intermediate parser must never activate partially implemented authority.
- [ ] **Step 4 — Implement static lineage verification.** Bounded-read epoch/collector/capture acceptance plus pair/history references through the same graph; exact hashes agree in bundle and every signed record. First capture is sequence 1, capture acceptance history contains capture leases only, preparation history extends it and contains capture leases only, and first ready follows its exact last pair. Check epoch observer/source identity and fixed profile facts in all relevant records; epoch/collector have no independent authority. Verify historical acceptance at internally recorded issuance with current trust; its next-stage ready issuance lies within its acceptance window. Use collector observer bracket for observer-history coverage, source bracket for snapshot UTC and own-clock duration checks, with the conservative signed skew bound; do not invent precision beyond whole seconds. Existing preparation/native owner/population/archive eligibility and exact sole import checks remain required. Complete preparation history covers collector, acceptance issuance and the entire interval through first readiness; external admission/closure observations are custodian obligations bound by the epoch profile, not application-generated enforcement claims.
- [ ] **Step 5 — Run GREEN.** Repeat Step 2 plus existing `OpeningPreparationTest` under the native profile; expected complete population/import checks pass unchanged and all mutations deny before financial writes.
- [ ] **Step 6 — Commit.** Stage only the named files; commit `feat: bind capture completion to opening preparation`.

### Task 5: Integrate v2 bundle, live pointer and separate approval

**Files:** Modify `O/OpeningFenceReference.php`, `O/InactiveOpeningAuthority.php`; create `F/OpeningReadinessTest.php`.

**Interfaces:**
- Consumes: Task 4 `CaptureLineage`; Task 3 full current `LeaseHistory`.
- Consumes: Task 4 v2 bundle dispatch and `OpeningBundle::referenceRecord(string $name): array`.
- Produces: unchanged `OpeningFenceReference::current(): array` for v1 callers; add `currentV2(ControlProofGraph $graph): array` returning exactly `{payload:ProofRef,signature:ProofRef,history:ProofRef}`. It requires the configured v2 pointer, checks bounded original sibling files and exact history head, and never uses fixed-pair fallback.
- Produces: existing `InactiveOpeningAuthority` public signatures unchanged; v2 `fenceProof(): array` returns exactly `{schema_version:2,payload_b64:string,signature:array,history:ProofRef}`. V1 returns its existing two-field evidence unchanged.

- [ ] **Step 1 — Write RED tests.** `test_v2_accepts_later_capture_and_ready_without_the_v1_capture_window_rule` validates a capture snapshot genuinely earlier than the first ready interval. `test_v2_requires_all_eight_references_and_five_lineage_fields` removes/mutates each spec field/reference; all deny. `test_v2_refuses_legacy_pointer_approval_or_fixed_fallback` covers mixed versions and capture-as-human-approval. `test_current_pointer_is_a_complete_sibling_generation` covers partial pair/index, wrong head, cross-directory references and same-sequence substituted proof. `test_live_authority_rechecks_expiry_revocation_identity_and_pointer_before_commit` changes each after initial validation and requires rollback. `test_pointer_regression_to_still_live_older_head_is_denied` pins the last selected proof and refuses an older valid head. Mutate all five runtime fields, operator namespace/UID, baseline/release/backup, population, exclusions and human scope; preserve complete state and all holds on denial.
Core assertions below use values prepared by that task’s named test cases; they are test snippets, not code to run during planning.

```php
self::assertSame($bundle->target(), $authority->fenceFacts()['target_identity']);
// After revoking its key and attempting a commit, the full state must equal $before.
self::assertSame($before, (new OpeningTargetState)->capture());
```

- [ ] **Step 2 — Run RED.** NATIVE: `vendor/bin/phpunit -c phpunit.opening-native.xml --filter OpeningReadinessTest --fail-on-skipped --fail-on-incomplete --fail-on-risky`. Expected failure: unsupported v2 bundle/pointer/approval contract.
- [ ] **Step 3 — Implement reference dispatch.** For a v2 pointer require exact schema keys, sibling payload/signature/history, complete checksummed generation and matching last pair. Application remains a reader: durable atomic publication belongs to the external custodian/test harness, not a new application issuer. Preserve the v1 `current()` return shape and fixed-pair behavior for v1 callers only.
- [ ] **Step 4 — Implement v2 authority dispatch.** Build one fresh graph per `assertFenceCurrent()`, run static lineage then full current history/prefix extension, match the actual seven-field operator and five-field capture subset, first-ready/bundle/current stable facts and exact baseline. Check live head at real current UTC with exclusive expiry, then current trust, release/backup and separately signed v2 human scope under their existing current-time rules. `assertApproved()` compares all five added approval lineage fields to the original bundle and its original identity. Retain immediately-before-commit authority rechecks inside `duringAccount()`. Same sequence may be reread only as the already selected original pair; monotonic/UTC/sequence/prefix regression denies. Save exact head bytes/signature and the checksum-bound current history reference from that same verified selection for receipt persistence. No public time override, financial permission or config flag is added.
- [ ] **Step 5 — Run GREEN.** Repeat Step 2 plus `OpeningCommandTest`, `HeldOpeningPermitTest` and `OpeningBatchTest` with native flags; expect v2 integration passes and original inactive transaction/hold behavior stays intact.
- [ ] **Step 6 — Commit.** Stage only the three named files; commit `feat: require v2 readiness lineage for v2 openings`.

### Task 6: Persist history evidence and resume original inactive batches

**Files:** Modify `O/OpeningReceiptVerifier.php`, `O/OpeningBatchOperator.php`, `O/InactiveOpeningAuthority.php`; create `F/OpeningReadinessRecoveryTest.php`. Extend `O/OpeningFenceHistoryVerifier.php` with the exact recorded-head check below; do not change the journal/store delta schema.

**Interfaces:**
- Consumes: Task 5 versioned `fenceProof()`; existing store serializes this object under its unchanged `delta.fence_proof` and hashes the entire original receipt.
- Produces: existing receipt verifier public signatures unchanged; private `historicalFence(OpeningBundle $bundle, array $proof): array` dispatches by original bundle/proof version. Add `OpeningFenceHistoryVerifier::assertRecordedHead(array $history, string $payloadBytes, array $signature): void` to compare exact stored evidence against the checked historical head. Only v2 `committedRows()` entries gain `fence_history:ProofRef`, taken from the just-verified original `delta.fence_proof.history`; v1 row shape stays unchanged. Add `InactiveOpeningAuthority::assertRecordedFenceNotBefore(array $priorFence, array $priorHistoryRef): void`, which verifies the prior complete history/head and its exact prefix in the freshly selected current history. The operator calls it for v2 rows; its v1 call to `assertFenceNotBefore(array $prior): void` remains unchanged.

- [ ] **Step 1 — Write RED tests.** `test_v2_receipt_retains_exact_head_and_history_reference` asserts the exact four evidence keys, original byte/signature equality, pinned history checksum, receipt hash and unchanged wallet/cash/journal IDs. `test_read_only_verification_needs_historical_lineage_not_live_approval` lets current lease and human approval expire and verifies committed facts with valid historical signatures/current trust; revoked historical key/nonce denies verification without rewriting financial rows. `test_partial_resume_requires_original_bundle_and_continuous_extension` interrupts after one commit, renews under continuous independent custody, starts a fresh operator in the same isolated namespace and resumes original IDs only. `test_missing_history_after_commit_denies_without_repair` removes one original ancestor/index, records complete retained state, and denies verify/apply without new approval, deletion or receipt mutation. `test_fresh_process_rejects_older_live_pointer` and `test_gap_new_epoch_or_new_baseline_cannot_resume_original_batch` prove persistent regression/gap/replacement denial. Sealed-batch replay denies; restore after live financial use remains ineligible.
Core assertions below use values prepared by that task’s named test cases; they are test snippets, not code to run during planning.

```php
self::assertSame(['schema_version', 'payload_b64', 'signature', 'history'], array_keys($proof));
self::assertSame($originalReceiptIds, $verifiedReceiptIds);
self::assertSame($retainedState, (new OpeningTargetState)->capture());
```

- [ ] **Step 2 — Run RED.** NATIVE: `vendor/bin/phpunit -c phpunit.opening-native.xml --filter OpeningReadinessRecoveryTest --fail-on-skipped --fail-on-incomplete --fail-on-risky`. Expected failure: absent v2 receipt history/recovery checks.
- [ ] **Step 3 — Implement version-aware historical replay.** V1 keeps exactly `payload_b64`/`signature`; v2 requires exactly `schema_version`/`payload_b64`/`signature`/`history`, bounded canonical base64 and checked history through a shared graph, original bundle lineage and exact recorded head. Reject base64 longer than 87384 bytes before decoding and decoded payload above 65536 bytes; signature and referenced control data retain graph limits. Use internally parsed historical creation times and current trust/revocation. Read-only verification does not call current pointer/live approval; apply/resume does. Route v2 operator resume checks through `assertRecordedFenceNotBefore()` and the original persisted `fence_history`; retain original batch/grant/bundle/allocator IDs and all existing full-state comparison. No database migration or rewrite of old proof JSON; missing evidence is an explicit denial, not repaired automatically.
- [ ] **Step 4 — Run GREEN.** Repeat Step 2 plus existing `OpeningBatchRaceTest`, `OpeningHistoryMutationTest` and `OpeningBatchTest` with native flags; expected exact full-state/allocator/recovery and sealed replay contracts remain intact.
- [ ] **Step 5 — Commit.** Stage only actual named changes; commit `feat: retain v2 custody history in opening recovery`.

### Task 7: Pin legacy compatibility and isolation boundaries

**Files:** Create `F/OpeningVersionCompatibilityTest.php`; modify `U/OpeningTestProfileTest.php`, `F/OpeningIsolationTest.php`, `F/OpeningHistoryMutationTest.php`.

**Interfaces:** Consumes Tasks 1–6 through existing prepare/apply/verify commands and native profile. Produces regression tests only; no new runtime interface or legacy policy switch.

- [ ] **Step 1 — Write RED tests.** `test_schema1_input_and_historical_receipts_keep_original_contracts` compares existing fixtures/receipt bytes and preparation output without upgrading them. `test_v2_cannot_borrow_any_v1_authority_component` enumerates input, executable bundle, current pointer, ready lease, human approval and receipt combinations; deny unsupported mixes before writes. `test_capture_evidence_cannot_authorize_web_job_cron_or_funding` passes signed capture/ready reports to existing ordinary funding/isolation paths; zero permits, provider invocations, mail, dispatch or HTTP. Extend profile test's required native class list with every new file touching private roots/database/processes. `test_v2_does_not_exempt_session_support_or_unrelated_rows` mutates these tables and requires full-baseline denial with original allowed deltas unchanged.
Core assertions below use values prepared by that task’s named test cases; they are test snippets, not code to run during planning.

```php
self::assertSame($originalV1ReceiptBytes, $readbackV1ReceiptBytes);
self::assertSame($before, (new OpeningTargetState)->capture());
// The existing AssertsOpeningIsolation assertions additionally require zero channels.
```

- [ ] **Step 2 — Run RED.** NATIVE: `vendor/bin/phpunit -c phpunit.opening-native.xml --filter 'OpeningVersionCompatibilityTest|OpeningIsolationTest|OpeningHistoryMutationTest|OpeningTestProfileTest' --fail-on-skipped --fail-on-incomplete --fail-on-risky`. Expected new checks expose any missing guard; if already satisfied, retain assertions as regression proof and report that no runtime change was needed.
- [ ] **Step 3 — Complete regression coverage.** Preserve existing fictional report/permit rejection, dispatch/mail/HTTP channel assertions and native-profile separation. Add only the named tests/profile membership; correct a discovered implementation gap in its owning earlier task rather than weakening an assertion or widening a financial delta allowlist. Do not make old fixtures masquerade as chronology acceptance.
- [ ] **Step 4 — Run GREEN.** Repeat Step 2. Run ordinary `vendor/bin/phpunit -c phpunit.xml --filter 'CaptureProofSchemaTest|OpeningTestProfileTest|OpeningAttemptJournalTest'`; expected pure coverage passes without privileged discovery. Full native suite remains the final gate in Task 8.
- [ ] **Step 5 — Commit.** Stage only named regression files; commit `test: preserve opening compatibility and isolation`.

### Task 8: Prove actual native chronology and document the result

**Files:** Create `X/ChronologicalOpeningFixture.php`, `X/capture-custodian-worker.php`, `F/OpeningChronologyTest.php`; modify `docs/account-opening.md`, `tools/opening-controls/README.md`.

**Interfaces:**
- Consumes: existing owned `ConsoleChild`, runtime/batch workers and `ColdRestoreProbe`; Tasks 1–6 proof/authority contracts.
- Produces test-only `ChronologicalOpeningFixture::start(): self`, `completeCapture(): array` (schema-1 preparation input reference), `prepareReady(): array` (exact `{bundle_path:string,approval_path:string,signature_path:string}`), `interruptOperator(): void`, `stopCustody(): void`, `evidence(): array` (private event/hash/identity manifest), `retire(): void`. Each stage runs only after the previous stage's checked completion. No parameter can inject clock times, future hashes or live paths.
- Worker interface: `php tests/Fixtures/Opening/capture-custodian-worker.php <private-control-file>`. The file is root-private and contains only the owned fixture root/socket/process identities and fixture-only key/control endpoints. It is created by `start()` and never accepted by a product command. Worker refuses non-testing ownership/configuration before operations; it is separate from the opening operator and makes no application/financial call while issuing renewals.

- [ ] **Step 1 — Write the real-order RED.** `test_legacy_sign_before_capture_cannot_truthfully_bind_later_results` attempts original v1 issuance before snapshot/population/baseline exist, then really collects/imports later; the old exact-hash/time constraints deny. Retain events and original bytes proving no dates/hashes were patched afterward. `test_actual_capture_import_backup_ready_and_inactive_opening_order` records actual UTC/`hrtime(true)` and observed process identities at each completed event; asserts first capture lease precedes actual collector invocation, snapshot/population hashing precedes acceptance, acceptance precedes real import/preparation, full dump/cold restore/release/inventory precede first ready, final bundle precedes separate fictional human approval, and inactive account commits follow current complete ready history. No Carbon/test clock, injected timestamps or pre-signed hashes are used in positive chronology.
Core assertions below use values prepared by that task’s named test cases; they are test snippets, not code to run during planning.

```php
$fixture = ChronologicalOpeningFixture::start();
$fixture->completeCapture();
$paths = $fixture->prepareReady();
$result = (new OpeningBatchOperator)->apply(OpeningBundle::load($paths['bundle_path']), $paths['approval_path'], $paths['signature_path']);
self::assertSame('sealed', $result['status']);
self::assertFalse(AccountWallet::sole()->active);
```

- [ ] **Step 2 — Run RED.** NATIVE: `vendor/bin/phpunit -c phpunit.opening-native.xml --filter OpeningChronologyTest --fail-on-skipped --fail-on-incomplete --fail-on-risky`. Expected failure: missing genuine chronology harness or unsupported new sequence, not a skipped prerequisite.
- [ ] **Step 3 — Implement the owned fixture lifecycle.** Reuse existing root-private/committed-database/cold-probe machinery without exporting real data or sharing original QA. Use a separate fictional source artifact and accepted test-only writer gates: an attempted fixture source write is physically denied throughout custody, and preparation writer admission is unavailable before acceptance and closed before final baseline/readiness. Source artifact collection writes actual completed snapshot bytes only after the first checked capture lease; record collector run/code/transport identity and conservative clock measurement. Renew leases in an independent child around ten seconds from actual observed times, writing/fsyncing new immutable pair and full index before atomic pointer rename and directory sync. Use PHP `fsync` for regular files and a fixed fixture-only `/usr/bin/python3` helper using `os.open(path, os.O_RDONLY | os.O_DIRECTORY)`/`os.fsync` for the validated owned directory; no user-selected executable or code. Select first ready only after actual preparation and accepted cold restart/restore, binding actual operator namespace created later. Keep fixture-only signing outside product code and outside operator calls. If durable directory synchronization fails, fail setup rather than claim atomic acceptance.
- [ ] **Step 4 — Add interruption and publication tests.** `test_actor_restart_with_continuous_custody_resumes_original_ids` interrupts only the operator; renewal persists and a fresh process in the original namespace verifies/resumes exact receipt IDs. `test_signer_or_observer_loss_keeps_gates_closed` stops custody, lets its actual short lease expire, refuses further commits, and proves a late replacement timestamp cannot cover the gap. `test_crash_at_each_publication_boundary_never_acknowledges_partial_generation` terminates an owned publisher after payload, signature, index and pointer staging; readers select the prior complete generation or deny, never acknowledge a partial next generation. The test-only controller latches failed custody and never auto-thaws gates. Structurally signed negative fixtures may deliberately contain invalid dates to test rejection; they are never labeled truthful positive chronology.
- [ ] **Step 5 — Run complete GREEN acceptance.** NATIVE: `vendor/bin/phpunit -c phpunit.opening-native.xml --fail-on-skipped --fail-on-incomplete --fail-on-risky --log-junit <owned-private-report-path>`. Expected exit 0; zero skipped/incomplete/risky; genuine chronology, fresh-process verification/resume, native race/restore/full-delta/hold and dispatch/mail/HTTP suites pass. Retain exact commands, versions, original proof pairs/indexes, actual event order, resource/process/socket identities and cold-restore evidence privately. Verify protected source/production/original-QA fingerprints and holds before/after; no real provider, payment, mail or VM activity. Retain/hash evidence independently, then retire only exact owned fixture children/socket/RAM and prove their identities absent and resources reclaimed. If any gate fails, fix its owned task and rerun affected plus consequential checks; do not publish implementation as accepted.
- [ ] **Step 6 — Update as-built docs and publish only verified generic changes.** Replace the proposed-only documentation section with explicit v1/v2 contracts, exact lineage/reference/version rules, external custodian obligations, historical versus live validation, missing-evidence recovery and remaining physical-source controls. State native results from actual retained evidence, never from this plan. Scan every changed generic byte against protected values in place without exporting secrets; run `git diff --check` and the existing project formatter on changed PHP files. Commit named fixture/docs files as `test: prove chronological capture and opening readiness`; push non-force to the existing fork branch, attach/read back the existing draft PR and verify exact published commit/bytes. No installed runtime or real trust changes follow the push. Obtain separate operational approval only after the real containment procedure and physical controls are concretely reviewable.

## Self-review and handoff

Coverage: encodings/private-file limits → Tasks 1–2; every closed record/version → Tasks 2–5; custody/clock/prefix rules → Tasks 3–4; native current-time financial approval → Task 5; immutable receipts/recovery → Task 6; legacy/isolation/full baseline → Task 7; genuine chronology/owned retirement/privacy/publication → Task 8. Each Review Focus item has an explicitly named owned test. Signatures and PHPDoc result shapes above are shared contracts; no task relies on an undefined production helper or a new financial table.

Before implementation, review this saved plan with the user as required by the writing-plans workflow. Preserve native inline execution. Implementation completion requires actual Task 8 evidence; plan/schema review alone cannot qualify source enforcement, start billing or authorize a live freeze/handover.
