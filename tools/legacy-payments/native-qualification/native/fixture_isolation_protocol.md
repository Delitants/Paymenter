# Offline fixture boundary

`fixture_isolation.py` is a pure preparation/refusal model, not an issuer or a
filesystem, database, native SDK or notification adapter. It performs no source
I/O. Its immutable references describe authored inputs; they do not authenticate
originals, actors or file custody. Native N0–N3 decisions always deny, including
when every authored reference is present.

The only profile version is `paymenter-fixture-isolation-offline-v1`; protocol
`authored-config-v0`. Run is full64 lowercase hexadecimal, and module is `pmqa`
plus the first28 run characters. Full run and registry digest remain bound; a
matching truncated name is never authority. Any occupied name refuses. Expected
and actual profiles must match exactly, including executable/ancestor identities,
hashes and future monotonic expiry. A nonempty tuple contains one executable pin
and at most one inert metadata pin. Paths are canonical absolute printable ASCII,
with no dot/empty components, whitespace, backslash or duplicate path. Native
root/0600/single-link/0700 custody must eventually be checked by existing protected
readers; these Python values alone prove none of it.

Evidence reference kinds are closed: launcher, config, filter, fallback, creation,
financial-read, notification-direct, notification-queue and retirement. References
must bind the same full run/registry, with lowercase64hex digests. Unknown,
duplicate-kind, foreign or malformed inputs refuse with a fixed code, never input
contents. There is no JSON/environment activation, live factory or payment API.

## Native gates still closed

- N0: exact vendor config argv/bytes/parser, resolver/launcher/environment/FD/image
  chain, optional static metadata, target ABI/filter enforcement, original image
  custody, actual build-fallback paths and protected absence. Also supported
  constructor/config-error diagnostic notification prevention and durable evidence.
- N1: supported creator/disable/read interfaces, original IDs and authenticated
  supplied actor/session/project, creation-path direct/queue prevention/evidence.
- N2: actual owned disabled USD graph, allowrefundoff and exact owned project,
  canonical vendor empty XML and versioned live collector, complete independent
  provider/direct/queue facts, NativeAccess/session/cache binding and fresh recovery.
- N3: exact guard revocation/experimental manager recovery and surviving audit
  references; no invented callback-removal API or paid-record deletion.

BILLmanager stays billing authority. No fixture record, native callback invocation,
provider operation, source installation/restart, financial mutation or handover is
performed or authorized by successful offline validation.
