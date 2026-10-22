# Session-bound native manual probe implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Native inline execution is the user's preserved choice. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Implement the offline generic manual-probe core, with exception-safe caller context and an opt-in SDK callback adapter, while keeping live admission unavailable until the native fixture and observation contracts are qualified.

**Architecture:** A guarded own dispatcher holds a lexical RAII context while using `InternalCall(const Session&,...)` to run the original `payment.setpaid` action. The installed inner `PaymentStatusChanged` callback uses that exact context and cache for the QA journal. Native read/preflight and protected manual authority are mandatory interfaces; this offline increment supplies no live issuer, fixture creator, native financial reader, automatic registration or source operator.

**Tech Stack:** C++11, existing version-matched SDK adapter conventions, Python 3.10+ unittest, authored SDK/DB doubles, existing host compiler. No packages or new runtime.

**Spec:** [Approved callback amendment](../specs/2026-10-19-native-manual-callback-qualification.md). This is an offline preparation increment within Task4 of the source native qualification plan; it does not complete native M1-M5.

**Status:** Approved offline implementation, whole-increment review and post-review regression checks are complete (675 harness tests and two economic vectors). The existing framework bootstrap remains unchanged and framework-only. Operational evidence and all actual source identities remain outside Git.

## Global Constraints

- BILLmanager remains billing authority; source financial, customer, provider, VM, target and handover actions are not admitted by this plan.
- Use `payment.setpaid` through the supplied-session SDK overload; no `.one` substitution, parameterless Authenticate, unbound InternalCall, wildcard action hook or symbol override.
- Reuse the supplied cache; no new cache/connection, Begin/Commit/Rollback, custom Job, schema creation or payment-path barrier.
- The callback Store exposes no removal; callback state must remain alive and revoked through manager recovery. No automatic manual registration is added to the existing module entry/bootstrap.
- All live dependencies stay unavailable until separately implemented and qualified against native originals. Authored doubles and JSON review labels cannot issue authority or prove transactions.
- Four fresh owned unpaid payments have exact amount `0.0100` USD; M1/M5 share one, M2 and M3 each use another, and the two M4 callers share the fourth.
- The fixture must have zero native AccountNotify recipients: associated user rows must satisfy no `enabled='on'` receiver match. Client disablement and subscriptions alone do not establish isolation.
- All native fixture creation, queue/provider isolation, actual ABI/worker/cache/unwind behavior and financial observation remain gates for the later concrete native operation.
- Retain failed/paid audit evidence. No payment deletion, direct balance repair, provider refund, old control reuse or financial acceptance promotion.
- Generic code/tests/docs only in Git; no operational IDs, credentials, URLs, raw customer/notification bodies or actual evidence.

## Review Focus

1. A native call throws before its callback, then another request reuses the same OS thread: the old context must be gone (Task2).
2. A nested native call or concurrent foreign worker reaches an owned ID: refuse without an incorrect journal or a cross-thread Session pointer (Tasks2-3).
3. The handler commits or replaces its revision/connection internally: retain original failure and never substitute an independent cache or claim atomicity (Task3).
4. Registration partially fails after a callback is stored: retained disabled state must outlive the callback; no dangling closure or invented unregister (Task3).
5. A disabled account still contains an enabled user or a forced direct-notification path: the live fixture gate stays closed; no template/subscription workaround (Tasks1,4).

## File Map

All paths below are relative to `tools/legacy-payments/native-qualification/`.

- `native/manual_contract.hpp`: request/fixture/grant/snapshot types and mandatory authority/native-access interfaces; no SQL or issuance implementation.
- `native/manual_context.hpp/.cpp`: process/worker/request-bound lexical scope and one-use callback reservation, independent of SDK types.
- `native/manual_probe.hpp/.cpp`: opt-in SDK dispatcher and real callback registration; no module entry or live dependency factory.
- `tests/manual_context_driver.cpp`, `tests/manual_probe_driver.cpp`: executable authored-boundary cases.
- `tests/test_manual_context.py`, `tests/test_manual_probe.py`: compile/run the real generic implementation against those cases.
- `tests/fake_manual_sdk/`: isolated authored connection/Authen/XML/InternalCall/callback doubles, so the established framework doubles retain their contracts.
- `tests/test_manual_readiness.py`, `tests/manual_readiness_driver.cpp`, `README.md`: live-admission refusal and precise evidence/acceptance limits.

## Task 1: Define the immutable manual-probe boundary

**Files:** Create `native/manual_contract.hpp`, `tests/manual_context_driver.cpp`, `tests/test_manual_context.py`.

**Interfaces:** Consumes existing `Request`, `Grant`, `ProbeError` conventions from `native/cache_probe.hpp`. Produces namespace `paymenter_qa::manual` types: `Fixture` (account/subaccount/currency/method IDs, four payment IDs, user IDs, facts hash), `Request` (session identity, action, actor, run/case/nonce, payment ID, caller slot), `Grant` (request bindings, phase, expiry, fixture), `Snapshot` (owned IDs, status, four-decimal payment/balance strings and linkage/isolation facts), `Authority` and `NativeAccess`.

`Authority` methods are `operation_id()`, `authorize(const Request&)`, `current(const Grant&)`, `owns(int payment)`, and bounded private diagnostic methods. They perform no SQL/network/reentrant SDK calls. `NativeAccess::inspect(const Fixture&,int,mgr_db::Cache&)` returns a `Snapshot`; its later native implementation must establish actual facts, not decode caller booleans. Neither interface has a live factory in this increment.

- [x] Write failing driver cases for malformed/zero/negative/overflow IDs (native callback IDs must be `1..2147483647`), duplicate four-payment IDs, unknown caller slots, wrong actor/run/nonce/case/currency/amount, expired/unbounded grant, missing dependency and reused fixture/case. Require zero native dispatches/journal writes on refusal.
- [x] Run `python3 -m unittest discover -s tools/legacy-payments/native-qualification/tests -p 'test_manual_context.py' -v`; expect failure because the manual contract/core is absent.
- [x] Define the exact value types and validation in `manual_contract.hpp`. Grant duration is at most the existing600-second authority bound, case duration at most30seconds. `M5` is reader-only and cannot dispatch; `M4` permits only two distinct caller slots for its one bound payment. Use `enum class CallerSlot {Single,First,Second}`: M1-M3 require Single; M4 uses First and Second, each with its own nonce. Authority signatures are `std::string operation_id() const`, `Grant authorize(const Request&) const`, `bool current(const Grant&) const`, and `bool owns(int) const noexcept`. No default permissive implementation or accepted evidence-label flag.
- [x] Run the same test command; expect zero failures/skips for contract cases, with unimplemented context cases still kept out until Task2 adds them.
- [x] Commit `Define closed native manual probe contracts` after a green focused run and `git diff --check`.

## Task 2: Implement lexical context and callback consumption

**Files:** Create `native/manual_context.hpp/.cpp`; extend the Task1 driver/tests.

**Interfaces:** Consumes Task1 `Request`, `Grant`, `Snapshot`. Produces noncopyable `ManualScope(const Request&,const Grant&,const void* cache,const void* connection)`, destructor `noexcept`, and `CallbackToken ManualScope::consume(int old_status,int new_status,int payment,const void* cache,const void* connection)`. `CallbackToken` is a noncopyable one-use handle to that scope/request/grant. `static ManualScope* current() noexcept` exposes only the current worker's non-owning scope; it never returns another worker's Session pointer.

- [x] Add failing cases for exception before callback followed by same-thread reuse; exception during callback; early success return; nested scope; wrong thread/process/session/cache/connection; reused callback; wrong native payment/status; revocation/expiry during private authority reads. Assert the scope is absent after every lexical exit and no foreign context becomes current.
- [x] Run the focused Task1 command; expect these behavioral cases to fail against the missing context implementation.
- [x] Implement process and worker binding with lexical RAII. Reject nested ownership; reserve callback use before any diagnostic/inspection/journal action. Never hold a context-map lock across SDK/native-access/authority calls. Consumed attempt state cannot be undone by an exception. Do not use the SDK ThreadCache helper: it creates a Job and clears on commits, contrary to this scope's lifetime.
- [x] Run the focused command; expect all contract/context cases green, including real local thread/fork scenarios where supported by the existing test pattern. Local thread/process outcomes are not native SDK acceptance.
- [x] Commit `Keep manual callback context scoped to the native call`.

## Task 3: Wire the opt-in SDK dispatcher and inner callback

**Files:** Create `native/manual_probe.hpp/.cpp`, `tests/manual_probe_driver.cpp`, `tests/test_manual_probe.py`, isolated `tests/fake_manual_sdk/` headers.

**Interfaces:** Consumes Task1 authority/native-access and Task2 scope/token. Produces `RegisterManualProbe(std::shared_ptr<const manual::Authority>,std::shared_ptr<manual::NativeAccess>)`. Both dependencies are mandatory, and calling it is permitted only from a later separately admitted private bootstrap. The current `module_entry.cpp`, framework bootstrap and protected framework publisher are unchanged.

- [x] Write failing driver cases asserting a single original target `payment.setpaid`, the exact supplied Session/Authen identity, one fixed numeric elid from the grant, unchanged original XML/error, and no unbound InternalCall/Authenticate/`.one` calls. Add foreign-ID callbacks (zero SQL/files), owned callbacks with no valid scope, normal one-journal callback, before/after-journal exception phases, duplicate native refusal and duplicate native no-op preservation, and no M5 dispatch. Include a modeled native preliminary commit that does not clear the lexical scope, plus changed-connection/revision diagnostics; a same-connection commit must never become an atomicity claim. For an admitted M4 second call only, an unchanged-status callback may preserve the native no-op and add no journal when the native-access snapshot matches the independently bound first result; all other unexpected status transitions refuse.
- [x] Add failing registration cases for missing dependency/action, duplicate initialization, partial SDK registration failure, revoked gate and a callback invoked after failure. The callback's retained state must remain alive and disabled; a destructed closure or a call to nonexistent Store removal is a failure. Record authored fake store events only; never call the real vendor Store::Fire to manufacture evidence.
- [x] Run `python3 -m unittest discover -s tools/legacy-payments/native-qualification/tests -p 'test_manual_probe.py' -v`; expect missing-adapter failures.
- [x] Implement one own action `paymenter.qa.manual` using `InternalCall(ses,"payment.setpaid",fixed_params)`. Hold Task2 scope across the call. Before dispatch require an authority-bound native-access preflight of exact ownership, amount, USD currency, linkage and user-level receiver isolation. Require the same supplied cache object/connection before and inside the callback; preserve failures instead of reconnecting.
- [x] Register the version-pinned installed PaymentStatusChanged callback using Store::Add. Outside-registry IDs return before all I/O. Owned callbacks require the exact active scoped grant; inspect the actual native delta via NativeAccess, then insert exactly one QA journal effect using the existing fixed QA journal table/prepared-statement convention. Bind facts hash to fixture/run/case and observed native IDs. No synthetic business-row write represents the native credit. The existing cache Query revision-entry diagnostic may be reused; never add explicit transaction controls.
- [x] Map M1/M4 normal phases and M2/M3 before/after-journal exceptions; M5 is committed observation only. Return or rethrow the original native result without manufacturing ACKs. Keep all registration state process-lifetime-safe and revoked on initialization failure; no automatic unload claim.
- [x] Run the focused probe and context commands; expect zero failures/skips. These tests demonstrate generic refusal/lifetime/call routing, not native transactions, recipient filtering, ABI binding or financial isolation.
- [x] Commit `Add opt-in session-bound native manual callback probe`.

## Task 4: Preserve closed live admission and document the native gates

**Files:** Create `tests/test_manual_readiness.py`; update `README.md`; read existing `admission.py`, `execution.py`, `observer.py`, `native/module_entry.cpp`, `native/bootstrap.cpp` without widening their framework/live behavior.

**Interfaces:** Consumes the generic Task3 adapter. Produces a reviewable offline increment with the existing live operator/manual issuer/native financial reader still unavailable. Do not add a command-line activation flag or a JSON `ready` shortcut.

- [x] Add failing misuse tests: a complete fictional manual profile cannot construct an AdmittedRun or execute; the framework bootstrap does not register the manual action/callback; missing real NativeAccess refuses before registration/dispatch; private labels claiming no enabled users/notifications cannot issue live authority; supplied manual rows cannot pass the existing framework-only observer. Existing refusals that already pass are retained; any new boundary must be exercised behaviorally, not by grepping code.
- [x] Run `python3 -m unittest discover -s tools/legacy-payments/native-qualification/tests -p 'test_manual_readiness.py' -v`; investigate any failure before changing admission. Do not change a closed live factory to make an offline test succeed.
- [x] Document the distinction between completed offline code and unresolved native dependencies: fresh supported fixture creation, all associated user rows disabled, direct-notifier/queue/provider isolation, native read schema/collector, protected manual issuer, actual SDK compile/link/shared singleton/worker/cache/unwind evidence, new build F1-F5, original M1-M5, process-lifetime recovery and paid-audit retention. The later operation packet needs its own concrete approval; no source action runs in this plan.
- [x] Run the complete harness: `python3 -m unittest discover -s tools/legacy-payments/native-qualification/tests -p 'test_*.py'`. Expect zero failures/skips. Run `python3 tools/legacy-payments/check-identity-vectors.py` (two economic vectors pass) and `git diff --check`. Do not rerun unrelated whole-application suites.
- [x] Verify all existing financial/hold/ledger PHP files match this plan's base, and generic artifacts contain no actual operational identities or private paths. Complete one whole-increment review using the preserved workflow. Commit `Document manual callback preparation and closed native gates`.

## Completion and next handoff

This plan completes only the offline generic probe core and opt-in adapter. It deliberately does not implement a guessed native fixture creator, live NativeAccess, protected manual issuer, live reader or operation launcher while their original contracts remain unqualified. Those are named unresolved dependencies, not optional checks or placeholders in this deliverable. No source build, installation, load, restart, fixture, financial test, publication or handover follows automatically from green local tests.

After the generic increment is reviewed, prepare the next concrete native-dependency/operation increment. Native acceptance requires the new actual build and F1-F5/M1-M5 originals with independent business/QA observations and completed recovery. Only the exact supplied-session invocation is covered; direct external/provider paths remain separate. Preserve all earlier native framework and failed evidence without rerunning their spent scopes.
