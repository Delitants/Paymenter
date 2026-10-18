# Native legacy payment journal and Paymenter adapter

Status: proposed architecture for written review. This document implements no receiver, journal, table, authority, endpoint or financial movement. BILLmanager remains the billing authority until a separate accepted handover. Source publication does not authorize deployment.

## Outcome and scope

Preserve genuine legacy receipts and reversals across migration without lost money, duplicate cash, repeated fulfillment or changes to clients outside the migration. Preserve original four-decimal account amounts, accepted opening rounding, existing credit limits, shared-account financial ownership and elected holds. Source payment history remains history; it is not replayed as deposits.

The design covers one pipeline: validated source economic effect, transaction-bound journal, committed-row delivery, opening classification and an atomic legacy target receipt. Source retirement, native gateway validation and actual runtime acceptance are dependencies. Login migration, checkout, new native payments and provider refund execution retain their existing contracts.

## Approach

| Approach | Assessment |
| --- | --- |
| Journal through the existing native database cache, followed by a committed-row dispatcher and distinct target adapter | Recommended candidate. It can couple the receipt to the source effect without a remote call in the business transaction. Installed runtime enlistment and gateway coverage still require proof. |
| Poll payment status or send from an ordinary after-event | Discovery only. Status does not establish a distinct authenticated effect; after-events precede commit. |
| Retire cohort writers and reconcile a final snapshot without a continuing bridge | A possible separately designed operating policy, but it still needs treatment of outstanding external payments, later reversals and outside-cohort workloads. It is not an automatic fallback. |

The [COREmanager lifecycle](https://www.ispsystem.com/docs/coremanager/developer-section/general-specifications/system-architecture) places ordinary after-events before transaction completion. [C++ plugin guidance](https://www.ispsystem.com/docs/b6c/developer-section/integration-and-extension-of-billmanager/plugins/creating-plugin-for-billmanager) supports transaction integration, but does not qualify a particular installed gateway path.

The inspected installed declarations expose a shared `mgr_db::Cache`, automatic transaction-start documentation, `Cache::Prepare`, `Cache::GetConnection` and cache-mediated prepared statements. They justify this candidate architecture. They do not prove request enlistment, the dynamic cache type, rollback, commit timing or recovery. `Session::conn` is not established as the database connection. `Event::Final` and `OnAfterPost` are not accepted commit signals. Hiding transaction methods on a derived interface is not a runtime guard against calls through a base pointer. Exact header/package hashes stay in private operational evidence.

## Components and boundaries

| Unit | Consumes | Produces and owns |
| --- | --- | --- |
| Original source verifier | Original provider bytes or authenticated polling result; original merchant configuration | Qualified provider verification or a distinct, authorized manual operation. Existing provider response semantics remain owned here. |
| Source custody | Exact validator-required raw bytes and ordered headers | Private durable original evidence with bounded lengths, hashes and custody sequence. No raw payload or secret is sent to Paymenter or developer machines. |
| Native effect journal | Qualified validation facts and the source business operation's existing cache | Immutable effect row written in the same transaction as that operation. It creates no independent connection or commit. |
| Committed-row dispatcher | Only independently visible committed journal rows | Signed immutable envelopes, durable at-least-once delivery, retry state and target result references. It never captures or refunds money. |
| Legacy receipt verifier and inclusion classifier | Envelope, explicit trust, accepted mapping and opening manifest | Verified semantic effect and `included`, `excluded` or `ambiguous` disposition. No native invoice or deposit receipt is fabricated. |
| Legacy ledger adapter | Verified excluded effect, current dedicated authority and ledger state | One original legacy receipt, optional movement, guarded projection update and durable result in one target transaction. |
| Reconciliation reader | Immutable effects, delivery attempts, results and conflicts | Admin-visible unresolved work and permitted customer statement entries. It grants no posting authority. |

These are proposed interface names and responsibilities, not existing callable APIs. The generic PHP adapter belongs under `app/Services/Accounts/Legacy`; native source-side code belongs in an isolated generic tool package in this fork. Neither package contains production identities or credentials.

## Source recording and commit boundary

The native handler must reuse the exact existing cache/connection enlisted in the original business transaction. It may prepare and execute a journal insert; it must not call `Begin`, `Commit`, `Rollback`, create another cache, register schema during payment handling or issue remote HTTP. The business effect and journal insert must commit together or both disappear. Native acceptance must prove this relationship; comments and a nonzero transaction count are insufficient.

The journal accepts only a qualified immutable economic effect. An arbitrary action parameter, signed body alone, browser return, paid flag or gateway transaction ID alone cannot construct that effect. Provider captures require original merchant validation bound to the committed native transition and original allocation. Manual settlement, unsettlement and restoration require separate immutable admin-operation authorization, actor, reason and manual label. Promised credit, authorization without capture and unpaid requests produce no cash.

The source journal has a unique semantic effect key and immutable facts hash. A duplicate with identical facts reuses the effect. Conflicting facts must not be ignored or rewritten; they produce retained reconciliation evidence under an explicitly qualified failure policy. Existing native lock order, rollback and acknowledgement behavior must remain accepted before the handler is installed.

Schema registration can reconcile or create tables and execute initialization SQL. It is a deployment write, outside this handler and outside read-only diagnostics. Engine, schema and unique-index acceptance are prerequisites, not work hidden in plugin startup.

The dispatcher uses an independently qualified committed read and does not use dirty reads. It must not publish a row merely because the inserting connection can see it. Its source business-to-journal binding is established by native acceptance; it does not invent a commit ID or log position. `Event::Final` is unnecessary for correctness and supplies no delivery authority.

## Effect identity, facts and envelope

An effect is one capture, refund, reversal, chargeback or authorized manual operation. A delivery is one attempt to transmit it. Partial refunds are separate effects linked to their original capture. A change in amount must conflict on the same identity rather than create a new identity.

The semantic key is a domain-separated SHA256 over a permanently fixed economic-identity encoding: stable source-instance identity, immutable merchant binding, original source payment, effect kind and the provider-qualified economic-effect ID (or distinct manual operation ID). Envelope and facts schema versions, boot IDs, migration epochs, delivery IDs, payload hashes, amounts and target request IDs are excluded from this key. The original economic key survives re-encoding, key rotation and schema upgrades. A future identity-format migration requires a DB-enforced alias to the original effect before admission; an unknown format is refused rather than creating another economic identity. Provider identifiers retain exact bytes and case; no email-based ownership or implicit integer conversion is allowed.

Immutable economic facts include the original source account/subaccount and financial owner, provider payment identity, linked parent effect, original source-currency and wallet-currency components, and the qualified original FX/allocation facts. The semantic fingerprint uses the permanently fixed economic projection, independent of envelope/facts schema versions. Original provider precision is retained separately; the admitted wallet delta must fit `AccountAmount` exactly. Monetary components and rounding must have a qualified conservation equation. An unqualified allocation is quarantined, not guessed from field names.

Delivery/custody IDs, raw-body hashes, validator/code/config/credential versions, signing keys and observation timestamps are append-only evidence observations, excluded from the economic key and economic fingerprint. Each observation must independently authenticate and bind to the same committed source effect and economic facts. Preserve the first admitted validation/commit provenance and append later observations without replacing it. A different authenticated callback or poll body with identical economic facts is another observation, not another effect or a conflict. Target mapping versions and opening decisions are separately bound adjudications; they do not mint a new source identity or rewrite an original posting.

Canonical facts reuse the existing `CanonicalPolicy` byte rules after a closed typed schema and strict duplicate-key decoding. Values contain no floats or numeric financial tokens. Wallet amounts are normalized to exact four-decimal strings before fact hashing; original textual provider values remain separate evidence. Cross-language golden vectors must cover UTF8, escaping, array order, empty values and identifier distinctions. No ad hoc C++ JSON formatting defines authority.

The signed envelope contains the identity tuple, stable economic fact hash, original facts and separate source journal/custody and validation observations with original bytes' hashes. A separately signed delivery wrapper may add delivery sequence and attempt metadata without changing semantic identity. Raw bodies, authentication headers, tokens, merchant secrets and private keys remain server-only. Credential fingerprints must be keyed and non-reversible.

A hash is not posting authority. An explicit source trust allowlist must authorize issuer, key version, source instance, merchant bindings, schemas and effect kinds; an authenticated transport is also required. Envelope verification does not replace merchant validation. Unknown or revoked trust cannot create a new posting. Historical replay after rotation requires its accepted historical trust decision and original stored facts, never an undocumented exception.

## Opening inclusion and source retirement

The opening classifier consumes the accepted closing snapshot, writer/cohort retirement evidence and explicit effect-inclusion manifest. It binds the source account/currency and original opening receipt, snapshot, rounding policy and accepted mapping. `OpeningEvidence` currently lacks this effect manifest; a dedicated additive lineage contract is required. Existing opening receipts are never silently rewritten to acquire it.

An auto-increment journal ID, timestamp or maximum observed ID is not a commit watermark: a lower allocated ID can commit later. Inclusion therefore uses the consistent snapshot and explicit effect membership under accepted writer closure, with detectable manifest gaps. Capture order, delivery order and commit order remain distinct.

| Classification | Target behavior |
| --- | --- |
| Included in accepted opening | Record the original classification and lineage; create no new money. |
| Proven excluded, with conserved source/target boundary | Eligible for the exact verified net delta under current posting authority. |
| Ambiguous, missing lineage, or source expense/allocation crosses the boundary | Retain and quarantine; zero movement until accepted reconciliation. |

A late refund may link to a capture included in the opening rather than a target movement. Its parent allocation and component caps must be proven by that original lineage. It cannot require inventing an earlier deposit. A delayed provider callback remains associated with its original merchant and financial owner.

The source cohort must be unable to spend or renew transferred money after handover, while outside-cohort billing remains intact. The journal does not itself retire writers. It must neither re-enable source fulfillment nor credit gross receipts while omitting source expenses. A new epoch cannot reclassify an already adjudicated effect or create another opening.

## Target model and authority

Add separate logical records for `LegacyEffect`, `LegacyDelivery`, append-only validation/evidence observations, append-only `LegacyDisposition`/conflict evidence and immutable `LegacyPostingResult`. The effect has a globally unique semantic key; each posting result is unique per effect and links an optional unique movement. An included-opening result has no movement. Delivery deduplication never substitutes for the effect constraint. Operational retry cursors are mutable; original economic facts, classifications and results are not.

A new dedicated legacy receipt type must verify source provenance, exact immutable economic facts, native commit binding, accepted mapping and opening inclusion. Its admitted ledger action is the distinct `legacy_effect`; the original capture/refund/manual kind stays in the verified receipt. Each receipt supplies an exact signed four-decimal delta. This action needs its own receipt validation, source key, fingerprint, movement attributes and projection checks throughout `AccountWriteContext`, `AccountWriteGuard` and `WalletLedger`. A dedicated `LegacyPostingAuthority` must authorize current release/runtime, source/merchant/effect kinds, lineage and effect scope. It is distinct from ingress authentication, opening approval and ordinary admin permissions. Defaults are disabled/unset. Installing schema creates no authority, wallets, movements, jobs, keys or receipts.

`AccountWriteContext::MOVEMENTS` and `AccountWriteGuard` require an explicit legacy context. Existing native deposit and reversal constructors retain their requirements. `NativeDepositReceipt`, native paid invoices, direct `Credit` writes and arbitrary adjustment contexts are prohibited substitutes. `WalletLedger` must enforce the new receipt's exact delta and source key through the same guarded projection path. Refund reservations/native reversal logic must not be invoked merely because a legacy negative effect resembles a native refund.

Receipt ingress may retain authenticated evidence while a wallet is held or funding is disabled. That grants no movement or spendability. An included-opening classification is nonfinancial and requires its accepted lineage; cash posting always requires current funding/runtime/legacy authority, an active reconciled wallet and all existing migration holds and identity checks. The elected held account stays held. Shared membership grants only existing read permissions; it never changes the original financial owner or duplicates a wallet.

## Atomic posting and concurrency

One target transaction must verify current authority and immutable receipt facts, lock the existing admitted payment graph where applicable, lock the accepted owner and wallet in the ledger's current order, then lock the opening lineage, parent legacy effect and current effect in a deterministic order. Legacy posting has no invented invoice graph. The implementation plan must integrate this extension with `AccountPaymentLocks`; it must not acquire native invoice/provider locks after taking owner/wallet locks. Cross-owner mappings are rejected before financial writes.

Under those locks, recheck inclusion, parent/component caps, pending conflicting receipts, original ownership, projection consistency and exact authorized delta. Claim the globally unique effect identity, insert the immutable classification/result, post at most one movement and update the wallet plus authorized `Credit` projection atomically. The result and movement either both commit or both disappear. Included effects commit a zero-money result only. A proven excluded effect whose conserved wallet delta is zero commits `applied_zero` with its original component accounting and no ledger movement,under the full posting authority;it cannot be relabeled included. Holds or missing authority leave a pending disposition, not a partial ledger transaction.

Use a deterministic legacy request key and a domain-separated legacy movement source key derived from the semantic effect key. Preserve both the existing global movement source uniqueness and a new effect uniqueness constraint. A unique-key race must reread the committed original result and compare immutable facts; it must not be treated as success merely because an insert collided. Exact replay returns that result after current receipt/trust checks. Revoked authority can block a fresh response or posting without deleting an already committed result.

A semantic conflict is appended separately, never used to mutate a committed financial receipt. Any required funding block is an explicit guarded reconciliation operation under current authority; the verifier cannot bypass holds or acquire arbitrary owners to manufacture a block. Retry a deadlocked local transaction only with the original immutable receipt and no external effect. Unknown target commit is resolved by the globally unique effect/result lookup, never by capture/refund replay.

Negative effects use original component and cumulative caps, including distinct partial refunds and out-of-order delivery. A child waits for its proven parent lineage. Already spent cash may produce visible excess debt or a reconciliation block under the accepted reversal policy; insufficient cash cannot silently discard a verified reversal. Limits remain borrowing facilities, not funds. Legacy receipts never trigger invoice paid-processing, service creation or provider fulfillment. New checkout taxes, gateway fees and current FX are never reapplied to old effects.

## Delivery, acknowledgements and recovery

The original provider response remains owned by the qualified native verifier. The bridge introduces no universal provider ACK, HTTP status or response timing rule. Outside-cohort callbacks and polling retain their original behavior. Provider ACK, source custody acknowledgement, bridge receipt acceptance and target financial result are four different things.

Dispatch is at least once. Persist attempts, original envelope digest, next bounded retry and any accepted target result. An authenticated `stored_pending` response means custody only and keeps financial work visible. `included`, `applied` and `applied_zero` identify durable target results; `quarantined` is explicit unresolved work. Never label pending/quarantined work as financially complete or prune its source evidence. The bridge result itself binds effect key, immutable facts hash, disposition and original result identity.

| Failure point | Required recovery |
| --- | --- |
| Before durable raw custody | No new custody success; provider-specific qualified behavior applies. |
| After custody, before native business/journal commit | Preserve unresolved evidence; no target movement. |
| Native rollback | Neither business effect nor authoritative journal row is published. |
| Source commit before dispatch | Resume from the committed immutable journal row. |
| Target transaction fails or process dies | Resolve the original unique effect; uncommitted work produces no movement. |
| Target commit before response, or delivery ACK lost | Replay returns the same original result and movement. |
| Signer/dispatcher unavailable or authority revoked | Retain bounded unresolved work; no forged result, ledger bypass or source billing reactivation. |

Use closed envelope schemas with a 64 KiB envelope limit and bounded field counts/string lengths. Raw custody starts with reviewed limits of 1 MiB body plus 64 KiB required headers per delivery; provider qualification must establish that these bounds are sufficient before activation. Raw custody uses exclusive private ordinary files and file/directory fsync before its acknowledgement. Append-only custody sequence integrity must detect gaps and preserve failed/partial evidence.

Deployment must supply an explicit durable queue budget, storage reserve, backlog-age/retry limits and responsible operator. Capacity admission must stop new receipt-producing work before evidence storage is exhausted. At a hard bound, retain existing receipts without eviction or silent drop; the exact provider-specific failure behavior must already be accepted. No default queue quota or guessed failure ACK authorizes installation.

Whole-database restoration is allowed only within a separately accepted preactivation recovery scope. After real user, payment or ledger activity, preserve facts and reconcile forward. Lost leases, controller restart or a changed opening bundle never authorize automatic source reactivation.

## Native acceptance and delivery increments

| Required case | Acceptance evidence |
| --- | --- |
| Same source business/journal transaction, rollback, nested calls and request concurrency | Installed-version native proof of the actual enlisted connection and joint commit/rollback; declarations or fake I/O do not qualify it. |
| Source commit then crash; dispatcher crash; signer loss | Durable restart discovers only committed rows, preserves original validation/custody and resumes without external payment actions. |
| Same capture under a later envelope/facts schema or identity-format migration | The original economic key/result/movement is reused;identity aliases are DB-enforced before a new format is accepted. |
| Distinct authenticated callback/poll observations with different custody/raw-body/validator versions | Preserve both observations and first admitted provenance while producing one economic effect/result/movement. |
| Two simultaneous deliveries, cross-owner conflict and altered amount | Native target DB constraints produce one effect/result/movement; conflicts remain visible and original receipts unchanged. |
| Target commit then lost ACK; transaction/deadlock retry | Fresh-process replay finds the same original result, with no second cash or provider write. |
| Included/excluded/ambiguous opening; late low journal ID; source expense in flight | Snapshot/manifest lineage determines the disposition; no timestamp/sequence shortcut or double gross credit. |
| Partial/out-of-order refund; refunded opening capture; spent cash | Original parent/component caps and accepted debt policy conserve amounts without fake native deposits. |
| Wrong source/merchant/key/owner/currency; raw-body hash/signature binding tampered with, or economic allocation changed | Verification rejects or quarantines before financial mutation. |
| Promised credit; authorization-only; manual settlement/unsettlement/restore | No unreceived cash; each manual effect has dedicated immutable authority and a visible manual label. |
| Held/inactive wallet, revoked authority, projection drift, shared reader | Evidence retained, zero posting/spending bypass; original ownership and holds preserved. |
| Oversize input, full queue, unsupported schema, crash during custody | Bounded admission, retained failure evidence, no invented provider success or silent loss. |
| Outside-cohort callback and existing native payment/refund | Original behavior, receipt provenance, lock order and fulfillment remain unchanged. |

Implementation should proceed in three reviewable increments: strict receipt/inclusion/trust contracts and target quarantine model; distinct guarded target legacy posting with native race/recovery acceptance; source journal and dispatcher only after source runtime/validation evidence supports them. Each can remain disabled while its dependencies are unresolved. None may be replaced by a permissive legacy-deposit flag.

The written implementation plan must name exact schema/code changes, cross-language vectors, native fixture prerequisites and separately bounded source qualification proposals. Unknown source transaction binding, gateway validation/effect identity, installed ABI, source writer retirement, opening manifest or recovery is an explicit release blocker. These dependencies are deliberately unqualified here; the design supplies no automatic probe, installer, key, approval generator or handover operator.

Generic implementation and synthetic tests belong in this fork. Operational paths, source/cohort/account/merchant IDs, URLs, secrets, raw bodies and actual evidence stay private. Source and target installation, native acceptance, source retirement, opening, public routing and financial handover remain separate accepted operations.
