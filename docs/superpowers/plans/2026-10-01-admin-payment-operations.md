# Admin payment operations implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give administrators audited refunds, manual settlement/reversal and capability-based authorization capture for every gateway without repeating financial or service operations.

**Architecture:** Keep original payments immutable and add an encrypted durable operation journal. Manual and provider operations share permissions, migration holds, invoice locks and exact allocation rules; provider adapters prove merchant/payment identity before money movement. Native admin components expose the actions and explicit state labels.

**Tech stack:** Laravel/PHP 8.3, Eloquent/MySQL, Brick decimal arithmetic, Filament native actions, PHPUnit with offline provider fixtures.

**Spec:** `docs/superpowers/specs/2026-10-01-admin-payment-operations-design.md`

## Global constraints

- BILLmanager remains authoritative until the separate billing handover.
- No real refund/capture, customer mail, provisioning, live callback activation or production billing change during preparation.
- Additive schema and legacy-compatible defaults; do not relabel imported history as manual payments.
- Keep Paymenter invoice currency; no browser-supplied currency, merchant, tax or fee amount.
- Provider calls run outside enclosing database transactions, after a durable claim.
- Amount/currency/reference and actor/reason/date are fixed for a request UUID. Unknown writes require reconciliation.
- Customer gateway fees are untaxed; processor deductions are separate.
- Preserve existing automatic capture. Admin-requested capture is labelled manually settled with gateway verification.
- Public code, fixtures and documentation contain no real credentials, customer exports, hosts or actual deployment fee policy.
- Use the existing isolated worktree and the backed-up disposable QA database; serialize QA work with its existing lock.

## Review focus

1. Reversing and restoring a manually received payment must not renew a service or credit a wallet twice; Task 1 tests lifecycle replay.
2. Refunds racing with other refunds reserve the exact available principal and fee, even while pending; Task 2 tests two requests and an independent-process lock race.
3. A stale provider callback cannot undo a manual correction or create another refund; Tasks 1–3 test callback/native replay.
4. An API success response with the wrong merchant, original payment, amount or currency must not update native finances; Task 3 tests every adapter's readback identity.
5. A gateway with no authorized refund API must expose an honest manual accounting path and reject provider writes; Tasks 2–4 cover Wave, PayPal IPN, custom gateways and disabled operations.

---

### Task 1: Durable manual settlement and once-only paid processing

**Files:**
- Create: `database/migrations/2026_10_01_000001_create_admin_payment_operations.php`, `app/Models/PaymentOperation.php`, `app/Models/InvoicePaidProcessing.php`.
- Create: `app/Services/Gateways/Operations/{OperationPolicy,OperationJournal,ManualSettlements}.php`.
- Modify: `app/Models/InvoiceTransaction.php`, `app/Services/Billing/InvoicePricing.php`, `app/Services/Gateways/PaymentWriteGuard.php`, `app/Services/Invoice/ProcessPaidInvoiceService.php`, `app/Observers/InvoiceObserver.php`, `app/Policies/{InvoicePolicy,InvoiceTransactionPolicy}.php`, `config/permissions.php`, `app/Helpers/ExtensionHelper.php`.
- Test: `tests/Feature/BillmanagerMigration/AdminPaymentOperationsTest.php`.

**Interfaces:**
- `OperationPolicy::authorize(User $actor, string $permission, Invoice $invoice, Gateway $gateway): void` checks dedicated permission, private operations setting and holds.
- `OperationJournal::claim(...) : PaymentOperation` fixes request identity and actor, preserves replay and records source receipt identity.
- `ManualSettlements::record(User $actor, Invoice $invoice, Gateway $gateway, string $amount, string $reference, string $reason, string $effectiveAt, string $requestKey): PaymentOperation`.
- `ManualSettlements::unsettle(User $actor, InvoiceTransaction $transaction, string $reason, string $effectiveAt, string $requestKey): PaymentOperation` and `restore(...) : PaymentOperation`.
- `InvoiceTransaction::settlementLabel` reports legacy/provider, manually recorded, manually captured or unsettled without replacing the original status.
- `ProcessPaidInvoiceService::handle(Invoice $invoice): bool` returns true only for first successful processing; historical paid IDs have a legacy journal marker.

- [ ] Write tests for manual receipt, explicit labels, unpaid pending transactions, reversal/restore, request replay/drift, duplicate external receipt, dedicated permission, disabled setting, migration holds, frozen attempts, preservation of original transaction, and once-only credit/service lifecycle.
- [ ] Run `python3 paymenter-migration/ops/checkout-tax-fees-test.py --filter AdminPaymentOperationsTest`; expected missing manual service/state behavior before implementation.
- [ ] Add the schema, journal/policy/manual service and narrow guarded mutation scope. Count only received funds. Remove repeat paid-event dispatch after processed invoice replay.
- [ ] Run the focused tests and existing payment/financial tests; expected all pass with no outbound request.
- [ ] Commit generic files and record tests/rulings in the plan ledger.

### Task 2: Refund allocations and durable provider execution/reconciliation

**Files:**
- Create: `app/Services/Gateways/Operations/{RefundAllocation,Refunds,ProviderOperations,OperationResult,Adapter,GatewayOperations}.php`.
- Modify: `app/Classes/Extension/Gateway.php`, `app/Models/{PaymentOperation,InvoiceTransaction}.php`, `app/Services/Gateways/PaymentWriteGuard.php`.
- Test: `tests/Feature/BillmanagerMigration/AdminRefundsTest.php`, `tests/Feature/BillmanagerMigration/AdminPaymentRaceTest.php`.

**Interfaces:**
- `RefundAllocation::quote(InvoiceTransaction $transaction, string $amount, bool $includeFee): array` returns exact net/tax/untaxed-fee amounts and remaining availability, using the frozen original allocation less reserved/completed refunds.
- `Refunds::recordExternal(User $actor, InvoiceTransaction $transaction, string $amount, bool $includeFee, string $reference, string $reason, string $effectiveAt, string $requestKey): PaymentOperation`.
- `Refunds::submit(...) : PaymentOperation` and `ProviderOperations::capture(User $actor, Invoice $invoice, Gateway $gateway, string $providerReference, string $reason, string $requestKey): PaymentOperation` durably claim before writes.
- `ProviderOperations::reconcile(User $actor, PaymentOperation $operation): PaymentOperation` performs authenticated readback only.
- `Adapter::{capabilities,fingerprint,execute,reconcile}` receives a frozen PaymentOperation and returns `OperationResult` with explicit succeeded/pending/failed/uncertain state and verified provider reference.
- `GatewayOperations::for(Gateway $gateway): Adapter` provides built-in adapters and honest unsupported fallback; the gateway base allows custom capability implementation.

- [ ] Write full/partial amount/tax/fee tests, no-fee option, reservation/exhaustion, pending/unknown outcome, replay/drift, immutable original, external refund attribution, no reopened collection/service termination, unsupported adapter and simultaneous-claim tests.
- [ ] Run the refund tests; expected missing allocation/journal/execution behavior.
- [ ] Implement decimal allocations, shared claim/execution/readback and guarded metadata updates. No uncertain write resubmission; reserved amounts survive failure to verify an outcome.
- [ ] Run focused tests and race verification; expected one provider write/payment/refund per logical operation.
- [ ] Commit and ledger.

### Task 3: Gateway refund/capture adapters

**Files:**
- Create: `app/Services/Gateways/Operations/Adapters/{Klarna,AuthorizeNet,Stripe,PayPal,Mollie,WebMoney,Unsupported}.php` and bounded common authenticated HTTP helper.
- Modify: adapter factory/base gateway and WebMoney encrypted certificate configuration when necessary.
- Test: `tests/Feature/BillmanagerMigration/AdminGatewayAdaptersTest.php` and existing native gateway tests.

**Interfaces:**
- Use Task 2's exact Adapter/OperationResult interface and original native payment/attempt identity.
- Fingerprints agree with existing migrated adapters where PaymentAttempts already exists; credentials are read from the exact gateway record and never copied into operation logs.
- Provider refunds/captures use stable UUID idempotency keys where supported. Readback verifies original merchant/transaction, currency, amount and operation identity before success.
- WebMoney supports certificate-authorized X14 only after configuration; no test-mode refund or blind partial replay. Wave/IPN/custom fallback declares unsupported actual movement and keeps Task 1/2's manual accounting.

- [ ] Add synthetic exact request/readback tests for Klarna, Authorize.Net refund versus full void, Stripe, PayPal and Mollie, plus configured WebMoney X14 validation. Cover pending statuses, mismatched identity/currency/amount, wrong environment, no authorization, unsupported test mode, missing configuration and uncertain network writes.
- [ ] Run adapter tests; expected missing provider behavior before implementation.
- [ ] Implement each adapter using current official API documentation. Never retain a full card number; no provider tax rewrite. Captures freeze native quotes and produce one verified native payment with manual admin provenance.
- [ ] Run adapter tests plus existing gateway callbacks; expected original collection contracts and automatic capture remain intact.
- [ ] Commit and ledger; mark real merchant sandbox acceptance separately from fixture results.

### Task 4: Native admin actions and reconciliation visibility

**Files:**
- Create: `app/Admin/Actions/PaymentActions.php`, native operation history relation manager/resource and `app/Console/Commands/Extension/ReconcilePaymentOperation.php`.
- Modify: both native transaction tables, invoice resource relations and transaction policies.
- Test: `tests/Feature/BillmanagerMigration/AdminPaymentActionsTest.php`.

**Interfaces:**
- Bind Task 1/2 services to native admin actions with required reason/reference/effective date, UUID and exact server-derived currency.
- Expose full/partial refund preview, include-fee choice, external refund, manual receipt/restore/reversal and eligible authorization capture as separate actions.
- Show explicit provenance, effective state, refunded and available amounts plus pending/reconciliation records. Disable managed deletion/bulk deletion in backend policies as well as UI.
- CLI reconciliation requires an explicit admin actor and performs the same permission/hold checks; no schedule is enabled.

- [ ] Write native action/permission tests including direct unauthorized invocation, stale reference, state badges, preview and protected deletion.
- [ ] Run native admin tests; expected missing actions/protection.
- [ ] Implement using existing Filament components. Use Superdesign if new custom layout is needed; preserve standard admin controls otherwise.
- [ ] Run tests and isolated desktop/mobile native browser acceptance with synthetic records. Confirm no real provider movement, mail or service operations.
- [ ] Commit and ledger.

### Task 5: Review, publication and held installation

**Files:** generic implementation/tests/docs above; private QA/publication/install evidence remains outside Git.

- [ ] Run final focused/integrated offline tests, Pint and build if frontend assets changed. Obtain one independent whole-branch review through requesting-code-review; fix any material findings with red/green evidence.
- [ ] Restore exact QA baseline/jobs and remove only this task's temporary resources after preserving evidence.
- [ ] Scan the complete generic patch for private configuration/credentials; mirror identical files into source and public fork, commit and publish normal updates to the existing draft PR.
- [ ] Back up the held candidate; install additive schema/code with old financial values, credential settings, migration holds, jobs and production hashes verified. Operations stay disabled and historical financial values unchanged.
- [ ] Report code/native verification separately from outstanding provider sandbox/credential gates. BILLmanager continues billing.
