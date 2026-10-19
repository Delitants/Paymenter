# Standalone manual financial observations

This collector is implemented for offline qualification. It is not an issuer,
`NativeAccess` factory, admission token, financial success evaluator or notification
verifier. No SDK module or bootstrap calls it. A real fixture, its supported native
creation replies, caller/actor/writer provenance, notification/provider isolation,
source build and deployment still require separate qualification. Files or their
hashes alone establish none of those properties.

Build `manual_financial_reader.cpp` alone, with the existing reviewed C++11,
OpenSSL and MySQL C API dependencies. Do not separately link `authority_bridge.cpp`.
The build must supply fixed `PAYMENTER_QA_MANUAL_CREDENTIAL_FILE`,
`PAYMENTER_QA_MANUAL_DATABASE`, `PAYMENTER_QA_MANUAL_SOCKET`,
`PAYMENTER_QA_MANUAL_RUNTIME_PATH` and `PAYMENTER_QA_MANUAL_LIBRARY_PATH` string macros.
There are no default production paths or runtime connection/SQL overrides.
The CLI takes one protected operation-directory path. The entry point requires
Linux, effective group and user root, and the existing protected ancestor,
0700 directory and supported local-filesystem rules. Core dumps are disabled.

## Protected inputs

Each input is an immutable 0600, single-link regular file beneath the held
directory. Replacements, permission/ownership/metadata/content changes and
revoked/retired controls refuse. Formats below are ASCII, newline terminated,
with the stated purpose on the first line followed by exactly the listed
`key=value` fields, in order. Unknown/duplicate/trailing fields refuse.

| File | Purpose | Ordered fields |
| --- | --- | --- |
| `manual.read.approval.original` | `paymenter-manual-financial-read-approval-v1` | `intent run request boot actor_hex case journal_case phase facts writer previous registry_sha256 preimage_sha256 schema_sha256 foreign_keys_sha256 writer_sha256 identity_sha256 issued expires` |
| `manual.registry.original` | `paymenter-manual-financial-registry-v1` | `run boot actor_hex facts account subaccount project currency method module payments users runtime_sha256 library_sha256 reader_sha256` |
| `manual.preimage.original` | `paymenter-manual-financial-preimage-v1` | `run boot facts account subaccount project currency method payments users balance creation_sha256` |
| `manual.writer.original` | `paymenter-manual-financial-writer-v1` | `run boot actor_hex case payment facts connection pid start runtime_sha256 library_sha256` |
| `manual.identity.original` | `paymenter-manual-read-identity-v1` | `runtime_sha256 library_sha256 reader_sha256` |
| `manual.schema.original` | `paymenter-manual-financial-schema-v1` | One `table=sha256` field for each fixed table below, in that order. |

`manual.foreign_keys.original` starts with
`paymenter-manual-financial-foreign-keys-v1`, followed by fewer than 512 unique
five-column tab-separated rows: schema, table, column, referenced schema/table
joined by `.`, referenced column. Only identifier characters are allowed.
No incoming or outgoing QA-journal foreign key is admitted. Each
`manual.schema.<table>.original` holds the exact raw SHOW CREATE bytes, up to
32 KiB; its hash must match the manifest. The foreign-key original is bounded
to 64 KiB; the other manifests/controls to 4 KiB. These are existing originals,
never a request to create or discover tables.

The fixed alphabetic table list is:

```
account account2project affiliatereward billorder billorderitem currency
expense expense2payment invoiceitem2payment item lineitem payment
payment2payment payment_upload2payment paymenter_qa_journal_v1 paymethod
paymethod2company paymethod2group paymethod2project paymethodparam
promotioncoupon2account recurring recurring_subscription subaccount user
```

The intent is `observe-manual-financial`; run/request/facts and file/image hashes
are lowercase SHA256 strings. Boot must match the current source boot. Actor is
bounded hexadecimal text and must match the registry/writer; its spelling does
not prove an authenticated native actor. Case is M1–M5; journal case M1–M4.
They must match except that M5 names a previous journal case for reconciliation.
Phase is before/barrier/after/retired. Writer and optional comma-separated
distinct previous connection IDs are canonical positive uint64 values; `none`
means no history. The writer's connection/case/payment and all original bindings
must agree. PID/start and creation digest are retained provenance references,
not a live process/session or native creation attestation.

Account/subaccount/project/currency/method and four distinct payment IDs are
canonical positive signed-32-bit IDs. User IDs are the complete numerically
ascending set of one to eight disabled fixture users. Payment/user lists are
comma separated. Method module is a bounded identifier. Balance is an exact
signed DECIMAL(18,4) string. The current runtime, library and reader images must
match their protected identity original; path/held-file identity is rechecked
throughout. Images must be protected regular files, with executable bits on
runtime and reader. Logical image symlinks are not admitted by this collector;
any required alias compatibility needs a separate reviewed native qualification.

## Connection and observation

The wall-clock approval window is at most 30 seconds and is also bounded by a
monotonic deadline. There are no retries. Any existing started, observation,
completion or failure original refuses before a connection. A new exclusive
started original is published and fsynced before credentials or connection.

Credentials use the existing password-only protected-file channel: root-owned
0400/0600 single-link regular file, one `[client]` section, one password, no
includes or other client options. No credentials enter arguments, environment,
diagnostics or observations. The new root C API connection uses only the fixed
local socket/database, two-second connect/read/write timeouts, flags zero,
no default processing, TCP, reconnect, local infile or multiple statements.
The authenticated database principal must be root. Its initial transaction must
be absent before setting READ COMMITTED/autocommit; an inherited transaction is
never committed. Connection/session/status/database/socket remain checked after
every query; the actual connection must differ from writer and reader history.
Approval, all input originals, images, credentials, clocks and revocation remain
checked around each C API step and output publication.

Before and after the financial statement, the reader checks all 25 engines are
InnoDB, no selected-table triggers, exact incoming/outgoing FK metadata, and
exact SHOW CREATE bytes. FK metadata is limited to 512 rows and reaching the
bound refuses. A missing journal is failure, not permission to create one.

One fixed UNION ALL SELECT observes the financial state. There are no separate
balance/payment/journal read views or financial writes. Five columns encode
kind, exact owned ID, field, value, facts digest. It returns fewer than 128 rows,
at most 64 KiB of cells, with nonempty ASCII cells of at most 256 bytes. Reaching
the row bound, truncation, extra/missing/duplicate/foreign cells, ownership drift,
enabled/remote/staff users, fees, non-USD currency or forbidden links refuse.

The statement reads subaccount binding/balance/credit/postpaid state; four exact
payments' status, currency, amounts, usage, commission and tax; method binding,
disabled/nonrecurring state and amount limits; registered user membership/state;
exact-owned counts; and the current journal row if present. Canonical money uses
integer four-decimal units, never floating point or rounding. NULL, empty and
nonempty linkage are distinct tokens. It selects no passwords, OTP, profile
text, external ID contents, XML/secrets, sender/recipient values or notification
bodies. Nonempty forbidden strings are represented only by presence.

Counts include extra account users/subaccounts/services, payment rows by
subaccount and method, project membership (including NULL as foreign), owned
orders/expenses/lineitems, recurring/subscription links, method bindings/params,
and eight fixed reverse-payment edges. The non-FK `recurring.init_payment` edge
is explicitly checked. Physical columns are `payment.expense_commission` and
`payment.recurring_subscription`. Native history tables are not required to be
empty and are never modified.

Absent journal, credit without journal and journal without credit are retained
as observations; the reader does not certify financial success or rollback.
The outer trusted evaluator must compare the protected preimage and native
status/credit/journal outcomes, and treat surviving credit as failure. M5 cannot
dispatch another credit. No row or completion releases a barrier or authorizes
another operation.

## Output and refusal

`manual.read.started.original` binds request and approval hash.
`manual.read.original` retains the original bindings, actual connection,
start/finish times, READ COMMITTED/autocommit/no-transaction state and approval
hash, followed by `row=` and five tab-separated validated cells per line.
Only this fixed filename has a 64 KiB publication allowance; ordinary controls
retain their existing 4 KiB limit. `manual.read.complete.original` binds request
and observation SHA256. Stdout reports only retention success.

Any exception after reservation attempts to retain
`manual.read.failure.original`, which dominates even an existing completion.
Partial originals, lost completion, failure, revocation, custody drift or
unverified process exit/EOF never qualify. Failures are preserved, with no
overwrite, cleanup, retry or automatic recovery. A caller must independently
verify protected originals/hashes, exit/EOF and process retirement. Generic
completion is not a financial receipt, provider acceptance or proof that
notification paths were isolated.

The local C++ tests use authored C API/SDK boundaries and protected macOS files;
SQLite checks the emitted query's relational predicates. They establish no
InnoDB snapshot/locking behavior, native transaction unwind, real source image
compatibility or production acceptance. The framework collector and all live
admission gates remain unchanged. BILLmanager remains billing authority.
