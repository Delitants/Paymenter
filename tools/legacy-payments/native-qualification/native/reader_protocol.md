# Standalone source reader originals

This protocol is a read-only collector envelope, not `AdmittedRun`, a framework
transaction result or a financial receipt. The complete admitted-run controller
and factory must still establish approval, schema, resource, writer-diagnostic,
binary and caller custody before executing real cases. The existing Python
`CommittedObserver.read` remains unavailable.

Build `committed_reader.cpp` alone with the qualified existing C++/OpenSSL/MySQL
dependencies and exact reviewed credential-file, database and socket macros.
Do not link it into the SDK or compile `authority_bridge.cpp` a second time.
The CLI is `native-reader <protected-operation-directory>`; Linux/root and the
protected bridge filesystem/ancestor rules apply. There are no runtime SQL,
credential, socket, database, owner, boot or clock overrides. Existing credentials
remain source-private. The configured file must be a singly linked root-owned
0400/0600 regular file containing only one `[client]` section and one `password`
assignment. Horizontal whitespace around the key/value is accepted. Includes,
additional client settings, escapes and ambiguous values refuse before connect.

`read.approval.native.original` is root-owned 0600 and starts with
`paymenter-source-qa-read-approval-native-v1`, followed by these exact fields in
order, one `key=value` per line:

| Fields | Constraint |
| --- | --- |
| `intent`, `kind` | `observe-framework`; kind `channel` or `observation`. |
| `run`, `request`, `boot` | Two lowercase SHA256 identifiers and the actual source boot. |
| `case`, `phase`, `facts` | For observations: F1–F5, before/barrier/after/retired, lowercase SHA256. Channel mode: case/phase `none`, facts still a SHA256 identifier. |
| `writer`, `previous` | For observations: canonical positive connection ID and comma-separated distinct previous reader IDs, or `none` for empty history. Both `none` in channel mode. These declarations do not establish the writer's provenance. |
| `issued`, `expires` | Current positive epoch seconds, ordered window at most 30 seconds, checked against wall and monotonic clocks. |

The directory is one-shot. Any preexisting started/result/completion/failure
original refuses before connecting. Revoked/retired controls refuse. The reader
exclusively creates and fsyncs `read.started.original` before obtaining a new C
API connection. It disables TCP, defaults-file processing, automatic reconnect,
local infile and multiple statements, sets two-second connection/read/write
timeouts, and uses the fixed local database/socket. No credential is placed in
arguments, environment, errors or output.

A channel read checks the initial connection and status, sets only that session
to READ COMMITTED/autocommit, then checks the same connection's session variables,
database/socket and `SERVER_STATUS_IN_TRANS`/`SERVER_STATUS_AUTOCOMMIT` flags.
An active transaction before configuration refuses; configuration never commits
an inherited transaction. `START TRANSACTION READ ONLY` is not used. This path
performs two SELECTs and two session settings and reads no QA or customer rows.

An observation additionally checks distinctness from writer/history, selects the
two fixed QA tables' InnoDB engines, and reads matching QA rows in one UNION ALL
statement. It rechecks the same connection/session/status after each query. The
query accepts at most one exact fictional business row and one journal row;
split/absent pairs are preserved. It does not read native financial tables.

`read.original` retains validated metadata and selected original QA rows as
bounded native text. It binds run/request/boot/case/phase/facts/writer, actual
connection, started/finished epoch times, validated isolation/autocommit/status
and approval SHA256. Rows use `row=kind,run,case,amount-or-business-case,facts`.
All row values are closed validated tokens; no user text is interpolated.
Credential and approval identity/content are rechecked before completing.
`read.complete.original` binds request and original SHA256. Stdout contains only
`native-read-retained`, not database data.

On failure after reservation, `read.failure.original` is retained and dominates
any completion marker. Partial/missing originals, a failure marker, changed
custody, unverified transport exit/EOF or missing completion never qualify a
read. The trusted caller must verify every original's protected custody and
hash, current revocation and actual process retirement; a copied text document
is not a native receipt. No file is overwritten, retried or automatically
removed, and no result releases a barrier or advances an operation.
