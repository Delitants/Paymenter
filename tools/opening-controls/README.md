# External opening control rehearsal

This is an external operations toolkit, not a Paymenter extension. Python 3.10+ and its standard library are sufficient. It installs no routes, workers, schedules, trust or real signing keys. The application never invokes it.

## Dispatch/process preflight only

The [dispatch/process plan](../../docs/superpowers/plans/2026-10-14-source-containment-dispatch-process.md) has a portable preflight subset. `source_scheduler_adapter.py` projects exact reviewed whole-entry deletions and classifies claimed original/suppressed/competing file bytes without filesystem mutation. `source_start_adapter.py` checks candidate runtime/launcher obligations. `source_process_adapter.py` compares claimed process/thread/ancestry/membership and group states. These are pure planning helpers; consistent documents remain unqualified and real readiness stays false. The process input retains primary UID/GID only; complete credential, namespace, privilege and physical domain observation is still pending.

All scheduler/start/process physical API endpoints refuse with a fixed runtime-unqualified error before resource access. No matching owned legacy Cronie, vendor runtime or cgroup-v1 environment is registered. No Cronie generation observation, persistent launcher denial, freezer operation, controller-loss/reboot qualification or physical rollback exists yet. The native tests and all three full plan tasks remain incomplete. Existing SQL qualification cannot accept them.

Run only the portable preflight groups with:

```sh
PYTHONPATH=tools/opening-controls/tests python3 -m unittest test_source_scheduler_adapter test_source_start_adapter test_source_process_adapter -v
```

Their passing results are unit evidence, not native acceptance or a source maintenance permit. The wider toolkit also contains root Linux and owned MariaDB tests; do not count their unavailable-runtime failures or skip them as passed. No production adapter is added to the fixture dispatcher or application proof parser.

`custody.py` retains original bounded POST bodies, ordered headers, path/query and every delivery in an operator-owned `0700` directory with a `0600` SQLite journal. Transactions use FULL synchronization; a receipt is returned only after commit and filesystem synchronization. Authenticated Authorize.net notifications are deduplicated by notification ID and original body hash. Conflicting bodies remain quarantined; every retry remains in the arrivals table. Receipt acknowledgement means delivery, never settlement. No provider call, financial application or automatic replay exists.

Supply the separately configured Authorize.net Signature Key as decoded 64-byte data. It is never generated, loaded from the app or logged by this library. [Authorize.net documents HMAC-SHA512 verification and HTTP 200 delivery acknowledgement](https://developer.authorize.net/api/reference/features/webhooks.html). An accepted ingress can respond 200 only after `delivery_acknowledged=True`. Errors must prevent acknowledgement. Storage failure after commit is recoverable by redelivery; idempotency retains one logical event.

Other provider requests can be retained with `retain()` but cannot produce a delivery acknowledgement. Their actual source integration authentication, retry semantics and legacy reference ownership must be accepted separately. WebMoney prerequests must deny new payments while frozen: [the Merchant Interface requires YES to permit checkout](https://en.webmoney.wiki/projects/webmoney/wiki/Web_Merchant_Interface). The disposable listener returns NO and retains the request. Its HTTP responses are rehearsal behavior, not deployment configuration. Do not infer the Klarna acquirer webhook protocol applies to a hosted checkout integration.

`control.py` implements persistent **rehearsal-only** state, exact coverage of three fixture boundaries, increasing sequence, expiry and Linux boot/PID/start-time observation. A missing/changed gate or lost operator latches denial. Neither controller exit nor permit expiry removes gates. Explicit fixture release requires observed original operator exit. Permits have purpose `opening-control-rehearsal-permit` and `real_execution_ready=false`; they cannot satisfy Paymenter's native signed fence schema. Fixture gate files enforce only cooperating disposable writers, not arbitrary processes or database clients.

Run local focused tests:

```sh
python3 -m unittest discover -s tools/opening-controls/tests -v
```

The original small rehearsal has two root-Linux-only tests. Full toolkit acceptance also requires the configured owned SQL runtime described below; unsupported platforms/runtime cannot provide native acceptance. Run outside the app in a network-isolated root process:

```sh
unshare --net python3 -m unittest discover -s tools/opening-controls/tests -v
unshare --net python3 tools/opening-controls/rehearsal.py --evidence /ABSOLUTE/PRIVATE/DIRECTORY
```

The evidence directory must already be owned by the operator with mode `0700`. The runner creates only a unique `/dev/shm/opening-controls-rehearsal-*` directory, disposable SQLite ledger, Unix-domain callback listener, fixture key and owned subprocesses. It requires 1 GiB root and 512 MiB RAM reserve. It tests recurring source/target writers, late/duplicate/conflicting callbacks, unsupported aliases, denied WebMoney checkout, controller SIGKILL, expiry, fresh-process renewal and operator exit. It stops only subprocesses it created, retains optional fixture evidence and removes its owned RAM directory. It never imports Paymenter or opens its database socket.

This increment is **not a live freeze implementation or live acceptance**. Before genuine signed lease issuance, separately implement and accept persistent source scheduler/process/CLI/SQL controls; scoped canonical/direct/alternate ingress enforcement; target writer exclusion; provider-specific late-payment custody/reconciliation; MariaDB-version-matched lock-connection rehearsal; external signer isolation; exact manifests and actual-cohort timing. Preserve source billing until separately approved handover. Never install these fixture gates as a substitute for those controls, or acknowledge an event based only on its presence in custody.

## Source observation and coverage

`source_cli.py` has only `observe` and `evaluate`. Supply an explicitly private capture profile (0700 parent, 0600 regular file). A profile selects allowed read paths and source executables/modules; it cannot supply shell/SQL commands. Mark each actual nginx entry point `nginx-root`; `nginx` supplemental captures are parsed only through active includes. A copied inactive configuration never becomes an active entry point automatically.

```sh
python3 tools/opening-controls/source_cli.py observe --source "$SOURCE_SSH_DESTINATION" --profile "$CAPTURE_PROFILE" --evidence "$SOURCE_PRIVATE_EVIDENCE"
python3 tools/opening-controls/source_cli.py evaluate --observation "$SOURCE_OBSERVATION" --evidence "$SOURCE_PRIVATE_EVIDENCE"
```

All variables are operator-selected inputs, not defaults. The collector executes from memory on the source using Python 2.7/3 and reads only fixed metadata/configuration operations. Raw bytes remain private. Successful observation means capture retained; unsupported/dynamic route interpretation remains explicit in the observation. Failed/mixed-generation capture is rejected and retained. No live request, cron command or provider action is executed.

The offline nginx inventory uses an explicit context, block-form and argument-count policy. It retains reviewed settings and filesystem, header, access, upstream and mapping evidence with the complete include context. Empty quoted values and quoted punctuation remain data. MIME entries are recognized only inside a valid `types` block; `map` and `geo` rows retain their containing variable definition. Includes expand only among captured files, with cycle/expansion limits; block depth is bounded. Invalid forms and unknown modules remain blockers. Variable expressions and regular-expression/named locations remain unproved, even when their syntax can be recorded.

This policy is a bounded syntax inventory, not nginx configuration validation, effective inheritance resolution, module/version validation or request execution. Static records do not establish ownership. Certificate directives retain only configured path strings; the parser never reads certificate or key files. Reinterpreting a sealed historical capture does not refresh its receipt or establish current source coverage. Independently review ownership and enforcement against a fresh capture before accepting any profile.

Cron records separate the lexical executable, forwarded command tokens, forwarded module candidate and wrapper suffix candidate in `details.cron_dispatch`. For the reviewed `cron-core`, `cron-billmgr` and `cron-dnsmgr` names, the suffix identifies candidate license-guard and conditional error-registration modules; it does not select the forwarded work. Relative forwarded paths are recorded only against an absolute normalized wrapper `/sbin/` directory candidate; executable and wrapper paths must use literal ASCII letters, digits, `_`, `.`, `/` or `-`. System crontabs retain their user field separately. A literal leading `mgrctl -m MODULE TASK` prefix can retain its module candidate even when later shell syntax leaves the whole command unproved. `details.module` aliases that candidate only without shell-expansion blockers; argument basenames and later or repeated selectors cannot assign it.

Every wrapper remains blocked by `cron-wrapper-semantics-unproved`: filenames do not verify the installed script, symlink chain or effective runtime behavior. Direct commands also retain `cron-command-semantics-unproved`, including shell/interpreter invocations and paths named `mgrctl`; no execution authority is established by their names. Unknown wrappers, unresolved forwarded commands and shell/cron expansions remain explicit blockers, including conservative rejection of quoted expansion punctuation. Forwarded tokens are lexical candidates, not exact runtime arguments: the reviewed wrapper uses unquoted expansion. Parsing never executes jobs, checks license state, creates lock files or registers errors. This changes job content hashes; prior profiles and sealed receipts must not be rebased or silently accepted.

The reviewed compression forms are `gzip`, `gzip_vary`, `gzip_proxied`, `gzip_comp_level`, `gzip_http_version` and `gzip_types`. Their contexts, argument counts and literal value forms are checked and retained. `limit_req_log_level` accepts the four documented levels. `limit_req_zone` retains only the explicit key/zone/positive-size/positive-rate form, in HTTP context, without commercial options. Variable keys remain unproved; other variable or unreviewed parameter forms are blocked. A retained zone definition does not mean requests are limited: usage, inheritance, source module/version compatibility, ownership and enforcement require separate acceptance. Numeric size syntax does not prove platform-specific memory limits.

Reviewed syntax references: [nginx core](https://nginx.org/en/docs/http/ngx_http_core_module.html), [proxy](https://nginx.org/en/docs/http/ngx_http_proxy_module.html), [access](https://nginx.org/en/docs/http/ngx_http_access_module.html), [map](https://nginx.org/en/docs/http/ngx_http_map_module.html), [geo](https://nginx.org/en/docs/http/ngx_http_geo_module.html), [upstream](https://nginx.org/en/docs/http/ngx_http_upstream_module.html), and [TLS](https://nginx.org/en/docs/http/ngx_http_ssl_module.html).

Compression and rate syntax references: [nginx gzip](https://nginx.org/en/docs/http/ngx_http_gzip_module.html) and [request rate limiting](https://nginx.org/en/docs/http/ngx_http_limit_req_module.html).

Evaluation without an independently accepted private ownership profile reports `profile-unaccepted`. Even a completely matched fictional profile has seven unproved enforcement requirements, false real readiness, and a nonzero evaluation exit. Reports and raw evidence use exclusive fsynced private files. Neither report nor rehearsal permit is a native freeze proof. Observation freshness uses controller boot and monotonic time (60 seconds), never source wall-clock time.

## Disposable MariaDB lock experiment

`lock_harness.py` requires root Linux, `unshare`, `chroot`, and an explicit private archive/manifest containing the source-matched MariaDB 5.5.68 runtime, including `mysqladmin`. No binaries or private manifests are distributed here. The archive is bounded and every member hash/mode is checked before exclusive staging. Existing roots and externally supplied sockets/datadirs are rejected.

```sh
unshare --net python3 tools/opening-controls/lock_harness.py --archive "$LOCK_RUNTIME_ARCHIVE" --manifest "$LOCK_RUNTIME_MANIFEST" --evidence "$LOCK_PRIVATE_EVIDENCE"
```

The harness creates its own network, PID and mount namespaces, a private proc view and a new chroot under `/dev/shm/opening-lock-*`. It bootstraps only fictional system/user tables and runs ten checks on 300 fictional InnoDB tables. It demonstrates SUPER bypass of global read-only, READ-lock blocking, independent snapshot reads, unrelated-schema/new-table escapes, and lock release after a keeper starts a transaction or exits. These observations establish limitations of the database primitive, never a live source freeze.

Shutdown uses the matched `mysqladmin` against the owned socket. PID/start/boot identity must still match before forced retirement; failed retirement preserves the owned RAM directory and diagnostics. Acceptance requires all ten checks and independent retirement verification, with at least 1 GiB root and 512 MiB RAM remaining. Native logs remain private; every report states `real_execution_ready=false`.

For native acceptance, run both focused groups and the full toolkit in the isolated root Linux environment; require zero skips. Mocked unit tests establish input rejection, not database behavior. Inspect the private native report and transcript for exactly ten true checks and the parent retirement record for no remaining owned PID and removal of the owned RAM directory. Keep successful and failed diagnostics, runtime/candidate hashes and source observation receipt together. A coverage report and a rehearsal permit must each be rejected by the application's unchanged native fence parser; do not convert, sign or promote them.

Publishing this toolkit does not install controls into the source or target application. Scheduler/process/ingress/CLI/SQL enforcement, a persistent secondary lock keeper, provider custody/reconciliation and target/cohort exclusion remain separate acceptance gates before any real handover.

## Fictional boundary contracts

`boundary_cli.py` is a separate local-only validator for schema-1 boundary plans, witnesses and fictional evidence. Python 3.10+ on root Linux is required for root-owned 0700 reference/evidence directories and 0600 ordinary files. References bind original bytes, including whitespace; the loader rejects unsafe links, changed hashes, file/ancestor replacement, reference cycles, unknown purposes and incompatible evidence slots. Bounds are 4 MiB per document, 32 MiB combined bytes, 10,000 combined entries and eight reference levels. The fixture helper creates only fictional temporary documents; no live capture or production adapter is accepted.

```sh
python3 tools/opening-controls/boundary_cli.py validate --plan "$BOUNDARY_PLAN" --plan-sha256 "$BOUNDARY_PLAN_SHA256" --reference-root "$BOUNDARY_REFERENCE_ROOT" --evidence "$BOUNDARY_PRIVATE_EVIDENCE"
python3 tools/opening-controls/boundary_cli.py evaluate --plan "$BOUNDARY_PLAN" --plan-sha256 "$BOUNDARY_PLAN_SHA256" --reference-root "$BOUNDARY_REFERENCE_ROOT" --evidence "$BOUNDARY_PRIVATE_EVIDENCE" --witness "$BOUNDARY_WITNESS" "$BOUNDARY_WITNESS_SHA256"
```

Repeat `--witness PATH SHA256` for each control. Exit 0 means syntax-only validation and durable report storage, never accepted coverage. Exit 3 means evaluation stored a report with production readiness false, including a complete fictional rehearsal; exit 2 means an input or storage failure and no success acknowledgement. Stdout contains counts and a report hash only; stderr contains a fixed error code. Every report has `plan_accepted=false`, `enforcement_complete=false` and `real_execution_ready=false`.

Each exclusive report filename starts `boundary-unacknowledged-`: the aggregate receipt is returned only after file/directory synchronization and final input/observer verification. A failed or interrupted write may leave an unacknowledged private artifact; its existence alone is not success. Reports are never overwritten or promoted into native opening proofs. `fixture-boundary-v1` is the only registered adapter; arbitrary imports, shell/SQL operations and production adapters are unavailable. This CLI establishes or restores no control and issues no lease. Physical adapter, custody protocol, recovery and actual-cohort acceptance remain separate work.


## Application capture/readiness evidence

The application's schema-2 opening protocol is documented in [account-opening.md](../../docs/account-opening.md#capture-and-opening-readiness-protocol-schema-2). Its private bounded graph validates capture leases, completed collector/capture acceptance, preparation history and later ready leases. Existing source observation, fictional boundary reports and rehearsal permits cannot be promoted into these signed proofs or human financial approval.

The application remains a reader. An independently accepted external custodian must observe every physical source/target writer and payment boundary, retain complete original lease history, and publish immutable pair/index generations before atomically replacing and synchronizing the current pointer. Loss or expiry never thaws controls. A continuing operator must extend the original epoch/history; a late timestamp, new capture or new baseline cannot repair an old gap or replace original batch recovery evidence.

The native test-only chronology harness uses a fictional root-private source artifact, permission probes, a dedicated preparation role on its owned database, an independent renewing fixture custodian, genuine cold restart/restore, and a later isolated operator namespace. Fixture keys and control endpoints are generated privately. The harness accepts no live host, source date or future hash inputs and is not a deployable source enforcement adapter. Real scheduler/process/CLI/SQL and canonical/direct/alternate ingress containment, provider custody/reconciliation, target writer exclusion, independent signer qualification and actual-cohort timing remain separately reviewed operational work.

## Owned SQL privilege feasibility

`sql_fence_harness.py` implements the [SQL-only design](../../docs/superpowers/specs/2026-10-14-sql-privilege-feasibility-design.md) and [six-task plan](../../docs/superpowers/plans/2026-10-14-sql-privilege-feasibility.md). It uses an explicitly accepted MariaDB 5.5.68 archive and the fictional seed generated by `tests/sql_fence_fixtures.py`. Private references select the archive and evidence destinations; they cannot supply SQL, credentials, hosts, existing sockets/datadirs, executable commands or case subsets. The pure model/projection interfaces also reject unsupported privilege, proxy and definer graphs before native launch.

```sh
python3 tools/opening-controls/sql_fence_harness.py --plan "$SQL_PLAN" --plan-sha256 "$SQL_PLAN_SHA256" --reference-root "$SQL_REFERENCE_ROOT" --evidence "$SQL_EVIDENCE"
python3 -m unittest discover -s tools/opening-controls/tests -v
```

Run the CLI as root on Linux with new mount/network/PID namespaces and an already delegated cgroup-v2 memory/PID domain. It changes no ancestor controls. JSON originals must be root:root `0600` files under an anchored `0700` reference root; the evidence destination must be new. JSON limits remain 4 MiB per document, 32 MiB combined, 10,000 entries and eight levels. The separately pinned binary archive uses the existing runtime validator's 256 MiB packed/unpacked limits; its content is validated and staged only after memory admission.

The fixed budgets are a 256 MiB private tmpfs, a 512 MiB cgroup memory cap, zero swap, 64 PIDs, 512 MiB remaining RAM, and a 1 GiB + 128 MiB root recovery reserve after the maximum 16 MiB evidence allocation. Evidence accounts for physical small-file allocation, stores identical payloads once and fsyncs every event/prefix. Statements, bootstrap, readiness and retirement have fixed deadlines; the complete invocation has a 600-second limit.

All ten native cases are mandatory: global/SUPER bypass demonstrations on separate data, protected DML/DDL denial, unrelated InnoDB/MyISAM work, cached-session drain, host/pattern/direct-grant checks, administration/definer rejection, keeper/controller loss, orderly/hard server restarts, every typed-operation intent/readback interruption boundary, and exact grant/authentication restoration while retaining unrelated deltas. The protected manifest contains 300 unchanged InnoDB tables. Only finite authenticated privilege errors count as denial; generic error 1045 is not FILE fencing proof. FILE removal is checked through the complete native privilege census.

When `retention-request.json` appears, independently copy and verify every original in `phase-manifest.json`. Publish a complete, fsynced root:root `0600` `retention-ack.json` using an atomic rename that refuses to replace an existing file. Its exact fields are `schema_version: 1`, `purpose: "fictional-sql-retention-ack"`, and `manifest_sha256` containing the original phase manifest SHA256. The operator must acknowledge verified independent retention before owned RAM staging can retire. Final originals/manifest/result must also be retained after exit.

Exit 0 requires ten actual passing cases, acknowledged original evidence and verified retirement of owned processes, sockets, mounts, cgroup and staging. Exit 1 means failure; a partial report or existing artifact is not success. Stdout contains counts and a result hash only. Every result has `real_execution_ready=false` and purpose `fictional-sql-privilege-result`; application opening proof parsers reject it. Unacknowledged native metadata is never guessed during recovery, competing state prevents overwrite, and privilege restoration is never an exit handler.

The declared fixture administrator remains trusted. This experiment establishes no resistance to host root, installs no live source adapter, signer/trust, scheduler, ingress or provider custody, and opens no financial account or migration hold. Real source containment, actual cohort duration/storage, deployment and billing handover remain separate operational gates. Native inline execution and author-only review remain the selected method.

## Real-source containment implementation planning

The approved SQL-only rehearsal is followed by four reviewable implementation/rehearsal plans: [admission and SQL](../../docs/superpowers/plans/2026-10-14-source-containment-admission-sql.md), [dispatch and process](../../docs/superpowers/plans/2026-10-14-source-containment-dispatch-process.md), [ingress and custody](../../docs/superpowers/plans/2026-10-14-source-containment-ingress-custody.md), and [composition/readiness](../../docs/superpowers/plans/2026-10-14-source-containment-composition.md). The admission/SQL increment is implemented below; the three subsequent adapter families remain separate work. Matching source kernel/vendor/runtime, actual protocols, privileged authority, full-size storage/cohort and deployed controls remain separately unaccepted. No real enforcement CLI, key, trust, freeze, payment action or handover is installed by these documents. Native inline author-only execution remains selected.

## Closed admission and source-shaped owned SQL qualification

`containment_schema.py` and `containment_admission.py` validate exact context/boot/observer generations, root-private original references, complete member/dependency assignments, privileged actor declarations and bounded resource inputs. Actual original bytes, including opaque retained source captures, share the 4 MiB individual, 32 MiB aggregate, 10,000-entry and eight-level limits. Reference substitution and stale/future/mixed-boot observations reject or block admission. A matched profile still reports `physical-control-unaccepted` and `real_execution_ready=false`.

`containment_runtime.py` issues nonserializable, process-bound contexts for newly owned SQL control-file allocation. Caller-supplied roots, sockets or commands cannot construct a context. Other finite factory kinds remain unqualified until their own implementation/native acceptance. Allocation and retirement verify actual ownership, boot/PID/start identity, private markers and original bytes; unknown files are retained rather than recursively removed.

`source_sql_projection.py` consumes a closed full seven-table MariaDB 5.5.68 census, every declared schema/object, authenticated account/host/path attribution and explicit unrelated business requirements. It preserves physical authentication/TLS/resource/grant metadata independently of normalized grant operations. Manual-only proxy edges require exclusive attributed administration; automated administrators or proxy reachability, unsupported host/authentication forms, wildcard database patterns, incomplete schema/column summaries and definer graphs reject compilation. Original fictional schemas and allowlists remain unchanged. A pure projection is not physical control evidence.

`source_sql_adapter.py` binds only an independently created owned server/context. It journals fixed grant operations, drains only accepted idle completed sessions, rejects active/transactional/unowned sessions without killing them, verifies actual seven-table integrity rows and fresh authenticated denial, and preserves explicit unrelated InnoDB/MyISAM work. The separate restricted keeper issues complete READ locks without a snapshot or transaction. Keeper loss cannot restore primary privileges. Unacknowledged native operations remain recovery-required; exact grant-only restoration requires observed owned-operator retirement and anchored original evidence, and retains unrelated data deltas.

`source_sql_native.py` exercises seven mandatory actual cases: manual proxy/host precedence; cached privileges and idle reconnect; active/unowned session admission; actual grant integrity; complete keeper lock/transaction/exit behavior; partial grant restoration and competing state; orderly/hard restart and interrupted requester recovery. It creates seven fictional SQL identities and two manual proxy edges on a fresh server, with 300 protected InnoDB tables and three explicit business schemas. It accepts no existing server/socket/datadir, source credentials, arbitrary SQL, manager command or provider endpoint.

Set these explicit private inputs before running the root Linux toolkit: `SOURCE_CONTAINMENT_RUNTIME_ARCHIVE`, `SOURCE_CONTAINMENT_RUNTIME_ARCHIVE_SHA256`, `SOURCE_CONTAINMENT_RUNTIME_MANIFEST`, `SOURCE_CONTAINMENT_RUNTIME_MANIFEST_SHA256`, and `SOURCE_CONTAINMENT_EVIDENCE` (a new child of an existing private directory). Archive references are pinned separately; binary content validation/staging occurs inside the admitted worker. Missing runtime, root/platform or delegated memory/PID controls means unqualified failure, never a skipped acceptance.

```sh
unshare --net python3 -m unittest discover -s tools/opening-controls/tests -v
```

The fixed limits and independent `phase-manifest.json`/`retention-request.json`/atomic `retention-ack.json` procedure are the same as the SQL-only harness above. Independently retain final outcome/retirement originals after exit too. Require all seven cases, zero skipped tests, original hashes and actual owned process/socket/mount/cgroup/RAM retirement. Preserve failed trials; a negative CLI unit's `checks_passed: 0` footer is not the native qualification result.

Every admission/readback/qualification remains distinct from real opening proofs and has false real readiness. The supported owned SQL runtime/host/authentication assumptions do not accept the actual source's attribution, root authority, cgroup-v1/vendor start semantics, callback custody, target/cohort exclusions, capacity or live maintenance. No signer/trust, public runtime, source control, financial opening, hold release or handover is installed. BILLmanager remains authoritative until the separately accepted handover.
