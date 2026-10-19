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

## Authored confined child candidate

`fixture_child_x86_64.S` and `fixture_child.c` are freestanding Linux x86-64
sources with no libc startup. Assembly installs the filter before reading argc
or argv; it preserves the original stack and uses the raw exit syscall. The
candidate recognizes only `--offline-config-v0`, emits a bounded fixed offline
semantic document and exits0. All other arguments emit a fixed refusal to stderr
and exit64. Setup error exits126 before any output. Output errors/partial writes
exit74 without retry. It never reads stdin, argv[0] or environment.

The cBPF array in `fixture_child_policy.h` requires x86-64 (not x32) and only
allows exit/exit_group and writes to stdout<=4096 or stderr<=256 bytes. Full-width
fd/count comparisons prevent high-word bypass. It kills every other syscall;
`PR_SET_NO_NEW_PRIVS` then `SECCOMP_SET_MODE_FILTER` must succeed first. Minimum
Linux kernel4.14 with filter support is required. The filter limits each write;
the immutable program limits total writes. It does not constrain the parent
launcher or establish notification isolation.

Tests compile the actual C with an authored syscall boundary and interpret the
same filter bytes against denied syscall/descriptor/count/architecture inputs.
Those are portable tests, not kernel acceptance. Cross-compiling relocatable
objects checks assembler/compiler syntax only. Final static ELF checks require a
compatible ELF linker; `LinuxFixtureChildTests` also requires actual x86-64 Linux.
Missing prerequisites are explicitly `BLOCKED-O2`, never silently counted as an
accepted target. No linker, VM, kernel or other dependency is downloaded.

The candidate config bytes and switch are intentionally unrelated to the still
unqualified vendor protocol. No installable vendor image, discovery sidecar,
source build path or production module registration is created by these tests.

## Forward-only failure model

Lifecycle starts ABSENT, reserves intent, then retains either a known authored
creation result or an UNKNOWN result/error/lost response. A known result can bind
an independent readback, but cannot produce ISOLATION_QUALIFIED. An UNKNOWN result
can append an independently authorized read reference and stays UNKNOWN: no
retry, assumed rollback, guessed identity, credit repair or deletion. Original
references are immutable and append-only, full-run/registry bound and duplicate
hashes refuse. These states are not native receipts or dispatch permissions.

The offline example has two ordered one-use config slots, cold-config then
post-commit-reload. A duplicate/unknown slot or any failed budget refuses. This
model does not reserve real native invocation slots. Future exact operation
packets independently establish finite authority and current source identities.

Retention always keeps evidence; paid/unknown audit stays held, and an image is
kept whenever an audit reference needs the inert module. No retention decision
permits deletion or refund, even for unpaid setup. Native reconciliation and any
retirement need separate qualified authority. Callback guard revocation and the
existing reserved manager recovery remain the only proposed unload procedure;
M5 does not dispatch another credit. No cleanup invokes SQL or a provider.
