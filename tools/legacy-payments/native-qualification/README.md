# Source payment qualification contracts

This directory contains **preparation contracts and generic probe code only**.
It has no private native issuer, automatic module entry, observer, executor or
acceptance summarizer. Source installation and native acceptance remain blocked;
no shipped Python command installs a module or changes a payment.

```sh
python3 -m unittest discover \
  -s tools/legacy-payments/native-qualification/tests -p 'test_*.py'
```

The tests use fictional documents and locally compile the real probe adapter
against small authored SDK doubles. They check parsing, proposal sequencing,
request guards and emitted calls. The doubles implement no database transactions
or vendor lifecycle. These tests do not qualify installed SDK/ABI compatibility,
framework transactions, native manual credit, provider validation or posting.

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
- `native_metadata.parse_action_catalog` parses bounded original `actionlist`
  XML and returns exact action names and an original-byte fingerprint. It rejects
  empty CLI output, role warnings, duplicate entries, wrong routes, financial
  receipts, DTDs and unknown shapes. Names (including group/`.one` companions)
  establish presence only. They grant no invocation, hook, settlement or commit
  authority. A wildcard catalog entry is never permission to register a wildcard
  hook. Protected custody and source identity remain separate issuer obligations.

References are canonical absolute POSIX paths with exact byte length and SHA256.
Pure parsing does not establish filesystem custody, source identity or original
actor authorization. Those checks belong to the future native issuer. A manual
proposal cannot be executed until the installed action, supported invocation,
actor permissions, original amount/currency mapping, per-fixture isolation and
retirement method are established from original evidence.

## Native prerequisites still open

The retained installed SDK declarations support an administrator access mask, an action,
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

Inspect version-matched vendor build targets before proposing installation.
An `install` target can invoke a manager restart even when its name suggests
only file installation. A load declaration, module destructor or global core
deinitialization function does not establish safe unloading of one owned module.
Keep vendor build files, native catalog originals and runtime evidence private.

## Framework probe preparation

`native/cache_probe.cpp` defines the exact own action `paymenter.qa.transaction`
and its after-event. `RegisterFrameworkProbe` has no automatic caller. It must
only run inside a separately qualified SDK `MODULE_INIT`, after a protected,
process-bound private issuer admits the exact operation. The abstract
`FrameworkAuthority` interface in `cache_probe.hpp` has no production
implementation here. Its diagnostic recorder must retain the writer's server
connection ID in the admitted source-private evidence file. A complete proposal,
a Python type, a boolean, an HTTP
parameter or a test double cannot establish its protected native facts.

The probe restricts requests to the admitted actor, run, case, nonce, process,
thread and session. It rechecks authority after protected reads, pins the supplied
cache and connection objects, and rejects reused cases and reentrant phases.
Only F1–F4 have write modes; F5 consists of refused requests. A request named F5
never writes. Fixed deadlines bound the case and the own-action observation
barrier. The private bridge's methods must themselves perform bounded local
reads; no unqualified blocking callback belongs in that bridge.

The action inserts a fictional `0.0100` USD business row; its after-event inserts
one matching QA row through the same supplied cache. Throws after either write
and a pre-return observer barrier are QA behaviors on this own action only.
`Final` removes memory context and provides no commit signal. The probe checks
cache/connection object identity and uses `SELECT CONNECTION_ID()` through that
same cache to detect server-connection replacement. That ID is retained only by
the private bridge; it proves no transaction or durable outcome. The barrier
does not poll SQL while waiting for the observer. Rollback and independent
visibility still require native observations.

`native/schema.sql` defines the two fixed InnoDB QA tables outside handlers and
module startup. Existing table names must refuse the operation. The schema has
no foreign keys or native customer/payment references. Its execution, engine and
schema verification, resource ownership, exact retirement, private bridge,
action metadata and installed-vendor compilation all require the concrete native
packet; none is supplied by these local tests. Paid fixture records and accounting
audit are never cleanup targets of this probe.

The complete implementation remains tracked in
[the source qualification plan](../../../docs/superpowers/plans/2026-10-18-source-payment-native-qualification.md).
