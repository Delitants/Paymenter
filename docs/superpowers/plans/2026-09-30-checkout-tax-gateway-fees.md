# Checkout Tax and Gateway Fees Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Preserve the user's selected Native execution method; implement in this session and obtain one fresh whole-branch review before publication.

**Goal:** Show and collect one consistent native invoice total with precise product tax and an untaxed, configurable gateway fee.

**Architecture:** A decimal calculator produces unit product amounts and payment summaries. Native invoices retain their issued tax context and line tax amounts; the existing locked payment-attempt ledger commits one gateway-fee line and freezes its pricing identity before external checkout. Provider adapters consume that frozen allocation and settle the same native balance.

**Tech Stack:** PHP 8.3, Laravel/Eloquent, Livewire, Filament, MariaDB, Brick Math, PHPUnit, existing DomPDF and Vite tooling. No new dependency is required.

**Spec:** `docs/superpowers/specs/2026-09-30-checkout-tax-gateway-fees-design.md` (approved written design).

## Global Constraints

- The customer fee is untaxed and remains separate from the processor's deduction recorded on an invoice transaction.
- Rates, account identifiers, credentials, customer records and deployment addresses must not appear in public implementation or fixtures. Public examples below are synthetic. Deployment configuration is maintained privately.
- Calculate the gateway fee as the configured percentage of the product subtotal plus the configured fixed fee. Do not calculate a percentage on tax, on the fee itself, or on other fees, and do not gross up the result to recover a processor deduction.
- Preserve the native two-decimal unit-price contract; use HALF_UP and at least four fractional digits of percentage precision.
- The initial implementation supports two-decimal invoice and gateway currencies.
- Defaults are disabled/zero. Fixed fees must declare the currency they belong to; a mismatched fixed-fee currency is unavailable, never silently converted.
- A preview does not mutate the invoice or create an external checkout. An uncertain or expired attempt requires reconciliation.
- Imported history, balances and negotiated gross service prices remain intact. Billing handover, live collection, source changes, customer mail and provider provisioning are outside this preparation.
- Klarna Consumer FX and customer market selection follow the shared tax/fee work, as the spec states. This plan preserves that follow-up acceptance gate; it does not introduce a second FX accounting engine.

## Review Focus

- Small unit prices multiplied by quantity must match stored gross invoice rows; pin this in Task 1.
- A country, coupon or settings change must not alter an issued invoice's tax or an existing provider checkout; pin this in Tasks 2 and 4.
- A concurrent different gateway, wallet payment or admin/API item edit must not race an external claim; pin this in Task 4 using independent DB connections/processes.
- Legacy snapshots and already initialized attempts must remain numerically intact across the additive migration; pin this in Tasks 2 and 4.
- A provider's tax representation or rounding must not erase tax or overstate the fee; pin this in Task 6 and real sandbox acceptance in Task 7.

## File and interface map

- `app/Services/Billing/MoneyCalculator.php`: pure decimal unit-tax, fee and remaining-balance allocation.
- `app/Services/Billing/ProductAmounts.php`: immutable unit and quantity totals; all monetary fields are decimal strings.
- `app/Services/Billing/PaymentSummary.php`: immutable `currency`, `productNet`, `productTax`, `productGross`, `unpaidNet`, `unpaidTax`, `gatewayFee`, `total`, `paid`, `payable` summary; monetary values are decimal strings. `total` is the full invoice/quote amount, while `payable` is the remaining charge after succeeded payments.
- `app/Services/Billing/InvoicePricing.php`: issued tax context, line amounts, invoice summaries and canonical pricing fingerprints.
- `app/Services/Gateways/GatewayFeePolicy.php`: common default-off configuration, validation and gateway eligibility.
- `app/Services/Gateways/PaymentWriteGuard.php` and `app/Models/Traits/GuardsPaymentWrites.php`: serialize financial invoice mutations with external initiation and settlement.
- Existing `PaymentAttempts.php`: commit the fee and capture an encrypted pricing allocation; retain its public initiation/validation/settlement signatures.
- `Klarna/OrderLines.php` and `Wave/OrderLines.php`: provider-specific allocations from frozen pricing; keep HTTP, signatures and customer bindings in existing adapters.
- Existing cart, invoice models, views, coupon and service price paths consume these units. Existing gateway configuration import gets an explicit optional fee-preparation stage.

All new class names below refer to this map. The implementation workspace must be an isolated worktree selected through the worktree skill at execution time. Source and public-fork paths are mirror targets, not separate implementations.

---

### Task 1: Exact product tax and payment arithmetic

**Files:** Create `app/Services/Billing/MoneyCalculator.php`, `app/Services/Billing/ProductAmounts.php`, `app/Services/Billing/PaymentSummary.php`, `tests/Unit/Billing/MoneyCalculatorTest.php`; modify `app/Classes/Price.php`, `app/Models/Coupon.php`; create `tests/Feature/CheckoutTaxCalculationTest.php`.

**Interfaces:** Produce `MoneyCalculator::product(string $unitPrice, string $setupPrice, int $quantity, string $taxRate, bool $inclusive): ProductAmounts`, `fee(string $netBase, string $percent, string $fixed): string`, and `allocateRemaining(string $net, string $tax, string $remainingGross): array{net:string,tax:string}`. `ProductAmounts` exposes `unitNet`, `unitTax`, `unitGross`, `setupNet`, `setupTax`, `setupGross`, `quantity`, `net`, `tax`, `gross`. Produce `Coupon::calculateDiscountDecimal(string $price, string $type = 'price'): string`; preserve the legacy method as a compatibility wrapper. `Price` accepts optional precomputed `tax_amount` and `setup_tax_amount` in its existing input array.

- [ ] **Write failing tests:** `test_fractional_rate_and_untaxed_fee`, `test_unit_rounding_precedes_quantity`, `test_inclusive_price_is_not_taxed_twice`, `test_credit_allocation_and_fee`, `test_invalid_decimal_and_negative_values_are_rejected`. Assert the synthetic example from the spec: net `100.00`, tax `7.13`, fee `2.75`, total `109.88`. For unit `0.10`, quantity 3, rate `7.1250`, assert net `0.30`, tax `0.03`, gross `0.33`. Allocating an unpaid gross `50.00` against original net `100.00` and tax `7.13` gives unpaid net `46.67`, tax `3.33`, and a `1.42` fee at 2.5% plus 0.25. A zero payable purchase incurs zero fixed fee.

  ```php
  $p = (new MoneyCalculator)->product('100.00', '0.00', 1, '7.1250', false);
  $this->assertSame('7.13', $p->tax);
  $this->assertSame('107.13', $p->gross);
  $this->assertSame('2.75', (new MoneyCalculator)->fee($p->net, '2.5', '0.25'));
  ```
- [ ] **Run red:** `unshare --net php vendor/bin/phpunit -c migration.phpunit.xml --filter 'MoneyCalculatorTest|CheckoutTaxCalculationTest'`; expect missing calculator/decimal-discount failures.
- [ ] **Implement arithmetic:** use `Brick\Math\BigDecimal` and `RoundingMode::HALF_UP`. Reject nonfinite, exponential, malformed and negative fee/rate inputs; reject precision loss for monetary inputs. Calculate each product/setup unit tax before multiplying by quantity. Allocate partial credit proportionally, assigning the rounded residual to tax and checking bounds. Move applied coupons to the declared net product/setup amount before tax. Keep `Price` display formatting and public property names compatible; honor explicit zero tax and precomputed tax even after settings change.
- [ ] **Run green:** the focused command passes. Include percentage/fixed coupons and separate setup charges; assert a 10% coupon on synthetic net `100.00` yields net `90.00`, tax `6.41`, fee `2.50`, total `98.91`. Run existing checkout tests for compatibility.
- [ ] **Commit:** `Add exact product tax and gateway fee arithmetic`.

### Task 2: Frozen invoice tax, precision and accurate summaries

**Files:** Create `database/migrations/2026_09_30_230000_add_invoice_pricing_context.php`, `app/Services/Billing/InvoicePricing.php`, `tests/Feature/InvoicePricingTest.php`; modify `app/Models/TaxRate.php`, `app/Models/InvoiceSnapshot.php`, `app/Models/Invoice.php`, `app/Models/InvoiceItem.php`, `app/Observers/InvoiceObserver.php`, `app/Observers/InvoiceItemObserver.php`, `app/Listeners/CreateInvoiceSnapshotListener.php`, `app/Admin/Resources/TaxRateResource.php`, `app/Classes/Settings.php`.

**Interfaces:** Produce `InvoicePricing::capture(Invoice $invoice): void`, `lineTax(Invoice $invoice, string $grossUnit, int $quantity): string`, `summary(Invoice $invoice): PaymentSummary`, and `fingerprint(Invoice $invoice): string`. Consume Task 1 calculator and DTOs. Add invoice columns `pricing_tax_rate` decimal(7,4) nullable, `pricing_tax_name`/`pricing_tax_country` nullable strings, `pricing_tax_inclusive` nullable boolean. Add item columns `kind` string default `product`, `tax_amount` decimal(18,2) nullable. Widen existing `tax_rates.rate` and `invoice_snapshots.tax_rate` to decimal(7,4).

- [ ] **Write failing tests:** `test_issued_tax_survives_country_and_settings_changes`, `test_zero_tax_is_frozen`, `test_fee_is_excluded_from_invoice_tax`, `test_precision_migration_preserves_history_and_refuses_lossy_rollback`. Use native invoice/item factories, fake mail/jobs and synthetic tax records; test four-decimal save/load, old snapshot values and rollback precision protection. Assert widening `7.12` preserves the numeric value `7.1200`; narrowing must fail for `7.1250` without changing data. Assert a fee-bearing invoice's formatted tax is `7.13`, never tax extracted from `109.88`.

  ```php
  $this->assertSame('7.1250', $invoice->fresh()->pricing_tax_rate);
  $this->assertSame('7.13', (new InvoicePricing)->summary($invoice->fresh())->productTax);
  $this->assertSame('109.88', (new InvoicePricing)->summary($invoice->fresh())->total);
  ```
- [ ] **Run red:** `unshare --net php vendor/bin/phpunit -c migration.phpunit.xml --filter InvoicePricingTest`; expect absent columns/context failures.
- [ ] **Implement context and migration:** capture the current enabled tax policy at new native invoice creation, including zero. Prefer a historical paid snapshot for old invoices, then issued pricing context, then existing legacy behavior only for rows without either. Fill product line tax from authoritative product calculation when supplied; otherwise extract from its native gross unit amount and frozen context. Fee and credit-allocation rows carry explicit zero tax. Do not auto-backfill historical imports or rewrite old prices. Add `tax_scope` setting with backward-compatible `country` default and `all` mode using the configured all-countries record. Admin masks/casts retain four decimal percentage digits.
- [ ] **Implement summaries:** sum gross rows, product taxes and any already stored fee rows independently with decimals; subtract only succeeded payments. Use Task 1 for partial-credit allocation while the invoice is pending. A paid invoice retains its full `total` and has zero `payable`, `unpaidNet` and `unpaidTax`; do not allocate a negative product remainder when the paid amount includes the fee. Feed explicit tax amounts to existing `Price` formatting. Paid address snapshots copy the issued tax context, including zero, rather than current country/settings. Canonical fingerprints include invoice owner/currency, issued tax fields and sorted item IDs, kinds, gateway IDs, monetary values, quantities and reference identities; exclude mutable display-only descriptions.
- [ ] **Run green:** focused tests plus historical import and invoice payment processing tests pass. Verify old counts, IDs, amounts and snapshots are unchanged apart from equivalent decimal formatting/new empty columns.
- [ ] **Commit:** `Freeze invoice tax and preserve fractional rate precision`.

### Task 3: Common gateway fee settings and safe source preparation

**Files:** Create `app/Services/Gateways/GatewayFeePolicy.php`, `app/Services/BillmanagerMigration/GatewayFeeConfigurer.php`, `tests/Feature/GatewayFeeConfigurationTest.php`; modify `app/Helpers/ExtensionHelper.php`, `app/Classes/Extension/Gateway.php`, `app/Admin/Resources/GatewayResource.php`, `tools/billmanager/export-gateway-settings.py`, `app/Console/Commands/ImportFromBillmanager.php`, and `tests/Feature/BillmanagerMigration/GatewayConfigurationTest.php`.

**Interfaces:** Produce `GatewayFeePolicy::configFields(): array`, `values(Gateway $gateway, string $currency): array{enabled:bool,percent:string,fixed:string,currency:string}`, `quote(PaymentSummary $base, Gateway $gateway): PaymentSummary`, `assertSupported(Gateway $gateway, string $currency): void`, and `GatewayFeeConfigurer::configure(Snapshot $snapshot, ImportContext $context, string $encryptedBundle): array`. Consume the Task 2 invoice summary for previews and initiation; a cart constructs the same DTO from Task 1 product amounts. Add base extension method `supportsCustomerFeeCollection(): bool`, default false. Common setting keys: `customer_fee_enabled`, `customer_fee_percent`, `customer_fee_fixed`, `customer_fee_currency`.

- [ ] **Write failing tests:** `test_common_fee_fields_default_to_zero`, `test_invalid_and_mismatched_fee_policy_is_unavailable`, `test_mapped_source_fees_import_and_replay_without_enabling_collection`. All gateway admin forms expose common fields; invalid numbers/percent >=100, negative amounts and mismatched currencies are rejected. Confirm fee settings are separate from transaction deductions. Test mapped source IDs, module/currency/active checks, changed target rejection and exact disabled flags.

  ```php
  $this->assertSame('0.00', (new GatewayFeePolicy)->values($gateway, 'USD')['fixed']);
  $this->assertFalse($gateway->fresh()->enabled);
  $this->assertSame('0', $gateway->settings()->where('key', 'collection_enabled')->first()->value);
  ```
- [ ] **Run red:** `unshare --net php vendor/bin/phpunit -c migration.phpunit.xml --filter 'GatewayFeeConfigurationTest|GatewayConfigurationTest'`.
- [ ] **Implement policy and admin persistence:** append common fields through `ExtensionHelper::getConfig` so existing create/edit pages persist them using their existing config loop. Missing policy is disabled/zero. `quote` replaces any preview fee using the unpaid product net base, then computes `total` and `payable` from product amounts and succeeded payments; it never adds another percentage on the old fee. A fee-enabled adapter without the shared collection capability is unavailable until verified, including saved-method/automatic entry points; it must not silently collect a different amount. Zero-fee legacy adapters retain their behavior. Validate the exact selected record, rather than resolving its settings through another record with the same extension name.
- [ ] **Implement fee preparation:** add opt-in `gateway-fees` import stage and optional fee data to the private encrypted export. Read only authoritative `commissionpercent`, `commissionamount` and currency for selected source methods. The optional addition must not change existing credential-stage replay equality. Import fee settings only on the mapped disabled/held gateway after identity validation; reject drift and archive provenance. Keep real source rates out of public fixtures and defaults.
- [ ] **Run green:** focused configuration/import tests pass with synthetic fees; old bundles remain readable, old imports replay, and collection/provider flags stay disabled.
- [ ] **Commit:** `Configure shared customer gateway fees and mapped source import`.

### Task 4: Atomic fee claims, financial write guards and settlement

**Files:** Create `database/migrations/2026_09_30_230001_add_gateway_attempt_pricing.php`, `app/Services/Gateways/PaymentWriteGuard.php`, `app/Models/Traits/GuardsPaymentWrites.php`, `tests/Feature/GatewayFeeSettlementTest.php`; modify `app/Services/Gateways/PaymentAttempts.php`, `app/Models/GatewayPaymentAttempt.php`, `app/Models/Invoice.php`, `app/Models/InvoiceItem.php`, `app/Helpers/ExtensionHelper.php`, `app/Livewire/Invoices/Show.php`, `app/Console/Commands/CronJob.php`.

**Interfaces:** Existing `PaymentAttempts::begin`, `validate` and `settle` signatures stay unchanged. Add encrypted-array `pricing_payload` and nullable 64-character `pricing_fingerprint` on attempts. Produce `PaymentWriteGuard::withInvoiceLock(Model $record, callable $write): mixed`, `assertEditable(Invoice $invoice): void`, and `duringSettlement(GatewayPaymentAttempt $attempt, callable $write): mixed`. The model trait wraps Eloquent insert/update/delete operations affecting existing invoice finances in a DB transaction with parent invoice locks; moving a row checks both old and new parent IDs in ascending order. It preserves existing migration guards and auditing. `duringSettlement` is used only after ledger verification under its row locks, scopes the native pending-to-paid status transition to that exact invoice, and always clears its internal scope in `finally`; it cannot permit item/owner/currency changes.

- [ ] **Write failing tests:** `test_attempt_commits_one_untaxed_fee_and_replays_once`, `test_settings_change_reuses_frozen_attempt`, `test_stale_pricing_cannot_settle`, `test_cross_gateway_credit_and_item_mutations_serialize`. Initiation commits exactly one zero-tax fee line and attempt amount `109.88`; cached continuation does not add another fee. Stale invoice owner/currency/price/tax/fee identity fails verification. Genuine notification replays yield one transaction and one service action. Include new-row insertion, row deletion and row-parent reassignment, not only `update`.

  ```php
  $this->assertSame('109.88', $attempt->fresh()->amount);
  $this->assertSame(1, $invoice->items()->where('kind', 'gateway_fee')->count());
  $this->assertSame('0.00', $invoice->items()->where('kind', 'gateway_fee')->sole()->tax_amount);
  $this->assertSame(1, $invoice->transactions()->count()); // after authentic replay
  ```
- [ ] **Run red:** `unshare --net php vendor/bin/phpunit -c migration.phpunit.xml --filter GatewayFeeSettlementTest`; concurrency cases use independent DB connections/processes with bounded synchronization and no external HTTP. External-initiation cases use nontransactional migrated fixtures rather than an enclosing `RefreshDatabase` test transaction, so the durable-claim guard is exercised faithfully.
- [ ] **Implement claim:** retain gateway -> invoice -> attempt lock ordering. Under the invoice lock inspect attempts across all gateways, assert collection and holds, refresh rows, calculate the chosen policy, upsert the single `gateway_fee` line with quantity one/no service reference/zero tax, and create a destination attempt with its exact balance. Capture frozen net/tax/fee values, fee policy, line allocation and canonical fingerprint separately from provider payload. Claim before networking; reject an outer transaction if its rollback could erase the external claim. Cached existing attempts use their frozen payload rather than new settings. Legacy initialized attempts cannot acquire a retroactive fee.
- [ ] **Implement mutation and credit guards:** serialize wallet spending and all native invoice/item writes with the same invoice lock. Freeze financial fields once an unresolved or paid external attempt exists; settlement's own status/transaction operation remains permitted under its verified ledger transition. Do not guard ordinary unrelated account/profile edits. Credit payment before a claim records its normal native transaction and leaves external fee creation to the next initiation; recompute proportional unpaid net/tax. Cron and saved-method charges pass through policy/capability checks. No operator recovery or automatic attempt replacement is invented here.
- [ ] **Implement verification:** validate the pricing fingerprint and exact current fee line in addition to existing merchant/owner/amount/currency checks. Preserve legacy callback checks for attempts without the additive pricing payload when no retroactive fee was added. Native settlement includes the fee in invoice payment but never adds it to service renewal or wallet deposit value.
- [ ] **Run green:** settlement/concurrency tests and existing four-gateway suites pass. Timeout/expired-session tests confirm no second provider initialization. Direct adapter initiation and direct helper calls both enforce the same fee policy.
- [ ] **Commit:** `Bind untaxed gateway fees to immutable native payment attempts`.

### Task 5: Native pricing, checkout, invoice and PDF consistency

**Files:** Modify `app/Models/CartItem.php`, `app/Models/Service.php`, `app/Livewire/Products/Checkout.php`, `app/Livewire/Cart.php`, `app/Livewire/Invoices/Show.php`, `themes/default/views/cart.blade.php`, `themes/default/views/invoices/show.blade.php`, `themes/default/views/invoices/partials/payment-options.blade.php`, `themes/default/views/invoices/partials/payment-modal.blade.php`, `resources/views/pdf/invoice.blade.php`; create `tests/Feature/CheckoutGatewayFeeTest.php`, extend checkout and invoice tests.

**Interfaces:** Consume Tasks 1-4. Add computed `paymentSummary(): PaymentSummary` to the cart and invoice components, resolved from server-side selected gateway/credit state. Preserve existing locked totals, URL-safe registrar contact handling and gateway view return contract.

- [ ] **Write failing tests:** `test_switching_gateway_only_changes_preview`, `test_coupon_quantity_and_option_recalculate_tax_and_fee`, `test_checkout_rejects_forged_payment_values`, `test_pdf_invoice_and_checkout_share_totals`, `test_fee_does_not_change_service_or_credit_value`. Use native cart/product/user fixtures and Livewire; entirely credit-paid and zero-priced checkout have no fee. Gateway preview changes create no invoice/attempt/HTTP request.

  ```php
  $this->assertSame('2.75', $component->instance()->paymentSummary()->gatewayFee);
  $this->assertSame('109.88', $component->instance()->paymentSummary()->payable);
  $this->assertSame(0, GatewayPaymentAttempt::count()); // preview only
  Http::assertNothingSent();
  ```
- [ ] **Run red:** `unshare --net php vendor/bin/phpunit -c migration.phpunit.xml --filter 'CheckoutGatewayFeeTest|CheckoutTest|InvoicePricingTest'`.
- [ ] **Implement pricing integration:** replace monetary float accumulation in the changed product/cart/service price paths with decimal calculator inputs, including integer-minor-unit paid options. Apply coupons on net inputs, preserve first-cycle/recurring coupon semantics and store the corresponding explicit item tax at invoice creation. Keep setup price out of recurring service renewal. Issued renewal invoices capture tax without increasing a stored negotiated gross service price.
- [ ] **Implement summaries and controls:** show product subtotal, tax label/rate/amount, selected gateway fee and total; list only enabled and eligible gateways. Preserve the previously accepted grouped UI and locked domain zone. Carry a selected eligible cart gateway to invoice payment as a preference only; re-authorize it at initiation. Continue a claimed checkout on its original gateway; disable conflicting credit/switch controls with a clear reconciliation message. PDF subtotal excludes the separate fee.
- [ ] **Run green:** focused native tests pass. Exercise disposable browser checkout at desktop and narrow widths; verify displayed fee switching and the native receipt/PDF. Build existing frontend assets and run `vendor/bin/pint --test` on changed PHP files. No new UI framework or dependency.
- [ ] **Commit:** `Show precise tax and untaxed payment fees throughout checkout`.

### Task 6: Tax-aware provider allocations

**Files:** Create `extensions/Gateways/Klarna/OrderLines.php`, `extensions/Gateways/Wave/OrderLines.php`; modify `extensions/Gateways/Klarna/Klarna.php`, `extensions/Gateways/Wave/Wave.php`, `extensions/Gateways/AuthorizeNet/AuthorizeNet.php`, `extensions/Gateways/WebMoney/WebMoney.php`; extend `tests/Feature/BillmanagerMigration/KlarnaTest.php`, `tests/Feature/BillmanagerMigration/WaveTest.php`, `tests/Feature/BillmanagerMigration/AuthorizeNetTest.php`, `tests/Feature/BillmanagerMigration/WebMoneyTest.php`.

**Interfaces:** Add `supportsCustomerFeeCollection(): bool` returning true only to adapters using the Task 4 ledger. Produce `Klarna\OrderLines::build(GatewayPaymentAttempt $attempt, string $purchaseCountry): array{order_amount:int,order_tax_amount:int,order_lines:array}` and `assertCaptured(GatewayPaymentAttempt $attempt, array $order): void`. Produce `Wave\OrderLines::build(GatewayPaymentAttempt $attempt, string $productId, ?array $verifiedTax): array`, `assertTax(array $tax, string $businessId, string $expectedRate): void`, and `assertInvoice(GatewayPaymentAttempt $attempt, array $invoice): void`. Add Wave setting `sales_tax_id`; no default merchant tax ID.

- [ ] **Write failing tests:** `test_tax_and_untaxed_fee_lines_match_native_capture` and `test_bad_tax_allocation_cannot_approve_or_settle` in each relevant provider suite. Synthetic `109.88` checkout sends product net `100.00`, tax `7.13`, untaxed fee `2.75`, and exact captured amount. Reject wrong provider tax, line totals, fee amount, merchant tax owner/rate/compound status, currency and partial capture. Preserve token/signature, customer-claim, timeout, refund and callback replay cases; move external-initiation fixtures to nontransactional migration setup as Task 4 requires.

  ```php
  $body = OrderLines::build($attempt, 'US'); // Klarna OrderLines
  $this->assertSame(10988, $body['order_amount']);
  $this->assertSame(713, $body['order_tax_amount']);
  $this->assertSame(10988, array_sum(array_column($body['order_lines'], 'total_amount')));
  ```
- [ ] **Run red:** `unshare --net php vendor/bin/phpunit -c migration.phpunit.xml --filter 'KlarnaTest|WaveTest|AuthorizeNetTest|WebMoneyTest'`; all HTTP is fake and stray requests are prevented.
- [ ] **Implement Klarna allocation:** use the frozen unit/quantity tax amounts and zero-tax fee. US uses the documented separate sales-tax line; other verified markets use documented line-inclusive taxes and integer rate encoding with its validation tolerance. Total all order lines exactly once. Unsupported precision/low-value allocation is rejected before provider session creation, never replaced with zero tax. Validate authenticated captured order allocations as well as total, fraud, refund and native identity. Partial-credit markets remain refused until separately accepted.
- [ ] **Implement Wave allocation:** authenticate a business-scoped read of the configured sales tax before customer creation/invoice writes. Validate ownership, effective rate and noncompound behavior against issued tax. Send product net lines with that tax and a separate fee line with no taxes. Include remote invoice item/tax amounts in create/read/approve/reconcile fields and require equality with the frozen native allocation. Do not create or modify sales-tax records implicitly. Preserve customer bindings and no-mail invoice behavior. Partial-credit Wave invoices remain refused until accepted.
- [ ] **Integrate amount-only adapters:** Authorize.Net and WebMoney already receive the ledger amount; verify they now receive the fee-bearing amount without a second addition. Existing merchant and signature checks remain unchanged.
- [ ] **Run green:** four offline gateway suites plus Task 4 settlement tests pass. No unsupported taxed invoice is silently represented as a zero-tax gross line.
- [ ] **Commit:** `Verify tax and fee allocations in native gateway adapters`.

### Task 7: Integrated acceptance, private configuration and publication

**Files:** Update `docs/billmanager-migration-payments.md`; add only generic regression tests needed by whole-branch findings. Private acceptance harness/evidence and real tax/fee settings remain outside the repositories.

**Interfaces:** Consume the completed native pricing/ledger/provider interfaces. No new public API.

- [ ] **Run integrated checks:** focused billing/gateway/checkout/migration suites, then the existing network-isolated suite, existing Vite build and Pint. Require no failures or unhandled warnings. Restore the isolated baseline and preserve existing synthetic jobs after test runs. Do not claim fixture tests as real provider acceptance.
- [ ] **Run native disposable browser/provider acceptance:** apply real configuration only to disposable test gateway records. Repeat Authorize.Net sandbox checkout with tax and fee; complete native Klarna playground session/order/callback acceptance in an account-accepted USD market. Confirm one payment/service activation and authentic callback replay idempotence, rejecting tampered totals/taxes. Archive provider evidence privately, remove exact temporary routes/webhooks, and restore the baseline. The earlier nonexistent-session 404 responses do not prove Klarna authentication or market/Consumer FX enablement.
- [ ] **Preserve missing provider gates:** Wave writes require an explicitly isolated business and verified tax record; WebMoney requires verified merchant test-mode acceptance. Use offline cases until those prerequisites are available. Keep these gateways disabled and report the exact remaining gate. Consumer FX and customer market selection continue as the spec's follow-up; do not claim local-currency checkout has been implemented by this plan.
- [ ] **Obtain whole-branch review:** use the review skill with one fresh reviewer under the chosen Native workflow, resolve material findings with regression tests, and rerun only affected checks.
- [ ] **Prepare held candidate:** back up its code/database/config, apply only the approved additive schema/private fee and universal tax policy, and verify source mappings/credentials/collection holds. Compare all original financial values, counts, IDs and balances using canonical decimal values; newly added columns and private settings are explicit expected changes, not a claim of byte-identical table fingerprints. Verify production runtime/environment and source billing remain untouched. Reconcile public browser access separately before activation.
- [ ] **Publish and record:** mirror generic commits to the existing Paymenter fork branch/draft PR, scan the patch for private configuration, and verify the remote commit. Update private readiness with exact offline/browser/sandbox evidence and remaining gates. Collection and handover require their separate approval.

## Execution handoff

The user selected Native execution earlier. Review this plan before starting Task 1; retain that execution method. Implementation uses the executing-plans skill, an isolated worktree, task-by-task checks/commits, and one fresh whole-branch review. Approval of this plan authorizes the implementation and isolated preparation described here; it does not authorize live handover.
