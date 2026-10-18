# Fixed native QA retirement backend

`qa_retirement_mysql.cpp` is an internal C API backend for
[the existing retirement policy](case_evidence_protocol.md). Compile it alone;
it includes the protected archive, bridge and policy. Do not separately compile
those included translation units into the same binary. It is never linked into the SDK.
It has no main, command interface, table creator, native resource issuer, module
unloader or permissive guard. It cannot turn a preparation proposal into a run.

The trusted issuer must supply a qualified protected credential channel, immutable
original resource registry, process-bound cleanup guard and monotonic deadline of
at most 30 seconds. The guard must independently establish original table
absence/creation/schema custody, exact SDK writer retirement, current source boot
and fresh cleanup authority. A protected assertion or an empty table alone is
insufficient. This backend does not establish those prerequisites. The object is
confined to its original process/thread; it does not accept command, SQL or table
selectors from the caller. Credentials must remain source-private, never in
arguments, environment, output, receipts or committed configuration.

The channel uses only a local Unix socket, no client-defaults parser, TCP,
multistatements, local infile or reconnect. Native connect/read/write timeouts
are each two seconds. Session/database/socket/connection ID and the C API server
status are checked against the original local connection. An initially open
transaction or disabled autocommit refuses before locking. The issuer must also
supervise the direct child with the packet/case deadlines and aggregate output
budget: a client timeout alone does not qualify server-side cancellation.

Before any table lock, fixed metadata SELECTs require both exact names to be
InnoDB base tables, with no triggers and no incoming or outgoing foreign keys,
including links from other schemas. Before those metadata SELECTs, session
metadata and InnoDB lock waits are set to two seconds and read back; `innodb_table_locks` must be enabled. All these
settings apply only to this newly owned connection. No global setting is changed.
Exclusive original schema custody and retired writers remain guard obligations;
metadata preflight alone cannot exclude a concurrent privileged schema change.

The backend disables autocommit, then requests one fixed WRITE-lock statement
covering both QA tables. It repeats the object/link checks after locking. It uses
no `START TRANSACTION`, additional `LOCK TABLES`, `FLUSH`, named/global lock,
`IF EXISTS`, `TRUNCATE`, cascading operation or native payment table. The policy
retains and reloads the archive before permitting deletion. Fixed SHOW CREATE
statements and a bounded UNION ALL return both definitions and all QA rows;
33 rows detect overflow instead of silently truncating the policy's 32-row cap.
Null/oversized/malformed results refuse.

An erase must match the immutable original run and registered case/facts, as well
as the fixed journal business key and fictional `0.0100` amount. It rechecks both
schemas, deletes the matching journal/business rows and explicitly commits with
`AND NO CHAIN NO RELEASE`, then confirms the unchanged session has no open
transaction. The explicit server table locks are intended to remain held across
this commit; the exact installed MariaDB version still needs native qualification.
Both schemas and table emptiness are rechecked before one fixed DROP of both
names. Old-server DDL may partially succeed, so no response alone counts as
retirement. A fresh metadata SELECT must establish that both names are absent.

Normal release explicitly rolls back any remaining read transaction without
chaining/releasing, unlocks and closes the connection. Query/commit uncertainty,
expiry or revoked guard closes this connection and leaves retirement incomplete.
A released channel is terminal; later metadata/DDL calls refuse before client I/O.
It never reconnects, retries DML/DDL, restores rows, recreates a dropped table or
compensates through another account/provider. A forked object's destructor must
not send COM_QUIT over the parent's inherited socket; it abandons that local
allocation until child exit. No unrelated connection or process is signalled.

The C API tests are strict authored boundary doubles, not a live database.
Installed-header object compilation checks source compatibility only. Actual
lock retention, SQL/DDL behavior, resource creation and module/writer retirement
must be established by the separately approved source-native packet before any
cleanup CLI or full admitted-run factory is enabled.

The SQL design follows the vendor documentation for
[table locks](https://mariadb.com/docs/server/reference/sql-statements/transactions/lock-tables),
[metadata lock timeouts](https://mariadb.com/docs/server/reference/sql-statements/transactions/metadata-locking),
[implicit commits](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit)
and [commit versus explicit table lock lifetime](https://dev.mysql.com/doc/refman/9.7/en/lock-tables.html).
These current references are design guidance, not qualification of the installed
legacy server.
