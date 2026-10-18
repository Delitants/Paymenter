# Legacy Payment Receipt Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Native inline execution was already selected; preserve that choice. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a disabled-by-default, bounded authenticated legacy receipt inbox with stable economic identities, immutable evidence and durable quarantine, without creating financial posting authority.

**Architecture:** This is the first independently testable increment of the approved pipeline. Authenticate transport and retain economic candidates plus separate observations transactionally; every accepted candidate remains pending source qualification. Financial classification/posting and the native source journal are later increments with the dependency contracts below.

**Tech Stack:** Existing PHP requirements (`^8.3 || ^8.4`), Laravel, `AccountAmount`, `StrictProofJson`, `CanonicalPolicy`, OpenSSL RSA-SHA256, and the project's owned Linux/MariaDB native test profile. No new package, production connection or SDK build is required.

**Spec:** [Native legacy payment journal and Paymenter adapter](../specs/2026-10-17-legacy-payment-journal-design.md), approved before this plan. The complete pipeline is deliberately split: this executable plan covers receipt contracts and quarantine; the later target financial adapter and source runtime bridge need their own implementation plans. This plan does not claim to implement those dependencies.

## Global Constraints

- BILLmanager remains the billing authority until a separate accepted handover. Source publication does not authorize deployment.
- No receiver endpoint, scheduled job, signing key, source plugin, native SDK compilation/loading, provider call, wallet opening, financial movement, hold release or public activation is added by this increment.
- Original provider bytes, required headers, merchant credentials and private keys remain server-only. This inbox contains only bounded signed receipt envelopes and their signatures, never original provider bodies or tokens.
- Preserve original four-decimal account amounts, accepted opening rounding, existing credit limits, shared-account financial ownership and elected holds. Source payment history remains history; it is not replayed as deposits.
- The economic identity and economic fingerprint survive schema versions, delivery retries and key rotation. A payload hash is observation identity, not economic identity.
- Existing login/password/OTP,SMTP configuration and final renewal totals are outside this increment and must not change;this plan adds no authentication,mail or pricing path.
- Monetary values are strings. The admitted wallet delta must fit `AccountAmount` exactly. No float, fresh FX, checkout tax or gateway fee calculation belongs here.
- Envelope limit: 64 KiB. This increment uses a separate 64 KiB signature-JSON bound and a 256 KiB protected trust-file bound before parsing/allocation. Source raw custody limits remain 1 MiB body plus 64 KiB required headers and are not implemented here.
- No framework startup or schema migration creates receipts, budget rows, wallets, keys, jobs or acceptance files. Durable inbox operations require explicit enabled configuration and fresh protected trust, and still authorize no money.
- Native acceptance requires an already accepted owned Linux fixture with an outbound-disabled namespace, an explicit root-owned local socket and fictional data. Never run a feature/native suite against a production/shared database or silently fall back to TCP/SQLite.
- Production BILLmanager/Paymenter host access,new environment provisioning,installation and capacity changes are not authorized by plan execution. The retained,previously accepted owned local Linux fixture may use its existing start/stop lifecycle only after fresh identity/isolation/capacity admission;no new VM or customer resource is allocated. If the owned native fixture is unavailable, retain pure-unit results and report native acceptance incomplete without requesting a disposable BILLmanager installation.

## Review Focus

1. An envelope/facts schema upgrade or provider ID differing only by case/leading zeros must not create an accidental duplicate or merge distinct effects: Task 1 golden-vector tests.
2. Two authenticated observations of one economic effect may have different bodies, nonces, validators or credential versions: Tasks 2 and 5 must retain both while preserving the original economic facts.
3. Valid transport signatures are not proof of native commit, opening exclusion or manual-settlement authority: Tasks 3 and 5 must retain only pending/quarantined records, with zero account writes.
4. A reused worker may see revoked/changed trust or reduced queue capacity between validation and commit: Tasks 3 and 5 must reread policy and deny atomically without deleting accepted evidence.
5. Concurrent first deliveries, quota exhaustion, process death and destructive schema rollback must preserve receipts and usage counters: Tasks 4–6 native tests, not an in-memory map.

---

## Contracts shared by the tasks

These choices refine the approved design for this increment; later plans must consume them without renaming economic identities.

### Closed envelope and economic format

Accept envelope schema versions `1` and `2` with exactly the same closed field set; version 2 is an encoding-compatibility test, not a different economic identity. Reject other versions. Root keys are `schema_version`, `purpose`, `issuer`, `not_before`, `expires_at`, `nonce`, `identity`, `economic`, `observation`. Purpose is exactly `legacy-payment-observation`. UTC validity uses the existing `ProofSchema::date` format and requires `not_before <= now < expires_at`, with positive validity no longer than 86,400 seconds.

All IDs are nonempty UTF8 strings of at most 512 bytes, with no C0/DEL controls; preserve case, leading zeros and Unicode bytes without normalization. Nonces and issuers are limited to 128 bytes. Reject duplicate decoded keys, unknown/missing fields, invalid UTF8, oversized/deep input, numeric financial tokens and unsupported enums before retaining anything. An accepted envelope must be byte-canonical under `CanonicalPolicy`; property ordering is normalized by the producer before signing. Bound this closed structure to eight nested containers and 256 decoded fields/items, checked by a bounded scanner before the shared JSON decoder. Do not change opening-proof schema behavior to admit legacy data.

`identity` keys: `source_instance`, `merchant_binding`, `source_payment`, `effect_kind`, `economic_effect_id`. Allowed kinds: `capture`, `refund`, `reversal`, `chargeback`, `manual_settlement`, `manual_unsettlement`, `manual_restore`. All are opaque original identities, not names of active credentials. The permanent effect key is SHA256 of `"legacy-economic-effect-v1\0"` plus `CanonicalPolicy::bytes(identity)`. Envelope/facts schema version, custody, amount, owner mapping, boot and epoch are excluded. This increment accepts only that fixed identity format; future format aliases require a separate DB migration/plan and cannot mint a second effect.

`economic` keys: `source_account`, `source_subaccount`, `parent_effect_key`, `wallet_currency`, `wallet_delta`, `wallet_components`, `provider_currency`, `provider_components`, `allocation_rule`, `allocation_evidence_sha256`. Parent is null or a lowercase 64-hex original effect key; captures/manual settlements have no parent, other kinds require one. Currency is three uppercase ASCII letters. `wallet_delta` is normalized by `AccountAmount::parse(...)->exact()`; require the original admitted representation to equal that exact four-decimal value. `wallet_components` is a list of 1–16 closed objects `{component_id, delta}` with distinct IDs in ascending bytewise `component_id` order and exact four-decimal signed deltas whose sum equals `wallet_delta` within `AccountAmount` bounds. Sum the bounded list with arbitrary-precision `BigDecimal`,then apply `AccountAmount` to the final sum,so component order cannot create an intermediate overflow that changes acceptance. Require canonical component order in the signed economic representation. This arithmetic checks the claimed decomposition only; it never qualifies the source allocation rule.

`provider_components` is a list of 1–16 closed objects `{component_id, amount}` with distinct IDs in ascending bytewise `component_id` order. Use exact canonical provider decimal strings: optional minus, no exponent/leading plus, no leading integer zeros except zero itself, 1–30 integer digits, up to18 fractional digits and at most64 bytes. Remove insignificant trailing fractional zeros and normalize signed zero without changing numeric value;the signed economic representation must already be canonical or it is refused. Original provider spelling/precision stays in the separate source-private validation/custody evidence referenced by the observation. Reject unsupported precision;do not round,truncate or translate it. `allocation_rule` is a bounded opaque ID and the evidence hash is lowercase 64-hex. The stable economic fingerprint is SHA256 of `"legacy-economic-facts-v1\0"` plus `CanonicalPolicy::bytes(economic)`. Schema version and observation data never enter it. The amount bounds and conservation check apply even when the signature is valid.

`observation` keys: `custody_id`, `journal_id`, `raw_sha256`, `validation_ref`, `validator_sha256`, `credential_version`, `commit_binding_ref`. These are bounded opaque references/hashes to server-private evidence; they cannot contain bodies, headers or secrets. References assert a source claim only: none makes the envelope a verified committed effect. This increment has no source capability registry that can promote them.

### Trust and output types

`config/legacy-payments.php` defines `receipt_ingress_enabled=false`, `trust_path=null`, `trust_reader_gid=null`; no posting switch is introduced. Only explicitly configured absolute paths may be read. Trust uses root-owned service-readable 0640 ordinary single-link files and the existing `RuntimeProofFile` custody rules. Add an optional read cap to that reader with unchanged default semantics; legacy loading always supplies 262,144 bytes. No cached trust snapshot may authorize another operation. Bound trust parsing to depth8 and4096 decoded fields/items before the shared decoder;key IDs must start with an ASCII letter and contain only letters,digits,dot,underscore or hyphen (maximum128 bytes),avoiding numeric-map-key coercion.

Trust keys are exactly `schema_version=1`, `purpose=legacy-receipt-trust`, `keys`, `revoked_keys`, `revoked_nonces`, `retained_byte_limit`, `retained_observation_limit`. Each key record is exactly `{issuer, public_key, purposes, sources}`. A source grant is exactly `{source_instance, merchant_binding, effect_kinds}`; no wildcard or fallback merchant is accepted. Both limits are positive integers within signed 32-bit range; absence/zero/invalid values deny ingress. These are logical retention quotas, not a claim about physical database free space. Deployment still needs separate physical capacity/reserve acceptance.

Signature JSON uses exactly `{schema_version:1, key_id, algorithm:rsa-sha256, signature_b64}`. Verify the original canonical envelope bytes with RSA at least 3072 bits and strict canonical base64. Issuer, purpose, source, merchant, effect kind, time and revocation must match protected trust. Do not reuse `SignedAttestation::verifyBytes` by weakening its closed opening purposes/trust schema. Reuse the established OpenSSL primitive and shared parser/custody helpers, with purpose-specific legacy verification. The application contains no signer. Fictional test keys exist only in fixture memory.

`AuthenticatedLegacyObservation` is a readonly transport-authenticated object, created only by its own `verify` factory after complete signature/trust/schema checks. Properties: `EffectIdentity identity`, `EconomicFacts economic`, `array observation`, `string payloadBytes`, `string signatureBytes`, `string payloadSha256`, `string signatureSha256`, `string issuer`, `string keyId`, `string nonce`, `string policySha256`. It has no `committed`, `included`, `posted` or financial-authority field. An original validation reference is evidence to preserve, not a capability token.

`LegacyRetentionResult` is readonly with `string effectKey`, `string observationKey`, `int observationId`, `string disposition` and `bool replay`. The only dispositions this increment can return are `pending_source_qualification` and `economic_conflict`. No movement ID, customer owner or source secret is returned. A replay is receipt retention only, never a provider/payment ACK or claim of financial completion.

### Durable inbox schema

Create one additive migration `2026_10_18_000001_create_legacy_receipt_inbox.php` with four InnoDB-compatible tables, no seeded rows:

| Table | Required columns/constraints |
| --- | --- |
| `legacy_effects` | ID; globally unique 64-char `effect_key`; 64-char `economic_sha256`; canonical identity/economic text; immutable creation time. No wallet/user/invoice FK or financial status. |
| `legacy_observations` | ID; restrict-delete FK `effect_id`; globally unique 64-char `observation_key`; original canonical envelope/signature text, their hashes, economic hash, policy hash; disposition enum above; immutable creation time. Payload/signature attributes hidden from ordinary serialization. |
| `legacy_receipt_conflicts` | ID; restrict-delete FKs to original effect and unique observation; expected/observed economic hashes; fixed `economic_facts_changed` reason; immutable creation time. |
| `legacy_receipt_budgets` | Unique `store_key=legacy-receipt-store-v1`; unsigned big-integer `retained_bytes`, `retained_observations`. This is the only mutable table; it contains no source trust or financial authority. |

Observation key is SHA256 of `"legacy-observation-v1\0"` plus the two original envelope/signature SHA256 values in fixed order. It deduplicates exact delivery bytes only. Distinct legitimate observations of the same effect remain separate rows. Store canonical JSON as text, not lossy numeric JSON casts. Retained logical bytes are the UTF8 byte lengths of unique stored envelope/signature text plus identity/economic text when a new effect is first inserted; counts include conflict observations. Exact replay costs zero additional quota. Physical row/index overhead is outside this logical counter and must not be advertised as free-space accounting.

Evidence model instance update/delete operations and direct instance creation outside an admitted inbox write scope are refused. The evidence-only Eloquent builder on the three immutable evidence models refuses bulk insert/insertOrIgnore/upsert/update/delete/increment/decrement and truncate;the inbox uses guarded instance saves only. The mutable budget model uses the ordinary builder for guarded instance saves,with old/new counters and quota checked by the retention guard. Bulk budget queries,like direct DB-facade SQL,are outside this model-instance guard boundary and are not exposed or used by the inbox. No mass-assignment endpoint is added. Append-only immutability is enforced by new model guards and tested; privileged SQL is outside their security boundary. Migration `down()` refuses if any evidence/conflict exists or usage is nonzero; it must not erase receipts to make rollback pass.

---

### Task 1: Permanent economic identity and golden vectors

**Files:** Create `app/Services/Accounts/Legacy/EffectIdentity.php`, `EconomicFacts.php`; `tests/Fixtures/LegacyReceipts/GoldenVectors.php`; `tests/Fixtures/LegacyReceipts/identity-vectors.json`; `tools/legacy-payments/check-identity-vectors.py`; tests `tests/Unit/Accounts/Legacy/EffectIdentityTest.php` and `EconomicFactsTest.php`.

**Interfaces:** `EffectIdentity::fromArray(array $value): self`, `key(): string`, `data(): array`; `EconomicFacts::fromArray(array $value): self`, `fingerprint(): string`, `delta(): string`, `data(): array`. Readonly constructors are private; the validated data uses the exact closed formats above. The pure unit tests extend `PHPUnit\Framework\TestCase`,not Laravel `Tests\TestCase`,so no framework/database bootstrap occurs. The test-only `GoldenVectors::identity(): array` and `economic(): array` return fictional complete records; vectors contain independently frozen canonical bytes and expected hashes.

- [ ] Write failing tests asserting that `source_payment='001'` differs from `'1'`, case changes remain distinct, identical identity data yields the same fixed key, amount changes keep that key but change the economic fingerprint, and observation/schema version cannot enter the closed identity/facts objects. Test `7.5001 + (-2.0000) = 5.5001`,canonical provider numeric spellings with originals retained only as observation references,and reject numeric tokens, noncanonical money, duplicate/unsorted component IDs, final overflow and sum mismatch. Cover cancelling large components whose intermediate sum exceeds `AccountAmount` but whose final exact sum fits;do not use rounding or order-dependent arithmetic.
- [ ] Run `vendor/bin/phpunit tests/Unit/Accounts/Legacy`; retain the expected missing-class failures.
- [ ] Implement the exact interfaces using the existing canonical byte helper and `AccountAmount`. Freeze identity and economic domains permanently; do not add versioned delivery fields to either.
- [ ] Freeze literal expected bytes/hashes using independent PHP/Python encoders with `ensure_ascii=False`, compact separators and bytewise key sorting over these bounded UTF8 vectors. Include UTF8, escaping, arrays, null parent, leading zeros and case. Match the established canonical helper's escaping of U+2028/U+2029 explicitly (Python must escape those after its Unicode JSON encoding);include both in fixed vectors rather than assuming the encoders agree. The Python checker reads only the synthetic vector JSON, uses standard-library `hashlib/json`, and refuses missing/changed expected values; it generates no source receipts or keys.
- [ ] Rerun both PHP tests and `python3 tools/legacy-payments/check-identity-vectors.py`; expected all vectors pass with zero remote/DB calls. Mutation-check omission of a tuple member, a wrong domain and a float conversion against the assertions.
- [ ] Commit only this task's files with message `Add stable legacy economic identity contracts`.

Representative Task 1 assertions (fixture identity uses source payment `001`):

```php
$id = GoldenVectors::identity();
self::assertNotSame(EffectIdentity::fromArray($id)->key(),
    EffectIdentity::fromArray([...$id, 'source_payment' => '1'])->key());
self::assertSame('5.5001', EconomicFacts::fromArray(GoldenVectors::economic())->delta());
```

### Task 2: Bounded closed envelope parsing

**Files:** Create `app/Services/Accounts/Legacy/LegacyEnvelope.php`, `BoundedLegacyJson.php`; create `tests/Fixtures/LegacyReceipts/LegacyReceiptFixture.php`; test `tests/Unit/Accounts/Legacy/LegacyEnvelopeTest.php`.

**Interfaces:** `BoundedLegacyJson::decode(string $bytes, int $byteLimit, int $depthLimit=8, int $itemLimit=256): array` checks size and structure before invoking `StrictProofJson`. Byte limits must be positive and no greater than262,144,container-depth limits1–8,and item limits1–4096;root object depth is1 and each object field/array element counts once,with nested fields/elements additionally counted; `LegacyEnvelope::parse(string $bytes): self` exposes readonly `int schemaVersion`, `EffectIdentity identity`, `EconomicFacts economic`, `array observation`, `array data`. `LegacyReceiptFixture::payload(array $rootOverrides=[]): string` makes complete canonical synthetic envelopes with source payment `001`,wallet delta `5.5001`,issuer `fictional-source`,validity `2026-01-01T00:00:00Z` through `2026-01-02T00:00:00Z` and synthetic source/merchant IDs; nested overrides must be explicit complete arrays, not a permissive production merge.

- [ ] Write failing byte-reader tests using the generic valid object `{"p":"..."}` with65,528 versus65,529 ASCII padding characters (total65,536 versus65,537 bytes). The bounded decoder admits only the former;the envelope schema rejects the padding field in both,so do not invent a padding-enabled envelope to reach its cap. Add envelope tests for excessive nesting/items, duplicate decoded keys including escaped duplicates, invalid UTF8, unknown/missing root/nested keys and body/header/token fields. Reject noncanonical envelope bytes rather than resigning or rewriting them.
- [ ] Add tests comparing versions 1 and 2 of the same economics: effect key/fingerprint equal; nonce, observation, issuer and validity changes do not affect those values; a changed economic amount conflicts instead. Reject versions `0`, `3`, strings and floats.
- [ ] Run `vendor/bin/phpunit tests/Unit/Accounts/Legacy/LegacyEnvelopeTest.php`; confirm the intended parser/class failure before implementing the two interfaces. Use a lightweight bounded structural scanner, with no unbounded decode or new parser dependency.
- [ ] Implement the exact closed contracts and bounds. `parse()` performs no clock, signature, database, filesystem or financial action; successful parsing is explicitly unauthenticated.
- [ ] Rerun all `tests/Unit/Accounts/Legacy` tests. Expected: all pass, including unchanged original signed bytes and economic identity through two envelope versions.
- [ ] Commit with message `Add bounded legacy observation envelope parser`.

Representative `test_new_schema_and_observation_preserve_economic_identity` assertions:

```php
$a = LegacyEnvelope::parse(LegacyReceiptFixture::payload());
$data = $a->data;
$data['schema_version'] = 2;
$data['nonce'] = 'delivery-two';
$data['observation']['custody_id'] = 'custody-two';
$b = LegacyEnvelope::parse(CanonicalPolicy::bytes($data));
self::assertSame($a->identity->key(), $b->identity->key());
self::assertSame($a->economic->fingerprint(), $b->economic->fingerprint());
```

### Task 3: Purpose-specific transport verification and protected trust

**Files:** Create `app/Services/Accounts/Legacy/LegacyTransportTrust.php`, `LegacyTrustReader.php`, `AuthenticatedLegacyObservation.php`, `LegacyClock.php`, `SystemLegacyClock.php`; modify `app/Services/BillmanagerMigration/Opening/RuntimeProofFile.php` only to add an optional bounded-read argument; create tests `tests/Unit/Accounts/Legacy/LegacyObservationVerifierTest.php`, `tests/Feature/Accounts/Legacy/LegacyTrustCustodyTest.php`; add fictional signing helpers to `LegacyReceiptFixture.php`.

**Interfaces:** `LegacyTransportTrust::fromBytes(string $bytes): self` validates the exact trust schema; `LegacyTrustReader::read(string $path,int $readerGid): LegacyTransportTrust` uses `RuntimeProofFile::read($path,$readerGid,262144)`; `RuntimeProofFile::read(string $path,int $readerGid,?int $maximumBytes=null): array` retains its existing return shape and default behavior. `AuthenticatedLegacyObservation::verify(string $payloadBytes,string $signatureBytes,LegacyTransportTrust $trust,DateTimeImmutable $now): self` performs full verification and is the sole factory for its private constructor. `LegacyClock::now(): DateTimeImmutable` is an interface; `SystemLegacyClock` returns current UTC. Fixture interfaces: `LegacyReceiptFixture::keyPair(int $bits=3072): array` returns in-memory `private_pem`/`public_pem`;`signature(string $payload,array $keyPair): string` returns closed signature JSON;`trust(array $keyPair,array $rootOverrides=[]): array` returns complete fictional allowlisted trust;`withProtectedTrust(array $trust,Closure $test): mixed` creates only a fictional policy file with the required root/service-group custody under the accepted protected fixture root and invokes `$test($policyPath,$readerGid)`,then retires only its owned file/directory. It does not depend on the later inbox. Tests inject a test-only clock. No constructor taking a claimed `verified=true` boolean exists.

- [ ] Write failing tests using fictional in-memory RSA keys: exact valid signature succeeds, opening-purpose trust cannot authorize legacy data, wrong source/merchant/kind/issuer/key fails, 2048-bit keys fail, noncanonical base64/modified bytes fail, revocation fails, and validity accepts the start instant but rejects expiry. Cover both schema versions and an authenticated but unproven `commit_binding_ref`.
- [ ] Test that raw-body/custody/validator changes signed as a new observation preserve effect key/fingerprint while altering observation evidence. Test that the returned type provides transport authentication only and cannot construct a native/legacy financial write context.
- [ ] Run `vendor/bin/phpunit tests/Unit/Accounts/Legacy/LegacyObservationVerifierTest.php` for expected RED, then implement purpose-specific verification. Do not modify `ProofSchema::attestation` or accept `opening-trust` as a legacy policy.
- [ ] Extend the existing proof reader with size admission on held metadata before content allocation and a `maximumBytes+1` bounded read when supplied. Preserve all ownership, mode, ancestry, hardlink and read-stability checks; invalid caps deny. Null keeps existing callers unchanged.
- [ ] In the owned native fixture, test 256 KiB boundary, oversize, symlink/hardlink, writable parent, wrong group and changed-during-read refusal. Mark these tests `opening-native` so only the existing privileged profile supplies ownership prerequisites; ordinary unit success is not custody acceptance.
- [ ] Run the legacy unit directory and, only after native fixture admission, `vendor/bin/phpunit -c phpunit.opening-native.xml --filter LegacyTrustCustodyTest`. Expected zero skipped/incomplete/risky tests. Run existing runtime authority tests in that same accepted profile to verify the optional argument does not change callers using the default.
- [ ] Commit with message `Verify legacy transport observations under separate protected trust`.

Representative `test_original_bytes_signature_is_required` assertions:

```php
$keys = LegacyReceiptFixture::keyPair();
$trust = LegacyTransportTrust::fromBytes(CanonicalPolicy::bytes(LegacyReceiptFixture::trust($keys)));
$payload = LegacyReceiptFixture::payload();
$signature = LegacyReceiptFixture::signature($payload, $keys);
$now = new DateTimeImmutable('2026-01-01T00:01:00Z');
self::assertSame('5.5001', AuthenticatedLegacyObservation::verify($payload, $signature, $trust, $now)->economic->delta());
$this->expectException(RuntimeException::class);
AuthenticatedLegacyObservation::verify(str_replace('5.5001', '6.5001', $payload), $signature, $trust, $now);
```

### Task 4: Immutable evidence schema and disabled admission

**Files:** Create `database/migrations/2026_10_18_000001_create_legacy_receipt_inbox.php`; create `app/Models/LegacyEffect.php`, `LegacyObservation.php`, `LegacyReceiptConflict.php`, `LegacyReceiptBudget.php`; create `app/Services/Accounts/Legacy/LegacyReceiptWriteGuard.php`, `LegacyRetentionContext.php`, `app/Models/Traits/GuardsLegacyReceipts.php`, `app/Models/Builders/LegacyEvidenceBuilder.php`; create `config/legacy-payments.php`; test `tests/Feature/Accounts/Legacy/LegacyReceiptSchemaTest.php`.

**Interfaces:** `LegacyRetentionContext::fromObservation(AuthenticatedLegacyObservation $observation, LegacyTransportTrust $trust): self` is retention-only and contains immutable policy/economic/observation bindings. `LegacyReceiptWriteGuard::during(LegacyRetentionContext $context,Closure $write): mixed` permits only the exact admitted effect/observation/conflict and bounded usage changes in the current DB transaction, refuses nested scopes, and clears scope in `finally`. Guard refusal raises `DomainException` before SQL,so guard tests cannot accidentally pass on a database constraint error. The guard rereads the configured protected policy and requires its current hash to equal the context's verified policy;an object authenticated against a caller-invented trust object cannot authorize model writes. It never implements `AccountWriteContext`, and existing ledger code is unchanged.

- [ ] In the owned native fixture, write failing tests that migration creates four empty evidence tables and no budget row, wallet, movement, credit, invoice, operation, key, job or configuration acceptance. Verify physical unique constraints and restrict-delete relationships using independent connections.
- [ ] Write tests refusing unguarded instance creates,all evidence updates/deletes,and bulk Eloquent mutation methods;also test conflicting original facts,nested write scopes and scope leakage after an exception. Guard instance writes through a trait following `GuardsAccountWrites`;use a dedicated evidence Eloquent builder on the three immutable models to refuse bulk mutation. Quiet instance writes must still reach the guard;increment/decrement paths are refused for evidence. Budget writes use only bounded guarded instance saves with the ordinary builder,so immutable-builder refusal cannot accidentally prevent its legitimate counter update. Direct `DB::table`/privileged SQL is explicitly outside this application guard boundary and is never used for evidence insertion by the inbox. Ensure hidden payload/signature/identity fields do not appear in ordinary model serialization. Budget mutation must be bounded by the current guard, not exposed through a public model edit.
- [ ] Run `vendor/bin/phpunit -c phpunit.opening-native.xml --filter LegacyReceiptSchemaTest` and retain RED. Implement the exact schema and guard; no source schema or business-table change is permitted.
- [ ] Make `down()` test every evidence table and usage before dropping anything. Tests must prove an evidence-bearing rollback refuses without losing a row, while an empty owned fixture can reverse the migration. Do not add cascade deletion or a cleanup command.
- [ ] Rerun schema tests under the same accepted native profile. Expected all pass, defaults false/null, and no change to `AccountWriteContext::MOVEMENTS`, `NativeDepositReceipt`, `WalletLedger`, `MigrationHold`, funding switches or native invoice processing.
- [ ] Commit with message `Add immutable disabled legacy receipt inbox schema`.

Representative `test_direct_model_create_is_denied_before_sql` assertions:

```php
self::assertSame(0, LegacyEffect::count());
$this->expectException(DomainException::class);
LegacyEffect::create([]); // Guard failure, not a database not-null error.
```

### Task 5: Transactional bounded quarantine retention

**Files:** Create `app/Services/Accounts/Legacy/LegacyReceiptInbox.php`, `LegacyRetentionResult.php`;modify `tests/Fixtures/LegacyReceipts/LegacyReceiptFixture.php` for the inbox-specific fixture helper;test `tests/Feature/Accounts/Legacy/LegacyReceiptInboxTest.php`; modify `app/Providers/AppServiceProvider.php` only for lazy nonexecuting binding if required by constructor injection. No route, controller, console ingestion command or scheduler entry is created.

**Interfaces:** `LegacyReceiptInbox::retain(string $payloadBytes,string $signatureBytes): LegacyRetentionResult`, with an injected `LegacyClock` and protected configured trust reader. The inbox obtains fresh UTC from the clock before authentication and again immediately before commit;the caller cannot supply a validity timestamp. The service reads only configured protected trust, authenticates and validates before its own local transaction, then rereads the exact current policy immediately before commit. It obtains the fixed budget row, effect row and observation/conflict rows in that order. Refuse entry when `DB::transactionLevel() !== 0`,so no result can claim durable retention inside a caller's uncommitted transaction. Use one outer transaction attempt;deadlocks propagate for an explicit caller retry of the original bytes,without external action. No owner/wallet/invoice lock or ledger method is called. Bind `LegacyClock` lazily to `SystemLegacyClock` without reading trust or doing any work at application boot. Task5 adds `LegacyReceiptFixture::withProtectedIngress(Closure $test): mixed`,which builds on Task3 protected trust,sets only test configuration and a test clock inside fixture validity,invokes `$test($inbox,$payload,$signature)`,then restores its test configuration/owned files.

- [ ] Write failing native tests for entry inside an ambient transaction (refuse before mutation),default-disabled/missing trust, valid signed receipt retained only as `pending_source_qualification`, exact byte replay returning the original observation with zero quota increase, distinct authenticated observations producing one effect and two observations, and altered economics producing an immutable conflict with the first effect untouched.
- [ ] Test a valid signature carrying claims of native commit, opening exclusion or manual authority: it still receives no promotion or money. Snapshot account wallets, movements, credits, opening receipts, native paid processing, holds, invoices, payment operations and job/mail/provider channels before/after; require exact preservation and zero effects.
- [ ] Run `vendor/bin/phpunit -c phpunit.opening-native.xml --filter LegacyReceiptInboxTest`; confirm RED before implementing retention. Check quotas from protected trust; initialize the one fixed budget row transactionally on first authorized retention, never one budget per policy version. Use DB uniqueness for first-row/effect/observation races rather than assuming an absent row is locked.
- [ ] Charge actual unique retained text lengths defined above, with overflow-safe integer addition. Lock and reread usage before admitting any insert. Exact duplicates add no bytes/count and may return their original pending result under valid current trust even if a later policy lowered quotas below retained usage;they do not admit new evidence. Conflict observations count and remain retained; a quota refusal creates no custody success and rolls back every partial row/counter change. Test zero-cost exact replay after a quota reduction separately from refusal of a new observation. Never evict receipts or silently reduce usage to make room.
- [ ] Before commit, reread protected trust and require the same policy hash plus still-valid signature/time/grants and configured enabled state. Revocation, replacement, expiry, lower capacity or custody drift aborts the transaction. Restart under a changed accepted policy is a fresh explicit caller attempt; there is no implicit retry using stale trust.
- [ ] Assert the only result dispositions are the two defined above. No `included`, `applied`, `applied_zero`, movement ID or provider ACK can be emitted. Do not create a source capability registry or an always-accepting verifier to make tests green.
- [ ] Rerun inbox tests; use separate native assertions for each preservation channel so an empty jobs table cannot hide a synchronous side effect. Commit with message `Retain authenticated legacy receipts as bounded pending evidence`.

Representative `test_exact_delivery_replays_pending_retention_without_cash` assertions:

```php
LegacyReceiptFixture::withProtectedIngress(function ($inbox, $payload, $signature) {
    $a = $inbox->retain($payload, $signature);
    $b = $inbox->retain($payload, $signature);
    self::assertSame('pending_source_qualification', $a->disposition);
    self::assertSame($a->observationId, $b->observationId);
    self::assertTrue($b->replay);
    self::assertSame(1, LegacyEffect::count());
    self::assertSame(1, LegacyObservation::count());
    self::assertSame(0, AccountMovement::count());
});
```

### Task 6: Native races, interruption recovery and publication gates

**Files:** Create `tests/Feature/Accounts/Legacy/LegacyReceiptRaceTest.php`, `tests/Fixtures/LegacyReceipts/inbox-worker.php`, `docs/legacy-payment-receipts.md`; retain private run evidence outside the repository. Modify test fixture helpers only as needed for fictional keys/policies and independently committed DB workers.

**Interfaces:** Test-only worker receives the same explicit accepted local socket/database identity and fictional protected policy as its parent, performs one inbox retention, and emits bounded fixed result IDs/hashes. It has no source/provider/client connection or payment action. Use the existing `UsesCommittedDatabase` pattern and observe actual InnoDB waits rather than sleeps as proof of contention. Test-only model-created and `TransactionCommitted` listeners provide barriers after insertion/commit;all pause/termination logic stays in fixture code,with no production fault-injection flags. The worker accepts only `before_write`, `after_effect_insert`, `after_observation_insert`, `after_commit` barrier phases after explicit fixture/runtime admission and produces fixed ready/result messages. Kill only the exact fixture child/group owned by that test.

- [ ] Write native race cases: two first deliveries of one observation, two observations of one effect, conflicting economics, and two deliveries competing for the last quota. Assert one original effect, correct unique observations/conflicts, exact once-only byte/count accounting and no financial records. Every case must use independently committed connections.
- [ ] Add process interruption cases before insert, after effect/observation insert before commit, and after commit before returning a result. Synchronize test barriers with real DB locks/worker messages. Before-commit interruption leaves no partial rows or usage; a fresh process after committed-response loss returns the original observation and consumes no extra quota.
- [ ] Exercise changed/revoked trust in a reused worker, policy changes during the transaction, and budget-policy rotation without a new budget row. A current trust failure leaves original evidence intact and creates no new retention result. Race assertions cover exact global uniqueness, not merely the status printed by a worker.
- [ ] Add documentation explaining transport authentication versus native commit/posting, both pending dispositions, private root-owned trust, disabled defaults, logical quota versus physical storage, and the absence of public ingestion or posting commands. Document that no user needs to supply real keys or source payloads for these tests.
- [ ] Run pure units: `vendor/bin/phpunit tests/Unit/Accounts/Legacy`; run vectors: `python3 tools/legacy-payments/check-identity-vectors.py`; run the complete existing accepted native profile: `vendor/bin/phpunit -c phpunit.opening-native.xml`; run `vendor/bin/pint --test` and `git diff --check`. No skipped/incomplete/risky native tests are accepted. Run the contributor profile `vendor/bin/phpunit` in the same owned fixture environment to preserve normal suite coverage. Missing environment prerequisites stop native acceptance before DB/framework setup; they are not skipped tests or permission to provision another environment.
- [ ] Record unchanged financial/hold/history checks and isolated fixture identity, complete suite evidence, zero provider/HTTP/mail/fulfillment effects and exact recovery results. These are fictional target inbox tests only; do not claim BILLmanager transaction/gateway or production acceptance.
- [ ] Obtain one fresh whole-increment review using the preserved native execution method, fix material findings, rerun only affected checks plus required final suite, and commit with message `Qualify legacy inbox replay concurrency and recovery`. Publish only generic source/tests/docs to the existing draft PR after required checks; no private evidence or credentials enter the fork.

Representative `test_lost_retention_response_does_not_duplicate_evidence` outcome after the test-only committed barrier is interrupted and a fresh worker replays the original bytes:

```php
self::assertSame(1, LegacyEffect::count());
self::assertSame(1, LegacyObservation::count());
self::assertSame($originalObservationId, $freshResult->observationId);
self::assertTrue($freshResult->replay);
self::assertSame($originalUsage, LegacyReceiptBudget::firstOrFail()->only(['retained_bytes', 'retained_observations']));
```

## Self-review coverage and subsequent plans

| Approved design requirement | This plan or remaining dependency |
| --- | --- |
| Stable economic identity across versions; separate observations | Tasks 1–3 and 5–6. Unknown future identity aliases are refused, not implemented permissively. |
| Bounded strict parsing, purpose-separated trust, current custody | Tasks 2–3; byte caps precede allocation; native trust custody is separately tested. |
| Durable append-only inbox, quarantine, quotas, races, recovery | Tasks 4–6. No raw provider custody or provider ACK change is implemented. |
| Opening membership and conserved source/target boundary | Next target-posting plan must add a dedicated accepted manifest/lineage verifier; absence is never exclusion and auto-increment/timestamp shortcuts remain forbidden. |
| Atomic legacy effect/result/movement/projection; active financial authority | Next target-posting plan must add `legacy_effect`, native unique-effect/result constraints, accepted authority, lock-order integration, parent/refund caps and `applied_zero`. This foundation deliberately cannot construct those contexts. |
| Original merchant/manual validation and committed source journal | Separate source-qualification plan must establish the actual installed callback and same-cache business transaction contract, without guessing from `Final`, `Post`, status or signatures. |
| Source journal, committed dispatcher, custody and native ACK | A source bridge implementation plan follows only supported runtime/validation evidence. It must specify exact native hook, schema, transactional write, committed read, signer custody, recovery, capacity and original ACK behavior before any installation proposal. |
| Writer retirement, physical storage, opening, deployment and handover | Existing independent operational gates remain. This plan neither executes nor replaces them. |

There is no executable source-bridge task hidden behind an unspecified hook. The source-qualified interface does not exist yet and cannot honestly be implemented from header declarations alone. The next target-posting plan must likewise consume real accepted lineage/authority contracts, not promote an inbox record because it is signed. These are explicit boundaries between reviewable increments, not empty implementation steps.

This plan is ready for written review. Native inline execution is preserved. Approval permits implementation of this foundation within its constraints; it does not approve the later financial/source plans, production access, new runtime provisioning or handover.
