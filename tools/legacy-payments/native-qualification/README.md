# Source payment qualification contracts

This directory currently implements **preparation contracts only**. It does not
contain a source plugin, native issuer, observer, executor or acceptance
summarizer. No command in this directory installs anything or changes a payment.

```sh
python3 -m unittest discover \
  -s tools/legacy-payments/native-qualification/tests -p 'test_*.py'
```

The tests use fictional documents held in memory. They qualify the parsers and
fixed proposal sequence only; they do not qualify framework transactions,
native manual credit, provider validation or financial posting.

## Interfaces

- `admission.parse_profile` parses a closed, bounded profile with four original
  evidence references: runtime, build, resource registry and authority.
- `admission.assess` checks supplied original bytes against those references and
  checks proposal completeness. `ready=True` is **not execution authority**.
  Document labels such as `reviewed` are proposal assertions, not independently
  established native facts. All four acceptance flags remain false.
- `admission.build_operation_plan` produces a fixed, immutable proposal of
  operation enums and evidence references. It accepts no command, SQL or URL.
  It neither creates resources nor grants authority to do so.
- `admission.AdmittedRun` is reserved for the later protected native issuer.
  Ordinary construction refuses with `native-issuer-unavailable`. No live
  capability can be issued by this increment. Future consumers must validate
  issuer ownership, process identity and resource custody, never type alone.
- `protocol.parse_case` parses QA receipts that retain original evidence
  references. These receipts carry no success boolean or financial authority.
  Parsing a receipt does not read or qualify its referenced observations.

References are canonical absolute POSIX paths with exact byte length and SHA256.
Pure parsing does not establish filesystem custody, source identity or original
actor authorization. Those checks belong to the future native issuer. A manual
proposal cannot be executed until the installed action, supported invocation,
actor permissions, original amount/currency mapping, per-fixture isolation and
retirement method are established from original evidence.

## Native prerequisites still open

The installed SDK declarations support an administrator access mask, an action,
an after-event, prepared statements and the existing global cache. They do not
prove that an after-event shares the original payment transaction or executes
before its commit. Native observations must prove both successful commit and
rollback behavior. Do not use `Final`, a paid status, an SDK counter, a successful
lookup or `ValidateAuth` as commit or provider-validation proof.

Compilation, loading, QA-table creation and native fixture operations remain
separately gated by the approved implementation plan's concrete operation
packets. Unknown installation/unload behavior blocks source execution. No
manager restart, direct balance repair, provider refund, customer-record reuse,
hold release or billing handover is a fallback.

The complete implementation remains tracked in
[the source qualification plan](../../../docs/superpowers/plans/2026-10-18-source-payment-native-qualification.md).
