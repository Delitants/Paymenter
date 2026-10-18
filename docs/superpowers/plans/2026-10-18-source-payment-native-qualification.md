# Source payment native qualification implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Native inline execution is the user's preserved choice. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a bounded qualification harness that can prove framework transaction coupling and, separately, coupling to an original native manual-payment operation on fresh owned records.

**Architecture:** A test-only C++ module uses the installed global `std::shared_ptr<mgr_db::Cache> db` and its prepared statements inside framework-managed requests. An independent committed reader verifies outcomes; a private operation registry confines all native payment tests to freshly created owned records. Framework, manual-payment and provider-validation acceptance are distinct results.

**Tech Stack:** Version-matched BILLmanager/COREmanager C++ SDK and vendor build settings; host Python 3.10+ standard library; the existing supported source runtime and configured local database credential channel. Install no packages or substitute SDK/runtime.

**Spec:** [Native legacy payment journal and Paymenter adapter](../specs/2026-10-17-legacy-payment-journal-design.md), especially Source recording and commit boundary and Native acceptance and delivery increments. The approved continuation is source qualification; target posting remains a later increment.

**Status:** Task 1's generic preparation contracts and Task 2's generic framework probe are implemented and locally tested. Task 3 has local observation decoding, bounded byte capture and supplied-process-identity comparison only; it remains incomplete. The protected native issuer/bridge, live observer, process supervisor, resource retirement and acceptance derivation remain unimplemented. One source SDK syntax-only compilation passed; no object/library build, loading, QA table/account/payment creation or native acceptance case has run. The probe has no automatic module entry. Local tests use authored SDK doubles; the separate installed-SDK syntax result establishes declaration compatibility for the observed build only, not ABI linking or runtime acceptance. The earlier diagnostic `payment.info` call returned `missed`; neither the exact missing object nor a usable payment lookup was established.

## Global constraints

Lifecycle investigation: the vendor `ReloadMgr` helper is a manager-exit path,
not a verified module-only unload. The
[maintenance constraint revision](../specs/2026-10-18-source-qa-lifecycle-decision.md)
was approved for package preparation on 2026-10-18. It permits preparing two
planned manager restarts, with the second reserved for recovery after a failed
initial load. It does not authorize executing either restart. No native
startup/recovery acceptance is claimed.

Maintenance preparation progress: a private installation/rollback package now
binds original evidence and candidate sources, but remains non-admissible because
the loadable module and protected native issuer/bridge do not exist. One
installed-SDK syntax-only compilation passed without producing a binary or
calling the manager/SQL. The restart sequence must independently observe the old
PID/start identity gone and the replacement healthy; it cannot infer completion
from `exit` or hide the vendor restart helper's repeated internal requests.

- BILLmanager remains the billing authority until a separate accepted handover. Source publication does not authorize deployment.
- Source payment history remains history; it is not replayed as deposits.
- The business effect and journal insert must commit together or both disappear. Native acceptance must prove this relationship; comments and a nonzero transaction count are insufficient.
- The native handler must reuse the exact existing cache/connection enlisted in the original business transaction.
- No `Begin`, `Commit`, `Rollback`, independent cache, schema registration, remote HTTP or provider request belongs in the journal handler.
- Generic implementation and synthetic tests belong in this fork. Operational paths, source/cohort/account/merchant IDs, URLs, secrets, raw bodies and actual evidence stay private.
- Use the existing source host for the separately approved native operation. No separate BILLmanager installation, customer VM or new host is required.
- Implementation approval permits generic code and local tests. Source compilation, installation/loading, DDL and owned native financial writes require a concrete reviewed operation packet before execution.
- No target write, signing/trust installation, public receiver, dispatcher, gateway action, migration hold release or billing handover is part of this plan.

## Review focus

1. Concurrent legitimate source billing: no whole-database restore or global before/after comparison may erase or misattribute unrelated activity.
2. Nested requests, pooled connections and reused numeric IDs: registry, request and runtime identity must remain bound; no stale ownership or transaction context survives.
3. A native payment action committing before its after-handler: forced exceptions must detect an already committed business effect rather than label the journal atomic.
4. Notification and provider side effects: absence of services or an example email address does not prove suppression; manual tests remain blocked without qualified per-fixture isolation.
5. Partial installation, lost caller response and cleanup failure: preserve evidence and exact resource identity; never kill the manager, refund through a provider or silently remove paid records.

## Files and interfaces

Create `tools/legacy-payments/native-qualification/`:

| File | Responsibility |
| --- | --- |
| `admission.py` | Closed private profile, bounded evidence parsing, exact authority/resource checks and separate acceptance decisions. |
| `protocol.py` | Fixed case IDs, redacted result parsing and immutable QA evidence semantics. |
| `native/cache_probe.cpp` | Own framework action and after-event; two fictional tables only. |
| `native/manual_probe.cpp` | Exact owned-record guard and after-event on the admitted original native manual-payment action. |
| `observer.py` | Independent local-socket committed observations; no mutation interface. |
| `execution.py` | Reserved operator and local capture/identity checks; live supervision, sequencing, retention and retirement remain pending. |
| `tests/test_*.py` | Offline refusal, parser, state-machine and operation-plan tests. |
| `README.md` | Build/operation prerequisites, acceptance boundaries and rollback instructions. |

All Python modules are importable by adding this directory to the test path; importing them performs no I/O. The planned `operator.py` is named `execution.py` to avoid shadowing Python's standard library; a fresh-interpreter regression test reproduced that failure before the rename. Native headers and libraries remain private vendor dependencies, never copied into the fork.

`parse_profile(raw: bytes) -> Profile` accepts at most 65,536 bytes, rejects duplicate keys, unknown fields and floats. Define frozen dataclasses: `EvidenceRef(path: str, sha256: str, bytes: int)`; `Profile(schema_version: int, mode: str, run_id: str, runtime: EvidenceRef, build: EvidenceRef, registry: EvidenceRef, authority: EvidenceRef, expires_at: str)`; and `Decision(ready: bool, blockers: tuple[str,...], framework_atomicity: bool, manual_atomicity: bool, provider_validation: bool, financial_posting_ready: bool)`. Version is exactly 1, mode is `framework` or `manual`, run ID is 64 lowercase-hex characters and expiry is canonical UTC `YYYY-MM-DDTHH:MM:SSZ`. Fixed limits below are compiled policy, not configurable increases. References bind original bytes and protected file identity. No executable command, SQL, callback URL, credentials or permissive override is accepted from a profile.

`assess(profile: Profile, evidence: dict) -> Decision` returns `ready: bool`, sorted `blockers: tuple[str,...]`, and **false** initial `framework_atomicity`, `manual_atomicity`, `provider_validation`, `financial_posting_ready`. A ready preparation is permission to propose the named operation, not proof that it ran.

`parse_case(raw: bytes) -> CaseReceipt` accepts at most 16,384 bytes. Define `CaseReceipt(purpose: str, run_id: str, case_id: str, phase: str, evidence: tuple[EvidenceRef,...])` and `Qualification(framework_atomicity: bool, manual_atomicity: bool, provider_validation: bool, financial_posting_ready: bool, blockers: tuple[str,...])`. Case IDs are exactly F1–F5 and M1–M5; phase is `before`, `barrier`, `after` or `retired`. Purpose is exactly `source-payment-qa-v1`; it is never an authenticated legacy financial receipt. `summarize(receipts: tuple[CaseReceipt,...]) -> Qualification` derives row/delta comparisons from original observations and requires complete ordered evidence, not a claimed success field. Provider validation and financial-posting readiness are always false for this harness.

`build_operation_plan(profile: Profile, evidence: dict) -> OperationPlan` produces an immutable sequence of the fixed operations `verify_identity`, `compile_owned_module`, `install_owned_module`, `create_qa_tables`, `run_case`, `observe_case`, `retire_owned_resources`. Define `Operation(kind: str, case_id: str | None, resource: EvidenceRef)` and `OperationPlan(profile: Profile, operations: tuple[Operation,...])`. Each operation has an evidence-bound resource reference; operation order cannot come from the input profile. `admit_run(plan: OperationPlan, approved_packet: EvidenceRef) -> AdmittedRun` is the sole factory of an opaque, process-bound admitted-run capability; it validates the exact explicit approval and all admitted resources and registers retirement obligations before any mutation. Construction from arbitrary host/path/ID values is refused. Define `RunReport(run_id: str, receipts: tuple[CaseReceipt,...], stopped_case: str | None, retired: bool)`.

`CommittedObserver.read(run: AdmittedRun, case_id: str, phase: str) -> Observation` consumes that factory-issued run; phase comes from the compiled case sequence. Define `Observation(run_id: str, case_id: str, phase: str, isolation: str, reader_connection: str, writer_connection: str, business_rows: tuple[dict,...], journal_rows: tuple[dict,...], payment_rows: tuple[dict,...], subaccount_rows: tuple[dict,...])`; these selected original rows stay source-private. Keys are restricted to the compiled QA schemas and the reviewed native payment/subaccount schema. Fixed hashes/comparison flags may be exported. `Operator.execute(run: AdmittedRun) -> RunReport` has no host, command, SQL or resource-selector override.

## Native operation envelope

The implementation prepares two separately reviewable packets. It does not execute either automatically:

- **Framework packet:** one temporary module, one private operation directory and exactly two new fixed-name InnoDB tables, `paymenter_qa_business_v1` and `paymenter_qa_journal_v1`, initially absent. An existing name is a collision and refuses installation. No native customer, payment, service or provider record is created.
- **Manual packet:** only after framework qualification and manual prerequisites pass, admit a fresh operation, create fresh QA resources and rerun F1–F5 with the exact manual-packet build before M1–M5. Create one fresh fictional account, one subaccount and exactly four explicitly registered manual payment records A–D. A belongs to M1; B belongs to M2 and, after verified rollback, M5; C belongs to M3; D belongs to M4. Each native credit is exactly `0.0100` USD. Expected final increase before any separately qualified compensation is exactly `0.0300` USD: A, B and D credited once; C unchanged. These are explicitly authorized fictional accounting tests, not received provider money. All records are excluded from migration selection and remain non-spendable by clients. Do not reuse a retired fixture or combine case evidence from different runs.

Each packet pins the exact source/runtime/build and preimage identities, installation/loading method, caller/reader identities, credential channel, bounded invocation sequence, rollback and retirement commands. Any required manager restart, global setting change, unknown side effect or unqualified load/unload behavior blocks that packet; it is not hidden in setup.

Source case ceiling: 30 seconds each and 600 seconds per packet; framework-only observation barrier at most 5 seconds. At most two owned caller processes run concurrently. Output cap is 1 MiB per packet, with raw native payment/module responses retained only on the source. These are ceilings, not instructions to retry. Unexpected failure stops new cases and preserves the failed attempt.

## Task 1: Closed admission and executable operation contracts

**Files:** `admission.py`, `protocol.py`, `tests/test_admission.py`, `tests/test_protocol.py`.

**Consumes:** Retained public SDK declarations and the approved architecture. **Produces:** The immutable types and signatures above; `build_operation_plan(profile: Profile, evidence: dict) -> OperationPlan`, containing fixed operation enums and owned-resource references, never arbitrary command strings.

- [x] Write failing tests rejecting malformed/oversize profiles, unknown fields, stale boot/build or authority, missing original evidence, reused registry IDs, customer/cohort records, missing fixture notification isolation and unqualified install/unload methods. Assert exact blockers and zero executor calls.
- [x] Run `python3 -m unittest discover -s tools/legacy-payments/native-qualification/tests -p 'test_*.py'`; expect the missing admission behavior to fail.
- [x] Implement the closed preparation contracts. `assess` checks hash-bound proposal completeness only; its `ready` result grants no execution authority. The protected native issuer remains unavailable. Before any manual admission it must establish supported read/create/manual-credit calls, original actor authorization, currency/amount mapping, fixture isolation and safe retirement from original evidence. Do not guess action names, module values or status codes.
- [x] Test the concrete wrong-namespace payment-header case: installed declarations belong to the version-matched vendor include tree; missing public-example paths are not accepted as absent SDK functionality. Explicitly reject `ValidateAuth`, capability flags, `SetInfo`, `GetSuccessPage`, paid status, `TransactionCount` or a successful `payment.info` read as settlement/commit proof.
- [x] Run the complete new harness test suite; expect zero failures/skips, then commit `Define closed source payment qualification admission`.

## Task 2: Framework-managed cache probe

**Files:** `native/cache_probe.cpp`, `tests/test_framework_contract.py`, `README.md`.

**Consumes:** Admitted framework operation and protected run registry. **Produces:** Framework cases F1–F5 below through a test-only action `paymenter.qa.transaction` and `isp_api::Event` on that exact action.

Prerequisite progress: the pure `native_metadata.py` parser and its refusal tests
retain exact catalog action names and original-byte fingerprints. Action presence
is distinct from human-readable `mgrctl -i` descriptions, supported invocation,
safe loading/unloading and transaction acceptance. The generic probe adapter is
locally tested against authored SDK doubles; its protected native issuer and
installed-vendor object compilation/linking and registration remain unqualified.
A separate syntax-only check passed without producing a binary. No native cases have
been executed.

- [x] Write failing operation/protocol tests for wrong run/case, nested/reused context, explicit transaction calls, independent connection/cache, DDL inside a request, unexpected table names and a commit claim based on `Final`.
- [x] Implement `Execute(isp_api::Session&) const` and `AfterExecute(isp_api::Session&) const` using the supplied `db->Prepare(...).Execute(...)`. Pin required SDK access/session/build declarations before native compilation; unknown access or registration semantics block the operation. The explicit registration factory requires a private authority bridge; it has no automatic module entry.
- [x] Define the two QA tables outside request handling: both keyed by `(run_id, case_id)`; business row contains exact `DECIMAL(18,4)` amount, journal row contains that business key and immutable facts hash. No foreign key or trigger reaches a native business table. Neither table is a financial journal accepted by Paymenter.
- [x] Implement bounded fixed phases `normal`, `throw_after_business`, `throw_after_journal`, `observe_before_return`. Only this own action may wait at the 5-second observer barrier. Throwing and barrier code stay in the QA module.
- [x] Verify original/current cache connection object identity for diagnostics, but accept F1–F5 only from independently observed business/journal outcomes. Local protocol tests and compilation do not qualify native execution or server-connection identity.
- [x] Run the complete new harness tests; expect zero failures/skips, then commit `Add test-only framework cache qualification probe`.

## Task 3: Independent observation, supervision and recovery

**Files:** `observer.py`, `execution.py`, `tests/test_observer.py`, `tests/test_operator.py`.

Partial preparation: the decoder checks supplied framework observation documents;
it uses frozen typed QA rows instead of mutable dictionaries. Manual rows refuse
until their original schema is qualified. The collector checks supplied pipe,
exit and deadline facts. Neither establishes native provenance or performs live
reads, supervision or cleanup. The task's native requirements remain unchecked.

**Consumes:** The admitted operation plan and fixed native case protocol. **Produces:** Root-private original evidence, exact process/resource identities, `RunReport`, and a separately derived `Qualification`.

- [ ] Write failing tests for dirty-read acceptance, same writer/reader identity, stale snapshots, stdout overflow, invalid UTF-8/JSON, response loss, reused PID/start identity, partial install and cleanup failure. Unknown or partial native results must remain unqualified.
- [ ] Implement fixed independently committed SELECTs through the existing qualified configured credential channel; export no credentials. Verify actual `READ COMMITTED` isolation. Do not use unsupported `START TRANSACTION READ ONLY` syntax, invent a commit token or accept the writer's own SELECT as independent visibility.
- [ ] Implement deadlines and exact PID/start-bound retirement of owned callers/observers only. Never signal or stop BILLmanager, a service process, customer workload or VM. Kill no unknown process on timeout.
- [ ] Implement a lost-collector-response case by withholding the owned collector's result after independent commit visibility. Reconcile original rows on a fresh reader. This proves collector recovery only; it does not prove provider ACK timing or a manager crash/restart boundary.
- [ ] Implement retirement from the original resource registry. Unload only the exact admitted module. Durably retain and hash-verify all QA rows and failed evidence; then delete only this operation's registered fictional QA rows, verify the unchanged table schema and absence of other rows, and drop the now-empty owned QA tables. This authority never reaches native payment/subaccount tables. Never restore the source database, source billing settings or unrelated files. Unknown financial cleanup leaves the owned fixture held with an explicit incomplete-retirement result.
- [ ] Run the complete new harness tests; expect zero failures/skips, then commit `Bound source qualification observation and retirement`.

## Task 4: Original manual-payment coupling

**Files:** `native/manual_probe.cpp`, `tests/test_manual_contract.py`, `README.md`.

**Consumes:** Qualified F1–F5, admitted manual operation and fresh exact ownership registry. **Produces:** Manual cases M1–M5; no provider qualification.

- [ ] Write failing tests for outside-registry IDs, comma/group selectors, cross-account/subaccount/currency, reused IDs, wrong actor/nonce, expired authority and a linked service. Outside-fixture requests must return before SQL, file writes or any changed native response.
- [ ] Require the exact installed `payment.setpaid` action and its supported manual invocation to be established before registration. The candidate is `isp_api::Event("payment.setpaid", <QA event name>, epAfter)`; action presence and original behavior must be checked, not assumed from a header. No wildcard/global hook or fallback action is allowed.
- [ ] Bind the exact original actor, action, owned payment, subaccount, module/manual context and operation intent. Observe the actual native business delta before the after-handler writes its QA row. Inject owned-only exceptions before/after that insert to test rollback of the **real** payment and subaccount changes. Use no precommit wait in the original payment path; do not alter original lock order or payment response semantics.
- [ ] Refuse the manual packet until per-fixture mail/queue/provider suppression and zero linked services are qualified. A fake address, no HTTP call in the hook or global mail-disable proposal is insufficient. Do not invoke a provider refund to clean up a fixture.
- [ ] Verify original native duplicate behavior, including an original refusal if that is the qualified contract. A second call must not add another business credit or QA effect. Do not manufacture success or replace a native ACK.
- [ ] Keep paid fixture records and their accounting audit. Any compensating manual reversal must use a separately qualified native operation and its own intent; otherwise retain the fixture held and report cleanup incomplete. Never delete a paid payment or directly repair balances.
- [ ] Run the complete new harness tests; expect zero failures/skips, then commit `Guard owned native manual payment qualification`.

## Task 5: Acceptance packets and final review

**Files:** `tests/test_acceptance.py`, `README.md`; private operational packets/evidence remain outside Git.

**Produces:** A reviewable framework packet first, and a manual packet only when its additional prerequisites are met. Preparing them executes neither.

| Case | Required observation |
| --- | --- |
| F1 | Framework business and QA journal commit together; independent reader sees both exact rows. |
| F2 | Throw after business write: independent reader sees neither row. |
| F3 | Throw after journal insert: independent reader sees neither row. |
| F4 | While own-action precommit barrier is held, independent reader sees neither new row; after release sees both. |
| F5 | Missing/wrong/expired registry identity produces no business or QA mutation, including nested/reused contexts. |
| M1 | One original native manual credit changes only the owned payment/subaccount by the admitted `0.0100` USD and commits one matching QA effect. |
| M2 | Throw after the observed native credit but before QA insertion leaves original payment/subaccount preimages and no QA effect. |
| M3 | Throw after QA insertion leaves original payment/subaccount preimages and no QA effect. A surviving native credit fails atomicity. |
| M4 | Two owned callers for one payment follow original native duplicate semantics; one business credit and one original QA effect remain. |
| M5 | Lost collector result is resolved by a fresh committed read returning the original business/QA IDs, without another credit. |

- [ ] Add refusal tests for missing/duplicated/reordered cases, fabricated success, F-only promotion to manual acceptance, manual promotion to provider acceptance and QA evidence offered to an opening or legacy financial verifier.
- [ ] Run the complete new harness suite and `python3 tools/legacy-payments/check-identity-vectors.py`; expect zero failures/skips. Run `git diff --check`. Verify existing financial/hold/ledger PHP files remain byte-identical to the plan base. No unrelated PHP formatting or hour-long unchanged-application suite rerun is required by this tools-only increment.
- [ ] Review the whole generic increment once using the preserved native workflow. Keep source mutations blocked until the concrete framework operation packet is reviewable and approved. Then execute only that packet, derive acceptance from originals, and retire its exact resources. Manual packet approval is a separate later step, not an automatic continuation of F1–F5.
- [ ] Record actual completed case counts, failures and cleanup state. Framework acceptance requires F1–F5; manual acceptance requires F1–F5 and M1–M5. Neither accepts native gateway validation, provider refunds/chargebacks, original ACK semantics, writer retirement, opening inclusion, source bridge deployment or target posting.
- [ ] Commit `Document bounded source payment native acceptance`. Publish only generic reviewed changes to the existing draft PR after its source-publication gate; retain private evidence separately.

## Completion boundary

The generic harness and two reviewable operation packets complete implementation preparation. Actual framework acceptance, actual manual acceptance and each provider's validation/commit coverage remain separately reported runtime results. If a prerequisite is unresolved, finish independent local work and retain an explicit blocker; do not substitute a synthetic action, package presence, guessed native command or paid snapshot for the missing proof.
