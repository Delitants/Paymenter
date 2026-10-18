# Protected framework control protocol

This is native QA control, not a proposal profile, financial receipt or Python
admitted-run issuer. The private issuer must validate explicit approval and
original runtime/build/resource facts before writing it. That issuer and the
automatic module entry are not implemented. Never create these controls from
`assess.ready`, `reviewed` labels, HTTP parameters or a fictional test fixture.
Root file custody and matching hashes do not prove a database commit.

`OpenProtectedFrameworkAuthority(directory)` is the only public bridge factory.
It accepts no owner, clock, runtime-check, process-ID or hash override. It requires
Linux and effective UID/GID zero. The root-owned operation directory must be mode
0700 on ext4, XFS or tmpfs. Its ancestors must be root-owned directories without
group/other write permission; no symlink is followed. Directory replacement
revokes access even though the old descriptor remains open.

Inputs are mode-0600 root-owned regular files with one link. Original bytes and
identity/nanosecond metadata remain bound, including across identical-content
replacement. Controls are ordered printable ASCII `key=value` lines, preceded
by their exact purpose and ending with a newline. Unknown, missing, duplicate
or reordered fields refuse. Control size is at most 4096 bytes, each value at
most 1024 bytes. Paths are canonical absolute ASCII paths without whitespace,
backslashes, doubled separators or `.`/`..` components. Digests/nonces are 64
lowercase hex characters. Positive decimals have no leading zeroes and fit
unsigned 64 bits.

## Immutable authority

`authority.v1` starts with `paymenter-source-qa-authority-v1`, then these keys in
this exact order:

| Keys | Constraint |
| --- | --- |
| `intent` | Exactly `framework-qa-execute`; preparation intent refuses. |
| `run` | 64-character operation identifier. |
| `boot` | Actual kernel boot identifier. |
| `actor_hex` | Lowercase hex encoding of the exact SDK actor, at most 128 decoded bytes; control bytes refuse. |
| `issued`, `expires` | Current epoch window of at most 600 seconds; future issue and expired windows refuse. |
| `module_path`, `module_sha256` | Actual loaded bridge image and original-byte hash. |
| `runtime_path`, `runtime_sha256` | Actual kernel-reported executable and original-byte hash. |
| `registry_sha256`, `build_sha256` | Hashes of the two protected originals below. |
| `nonce_F1`, `facts_F1`, `nonce_F2`, `facts_F2`, `nonce_F3`, `facts_F3`, `nonce_F4`, `facts_F4` | Immutable case bindings; four distinct nonces. |

The native factory checks actual boot, PID/start, executable and loaded-image
mapping. Mapping path/device/inode and executable/private permissions must match
the opened module. The module must differ from the process executable. Both
image hashes are checked initially; their identities/metadata are rechecked for
each grant validation. A forked process refuses before taking the bridge mutex.

`registry.original` and `build.original` are immutable hash-bound originals of at
most 65536 bytes each. Their semantic qualification belongs to the private issuer:
the bridge performs no SQL, installation or independent source-fact discovery.
The registry must register all four fixed diagnostic names before activation.
Any existing diagnostic at factory open refuses operation reuse.

## Current case

`intent.v1` starts with `paymenter-source-qa-intent-v1`, with ordered keys:
`run case nonce facts pid start issued expires`.

Only F1–F4 are grantable. They map to normal, throw-after-business,
throw-after-journal and observe-before-return. There is no mode override. Nonce
and facts must match the immutable authority. PID/start must match the actual
factory process. Issue/expiry must be current, within the authority window, and
at most 30 seconds apart. Both wall-clock and monotonic deadlines apply.

Case issue is single-use. The originating session identity and thread remain
bound: another session cannot record its diagnostic and another thread cannot
use the grant. The SDK probe separately enforces actual administrator permission
and native session/context. Intent replacement invalidates the outstanding grant;
the controller may publish the next one only after independent case resolution.
Any `revoked.v1` or `retired.v1` directory entry revokes the operation.

## Diagnostics and barrier

Before the business insert, exclusive non-following creation writes exactly one
of `writer.F1.original` through `writer.F4.original`. It records run/case/nonce,
facts, canonical server connection ID, PID/start and original intent hash. The
file and directory are synchronized. Write, sync or reuse failure holds the
bridge, retains the partial/failed original and performs no cleanup or retry.
This diagnostic is not a commit token; the framework still owns all SQL.

Only F4 reads `release.F4.v1`. Its purpose is `paymenter-source-qa-release-v1` and
ordered keys are `run case nonce original_sha256`. Absence means wait; malformed
or mismatched release throws. It binds current F4 identity and the exact bytes of
`barrier.original` (at most 65536 bytes). The observer/issuer must first qualify
that original as the correct independent committed-reader result. Hashing
arbitrary bytes does not establish independence or visibility. No SQL poll,
network request or SDK callback belongs in these methods; the probe owns the
five-second wait ceiling.

## Verification boundary

Local tests use real file operations with the test user's fixture ownership and
authored runtime/clock facts inside the implementation test unit. Those overrides
are absent from the public factory. The sync-failure double wraps only `fsync`;
the written/failed bytes remain real fixture files. These tests do not qualify
Linux root acceptance, loader ABI, source evidence semantics, transaction coupling
or retirement. Syntax-only checks establish none of those native outcomes.
