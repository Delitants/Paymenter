# Separate offline XML candidate and diagnostic policy

This is an authored config-only candidate, not an installed vendor module or
native evidence producer. The original child, v1 isolation profile, financial
readers and manual contract retain their original meanings and refusal gates.

## Child interface

Build `fixture_vendor_config_child.c` separately from `fixture_child.c`; both
export the entry symbols consumed by the unchanged `fixture_child_x86_64.S`.
They must never be linked together. The new child accepts exactly argc 3 with
`argv[1] == "--command"` and `argv[2] == "config"`. It does not read argv[0],
environment or stdin. Matching includes the terminating null bytes.

After filter setup it writes exactly these 110 bytes to stdout, without a
trailing newline:

```xml
<doc><feature><pmtune>off</pmtune><pmvalidate>off</pmvalidate><pmusercreate>off</pmusercreate></feature></doc>
```

SHA256: `2650423ddfff875c7824a89a36be0557298e0015b8b3a336788b0f7fa9d94757`.

| Outcome | Status | Output |
| --- | --- | --- |
| Exact config invocation and complete write | 0 | Exact config on stdout |
| Any other invocation | 64 | Exactly `fixture invocation refused\n` (27 bytes) on stderr |
| Error or short write | 74 | One write attempt only; no retry or additional output |
| Filter setup refusal, controlled by assembly entry | 126 | No output |

Extra arguments, `--lang`, the old `--offline-config-v0` switch, malformed or
case-changed commands and all financial/tune/validate/user-create/async commands
refuse. No success-like payment XML exists. The config contains only the three
global feature nodes: no per-method capability overrides, payment_type entries,
params, extra needprofileinfo fields or credentials. Intrinsic name/person
defaults can still be inserted by the vendor; an empty extra-field declaration
does not mean an empty effective profile set.

The unchanged filter uses ordered legacy prctl no_new_privs and filter setup,
the Linux 3.10 interface, x86-64/non-x32 checks and full-width fd/count comparisons.
It permits only bounded stdout (4096) and stderr (256) writes and raw exit calls.
All other calls kill the calling task. This requires a single-thread freestanding
entry without a loader or constructors; it is not a process-wide kill guarantee.
The portable driver checks the setup arguments and actual BPF bytes, models
failures and counts output attempts. Its results are not actual kernel evidence.
ET_REL objects prove compilation only. Existing linked-image/kernel receipts
belong only to their pinned old images and cannot qualify this candidate.

## Closed authored policy model

`fixture_notification_policy.py` defines frozen `PolicyProfile` and
`PolicyEvidenceRef` types. The only profile version/protocol are
`paymenter-fixture-policy-offline-v2` and `authored-vendor-config-candidate-v1`.
The sole `DiagnosticPolicy` value is
`existing-admin-problems-allowed-customer-notices-forbidden-v1`.

Profiles bind a full lowercase 64-hex run, registry digest and generation, the
derived module name, one or two immutable ImagePins, and a future exact-integer
monotonic expiry. Image metadata uses the unchanged v1 canonical-path and
printable-identity rules. Occupied module names refuse; the shortened module
name never substitutes for full-run binding. Metadata is authored, not custody.

References have exactly the enum kinds `customer-direct-prevention`,
`customer-queue-prevention` and `administrative-problem-scope`, with distinct
digests and exact full-run/registry/generation bindings. There may be zero to
three references. Wrong types, mutable containers, malformed digests, duplicate
kinds/digests and foreign bindings refuse with fixed errors:

| Invalid domain | IsolationError text |
| --- | --- |
| Profile, time, image or name inputs | `fixture-policy-profile` |
| NativeGate type | `fixture-policy-gate` |
| Evidence container or reference | `fixture-policy-evidence` |

Even complete references return `AdmissionDecision(False,
('native-evidence-verifier-absent',))` for every N0–N3 gate. These references
establish neither authenticity, prevention, recipients, delivery nor execution
authority. There is no native factory, NativeAccess issuer, request handler,
JSON/env activation or conversion to manual::Snapshot. v2 is never relabelled as
v1; v1 direct_isolated/queue_isolated continue to mean their original no-send
assertions, with no Boolean adapter based on permission for administrative mail.

## Notification and launcher prerequisites

Existing administrative problem alerts are permitted without changing global
settings. That path may commit a problem and attempt direct SMTP; neither is
test success, and delivery is unknown without independent evidence. Before any
future native operation, verify its existing audience/settings source-side
without exporting addresses or bodies, exact owned context, autosolve and other
follow-up effects. Unrelated alerts remain untouched. No new diagnostic message,
per-report mute, SMTP/template change or support contact is implied.

Test-related direct and queued customer notices remain prohibited. Disabled
users, fake addresses, notifytask_stat totals, template history, queue emptiness
or administrative permission do not prove whole-path prevention. Complete owned
interval evidence must survive queue/file cleanup. No qualified native producer
for customer prevention or administrative scope exists in this increment.

The retained string launcher passes through `/bin/sh -c` before the child; a
vector launcher is a separate interface. The child filter cannot constrain that
earlier shell, loader, cwd, inherited environment/descriptors or helpers. Actual
resolver/absolute image, virtual dispatch and complete process retirement still
need qualification. A selected result routine is not a descendant/deadline
guarantee. The installed resolver must establish protected absence of source
Makefile, retry marker and build fallback paths; never guess paths or invoke
config to discover them. Cold config and post-commit reload are separate finite
slots. Unexpected repeats, build/fallback, changed image or lost/error outcomes
stop and retain originals without retry.

## Native and financial boundaries

This output is config XML consumed by MethodInternal::Load, not method-row
xmlparams. The independent financial-reader v2 exact `<doc/>` profile is
authored, not a verified native default. Never place config XML in the row,
normalize unknown XML or accept arbitrary supplied bytes/hash. A future native
reader needs independently observed empty method bytes, one proven owned project
edge, allowrefund=off, and the existing one-view/schema/FK/cell/privacy limits.
Payment/receipt/profile fields, company/group/param and foreign/duplicate project
edges remain refused.

Future creators must use the supplied original Session and supported native
creator/disable/read interfaces, exact actor/project/cache/worker bindings and
original requests/replies/IDs. No reauthentication, unbound InternalCall, invented
IDs or SQL fixtures. Known notify=off, recovery=off and verify_email=off flags
cover only examined branches, not whole-path prevention. The eventual graph is
one owned account/USD subaccount, all disabled nonstaff users, one distinct
disabled USD manual method with zero fees, nonrecurring and allowrefund=off, and
four distinct unpaid 0.0100 payments. No such graph is created here. No customer,
provider, order, invoice, service, expense, refund or stored-method links belong
in it. Wizard commit/reload are real boundaries an outer rollback cannot erase.

| Gate | Still required |
| --- | --- |
| N0 | Actual launcher/resolver/environment/fds/retirement, fallback absence, config discovery/parser, administrative audience/follow-up and applicable customer prevention |
| N1 | Supported creator, actor/project and original reply/ID provenance, disabled graph, complete creation customer-notice prevention |
| N2 | Native method XML/defaults/project, versioned independent reader, explicit v2 admission, original callback/session/cache integration |
| N3 | Guard revocation, exact process recovery retiring the experimental manager generation, surviving audit/image references |

All four remain closed. Framework F1–F5 do not substitute for native M1–M5:
one successful original payment plus journal commit; pre-journal and post-journal
failures each rolling back both native finance and journal; barrier-coordinated
duplicate callers proving one committed effect; and readback of a lost reply's
original committed outcome without dispatching another payment. Each requires
actual native entry and independent original observations.

Unknown outcomes consume their scope: preserve original errors, paid/unknown
rows and audit; separately authorized readback is not automatic retry. Never
clear a problem, manufacture an ACK, delete paid rows or refund as cleanup.
Callback Store has no retained removal API: revoke its guard and require exact
separately approved recovery before claiming unload. Keep inert images while
audit references need them; never restore a stale whole database over later
legitimate activity.

Provider signature/custody validation, external capture/refund, acknowledgement
and retry, once-only target receipt posting/ACK, legacy opening allocation,
final delta, source writer retirement and billing handover are separate gates.
Archived paid rows are never new deposits. Offline success authorizes no server,
provider or financial action. BILLmanager remains billing authority.
