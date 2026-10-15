# Source ingress and payment custody Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. The user's preserved native inline, author-only method overrides the subagent recommendation.

**Goal:** Implement a source-matched harmless ingress matrix and durable provider-specific custody without applying payments.

**Architecture:** Observe raw arrivals before any unqualified normalization. Selectively deny new billing work while preserving already-issued payment deliveries under a separately accepted protocol; maintain an immutable legacy reconciliation ledger.

**Tech Stack:** Python 3.10+, standard-library unittest and fixed Linux/native runtime interfaces; source-version-matched disposable resources.

**Spec:** [Source boundary design](../specs/2026-10-07-source-boundary-enforcement-design.md) and the approved private physical-containment proposal. Depends on shared admission types; reuse `custody.py` without widening its acknowledgment promises.

**Status:** Written implementation/rehearsal plan, awaiting review. No implementation or live enforcement has occurred under this plan.

## Global Constraints

- Python 3.10+ and standard library; no application bootstrap, extension route, scheduler, real signer or trust installation.
- Native inline, author-only execution is already selected. Do not dispatch subagents or ask for the method again.
- Preserve BILLmanager billing authority, every migration hold and the separately elected exclusion. No financial opening, activation, collection, mail, provider mutation, VM action or handover.
- All qualification documents have `real_execution_ready=false`. Owned-native qualification, deployed control acceptance and financial authority are separate records.
- Only independently owned fictional resources may be mutated during implementation. Real metadata remains observation; its age and unresolved findings remain visible. No production adapter enters the existing fixture evaluator's allowlist.
- Fixed typed operations only: no profile-provided shell, SQL, import path or executable snippet. Never adapt the fictional SQL driver by supplying a real socket.
- Ordinary root-owned 0600 inputs under root-owned 0700 directories; reject symlink/hardlink substitution and changed pre/post-read identities. Bound each document to 4 MiB, the complete graph to 32 MiB, 10,000 entries and depth eight.
- Root reserve is at least 1 GiB + 128 MiB; retain at least 512 MiB available RAM after admitted budgets. Existing backups, failed evidence and immutable history are retained. Full migration coexistence needs its own measured acceptance.
- Controller, keeper, signer, network or clock loss does not restore gates. Unknown outcomes require readback and reconciliation, never automatic thaw, cron restore, SQL restore or replay.
- No hostile-root guarantee. Every privileged automated consumer is attributed or blocked; exclusive human maintenance authority is explicit and separately accepted.

## Review Focus

- Alternate/default/direct/internal or persistent-connection path bypasses denial (Task 1).
- Proxy/PHP reconstruction loses ordered raw headers or request target (Task 1).
- Success redirect, WebMoney prerequest or Klarna authorization is mistaken for settled cash (Task 2).
- Disk-full, duplicate/conflicting event or unsupported protocol receives success acknowledgment (Task 2).
- Legacy/native ID collision, partial/FX payment or excluded account creates a new credit (Task 3).

---

## File structure and protocol prerequisites

Create `source_ingress_adapter.py`, `source_callback_contracts.py`, `source_reconciliation.py` and their matching unittest files under `tools/opening-controls`. Raw private envelopes and provider secrets never enter fixtures/Git. Source adapters accept only finite protocol versions qualified against the installed original handler and authoritative provider contract; a configured or disabled method name is insufficient.

The existing Authorize.net HMAC library is reusable code, not deployment acceptance. WebMoney notification signature/precheck/return modes, exact Klarna installed endpoint behavior, Wave status mapping and historical methods each require accepted original contract evidence. Unsupported protocols may be retained privately but must not obtain success acknowledgment. Do not issue provider requests, checkout sessions, captures, refunds or test charges as part of this implementation scope. Use fictional signed envelopes and an owned read-only status stub for tests.

### Task 1: Scoped ingress installation and raw custody boundary

**Files:**
- Create: `tools/opening-controls/source_ingress_adapter.py`
- Test: `tools/opening-controls/tests/test_source_ingress_adapter.py`

**Interfaces:**
- Consumes: Shared types; exact nginx/manager/PHP dependency inventory and an owned source-version-matched harmless backend.
- Produces: `establish_ingress(ctx: OwnedContext) -> AdapterReadback`; `read_ingress(ctx: OwnedContext) -> AdapterReadback`; `release_ingress(ctx: OwnedContext, retirement: EvidenceRef) -> AdapterReadback`; `capture_envelope(raw: bytes, protocol: str) -> bytes` (bounded preserved bytes, no execution).

- [ ] **Step 1: Write the failing tests.** Add actual native `test_canonical_alternate_default_direct_and_ipv6`, `test_internal_error_named_encoded_and_case_paths`, `test_existing_keepalive_and_active_request_drain`, `test_raw_duplicate_header_order_and_target_preserved`, `test_unrelated_routes_and_shared_manager_preserved`. An instrumented billing backend must record zero admissions while unrelated requests complete.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert selected_backend_admissions == 0
assert unrelated_backend_completions > 0
assert captured_ordered_headers == original_ordered_headers
```

- [ ] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_ingress_adapter.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [ ] **Step 3: Implement the interface.** Validate exact installed configuration/module semantics in owned namespaces, including effective includes, variable routing, default/SNI, internal redirects and direct manager/FastCGI branches. Do not call actual callback scripts. Place qualified capture before lossy rewrites; if raw preservation cannot be demonstrated return unproved. Installation journals each exact preimage/readback; shared route/pool ambiguity blocks. Deny new checkout first, wait for attributable active requests without aborting work, then establish remaining denial. Retain accepted custody route throughout restoration.
- [ ] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [ ] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: add source ingress qualification`. Record author-only review; do not claim independent review.

### Task 2: Provider-specific durable arrival contracts

**Files:**
- Create: `tools/opening-controls/source_callback_contracts.py`
- Test: `tools/opening-controls/tests/test_source_callback_contracts.py`

**Interfaces:**
- Consumes: Task 1 raw envelope bytes; existing `custody.py`; private per-protocol configuration and accepted legacy reference map.
- Produces: `retain_callback(ctx: OwnedContext, protocol: str, envelope: bytes, config_ref: EvidenceRef) -> CallbackDisposition`; `CallbackDisposition(arrival_ref: EvidenceRef, authenticated: bool, delivery_acknowledged: bool, response_ref: EvidenceRef | None, settlement: None)`.

- [ ] **Step 1: Write the failing tests.** Add `test_authorizenet_signature_header_conflict`, `test_webmoney_precheck_denied_final_and_return_separated`, `test_klarna_authorization_never_capture`, `test_wave_no_callback_setpaid`, `test_historical_unsupported_unacknowledged`, `test_durable_commit_fsync_before_ack_and_disk_full`, `test_retry_retains_every_arrival_conflict_quarantined`. Assert no payment writer/provider call and settlement always None.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert result.delivery_acknowledged is False  # unsupported protocol or failed durability
assert result.settlement is None
assert provider_calls == []
```

- [ ] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_callback_contracts.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [ ] **Step 3: Implement the interface.** Implement one finite accepted protocol branch at a time; missing acceptance retains unacknowledged/unproved status. Preserve original method/target/ordered headers/body and every delivery, authenticate before acknowledgment, deduplicate financial intent without deleting evidence. Response bytes are protocol-specific and bound to accepted contract, not generic HTTP success. Wave reconciliation is a separate read-only observation, not an invented webhook. Keep production purse/mode unchanged and WebMoney refunds deferred. Cap custody disk/record/body budgets from the admitted private budget; write/flush failure cannot return success.
- [ ] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [ ] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: add explicit legacy callback custody contracts`. Record author-only review; do not claim independent review.

### Task 3: Immutable arrival-to-legacy reconciliation

**Files:**
- Create: `tools/opening-controls/source_reconciliation.py`
- Test: `tools/opening-controls/tests/test_source_reconciliation.py`

**Interfaces:**
- Consumes: Task 2 original authenticated arrivals; frozen source reference/population evidence; explicitly scoped read-only provider-status observations.
- Produces: `reconcile_arrivals(arrivals: tuple[EvidenceRef, ...], snapshot: EvidenceRef, mapping: EvidenceRef) -> Reconciliation`; `Reconciliation(dispositions: tuple[EvidenceRef, ...], blockers: tuple[str, ...], financial_writes: int = 0)`.

- [ ] **Step 1: Write the failing tests.** Add `test_legacy_native_same_number_not_same_owner`, `test_partial_overpaid_refunded_currency_and_fx_block`, `test_paid_unposted_and_excluded_account_block`, `test_disabled_recurring_flag_not_inactivity`, `test_duplicate_arrivals_preserve_history`. Assert immutable dispositions, exact provider/account/amount/currency binding and zero cash/credit/settlement writes.

Assertions for the named fixture after its operation (fixture setup stays in the owning test file):

```python
assert result.financial_writes == 0
assert result.blockers  # legacy/native numeric ID collision cannot establish ownership
```

- [ ] **Step 2: Confirm RED.** Run `python3 -m unittest discover -s tools/opening-controls/tests -p test_source_reconciliation.py -v`. Each newly named test must fail at its missing interface or asserted behavior; an unrelated import, privilege, timeout or platform error is not RED evidence.
- [ ] **Step 3: Implement the interface.** Use dispositions already-reflected, received-unposted, duplicate, conflict, excluded and unresolved. Material received-unposted/conflict/unresolved states block capture/opening acceptance until separately accepted financial policy is applied; this module applies none. Keep original provider amount/currency/FX observations and source/native ownership distinct. Inventory late/disabled routes and remote agreement disposition as unresolved if not accepted. Read-only polling status is not custody authentication or authority to execute the source paid writer.
- [ ] **Step 4: Confirm GREEN.** Run the same command. Require all named tests to pass. Record exact source hashes and original output; no skip qualifies a native requirement.
- [ ] **Step 5: Commit the task.** Review the exact diff and privacy first, then commit only its listed files with message `feat: reconcile legacy payment custody evidence`. Record author-only review; do not claim independent review.
