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
fd/count comparisons prevent high-word bypass. It kills the calling task for
every other syscall using Linux3.10's `SECCOMP_RET_KILL` action. That terminates
the entire fixture only because its freestanding entry is strictly single-thread:
no interpreter, constructors or pre-filter thread creation, and no clone/fork/exec
allowed after setup. This policy is not valid for a multithreaded process and does
not claim thread-group termination. The final ELF/entry/launcher qualification
must establish this invariant; an unqualified image cannot gain native admission.

`prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0)` followed by
`prctl(PR_SET_SECCOMP, SECCOMP_MODE_FILTER, &program, 0, 0)` must succeed before
argument handling. The child uses that one fixed setup interface; it never tries
a newer syscall or continues without a filter. Either setup failure exits126
with no config or diagnostic output. Linux3.10 with actual seccomp-filter support
is the candidate floor; kernel version alone does not establish support. The
filter limits each write; the immutable program limits total writes. It does not
constrain the parent launcher or establish notification isolation.

The [upstream Linux3.10 filter documentation](https://raw.githubusercontent.com/torvalds/linux/v3.10/Documentation/prctl/seccomp_filter.txt)
describes this interface and its no_new_privs prerequisite. It is not evidence
that an installed host has filtering enabled.

Tests compile the actual C with an authored syscall boundary and interpret the
same filter bytes against denied syscall/descriptor/count/architecture inputs.
The authored syscall boundary admits only ordered no_new_privs then legacy prctl
filter installation with the actual BPF bytes, and can refuse either setup step.
The portable interpreter uses a distinct invalid-program sentinel, so malformed
instructions cannot masquerade as the zero-valued legacy kill action.
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
