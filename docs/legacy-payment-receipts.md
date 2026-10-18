# Legacy payment receipt foundation

This increment retains authenticated legacy observations as bounded evidence. It does not import money, settle invoices, release migration holds, authorize refunds, acknowledge providers or establish that a source transaction committed. BILLmanager remains the billing authority until the separate handover gates are met.

There is no public ingestion route, controller, console ingestion command or scheduled dispatcher. Application boot registers only a lazy UTC clock binding. All receipt ingress settings default to disabled or null:

- `LEGACY_RECEIPT_INGRESS_ENABLED=false`
- `LEGACY_RECEIPT_TRUST_PATH` unset
- `LEGACY_RECEIPT_TRUST_READER_GID` unset

Enabling these settings alone grants no financial authority. No existing account-funding, native invoice-processing, hold or ledger switch changes in this increment.

## Identity, facts and observations

`EffectIdentity` defines the permanent economic identity independently of transport versions, delivery nonce, credential rotation and observation references. Its SHA-256 domain is `legacy-economic-effect-v1` followed by a zero byte and canonical identity JSON. Opaque identifiers preserve case and leading zeroes.

`EconomicFacts` binds exact four-decimal wallet effects and independently preserved canonical provider decimals. It conserves wallet components without binary floating-point arithmetic. Its independent hash domain is `legacy-economic-facts-v1`. A changed provider amount, allocation, parent or wallet fact never silently overwrites the first retained economic facts.

Envelope versions 1 and 2 use the same identity rules. Both payload and signature wrapper must already be byte-canonical; the original bytes are verified and retained. Byte, nesting and item limits apply before general JSON decoding. Duplicate decoded keys, ambiguous numbers, unknown fields and unsupported versions are refused.

An observation key hashes the domain `legacy-observation-v1`, a zero byte, the payload SHA-256 hex and the signature-wrapper SHA-256 hex. It is separate from the economic effect key. The original identity/economic JSON and payload/signature texts are hidden from ordinary model serialization.

## Transport trust is not source qualification

The configured policy is a private operational file, never a repository credential. The protected reader requires an absolute ordinary file, stable descriptor/path identity, root ownership, the configured service reader group, protected ancestors and read-only service access. It enforces a 262,144-byte limit before allocating the full policy. The purpose-specific policy explicitly grants issuer, key, source instance, merchant binding and effect kinds; there is no wildcard or fallback key.

RSA/SHA-256 transport authentication proves that a currently granted signer authenticated these exact bytes. Journal IDs, validation references, native-commit claims, opening-exclusion claims and manual-authority claims remain unqualified references. Neither a valid signature nor an absent opening record proves a source commit or permits posting.

Only two retention dispositions exist:

- `pending_source_qualification`: authenticated evidence retained without money authority.
- `economic_conflict`: an additional observation disagrees with the first facts for the same effect; the observation and immutable conflict remain evidence, while the first facts remain unchanged.

There is no `included`, `applied`, `applied_zero`, movement ID or provider ACK result here. Native source validation/commit evidence, opening membership, accepted posting authority and financial effect application require later reviewed increments.

## Transactions, capacity and replay

`LegacyReceiptInbox::retain()` refuses an ambient transaction. It owns one outer transaction attempt and returns only after that transaction commits. It locks the one fixed `legacy-receipt-store-v1` budget, effect, observation and conflict in that order. Physical unique indexes settle first-delivery races; a missing row is not treated as a lock. Deadlocks and database failures propagate. A caller may make an explicit fresh attempt using the same original bytes; the inbox performs no automatic retry or external action.

The four additive InnoDB tables start empty, including the budget. Guarded instance saves authorize only the exact admitted immutable evidence and bounded budget increment. Public evidence builders reject bulk mutation. Quiet saves still pass the guard; updates/deletes of evidence and arithmetic instance writes are refused. These are application guards, not restrictions on a privileged database administrator or raw query-builder SQL. The inbox never uses raw SQL to insert evidence.

Logical retained bytes are the actual byte lengths of identity and economic JSON once per new effect, plus original payload and signature-wrapper bytes once per new observation. Observations, including conflicts, each consume one count. Physical rows, indexes, undo logs and backups require separate operational capacity planning; the logical quota is not a disk-space guarantee.

The current protected policy sets global byte/count ceilings. Rotation never creates a fresh budget namespace. Overflow or quota refusal rolls back every partial row and counter change. No receipt is evicted and no usage is reduced. Exact-byte replay reauthenticates against current protected trust, returns the original observation/disposition and consumes no new bytes or count. It may succeed after a quota reduction below existing usage, because it admits no new evidence. Revoked or expired exact replays still fail.

Configuration, exact policy hash, grants, signature validity, custody and current UTC are checked again immediately before commit. A policy change while waiting or writing aborts that attempt; a subsequent attempt must authenticate afresh. The rollback migration refuses any evidence or nonzero usage rather than deleting records. An empty owned fixture may reverse the migration.

## Verification without customer data

The tests generate fictional keys in memory and use fictional canonical payloads. No administrator needs to supply real keys, provider credentials or source customer payloads. Native tests require the explicit owned root Linux/socket-only MariaDB fixture admitted by `phpunit.opening-native.xml`; missing prerequisites fail before database setup.

`LegacyReceiptRaceTest` uses separate PHP processes and committed connections. It observes actual InnoDB waits on the fixed budget, tests first-delivery uniqueness, concurrent observations/conflicts and last-quota contention, and verifies exact text accounting. Test-only listeners pause workers before writes, after effect/observation inserts and after the outer commit. Termination checks the exact owned child's PID/start identity. A fresh worker after committed response loss must return the original observation without extra usage. No fault-injection flags enter product code.

Mail, queue, synchronous bus and HTTP channels are checked separately, including inside child workers. Financial, hold, history, invoice-processing and service records remain unchanged. These tests qualify fictional target inbox behavior only; they do not qualify BILLmanager callbacks, source transactions, production migration or billing handover. The complete native and contributor profiles, formatter and whole-increment review remain separate publication gates; a passing receipt subset cannot replace them.
