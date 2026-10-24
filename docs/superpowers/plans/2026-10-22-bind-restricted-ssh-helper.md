# Restricted SSH BIND Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add native, ownership-scoped customer DNS management for an existing BIND primary and secondary through a dedicated restricted SSH helper.

**Architecture:** A Paymenter `Others/Bind` extension authorizes native account/service access and exchanges bounded JSON with a forced-command helper. The Python helper owns view-specific zone validation, durable request journals, atomic installation, rollback and secondary readback. Application and server allowlists independently enforce zone ownership; migration holds remain effective.

**Tech Stack:** Existing PHP8.3+/Laravel12/Livewire4/Filament5 and Symfony Process7.4; OpenSSH; isolated Python3.11+ with stable dnspython2.9.0 plus IDNA support and hashed dependency lock; existing BIND utilities. No new PHP SSH library or system-wide Python changes.

**Spec:** `docs/superpowers/specs/2026-10-22-bind-ssh-helper-design.md`, owner approved on2026-10-22. The existing chosen execution method is **native/inline**; retain it after written-plan review.

## Global Constraints

- BILLmanager keeps billing until the owner approves handover. Implementation approval alone does not authorize installation, live DNS writes, customer activation or handover.
- The operator's Mac key stays on the Mac. Use dedicated integration identities; no private hostnames, customer data, credentials or host-key pins in source/defaults/tests.
- Adopt only independently verified exact account/service/domain bindings. Do not bulk-adopt discovered zones; existing bindings and customer access stay disabled during migration.
- Closed protocol `paymenter-bind-v1`; UUID request and binding IDs; at most32KiB stdin,100 changed RRsets,500 records per zone,2MiB zone file,30seconds ordinary operation and60seconds lifecycle operation. Bound stdout/stderr too; default JSON response cap1MiB, including large inspection responses.
- Editable types A,AAAA,CNAME,MX,TXT,CAA,SRV. Preserve unrelated RRsets; reject apex SOA/NS changes, dynamic journals, inline signing/DNSSEC-managed zones, `$INCLUDE`, `$GENERATE` and unsupported constructs. Refuse oversized data instead of truncating it.
- No remote delete, shell/SCP/SFTP/arbitrary command, all-zone enumeration, automatic mutation retry, broad transfer permission, service-event zone deletion or named service restart.
- Keep exact preimages, claims, failure evidence and receipts. Distinguish synthetic tests, installed SSH restrictions, real BIND behavior and billing handover acceptance.
- Reuse an isolated worktree. Preserve existing unrelated ticket changes. Publish only focused, generic commits to the owner's Paymenter fork after checking the branch/diff.

## Review Focus

1. Same zone name in different BIND views must never select or mutate the wrong view; test in Tasks2/4.
2. Migration holds or shared-account membership changing between authorization and dispatch must stop a request before SSH; test in Task6.
3. Lost SSH responses and helper crashes after rename must remain uncertain, retain evidence and never create a new mutation; test in Tasks3/5/6.
4. Quoted/multiline TXT, IDNA names, unknown RRsets and directive text must round-trip safely without expanding files or dropping records; test in Task2.
5. Primary success with a lagging secondary and serial wraparound must report actual state without replay or restoring an older serial; test in Tasks2/3/4/7.

## File and interface map

Paths below are relative to the Paymenter fork root. `tools/bind-helper/paymenter_bind/` contains `protocol.py` (strict wire schema), `config.py` (root-owned allowlist/path/view policy), `zone.py` (typed records/revisions/serials), `custody.py` (descriptor-relative files/locks), `journal.py` (durable request states), `commands.py` (fixed bounded BIND subprocesses), `operations.py` (primary record changes), `lifecycle.py` (primary/secondary declarations), `cli.py` (one-request dispatch) and `convergence.py` (bounded authoritative checks). Its `tests/` mirrors those responsibilities. `tools/bind-helper/pyproject.toml` and `requirements.lock` define isolated dependencies.

`extensions/Others/Bind/` contains `Bind.php`, `Protocol.php`, `SshTransport.php`, `BindAccess.php`, `ZoneManager.php`, `Models/ZoneBinding.php`, `Models/DnsOperation.php`, `Policies/ZoneBindingPolicy.php`, `database/migrations/2026_10_22_000001_create_ext_bind_tables.php`, `Admin/Resources/ZoneBindingResource.php`, `Livewire/Zones/Index.php`, `Livewire/Zones/Show.php`, `routes/web.php`, `resources/views/zones/{index,show}.blade.php` and `resources/lang/en/bind.php`. PHP tests live in `tests/Unit/Bind/` and `tests/Feature/Bind/`.

Shared generic wire fixtures: `tests/Fixtures/Bind/protocol-v1.json`. Installation artifacts: `tools/bind-helper/install/{ssh-dispatch,sudoers.example,authorized_keys.example,server-config.example.json}`. Operator guide: `docs/integrations/bind.md`. All example domains use `.test` or `.invalid`, addresses use documentation ranges and keys are explicit placeholders, never functioning credentials.

Wire contract: `{protocol,request_id,binding_id,operation,payload}`. Results echo the first three fields plus `state`, `code`, `data`; states are `observed`, `committed`, `pending_replication`, `rejected`, `uncertain`. `inspect_request` payload contains the original request UUID. `replace_records` payload is `{expected_serial,expected_revision,changes}`; each change is `{owner,type,ttl,records}` with an empty record list meaning removal of that RRset. A fixture pins the full allowed key sets/types for all five operations. The server resolves zone/class/view/paths from binding UUID; callers cannot supply them.

Run commands from the fork root. `PY` below means the absolute interpreter in this project's isolated helper venv, not a global interpreter. `PHP` means the verified project PHP8.3+ interpreter. `BIND_TEST_ENV` is a freshly generated PHPUnit XML configuration with a guarded bootstrap and forced environment for a **disposable local/isolated** test database (`bind_test_` plus a generated UUID); its preparer refuses any production/runtime/migration schema or non-loopback endpoint. Do not use the existing phpunit.xml database defaults blindly. PHP dependencies are absent in the inspected worktree: install locked vendor dependencies without scripts into the isolated test checkout before tests; never bootstrap a production `.env` to obtain them.

### Task 1: Strict interoperable protocol and isolated test entrypoints

**Files:** Create `tools/bind-helper/{pyproject.toml,requirements.lock,paymenter_bind/__init__.py,paymenter_bind/protocol.py,tests/test_protocol.py}`, `extensions/Others/Bind/Protocol.php`, `tests/Unit/Bind/ProtocolTest.php`, `tests/Fixtures/Bind/protocol-v1.json`, `tools/bind-helper/tests/{test-environment-guard,prepare-test-env,phpunit-bootstrap,test_test_environment}.php`.

**Interfaces:** Python `decode_request(raw: bytes) -> dict`, `encode_result(request: dict, state: str, code: str, data: dict) -> bytes`; PHP `Protocol::encode(string $requestId,string $bindingId,string $operation,array $payload): string` and `Protocol::decode(string $raw,string $requestId,string $bindingId): array`. Produces shared fixtures and a test-environment guard/bootstrap used by every PHP verification. The preparer emits the XML path; its bootstrap validates the exact generated disposable schema/loopback endpoint before requiring the native test bootstrap or permitting a DB connection. It does not create a server or infer that an existing database is disposable.

- [ ] Prepare only owned isolated development dependencies (no application bootstrap/scripts) and write standalone `test_test_environment.php` for `assert_bind_test_environment(array $env): void`: every non-`bind_test_<UUID>` schema, known production/migration schema, non-loopback DB host or inconsistent generated XML environment rejects before any DB connector is called.
- [ ] Run `"$PHP" tools/bind-helper/tests/test_test_environment.php`; expect missing guard-function failure.
- [ ] Implement the pure guard in `test-environment-guard.php`, guarded PHPUnit bootstrap and XML preparer; no server/database creation.
- [ ] Run the standalone test again; expect PASS, then generate `BIND_TEST_ENV` from explicit owned disposable test-instance settings. Do not proceed without a verified disposable instance.
- [ ] Write Python/PHP protocol fixture tests: valid five operations round-trip; duplicate/unknown keys, bad UUIDs/UTF-8, trailing input, wrong protocol,32769bytes input, oversized response and mismatched echoed binding/request IDs reject.
- [ ] Run `"$PY" -m unittest discover -s tools/bind-helper/tests -p test_protocol.py` and `"$PHP" vendor/bin/phpunit -c "$BIND_TEST_ENV" tests/Unit/Bind/ProtocolTest.php`; expect missing implementation failures, not environment failures. Prepare locked dependencies only in owned local paths; record dependency/runtime identities.
- [ ] Implement the exact protocol contract. Use duplicate-aware JSON parsers on both sides; plain PHP json_decode alone cannot detect duplicate keys. Lock dnspython2.9.0/IDNA dependencies with SHA256 hashes and no development-branch dependency. Do not install anything on nameservers.
- [ ] Repeat both commands; expect all tests passing, no network/DNS calls.
- [ ] Commit only Task1 files: `feat: define strict BIND helper protocol`.

### Task 2: View-bound allowlist, safe zone semantics and file custody

**Files:** Create helper `config.py`, `zone.py`, `custody.py`, `tests/{test_config,test_zone,test_custody}.py` and generic zone fixtures under `tools/bind-helper/tests/fixtures/`.

**Interfaces:** `config.py` defines a frozen `Binding` dataclass containing UUID, zone, class`IN`, exact view, role, fixed relative file and allowed operations. `load_binding(config_dir_fd: int,binding_id: str,operation: str) -> Binding`; `read_zone(binding: Binding) -> ZoneSnapshot`; `apply_rrsets(snapshot: ZoneSnapshot,changes: list[dict],serial: int) -> ZoneCandidate`; `next_serial(current: int) -> int`; `locked_zone(binding: Binding)` is a context manager retaining guarded directory/file descriptors. `zone.py` defines `ZoneSnapshot`/`ZoneCandidate` dataclasses with canonical semantic RRsets, exact bytes, serial and SHA256 revision; do not expose paths to clients.

- [ ] Write tests for absent/foreign/protected bindings, same domain in two views, unsafe root/config/parent permissions, symlinks/hardlinks/nested mounts and substitution while locked. Assert out-of-zone owners, apex changes, CNAME conflicts, invalid typed data, oversized zones and all unsupported constructs reject before writing; quoted multiline TXT/IDNA and unknown ordinary RRsets preserve semantics. Assert serial4294967295 advances to0 and ambiguous half-range comparisons reject.
- [ ] Run `"$PY" -m unittest discover -s tools/bind-helper/tests -p 'test_[cz]*.py'`; expect failures for missing named units.
- [ ] Implement with dnspython typed RDATA/zone APIs, explicit `allow_include=False`, allowed directives only `$ORIGIN`/`$TTL`, and lexical handling that never interprets quoted TXT as a directive. Include live BIND journal/signing flags supplied by Task3 before admitting writes. Resolve `(view,zone)` solely from root config; denylisted suffixes override allowlists. Use descriptor-relative bounded reads and retained parent/mount identities.
- [ ] Repeat the tests; expect all pass and no mutations outside owned temporary fixtures.
- [ ] Commit only Task2 files: `feat: validate bound BIND zones and record changes`.

### Task 3: Durable exactly-once primary change and rollback

**Files:** Create helper `journal.py`, `commands.py`, `operations.py`, `tests/{test_journal,test_commands,test_operations}.py`.

**Interfaces:** `BindCommands.status(binding: Binding) -> LoadedState`, `.check(candidate: ZoneCandidate) -> None`, `.reload(binding: Binding) -> None`, `.notify(binding: Binding) -> None`, all fixed argv and bounded process groups. `commands.py` defines `LoadedState` with loaded serial, selected view, dynamic/signing/inline flags and loaded-file identity; missing/unknown flags deny mutation. `Journal.claim(request: dict) -> Attempt` resolves prior same-byte UUIDs without replay; `Attempt.record(state: str,data: dict) -> None` fsyncs state. `Operations.replace(request: dict,binding: Binding) -> dict` consumes Tasks1/2 and returns the wire result.

- [ ] Write tests for revision/loaded-serial conflicts, dynamic/signing refusal, validation failure, parent swap, interrupted journal, concurrent writers, timeout, crash just before/after rename, lost response and same-ID/different-payload replay. Assert install→reload→notify→loaded-readback order; failed reload restores prior RRsets only after exact candidate comparison and with a newer serial; externally changed files remain untouched and outcome uncertain.
- [ ] Run `"$PY" -m unittest discover -s tools/bind-helper/tests -p 'test_*operations.py'` plus `test_journal.py` and `test_commands.py`; expect missing implementation failures.
- [ ] Implement per-zone/config locks, exclusive candidates/preimages, fsync/atomic rename, bounded child execution and durable request outcomes. Exact view/class accompany applicable rndc commands; never global reload/restart. Deadline includes evidence and reserved retirement/rollback time; failure to prove containment/rollback remains uncertain. `inspect_request` is read-only and can reconcile an interrupted request without execution.
- [ ] Repeat tests; expect all pass including actual temporary-file fault injection.
- [ ] Commit only Task3 files: `feat: journal atomic BIND updates and verified rollback`.

### Task 4: Controlled primary/secondary lifecycle and convergence

**Files:** Create helper `lifecycle.py`, `convergence.py`, `tests/{test_lifecycle,test_convergence}.py`; extend `commands.py` with fixed candidate-config validation/reconfiguration.

**Interfaces:** `Lifecycle.prepare_primary(request: dict,binding: Binding) -> dict`, `.prepare_secondary(request: dict,binding: Binding) -> dict`; `Convergence.observe(binding: Binding,expected_serial: int,expected_revision: str) -> dict`. Consumes allowlists/custody/journals; produces authoritative observed/committed/pending/uncertain outcomes. Server config supplies peer/transfer/template data; payload supplies none.

- [ ] Write tests for existing file/declaration refusal, wrong view, partial two-host creation, peer-specific transfers, validation failure, secondary lag, serial wraparound and uncertain primary outcome. Assert no open transfer ACL, no deletion of existing artifacts, no mutation from convergence polling and readiness only after observed authoritative primary/secondary match.
- [ ] Run `"$PY" -m unittest discover -s tools/bind-helper/tests -p 'test_lifecycle.py'` and `test_convergence.py`; expect missing implementation failures.
- [ ] Implement root-owned managed include fragments in the configured views and complete candidate-config `named-checkconf` validation without editing unrelated configuration. Installation of the initial include hook remains an explicit later operator action. Preserve existing zone transfer policy; require a configured narrow peer policy for new zones. Never treat cross-host creation as atomic or auto-delete a partial creation.
- [ ] Repeat tests; expect pass and unchanged foreign fixtures.
- [ ] Commit only Task4 files: `feat: prepare scoped BIND zones and track replication`.

### Task 5: Forced-command entrypoint and PHP SSH transport

**Files:** Create helper `cli.py`, `install/{ssh-dispatch,sudoers.example,authorized_keys.example,server-config.example.json}`, `tests/test_dispatch.py`; create `extensions/Others/Bind/SshTransport.php`, `tests/Unit/Bind/SshTransportTest.php`.

**Interfaces:** CLI accepts no argv beyond its fixed launch, reads one request and dispatches only the five named operations. PHP `SshTransport::request(string $bindingId,string $operation,array $payload,string $requestId): array`; endpoint/key/host-key data come only from validated encrypted extension configuration. A process-runner interface accepts argv/stdin/timeouts and returns bounded captured bytes, permitting tests without SSH.

- [ ] Write dispatcher/transport tests: wrong original command, extra args, shell/SFTP/forwarding/PTY attempts, missing/changed host key, endpoint/option injection, output flood, process timeout, key cleanup and lost response. Assert exact argv/no shell, `BatchMode=yes`, `StrictHostKeyChecking=yes`, fixed private known_hosts, no user config/agent/multiplexing/forwarding, one attempt, process deadlines and no mutation retry. Assert `inspect_request` requires the original operation UUID and is not a second replace.
- [ ] Run `"$PY" -m unittest discover -s tools/bind-helper/tests -p test_dispatch.py` and `"$PHP" vendor/bin/phpunit -c "$BIND_TEST_ENV" tests/Unit/Bind/SshTransportTest.php`; expect missing implementation failures.
- [ ] Implement forced dispatcher→exact no-argument sudo helper, scrubbed environment/fixed executable paths, `sudoers` no-argument matching and restrictive authorized-key templates. Implement Symfony Process argv transport; use encrypted key material with0600 ownership-marked temporary files/0700 directory and crash-safe exact cleanup. Reject secret/config raw output in diagnostics. Transport deadlines are40seconds ordinary/70seconds lifecycle including a10second margin over server budgets; remote outcome after disconnect stays uncertain.
- [ ] Repeat tests; expect all pass without real SSH.
- [ ] Commit only Task5 files: `feat: restrict BIND helper transport and dispatch`.

### Task 6: Native Paymenter binding, policy and operation service

**Files:** Create extension `Bind.php`, `BindAccess.php`, `ZoneManager.php`, `Models/{ZoneBinding,DnsOperation}.php`, `Policies/ZoneBindingPolicy.php`, migration `database/migrations/2026_10_22_000001_create_ext_bind_tables.php`; tests `tests/Feature/Bind/{BindingAccess,ZoneManager,ExtensionBoot}Test.php`.

**Interfaces:** `ZoneBinding` UUID primary key, canonical zone, service FK(restrict), enabled defaultfalse and approved_at nullable; no editable client owner/host/view/path. Ownership derives from current Service.user_id plus native AccountAccess shared membership. `DnsOperation` stores immutable UUID, binding UUID, canonical request hash, state, safe code/serial/revision and timestamps, not credentials/full zone content. `BindAccess::assertAllowed(User $user,ZoneBinding $binding,string $operation): void`; `ZoneManager::{inspect,replace,reconcile,prepare}(User $user,ZoneBinding $binding,array $input): array` wraps authorization, operation journal and Task5 dispatch. `prepare(User $user,ZoneBinding $binding): array` is administrator-only, journals secondary preparation then primary preparation and read-only convergence; it stops if any step is uncertain, retains partial state and never deletes prior artifacts. Other three methods take the displayed `$input` array.

- [ ] Write tests: foreign account/binding, disabled extension/binding, inactive/suspended service, held service/owner, held shared member, missing migration schema, revoked membership/hold inserted before dispatch and attempted client approval all deny before any SSH. Eligible owners may read/edit after explicit binding approval in fixtures. Shared members may read via AccountAccess, but editing additionally requires the existing Gate `update` permission on the service; assert a read-only shared member cannot edit. No new shared-account write grant is introduced. Held clients cannot read DNS; administrators with explicit DNS inspection permission may inspect held bindings read-only, never mutate through that exception. Same request UUID is immutable across retries/reconciliation.
- [ ] With `BIND_TEST_ENV` verified disposable, run `"$PHP" vendor/bin/phpunit -c "$BIND_TEST_ENV" tests/Feature/Bind/BindingAccessTest.php tests/Feature/Bind/ZoneManagerTest.php tests/Feature/Bind/ExtensionBootTest.php`; expect missing implementation failures.
- [ ] Implement native ExtensionHelper migration/boot/config patterns and Gate policy, encrypted secrets and permission definitions. Use AccountAccess::visibleOwnerIds and MigrationHold current reads; also enforce current actor/user holds and no billing/hold bypass. Reauthorize immediately before dispatch after operation preparation, without holding long DB locks during SSH; record in-flight authorization and block later requests once revoked. No promise that revocation can cancel an already accepted remote mutation. Client record operations require active service; administrator lifecycle preparation may use a valid ordered service only with explicit `admin.bind.prepare` permission, approved binding and no service/owner/actor hold. Model/service integrity and migrations fail closed; never release holds or register billing lifecycle actions.
- [ ] Repeat tests; expect pass/no external calls and no automatic binding adoption.
- [ ] Commit only Task6 files: `feat: authorize native DNS bindings and held operations`.

### Task 7: Administrator and client DNS interface

**Files:** Create extension `Admin/Resources/ZoneBindingResource.php`, `Livewire/Zones/{Index,Show}.php`, `routes/web.php`, `resources/views/zones/{index,show}.blade.php`, `resources/lang/en/bind.php`; test `tests/Feature/Bind/ZoneScreensTest.php`.

**Interfaces:** Routes `/dns` and `/dns/{binding}` use existing authenticated/verified client middleware; every Livewire action calls Task6 service, not raw transport. Administrator resource uses explicit `admin.bind.view`, `.approve`, `.inspect`, `.prepare`, `.reconcile` permissions. Primary/secondary prepare is administrator-only through Task6, disabled during migration, with explicit partial-state display. No helper-install, key-generation, bulk adoption or remote-delete action appears in the client.

- [ ] Write native route/Livewire tests for owner/shared/foreign/held access, direct action calls after revocation, revision conflict, unsupported types, escaped TXT/error output and disabled registration. Assert pending replication says `Your DNS update was saved. The secondary nameserver is still updating.`; uncertain says `We could not confirm the update. Please do not submit it again; contact support with reference :id.` Display cache TTL separately from nameserver replication.
- [ ] Run `"$PHP" vendor/bin/phpunit -c "$BIND_TEST_ENV" tests/Feature/Bind/ZoneScreensTest.php`; expect missing screens/route failures.
- [ ] Implement with existing native theme/Filament fields and consistent editable RRset forms, including A/AAAA multi-address values and read-only presentation for shared members without native service-update permission. Administrator binding approval requires exact verified service/domain evidence and remains disabled for the migration until separately activated. Do not invent a new visual style; apply the required design skill if new layout work becomes necessary.
- [ ] Repeat tests and render-check desktop/mobile in the isolated fixture application only. Verify no raw keys/paths/provider stderr appear.
- [ ] Commit only Task7 files: `feat: expose owned DNS records and clear operation states`.

### Task 8: Qualification package and focused fork delivery

**Files:** Create `docs/integrations/bind.md`, `tools/bind-helper/tests/test_qualification.py`, `tools/bind-helper/tests/native-acceptance.example.json`; extend existing tests where full-flow gaps appear. Actual host/zone/key inventory and execution receipts stay outside the public repository.

**Interfaces:** Produce a versioned source/dependency manifest, complete local test receipts, default-disabled installation/rollback instructions, and a **proposed** native packet with exact future installation paths/keys/views/test-zone/rollback set. `test_qualification.py` verifies package completeness and denied-by-default installation templates; it does not perform installation.

- [ ] Write packaging tests: missing CLI/module/dependency, broad sudo rule, absent forced-command restrictions, missing host-key requirement, nondefault-enabled bindings, secret-like populated examples and unpinned native target all reject qualification. Verify an example packet cannot execute against any real endpoint without a concrete reviewed inventory.
- [ ] Run `"$PY" -m unittest discover -s tools/bind-helper/tests -p test_qualification.py`; expect missing packaging artifacts failures.
- [ ] Write operator guide and package manifest. Native proposal must inventory exact installed BIND version/command syntax, Python3.11+/dependencies or a separately reviewed isolated-runtime requirement, named service/chroot/SELinux/AppArmor constraints, PHP/FPM request timeouts sufficient for40second ordinary and70second lifecycle SSH deadlines, view selection, include hooks, supported transfer policy, filesystem capacity and backup custody. Never infer compatibility from modern docs or install/upgrade system packages automatically. Test scope is one expressly designated disposable zone and exact newly owned artifacts; existing customer/infrastructure files and loaded serials must be read back unchanged. No new VM or unrelated Docker resource is needed by this plan.
- [ ] Run helper suite: `"$PY" -m unittest discover -s tools/bind-helper/tests`; run scoped native PHP suite: `"$PHP" vendor/bin/phpunit -c "$BIND_TEST_ENV" tests/Unit/Bind tests/Feature/Bind` in verified disposable DB; run `"$PHP" vendor/bin/pint --test extensions/Others/Bind tests/Unit/Bind tests/Feature/Bind`. Expect all passing; report actual counts, not predetermined proof totals. Review full diff, preserve failed evidence and run one fresh whole-branch review using the retained native/inline execution method. Fix material findings with focused RED→GREEN checks.
- [ ] Commit only Task8 files: `docs: package restricted BIND integration and native acceptance procedure`.
- [ ] Publish focused generic changes to the user's fork and attach any created PR. Do not publish the entire divergent migration branch or private operational packet.

## Handoff and acceptance boundaries

This plan implements and locally verifies the reusable subsystem. **It ends before helper/key installation, production migrations, allowlist installation, binding activation or test-zone DNS changes.** The final package makes those actions concrete and reviewable; the later owner-approved native packet must pass actual forced-command denial, primary create/edit/rollback, secondary convergence and unchanged unrelated-zone readback. Only then can the integration be considered natively accepted. Customer activation and billing handover remain separate decisions.

Written-spec approval is recorded. Written plan approved by the owner on2026-10-22; native/inline implementation authorized. Keep the previously selected native/inline implementation method; do not ask the owner to choose it again.

## Plan self-review

Spec coverage: protocol/permissions/ownership→Tasks1/2/5/6; typed zone parsing/serials/custody→Task2; atomic changes/retained failure/rollback→Task3; lifecycle/secondary→Task4; native UI/holds→Tasks6/7; publication/operator acceptance→Task8. All five Review Focus conditions have named tests. Self-review corrected the disposable-test configuration wiring, preserved shared-member write restrictions, and connected administrator lifecycle preparation to the PHP coordinator. Interface names match across tasks; helper datatypes are defined in the owning modules, while PHP wire values are arrays pinned by shared fixtures. Eight tasks each own a distinct deliverable and a RED→GREEN cycle. No task calls source billing, changes DNS or starts a native VM during implementation.

Implementation clarification: existing servers may contain multiple BIND views. Root allowlist view/class selection refines the approved zone-identity contract without exposing a new client capability or expanding adoption. Target runtime compatibility is a native deployment prerequisite, not a claim that Python3.11 or modern BIND syntax is already available. Existing source code is the reference for PHP framework patterns; the extension does not modify existing billing/session policies.

Primary references checked while planning: [dnspython zone parsing](https://dnspython.readthedocs.io/en/stable/zone-make.html), [dnspython upstream runtime/dependency documentation](https://github.com/rthalley/dnspython), [ISC rndc/zone tools documentation](https://bind9.readthedocs.io/en/v9.18.39/manpages.html#rndc-name-server-control-utility). Modern manual syntax must still be qualified against the exact installed BIND commands before native execution.
