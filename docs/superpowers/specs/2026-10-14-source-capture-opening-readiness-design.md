# Source capture and opening readiness proofs

Status: written design for review. This document changes no application behavior, trust, source control or billing authority. Implementation planning, implementation, disposable native acceptance and live operational approval are separate stages. Native inline execution remains the selected method.

## Intent and success criteria

Make a frozen financial migration executable in honest time order. Capture authorization must exist before collection without predicting the resulting snapshot, population review or refreshed target hash. Later opening authorization must bind those exact completed results and continuously maintained source/target controls. No newly signed document may pretend it was issued earlier.

Keep the source as billing authority until separately approved handover. Preserve inactive-only openings, all migration holds and account exclusions, exact debt and credit limits, shared-account ownership, immutable payment/support history, complete target baseline comparison, journal-bound recovery and independent cold-restore acceptance. This design grants no collection, refund, settlement, activation, hold-release or provider capability.

Private identities, addresses, credentials, selected account lists, database names and real proof bytes remain outside Git. This document and eventual generic code belong in the Paymenter fork.

Success means a disposable native execution can obtain capture proofs, collect/import a genuinely later snapshot, accept its complete target backup, issue an opening-ready lease and apply only separately approved inactive openings. It must reject gaps, reordered evidence, changed generations and substituted source/population/target facts. A design review or fictional permit is not live control acceptance.

## Existing ordering problem

`InactiveOpeningAuthority::assertFenceCurrent()` currently requires snapshot time inside the original fence window and identical population-review and target-baseline hashes in that original proof and current renewals. `OpeningPreparation` requires the population review to contain the exact snapshot hash. `OpeningBundle::context()` requires that exact snapshot already have a matching import.

A sign-before-capture procedure cannot yet know these completed facts. Taking another snapshot afterward changes its exact snapshot/import lineage. Synthetic fixtures with convenient dates do not prove a truthful production issuance sequence. This is an unproved operational sequence; it is not evidence that the strict parser accepts an unauthorized opening.

The current target identity also includes the root operator's network namespace. Capture time must not require prediction of a namespace created later. Use the existing authenticated runtime identity before collection; bind the complete operator identity only at opening readiness.

## Selected approach and alternative

Use separate capture-time and opening-readiness evidence linked by immutable signed control history. Capture leases contain only already observed identities and control scope. A completion receipt binds the resulting snapshot/population. The first opening-ready lease is issued after refresh, preparation and backup acceptance and binds the final baseline and actual operator.

An alternative is a once-only population/baseline binding transition inside the old lease family. It still needs explicit signed predecessor/successor evidence and historical replay rules, and makes ordinary renewal semantics harder to audit. This design selects separate stages. Ordinary renewals cannot change their stage's fixed facts.

## Scope

Add a version-2 executable opening bundle and version-2 opening-ready fence, plus capture lease/acceptance purposes and bounded private history records. Reuse the existing strict private-file reader, detached RSA signatures, public trust, native process isolation, import preparation, full baseline/cold-restore verification and receipt/journal machinery.

Do not implement physical source cron/process/ingress/SQL enforcement, a signer, a credential store or a source-freeze command in this increment. Accepted external controls must supply truthful evidence. Existing observer reports, boundary plans, fixture gates and rehearsal permits remain ineligible. No new route, worker, schedule or configurable arbitrary command runner is added. The separately deferred timing redesign is not reopened.

## Authority and identities

1. The external custodian observes physical controls and issues proofs outside the application and opening operator's outbound-disabled namespace. Its private key is never read by Paymenter, a web request, a queue, cron or a test approval API.
2. The accepted control epoch binds custodian observer identity/boot, source identity/boot, control-profile hash, writer and incoming-payment boundary fingerprints, and the authenticated runtime target. Epoch changes require a new capture; they cannot be hidden in a renewal.
3. `runtime_target_identity` has exactly the five fields returned by `OpeningProcessContext::runtimeTargetIdentity()`: `machine_id_sha256`, `runtime_realpath`, `db_database`, `db_socket_realpath`, `db_server_uuid_or_fingerprint`.
4. Opening `target_identity` retains the existing seven-field identity: the runtime fields plus `network_namespace_inode` and `operator_uid`. It must equal the actual isolated root operator. Its runtime subset must exactly equal the capture-stage runtime identity. No comparison drops a runtime field.
5. The preparation writer is a specifically accepted target-refresh capability in the fixed external control profile. It grants no opening authority. All other held writers remain excluded; all source writers remain fenced. Ending refresh closes that capability before final baseline acceptance and opening readiness.
6. New purposes require explicit external public-trust allowlisting. Existing keys are not automatically broadened, and this implementation installs no real signer, key, trust or approval.

## Proof encodings and bounds

Retain detached signature schema 1 (`rsa-sha256`, RSA at least 3072 bits), signature verification over original payload bytes, current revocation checks, closed JSON schemas and strict hashes. Unknown fields, duplicate decoded keys, floats, noncanonical timestamps and unsupported version/purpose combinations deny access. Financial values remain strings.

All proof/reference files use the existing root-owned ordinary `0600` file and root-owned private `0700` parent requirements, including safe ancestors, one hard link and read-time identity checks. A reference is exactly `{path, sha256}`; paths are absolute ordinary files, never URLs or stream wrappers. Payload bytes and their detached signature are always retained together. Hashing a report alone does not authenticate it.

New control-graph limits are 64 KiB per payload/signature/epoch/collector receipt, 4 MiB per history index, 32 MiB combined original control-document bytes, 10,000 lease records, and eight reference levels. Count a unique original document once in the byte budget, but reject duplicated lease entries, reused sequence numbers and cycles. Snapshot, population review, preparation input/report, human financial approval, full SQL, target inventory and installed-file manifest retain their existing separate verification contracts; their large data bytes are not part of the control-document budget. Exhausting a bound denies further authorization while physical gates remain closed. No automatic epoch rollover is permitted.

## Closed records

The following names are normative. Every listed field is required, with no other fields. Detached signatures and trust keep their existing schemas.

### Control epoch

Root-private ordinary JSON, schema 1, purpose `account-opening-control-epoch`:

- `schema_version`, `purpose`, `issuer`, `freeze_id`, `source_capture_id`;
- `observer_identity`, `source_identity`, `source_boot_id`, `runtime_target_identity`;
- `control_profile_sha256`, `controlled_writers_fingerprint`, `incoming_payment_boundary_fingerprint`.

`observer_identity` is exactly `{machine_id_sha256, boot_id, pid, start_ticks}`; PID is a positive integer and start ticks a canonical unsigned decimal string. Freeze/capture IDs and issuer are nonempty bounded strings of at most 128 bytes; source identity retains the existing 45-byte bound. Boot IDs are canonical UUID strings. The epoch is not authority alone: every lease/acceptance signs its original SHA256. The accepted external profile defines source/target writer denial, admitted preparation writer and observer custody; an application-generated epoch cannot establish enforcement.

### Capture lease

Attestation schema 1, purpose `account-opening-capture-fence`. Common fields are `schema_version`, `purpose`, `issuer`, `not_before`, `expires_at`, `nonce`. Additional fields are exactly:

`issued_at`, `freeze_id`, `source_capture_id`, `sequence`, `status`, `source_identity`, `runtime_target_identity`, `control_epoch_sha256`, `controlled_writers_fingerprint`, `incoming_payment_boundary_fingerprint`, `previous_lease_sha256`, `issued_monotonic_ns`, `expires_monotonic_ns`.

Status is `fenced`; sequence is a positive integer. Sequence 1 has null predecessor and all later records reference the preceding lease's exact payload SHA256. Monotonic values are canonical unsigned decimal strings no greater than 9223372036854775807, parsed with checked integer arithmetic, from the epoch observer's one clock domain; they are not compared with another host's monotonic values. UTC uses the existing exact second-resolution format. `not_before=issued_at`; both UTC and monotonic lifetimes are positive and at most 60 seconds. The external custodian renews around every ten seconds and issues no retrospective lease. There is no population or target-baseline field.

### Collector receipt

Root-private ordinary JSON, schema 1, purpose `account-opening-collector-receipt`:

`schema_version`, `purpose`, `freeze_id`, `source_capture_id`, `control_epoch_sha256`, `source_identity`, `source_boot_id`, `collector_identity_sha256`, `collection_started_at`, `collection_completed_at`, `collection_started_monotonic_ns`, `collection_completed_monotonic_ns`, `source_started_at`, `source_completed_at`, `source_started_monotonic_ns`, `source_completed_monotonic_ns`, `snapshot_captured_at`, `snapshot_sha256`, `source_clock_skew_upper_bound_ms`.

The external collector records its run/code/transport identity in independently accepted private evidence represented by `collector_identity_sha256`. It begins only after a valid capture lease has been published and verified, and records completion only after full snapshot bytes are retained and checked. Custodian times and monotonic values bracket the entire invocation and transfer; source times bracket the source collector. Snapshot UTC must equal the actual snapshot field and fall within the source-local bracket. Source clock skew must be measured conservatively, including timestamp resolution/transport uncertainty, and its finite integer upper bound must be in 0..2000 ms; uncertainty above that bound denies acceptance. Each host's source/custodian monotonic bracket is checked only in that host's own clock domain and must have positive duration; UTC completion is not before start. Each wall duration must differ from its own monotonic duration by at most two seconds to account for whole-second timestamp resolution. A larger discrepancy or reversal denies acceptance. Source-clock offset is independently bounded by the recorded skew measurement, not by comparing raw monotonic counters between hosts. No backdating, clock-grace extension or automatic source clock adjustment is provided.

This receipt has no authority by itself. The signed capture acceptance binds its original bytes, and the independently accepted custodian/collector is responsible for the observations. Application cryptography cannot prove that an external trusted signer is honest or physically controls a source.

### Capture acceptance

Attestation schema 1, purpose `account-opening-capture-acceptance`, with the common fields above and exactly:

`issued_at`, `freeze_id`, `source_capture_id`, `status`, `source_identity`, `runtime_target_identity`, `control_epoch_sha256`, `capture_fence_sha256`, `collector_receipt_sha256`, `capture_history_sha256`, `snapshot_sha256`, `source_population_fingerprint`, `login_cutoff`, `activation_cutoff`, `source_timezone`.

Status is `captured`; `capture_fence_sha256` identifies sequence 1 of this capture epoch. `issued_at=not_before` is actual creation time after collector completion and completed snapshot/population verification. Its positive acceptance interval is at most 21600 seconds. `capture_history_sha256` identifies a complete immutable lease index covering collection start through completion; the receipt contains no future refreshed target baseline or operator namespace.

The source population fingerprint remains the exact SHA256 of the existing complete population-review bytes, which contain this snapshot SHA. Retention cutoff remains unchanged; activation cutoff is recalculated exactly two source-local years before the actual snapshot timestamp. Native preparation still verifies the complete selected/eligible source population, owner eligibility, exact import and immutable archives. A signed receipt does not replace these checks.

### Opening-ready fence

Purpose remains `account-opening-fence`, with explicit attestation `schema_version=2`. Retain every existing fence field and add exactly:

`source_capture_id`, `control_epoch_sha256`, `capture_fence_sha256`, `capture_acceptance_sha256`, `preparation_history_sha256`, `previous_lease_sha256`, `issued_monotonic_ns`, `expires_monotonic_ns`.

The old `target_identity` is the complete actual operator identity; `target_baseline_sql_sha256` is the completed post-refresh full baseline. Source/population values equal capture acceptance exactly. The first ready lease follows the last capture-stage lease with the next global sequence and predecessor hash. `preparation_history_sha256` pins the complete capture-stage index through that predecessor; it extends the capture-acceptance history without replacing any earlier record. Ordinary ready renewals keep this bridge hash, capture acceptance, final baseline, epoch, source/control/population and full target identity unchanged. Each renewal adds one global sequence/predecessor and fresh real issue/expiry/monotonic times. The existing positive 60-second lease maximum and current-time denial are retained.

The first ready proof is issued only after exact import/reconciliation, native non-executable preparation, complete baseline capture, independently accepted cold restore, target inventory and release readback. Its creation time must lie within the signed capture acceptance interval. The custodian must refuse preparation-writer admission before verified capture acceptance and refuse readiness until its accepted refresh/backup workflow has completed. Ready issuance must not precede backup `accepted_at` or capture acceptance creation. No pending preparation writer remains. Existing release and backup proofs still authenticate their actual complete results; the new ready fence does not substitute for them or grant `inactive_opening` by itself.

### History index and current pointer

History index schema 1, purpose `account-opening-lease-history`, has exactly `schema_version`, `purpose`, `leases`. Each ordered entry is exactly `{payload, signature}`, both ordinary checksum-bound references. Indexes are immutable, finite and nonempty. All signatures/purposes/revocations are checked. They contain the complete chain from capture sequence 1 to the stated head, not an unverified tail or a trusted summary.

The current version-2 `account-opening-fence-reference` contains exactly `schema_version`, `purpose`, `payload`, `signature`, `history`. Payload and signature retain the existing ordinary sibling requirements; history is also an ordinary sibling reference. Its head must be precisely that payload/signature pair. Version 2 requires this configured index; the legacy fixed payload/signature fallback remains available only for version 1. Publish a fully fsynced immutable pair and new history index before atomically replacing the pointer and synchronizing its directory. A partial pair, changed reference, substituted ancestor, mixed generation, invalid head or missing predecessor denies execution.

## Continuous custody and time ordering

Use one global lease sequence across capture and readiness. Every adjacent index entry must be the exact predecessor, sequence +1, same issuer/epoch/freeze/capture/source/control fingerprints, nondecreasing issue time and strictly increasing issued monotonic value. Equal-sequence reread is allowed only for byte-identical already selected evidence; duplicate entries in an index are forbidden. Capture leases cannot follow ready leases.

Adjacent lease validity intervals must overlap or meet in both UTC and the observer's monotonic clock. The union must cover the complete collector interval, capture acceptance issuance, refresh, readiness issuance and the selected current head. Capture-acceptance history must be an exact prefix of preparation history, which must be an exact prefix of current history. Acceptance issue time occurs after collector completion and before refresh begins. The first ready issuance follows completion of target preparation/backup acceptance. No gap is repaired by moving timestamps, deleting history or signing a replacement old generation.

Expired live leases deny further work; physical gates remain held. An operator process may resume the same batch only when the independently maintained epoch/history stayed continuous and its actual target identity, bundle, journal, committed receipts and current ready lease all match. A signer/observer outage that creates an uncovered interval cannot be cured by a new timestamp alone. Keep the incomplete batch inactive and gates closed for separately reviewed recovery. A new epoch/new capture does not authorize overwriting an existing wallet or silently resuming an old bundle.

The external custodian must refuse issuance if its observer identity/boot, clock behavior, physical controls or accepted profile changed. Reboots, process identity reuse, changed roots, keeper loss and unknown writers deny renewal. Source and target gates are retained until the operator exits or is independently fenced, including after TTL expiration. This stronger observation requirement does not make a database lock the primary control or qualify the separately unimplemented source adapters.

## Bundle, approval and version compatibility

Keep the schema-1 non-executable preparation input unchanged. The version-2 executable `billmanager-inactive-opening-bundle` retains all existing fields/references and adds required fields `source_capture_id`, `control_epoch_sha256`, `capture_fence_sha256`, `capture_acceptance_sha256`, `preparation_history_sha256`. Add exactly these reference pairs/records:

`control_epoch`, `capture_fence`, `capture_fence_signature`, `collector_receipt`, `capture_history`, `capture_acceptance`, `capture_acceptance_signature`, `preparation_history`.

`freeze_receipt`/`freeze_signature` now point to the first version-2 opening-ready fence, and `freeze_receipt_sha256` binds that exact payload. Do not apply the old capture-time-inside-original-ready-window comparison to v2: collection is checked against its earlier capture chain instead. All old snapshot/import/policy/population/release/full-baseline/backup checks remain mandatory. Hashes in bundle fields and referenced payloads must agree exactly.

Use version-2 `billmanager-inactive-opening-approval` for a v2 bundle. Retain its existing purpose/fields and add the same five bundle lineage fields. It remains separately signed after the final bundle exists, with exact original human decision, scope, accounts, inactive-only capability, baseline and release. Capture acceptance or an opening-ready fence can never serve as this human financial approval.

Schema-1 parsing, historical verification and existing strictly validated schema-1 recovery remain unchanged. A new v2 bundle requires v2 ready fences, current pointer and human approval; mixed versions, omitted references and fallback to the old time rule are rejected. Nothing upgrades an old receipt or rewrites its bytes. New live proposals for this migration must use the reviewed v2 protocol; legacy compatibility is not approval to issue a synthetic/backdated v1 bundle. A change to global legacy issuance policy is outside this increment.

## Components and verification dispatch

- `ProofSchema` and `SignedAttestation`: explicit version/purpose dispatch for the two new capture purposes and v2 ready/approval payloads. Validate `runtime_target_identity` for capture purposes and complete `target_identity` for ready/financial purposes; the old unconditional target-field check cannot be reused for capture records. No extra fields or implicit version inference.
- `PrivateProofFile` plus a control-graph reader: enforce the new per-document/combined graph bounds before allocation/read completion while preserving existing large SQL/inventory behavior. Keep ordinary-file identity and ancestry checks; an aggregate size check after unbounded reads is insufficient.
- `CaptureLineageVerifier`: validate epoch, original capture proof, collector receipt, completed capture acceptance, snapshot/population/import correspondence and the immutable preparation bridge.
- `OpeningFenceHistoryVerifier`: validate original-byte signatures and complete ordered prefix/extension, current head and stage-specific immutable facts, bounded time/sequence behavior and revocation. It provides no signer or control installer.
- `OpeningBundle`, `OpeningFenceReference`, `InactiveOpeningAuthority`: strict v2 references/approval and current-head validation with the same isolated operator and transaction-bound authority checks.
- `OpeningReceiptVerifier` and existing receipt evidence: persist/replay the version-aware historical chain without rewriting old rows or requiring a new live human approval for read-only verification.

Signed completed receipts are historical facts: validate their signatures under current trust/revocation at their actual recorded creation time, then validate the next stage occurred inside the appropriate acceptance window. The first ready lease must have been issued before capture acceptance expired. Later ready leases remain current-time checked; human financial approval and release/backup acceptance keep their existing current-time rules. A caller cannot turn an expired live lease into authority by supplying its own verification time. Historical receipt verification never invents a missing signature or interval.

## Native verification and persisted evidence

A dedicated capture-lineage verifier should validate the static capture evidence and preparation bridge; a history verifier should validate current complete-chain extension. `InactiveOpeningAuthority` then validates the current ready lease and existing exact financial approval immediately before each native account commit. Parsing, current trust/revocation, bounds, process identity and full baseline checks fail before authorization, never after returning a permit.

The original bundle pins all static references and the first ready fence. The current pointer selects only a verified extension. Capture/baseline/population/control changes require a new reviewed bundle, not renewal. Cache only validated immutable bytes with their original file identities; refresh the pointer, head, current-time and revocation checks on every authority check.

The database batch pins the same original bundle and approval hashes. Persist the exact version-2 fence payload/signature and checksum-bound current history index reference alongside each committed receipt's existing fence evidence. All ancestor pairs/indexes must remain retained and immutable for historical verification. Version-2 receipt verification uses its capture/ready lineage and historical signature windows; it does not require a live unexpired lease or current approval merely to read already committed facts. Current revocation checks remain in force; revocation must never delete or rewrite financial records. Missing evidence denies further application and is reported explicitly; it is not repaired with a new approval.

Do not widen the native delta allowlist or exclude session/support tables from baseline comparison. Preserve allocator-journal limits and transaction-bound inactive wallet/cash projection permissions. No schema migration or new financial table is required by this design; new proof/history references are in existing original-bundle and receipt evidence contracts, with explicit version-aware verification.

## Failure and recovery

Before any financial commit, a missing/gapped/expired/changed proof denies authority without repairing data. Retain diagnostic hashes/counts only in ordinary output; real payloads and signatures stay private. Do not automatically restore source gates or replay callbacks on an exception.

After an interruption, verify original committed IDs, journal and full expected state before resuming the same approved bundle. A sealed batch cannot initialize again. A new target baseline, approval, epoch or capture cannot replace original recovery lineage. Unsupported legacy/v2 mixing must fail before wallet/projection writes. Loss of continuous custody leaves controls closed and requires the separately accepted financial recovery path; expiry is never thaw authority.

Whole-database restoration remains ineligible after any live financial use. Existing inactive-only recovery constraints, exact source billing deassignment and later runtime/hold-release acceptance remain separate. None of these new proofs transfers billing authority.

## Acceptance

1. Reproduce the original ordering problem with real-time fictional collection/import, not only edited fixture dates. Verify a capture lease can be issued with no snapshot/population/post-refresh target hash and that later receipts are built from actual completed bytes.
2. Run a complete owned native chronology: valid capture lease, actual later collector start/completion, captured source artifact and complete population, signed capture acceptance, real held-copy import, non-executable preparation, full baseline/cold restore, real operator identity, first ready lease, separately approved fictional inactive opening and fresh-process receipt verification/resume. Never reset the clock or pre-sign future hashes. Keep source/target fixture controls physically held throughout.
3. Reject capture before its lease, source timestamp outside its bracket, uncertain/excessive clock skew, backwards/jumped wall or monotonic time, delayed/backdated issuance, missing/skipped/duplicated/reordered history, absent predecessor, signature/key/purpose/revocation failure, unknown fields and every exact limit plus one. Every denial preserves the complete financial fixture baseline or original already committed receipt-verified deltas.
4. Reject changed source/observer boot, capture ID, control profile, writer/notification fingerprint, runtime database/socket identity, operator namespace/UID, snapshot/population bytes, import ID, preparation history, backup/release/baseline, account exclusions or human scope. A new capture-phase lease after readiness is invalid.
5. Prove actor interruption with continued valid independent custody resumes original IDs; signer/observer loss or history gaps latch denial and retain gates. A crash between pair/index/pointer writes never publishes a half-generation. No caller may mint a capture or financial approval through the application.
6. Preserve schema-1 non-executable preparation and unchanged legacy verification/recovery tests. Explicit v2 evidence must never downgrade or borrow v1 approval. Existing report/fixture permit rejection, source/target isolation, dispatch/mail/HTTP channel assertions and complete native financial recovery tests still pass.
7. Keep source, production and original QA untouched. Require independently owned root-native fixtures, no skipped/incomplete/risky cases, retained original artifacts and actual owned process/socket/RAM retirement. Protected-value scans cover all published generic bytes. Publish implementation to the fork only after focused and consequential native checks; no runtime installation or real trust follows publication automatically.

Written-spec completion means this protocol is reviewable. It does not close physical source coverage, implement signing, prove native execution, freeze the source or authorize handover. The next stage after written-spec approval is a concrete implementation plan using the preserved native method.
