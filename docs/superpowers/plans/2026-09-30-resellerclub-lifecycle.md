# Native ResellerClub Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Native paid domain registration and renewal with safe provider reconciliation.

**Architecture:** An opt-in extension hook consumes a paid invoice item in an after-commit queue job. The registrar keeps encrypted operation claims in a native additive table and confirms provider state before updating native services. Checkout validates ordinary domain orders and preserves distinct registration and renewal pricing.

**Tech Stack:** PHP 8.3, Laravel, Livewire, MariaDB, PHPUnit, LogicBoxes HTTP API.

**Spec:** `docs/superpowers/specs/2026-09-30-resellerclub-lifecycle.md`

## Global Constraints

- Migration holds and a default-off lifecycle switch must prevent all writes.
- No live registrar writes, customer mail, billing handover or production activation during preparation.
- Keep imported resource bindings and negotiated service prices unchanged.
- Publish generic implementation and tests without private configuration.

## Review Focus

- An uncertain POST must never cause a second purchase on retry.
- A duplicate invoice callback must not advance expiry twice.
- Changed server credentials, invoice ownership or resource binding must block execution.
- Premium and registry-specific domains must not be sold at standard prices.
- Claims must survive process failure and cannot be rolled back by a billing transaction.

---

### Task 1: Native paid-service dispatch

**Files:** Modify `app/Services/Service/RenewServiceService.php`, `app/Services/Invoice/ProcessPaidInvoiceService.php`; create `app/Jobs/Server/PaidInvoiceJob.php`; test `tests/Feature/ResellerClubLifecycleTest.php`.

**Interfaces:** Consumes `InvoiceItem`; produces `PaidInvoiceJob::handle(): void` and extension `handlePaidInvoice(Service $service, InvoiceItem $item): void`.

- [ ] Write tests showing a paid registrar service remains pending without a local expiry advance; unpaid/mismatched items are rejected; other server services retain native behavior.
- [ ] Run `unshare --net php vendor/bin/phpunit -c migration.phpunit.xml --filter ResellerClubLifecycleTest`; expect failures for missing paid hook.
- [ ] Dispatch the opt-in paid hook after commit, validate native identities in the worker and defer registrar activation.
- [ ] Run focused tests; expect pass. Commit this task.

### Task 2: Durable provider operations

**Files:** Create `app/Models/ExtensionOperation.php`, `database/migrations/2026_09_30_220000_create_extension_operations_table.php`, `extensions/Servers/ResellerClub/ApiClient.php`, `DomainOrder.php`, `Lifecycle.php`; modify `ResellerClub.php`; extend lifecycle tests.

**Interfaces:** Consumes the Task 1 paid hook; produces `Lifecycle::handle(Service $service, InvoiceItem $item): void`, `ApiClient::request(string $endpoint, string $method = 'GET', array $data = []): array|string`, `DomainOrder::fromService(Service $service): array`.

- [ ] Write registration, renewal, duplicate callback, unknown-result, account/owner/hold, contact-recovery and invalid-response cases with fake external HTTP only.
- [ ] Run focused tests; expect missing lifecycle failures.
- [ ] Implement encrypted unique durable claims, exact customer/contact/resource checks, default-off writes and verified native activation. Reject execution in an outer transaction.
- [ ] Run focused tests; expect pass. Commit this task.

### Task 3: Checkout, catalog and recovery acceptance

**Files:** Modify `app/Livewire/Products/Checkout.php`, `app/Livewire/Cart.php`, `extensions/Servers/ResellerClub/CatalogSync.php`, `app/Console/Commands/CronJob.php`; create `app/Console/Commands/ResellerClubReconcile.php`; update public plugin README and lifecycle tests.

**Interfaces:** Consumes `Lifecycle::handle`; produces optional `validateCheckout(Product $product, Plan $plan, array $values): array` and a read-only reconciliation command keyed to a paid invoice item.

- [ ] Write native checkout validation, unsupported/premium rejection, separate renewal amount, paid-renewal invoice, no-POST recovery and replay cases.
- [ ] Run focused tests; expect missing checkout validation/recovery failures.
- [ ] Add native checkout validation and contact fields, recurring catalog plans for future purchases, safe reconciliation and operator instructions. New lifecycle-owned domains use current catalog renewal prices before invoice creation; imported and explicitly negotiated prices are preserved.
- [ ] Run registrar suites and the whole network-isolated suite; expect no failures, warnings or skipped tests.
- [ ] Review the whole change, resolve important findings with regression tests, publish generic code to the existing draft PRs and install only the held candidate after backup. Review fixes add `app/Services/Service/PaidServiceLifecycle.php`: a pre-dispatch paid claim blocks duplicate invoice/expiry actions and completes with confirmed native expiry. Persist configuration fingerprints, require renewal bindings and reject unsupported IDNs.
- [ ] Verify demo access and run sandbox registration/renewal if credentials are available; otherwise record the missing acceptance gate precisely.
