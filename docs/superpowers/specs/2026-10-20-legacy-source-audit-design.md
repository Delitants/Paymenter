# Audit-only legacy source reconciliation

Status: written specification for owner review. The conversational direction is approved; this written specification, implementation plan, live execution and financial activation are separate stages. No application or source behavior changes with this document.

## Purpose and success

Identify committed legacy payment-record changes for the already selected migration cohort while the legacy system continues billing. Produce a complete, bounded status-and-exceptions audit without creating test payments, invoking native payment callbacks, sending messages or changing balances. Revisit old records so a payment completed after its initial creation is visible on a later successful audit.

The first increment succeeds when synthetic tests demonstrate deterministic coverage and exception reporting, exact decimal preservation and refusal of partial evidence. A later separately authorized source run can establish a particular snapshot's observed row state. Neither result establishes provider validation, one atomic business transaction, an opening balance, automatic credit authority or cutover acceptance.

## Scope and architecture

Use a standalone source-audit tool under `tools/legacy-payments/source-audit/`, separate from native qualification and the target application. It has four units:

1. A strict private descriptor reader binds source identity, a fixed cohort and schema profile to one audit attempt.
2. A fixed query planner and bounded collector obtain one consistent database snapshot using only the allowlisted reads below.
3. A pure analyzer checks ownership, coverage and exact row changes against a compatible previous complete audit.
4. A private evidence writer retains the attempt and a concise exceptions/coverage report.

The first implementation phase uses fictional inputs and a fake transport for collector failures. A live adapter must pass a later exact-source compatibility/read review before invocation. There is no target database client, application bootstrap, web route, cron entry, callback registration, provider SDK, mail sender, ledger call or receipt-inbox ingestion in this increment. Retain existing native qualification tools unchanged.

The existing financial archive and broad migration exporter inform the source field vocabulary, but are not reused as an executable collector. Their wildcard reads, contact fields, histories and dynamic login selection exceed this audit's scope. The target receipt inbox remains evidence-only and receives no audit rows. Do not label a source row as a provider capture merely to fit its economic-effect interface.

## Private inputs and identity

A version-1 descriptor supplies a fresh attempt ID, approval-scope reference, UTC not-before/expiry timestamps, an opaque source-instance identity, database/socket identity, pinned compatible server and query-profile hashes, exact schema fingerprint, cohort account-ID set, immutable migration-cohort reference, elected-hold account-ID set, previous complete audit reference and output root. Its authority interval is at most 30 minutes and must remain valid through finalization; an identifier is not itself approval evidence. The later execution packet must bind it to the actual human-approved operation scope. Credentials are supplied server-side through a protected descriptor/file handle, never repository defaults, command arguments, public artifacts or logs. No customer name, email, password, OTP, provider key, hostname or address is hardcoded in generic code or this specification.

The cohort is an explicit sorted, duplicate-free set of positive source account identifiers. Maximum 2,048 accounts; zero or overflow is refused. Do not reselect accounts by a rolling two-year login cutoff during an audit. Membership changes require a new reviewed cohort descriptor and a new baseline; comparisons across different source/cohort/schema profiles are refused. All elected holds must be a subset of that cohort and remain flags in the report, never release instructions.

A missing, mismatched, expired or insecure descriptor fails before a connection. Private directories require owner-only access and no symlink traversal; files are created exclusively with mode 0600 beneath a mode-0700 root. The output and previous-audit inputs must be distinct. No overwrite, append to a prior attempt or fallback to a global configuration is permitted. The attempt ID is consumed once before connection and remains consumed after failure or response loss; a second attempt requires a fresh descriptor and independently authorized scope.

## Source compatibility and fixed read surface

Before a live adapter is admitted, its server profile must establish actual vendor/version, local-socket identity, session REPEATABLE READ and READ ONLY support, transaction termination syntax, a connection identity usable for ownership evidence, bounded statement execution and the exact schema/engine fingerprints. Unsupported capabilities cause refusal, not fallback to a writable transaction or guessed syntax. The execution packet must pin literal metadata/session statements and data-query templates; runtime arguments cannot supply SQL, table names, columns or functions.

All five data tables must be base InnoDB tables in the pinned schema, with expected unique primary identifiers and compatible scalar types. Views and substituted schemas are refused. Preflight/postflight fingerprints and observed session identity must agree. A source schema migration concurrent with the read invalidates the attempt; no automatic reconnect or retry follows. A consistent read may retain metadata locks and consume resources; the design does not claim zero source impact.

The only business reads are the following projections. Every query selects explicitly named columns; no SELECT *, histories, XML payloads, free text or customer contact/authentication fields.

| Dataset | Projection | Ownership predicate |
| --- | --- | --- |
| Accounts | `account.id` | ID in the fixed cohort |
| Subaccounts | `id, account, project, currency, balance, creditlimit, allowpostpaid, active` | `account` in the fixed cohort |
| Payments | `id, subaccount, paymethod, currency, status, subaccountamount, paymethodamount, usedamount, commissionamount, taxrate, taxamount, createdate, paydate` plus fixed link-presence flags below | `subaccount` belongs to a cohort account |
| Payment methods | `id, module, currency, active, recurring` | Referenced by a selected payment |
| Currencies | `id, iso, active` | Referenced by a selected subaccount, payment or method |

Payment link flags are SQL scalar null/nonempty predicates for `externalid, billorder, invoice, recurring, recurring_subscription, expense_commission, sender, recipient, xmlparams, recurring_action_on_fail, recurring_action_on_success`; flags for `refund, autogenerated, chargecommission` distinguish NULL from the exact stored Boolean value. They expose no actual provider references, recipient details or XML. Unknown/null/invalid Boolean encoding is retained as unknown and reported, never treated as false. All underlying columns must exist with the pinned types; no silent omission or dynamic projection.

Explicit count queries use those same predicates in the same snapshot. Each data query orders by its unique ID. Primary IDs and foreign keys are returned as decimal strings, never floating point. Table/column names above are the closed version-1 surface. Additional allocation, expense/refund relationship, actor/history or provider-validation reads require another reviewed increment. As a result this audit deliberately cannot verify full payment economics or absence of reverse links.

## Attempt, limits and completeness

One attempt owns one fresh database connection and one read-only consistent snapshot. There is no ambient transaction, connection pool, global session change or source write. Set the fixed compatible session properties, start the snapshot, read metadata/time and the allowlisted datasets, then explicitly ROLLBACK with no chaining and disconnect. The exact server-compatible statements are selected only from the compiled profile admitted for that version.

MariaDB documents that an InnoDB consistent snapshot sees committed-at-snapshot data, and REPEATABLE READ keeps the view stable across transaction reads. This is database row visibility, not proof that a native workflow's multiple commits constitute one financial effect. See [consistent snapshot](https://mariadb.com/docs/server/ha-and-performance/standard-replication/enhancements-for-start-transaction-with-consistent-snapshot) and [transaction lifecycle](https://mariadb.com/docs/server/reference/sql-statements/transactions/start-transaction), checked 2026-10-20. Installed compatibility must still be qualified.

Hard ceilings: 2,048 accounts, 4,096 subaccounts, 50,000 payments, 256 referenced methods, 256 currencies; 256 rows per page; 32 MiB total raw business-result bytes; 4 KiB maximum encoded row; 30 seconds from snapshot start through explicit rollback, 3 seconds per statement, and 10 further seconds for bounded connection/child cleanup. Deadline accounting uses a monotonic clock. Metadata/diagnostics have a separate 64 KiB cap. Transport setup has a 10-second bound. Required limits must be enforced by the compatible adapter; unsupported query cancellation/deadline control prevents live admission.

Use keyset pages within the same snapshot and a final empty page to prove exhaustion, including at an exact cap. Reference tables use distinct referenced IDs, so join multiplicity cannot create duplicate projected rows. Counts must match unique enumerated identifiers and every cohort account must appear exactly once. A cap-plus-one result, nonempty exhaustion page, duplicate ID, parse failure, unexpected field, missing reference, bad ownership, timeout, connection/identity loss, incomplete EOF, unsuccessful rollback or unknown connection retirement makes the attempt incomplete. Count overflow is refused before business paging. No resume from another snapshot and no automatic retry. Preserve partial evidence privately with an incomplete reason; never advance the comparison baseline.

Each attempt performs a full bounded cohort sweep. Payment ID and timestamps are not incremental watermarks. A lower-ID unpaid record that later changes must be re-read in the next sweep. A successful audit describes one database snapshot only; it does not guarantee observation of every transient change between sweeps. Only a separately qualified change stream could make that stronger claim.

## Analysis and exact values

Distinguish collection state from business findings. Collection is `complete` or `incomplete`; it has no financial-authority status. A complete collection may have many unresolved exceptions and is not an approval to post money. Only complete attempts with the same source/cohort/schema profile are comparable; the first complete attempt is a baseline without invented prior state.

For each dataset and primary ID report `first_seen`, `unchanged`, `changed` or `missing_since_previous_complete`. Missing means absent from this scoped snapshot; ownership movement or deletion is not inferred. Preserve old and new canonical row hashes and changed field names in private evidence. Never replace earlier observations. Changes in currency, owner linkage, amounts, usage, method, status, links or dates remain explicit; no change automatically reverses or creates a credit. A source row ID is a record identity, not a proven immutable economic-effect identity.

Wallet amounts/balance/credit limits must parse as exact signed decimal strings at four places using integer/decimal arithmetic, preserving the original string alongside canonical form. Reject excess scale, exponent notation, NaN/infinity, whitespace ambiguity or overflow beyond the pinned column precision. Provider-currency amounts and rates retain their declared schema precision and source currency independently; do not force them to the wallet scale or perform FX conversion. Never infer a per-payment credited amount from aggregate balance or usage changes, sum unlike currencies or recompute tax/fees/renewal prices in this audit.

Statuses are preserved as source scalar codes, without hardcoded paid/provider-success semantics. A later qualified mapping may add an informational annotation; no current mapping can grant posting authority. Presence of external/manual/recurring/refund-related flags, missing/unknown method currency/module, currency mismatch, changed economics, elected hold and lack of provenance produce fixed reason codes. Unknown link presence is an exception. A missing flag is invalid input. Absence of these forward flags cannot prove no reverse relationship or provider interaction.

Every payment observation is marked `financial_authority=unqualified`; provider validation, manual authorization, allocation, source-transaction business atomicity and opening membership are `not_evaluated`. This initial audit has no financially eligible state. Complete and unchanged does not clear any of those fields. Preserve source credit-limit values as observations only; their eventual application remains governed by the approved migration policy.

## Evidence, privacy and failure handling

Each source-side attempt retains a versioned manifest, descriptor hash without credentials, start/end timestamps, compatible profile/query hashes, snapshot scope, dataset counts, ordered canonical row digests, private projected rows, previous complete reference, exceptions and explicit completion/rollback/retirement facts. Attempt IDs are fresh random identifiers; content hashes bind bytes but are not authenticity, native commit or financial authority evidence. If a later transport signs them, the signature authenticates the report bytes only.

Detailed source rows and account/payment identifiers stay in a protected directory on the source server by default. The Mac receives only fixed reason-code counts, coverage/completion flags, opaque attempt identity and integrity references under a later approved export contract. No customer fields or raw database errors enter terminal/general logs. Exact raw failures, if retained, stay server-side and bounded; public diagnostics use fixed aliases. No reference key or public digest derived solely from a low-entropy customer identifier is exported as an anonymization substitute.

Partial attempts remain immutable. Local evidence disk exhaustion, failed exclusive creation or failed final manifest sealing prevents completion. Finalize via an exclusive temporary artifact and atomic same-directory publication without overwrite; require filesystem durability before a success result. Repeated attempts retain separate histories; no cleanup deletes failed evidence automatically. A lost response does not authorize a second source attempt. Cancellation closes only the owned connection/children; it must not kill a manager or unrelated database session. Unknown retirement remains unknown.

The analyzer does not write the descriptor, cohort, previous manifest, application database or source. Offline output is private even with fictional data. Keep synthetic keys/IDs unambiguously fictional. Public repository files contain code, schemas, examples and test data only; deployment descriptors, real rows, credentials and protected recovery material are excluded.

## Verification required by the later plan

- Strict descriptor/profile parsing, duplicate/unknown keys, insecure paths, empty/oversized cohorts, elected-hold subset and mismatch refusal.
- Query planner's exact five-table/column/predicate surface; injection attempts; no wildcard/free-text/credential/history reads; bounded ordered pages/counts and no arbitrary SQL override.
- Exact cap with empty exhaustion page, cap-plus-one, missing/duplicate/out-of-cohort rows, dangling references, reordered/extra/truncated result sets and missing fields.
- Old low-ID payment changed between complete sweeps, new records, disappeared/reassigned records, incompatible baseline and changes without a timestamp change.
- Exact wallet/provider decimal behavior, null flags, unsupported codes, currency mismatch, amended amount/status/link and preservation of previous evidence/holds.
- Snapshot failures, unsupported isolation/version, changing schema/engine/identity, deadlines, partial output, rollback failure, child cleanup uncertainty and response loss: no completed report, baseline advance or retry.
- Exclusive/immutable artifact creation, byte/row/disk caps, hash mismatch, redacted diagnostics and deterministic replay of fictional evidence.
- Prove no mail/HTTP/provider/target/inbox/ledger calls in every success/failure case. All payments remain financially unqualified, including complete unchanged paid-looking rows.

Native SQL behavior must later be tested in an owned synthetic database fixture with explicitly scoped setup outside the source adapter, and then exact source compatibility separately. Offline/fake-transport results are not native or production acceptance. No real payment creation is part of these tests. Documentation-only specification review does not require rerunning the unchanged callback suite.

## Review and subsequent stages

This specification is narrow enough for one audit-tool implementation plan. Written-spec approval permits preparing that plan; it does not authorize live source/target connections, installation, customer data export, callback execution or financial operations. The plan must separate fictional implementation/verification from any later native fixture and one-attempt source packet. Any generic improvements belong in the maintained fork without private operational data.

Automatic posting would require a separate economic-provenance/allocation design, accepted source authority, permanent effect identity, exact opening inclusion/exclusion, atomic once-only target application, changed-facts handling and independent review. A final consistent migration delta, cohort-specific source billing retirement, full rollback capacity, exact target runtime/session/support attachment acceptance, BIND management access and explicit public/financial handover remain outstanding independently. Existing elected holds, preserved renewal totals, credit limits and deferred provider-refund work remain unchanged.
