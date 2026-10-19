# Offline manual reader v2 candidate

`manual_financial_reader_v2_core.hpp` is a separate offline core, with no CLI,
credential loader, SDK entry, live issuer or NativeAccess factory. The v1 core,
collector, protocol and tests retain their original behavior and exact bytes.
Successful authored observations prove no native action or notification isolation.

The request wraps `ManualFinancialRequest` with exact protocol
`paymenter-manual-financial-offline-v2` and the only enum profile
`AuthoredEmptyDocV0`. Unknown profiles or protocols, including v1, refuse before
any database/session call. The base request's numeric, ownership, journal,
case/phase, schema, FK, digest and size rules remain unchanged.

## Deliberately authored compatibility profile

Only method.xmlparams gains a finite byte match: ASCII `<doc/>`, exactly six
bytes, hex `3c646f632f3e`, emits `X:authored-empty-doc-v0`. This is an authored
candidate, **not** a claim about BILLmanager's canonical default. Runtime inputs
cannot add XML bytes, hashes or accepted profiles. A separately reviewed native
profile/control/result version requires actual vendor evidence first.

The fixed financial statement uses OCTET_LENGTH plus binary equality against the
literal blob. NULL emits `N`, zero bytes emits `E`, the exact candidate emits the
fixed token, and all other nonempty content emits `P` and refuses. No raw XML or
arbitrary XML digest is exported. Whitespace, declaration, case, comments,
attributes, nested content, DTD/entity/PI, embedded NUL and malformed variants do
not normalize into acceptance. Other method profile fields and payment XML keep
v1's rules; the exception applies only to this method XML position.

V2 separately checks method company0, group0, project total1, project foreign or
NULL0 and parameter0, in the same statement. The sole project edge must target
the registry-owned project. The independent method allowrefund observation must
be `V:off`; NULL/empty/on or missing physical column refuses. V1 does not gain
these allowances or pretend to have observed allowrefund.

## Observation boundary

One supplied independent ReadDatabase connection, READ COMMITTED/autocommit,
connection/history/writer separation, one-use monotonic deadline, all25 InnoDB
tables, no selected-table triggers, exact schemas/FKs before and after, and one
fixed UNION ALL financial statement are retained. Fewer than128 rows, at most
65536 cell bytes and at most256 printable ASCII bytes per nonempty cell; no
financial writes, second balance/payment view, DDL or arbitrary query override.
Up to eight users fit below the unchanged row bound. Missing/extra/duplicate or
foreign cells refuse. Credit/journal inconsistencies remain observations for the
outer evaluator; they are not silently corrected or described as success.

The core does not connect or authenticate a database principal. A future live
collector must retain the existing protected credential/local-socket/root
principal/image/custody wrapper under its separately reviewed v2 contract.
No such wrapper is shipped by this increment.

Tests execute emitted SQL in fictional SQLite tables with an explicit byte-cast
adapter because SQLite's `CAST AS BINARY` is not MySQL binary semantics. This
checks relational predicates, byte variants and privacy; it does not qualify an
actual MySQL schema/engine/collation, protected original or source installation.
