# Checkout tax and gateway fees

## Purpose and scope

Customers must see the product subtotal, tax, selected payment method fee and final payable total before starting an external payment. All payment gateways use the same calculation. The customer fee is untaxed and remains separate from the processor's deduction recorded on an invoice transaction.

Tax and fee rates are operator configuration, never merchant-specific code defaults. Rates, account identifiers, credentials, customer records and deployment addresses must not appear in public implementation or fixtures. Public examples below are synthetic. Deployment configuration is maintained privately.

This changes native cart, invoice payment and gateway initiation behavior, with compatibility for existing invoice accounting and migration holds. It does not authorize billing handover, live collection, source configuration changes, customer mail or provider provisioning. Imported history, balances and negotiated gross service prices remain intact. A new tax policy must not reinterpret an imported gross renewal price as a net catalog price.

## Chosen approach

Use a common decimal calculation and one identifiable gateway-fee invoice line. Include that line in the native invoice balance so provider capture, callback verification and Paymenter's paid-invoice hooks agree on one amount.

A separate surcharge outside the invoice would require a second accounting amount and reconciliation path. Per-plugin calculations would require each adapter to reproduce rounding and taxation rules. The common invoice approach keeps the customer's receipt and provider charge consistent.

## Calculation contract

For an unpaid product purchase, compute the discounted product subtotal before tax and before the gateway fee. Include product setup charges and paid product options in that subtotal. Calculate product tax using the configured rate, retaining at least four fractional digits of percentage precision. Calculate the gateway fee as the configured percentage of the product subtotal plus the configured fixed fee. Do not calculate a percentage on tax, on the fee itself, or on other fees, and do not gross up the result to recover a processor deduction.

Use decimal arithmetic. Preserve the native two-decimal unit-price contract: calculate and HALF_UP-round the product unit's tax and its separately priced setup charge's tax to two currency decimals, then multiply those tax amounts by quantity and sum them. Paid options contribute to the product unit's net amount before this calculation. Round the final gateway fee once with HALF_UP. Coupon changes and quantity changes require fresh calculation from product values; totals displayed earlier are not trusted input. Explicitly test small-value, multiple-quantity cases so stored gross unit prices multiplied by quantity equal the native invoice total.

Synthetic example: a net product subtotal of 100.00, a tax rate of 7.1250%, and a gateway fee of 2.5% plus 0.25 produce tax 7.13, fee 2.75 and payable total 109.88. The gateway fee's tax amount is exactly zero.

Preserve native tax-inclusive price support. In that mode, extract and freeze the product line's tax rather than adding it again. The deployment's new purchase policy uses tax-exclusive catalog prices. Existing gross service prices are not automatically converted or increased.

The initial implementation supports two-decimal invoice and gateway currencies. Fixed fees must declare the currency they belong to; a mismatched fixed-fee currency is unavailable, never silently converted. Zero-value purchases and payments entirely from existing credit do not incur an external gateway fee.

## Native records and precision

Add a forward migration widening both tax-rate storage and invoice-snapshot tax-rate storage to four fractional percentage digits; update casts and admin validation/input precision accordingly. Preserve existing numeric values and historic snapshots. Rollback must refuse a precision-losing narrowing while values requiring more than two fractional digits exist.

Persist each newly issued invoice's tax context, including a zero-tax context. Its rate must not be looked up again from a customer's changed country or current settings after issuance. Preserve existing customer/address snapshot behavior and migration imports.

Add nullable per-line tax amounts and an explicit line kind identifying a gateway fee. Existing rows use their existing accounting interpretation until an authorized new-invoice calculation produces a frozen context. New product rows carry the calculated tax; a gateway-fee row carries zero tax, quantity one, its exact gateway identity and no service or credit reference. Preserve native invoice item prices as gross amounts.

Invoice, cart, receipt and PDF summaries use the same frozen product tax totals. They must never extract tax from the grand total containing an untaxed fee. The fee must not become a recurring service price, a paid product option or an amount credited to the customer's wallet.

## Gateway configuration and migration

Provide shared admin fields for enabling customer fees, percentage, fixed amount and fixed-amount currency for every gateway. Defaults are disabled/zero. Validate finite nonnegative decimal values and a percentage below 100; reject malformed configuration instead of guessing. Changes affect new quotes, not a provider checkout already initialized.

Import customer-fee configuration from authoritative source payment-method fields using existing source identity and gateway mappings. Keep this private, separate from public defaults and processor transaction deductions. Preparation is explicit and replayable: it verifies source module, active state and currency, refuses changed destination configuration, and never enables a gateway or collection. Older imported configurations remain usable with fees disabled until this preparation step is explicitly applied.

Configure a universal tax rate privately for future Paymenter billing. Country-specific overrides must not defeat that deployment policy. Historical invoices, archived taxes, existing native paid invoices, held services and customer balances are not rewritten. Any conversion of imported gross renewal prices needs a separate reconciled handover action.

## Customer flow and quote ownership

Show subtotal, exact tax-rate label, product tax, selected gateway fee and total in the native grouped checkout and invoice payment flow. Switching a payment method updates the fee preview. Credit payment shows no gateway fee. The server resolves the exact enabled gateway and all monetary values; the browser cannot supply a fee, tax amount, provider currency or grand total.

A preview does not mutate the invoice or create an external checkout. On payment initiation, authorize invoice ownership and migration holds, lock the gateway and invoice, recalculate from current records, persist or replace the single fee line, and create an immutable destination payment attempt for the resulting invoice balance.

Once an external attempt is claimed, do not replace the fee, switch gateway, spend credit, or initialize another provider checkout for that invoice until the original attempt is definitively reconciled. Cached checkout continuation must reuse its frozen amount. Uncertain or expired provider responses remain blocked for reconciliation; no blind resubmission. A payment-method change before any provider claim replaces the fee rather than adding another line.

For partial credit paid before external initiation, allocate that credit proportionally to the remaining product net and tax components. Derive the percentage-fee base from that unpaid product net portion, with the rounding residue assigned to tax so net plus tax equals the exact remaining product balance. Apply one fixed fee to the remaining external payment. Reject allocations that cannot be reconciled. Existing adapter restrictions on partial payments stay in force until that adapter's allocation tests and provider acceptance pass.

Saved-method and automatic charge entry points must use the same policy. An adapter cannot bypass a configured customer fee by calling the legacy amount path. Unverified automated charge behavior stays unavailable rather than charging a different amount from the invoice.

## Provider adapters and settlement

Authorize.Net and WebMoney receive the exact gross native payable amount, including the untaxed fee. Their existing merchant, currency, signature, captured-state and transaction-identity checks remain mandatory.

Klarna sends product, tax and untaxed-fee allocations matching the frozen native amount. Use the documented market-specific tax representation and verify both the order and captured totals against the destination attempt. Preserve the native fractional tax rate and exact rounded tax amount even where Klarna's rate representation has lower precision. If a market cannot represent the allocation within documented validation rules, refuse initiation; never substitute zero tax or change the invoice tax rate to make the provider accept it.

Wave sends product net amounts with the verified business sales-tax identity, plus a separate untaxed-fee item. Authenticate and validate the configured tax's current rate, business ownership and noncompound behavior. Read back line tax amounts and final invoice totals before approving payment collection. Do not create or patch a live business sales-tax record implicitly, and do not represent a taxed invoice as one zero-tax gross line.

Callbacks settle the original attempt's exact amount and currency through the existing locked native ledger. Check invoice owner, current state, frozen pricing context and fee identity. Duplicate notifications remain idempotent; browser returns never settle an invoice. Paid-service lifecycle hooks run once and ignore the fee line.

## Klarna local currency follow-up

Keep Paymenter invoices and Klarna transaction/merchant settlement currency in USD for this deployment. Prefer Klarna Consumer FX for local customer billing after its merchant enablement and supported markets are verified. The customer's available local billing currency follows the selected supported country and Klarna account; an arbitrary country/currency combination is not offered.

Klarna provides conversion disclosures and determines its capture-time rate. Paymenter must not claim an indicative display conversion is a guaranteed charge. Customer market selection and Consumer FX acceptance follow the shared tax/fee implementation. Until tested, only verified account markets are eligible; there is no fallback bespoke FX engine or silent unsupported-market conversion in this change.

## Verification and delivery

Cover fractional percentage precision, tax-exclusive and inclusive products, untaxed fee lines, coupons, quantities, setup charges, credit allocations, rounding boundaries, zero purchases, mismatched fixed-fee currencies and malformed settings. Verify cart, invoice, PDF and provider amounts agree.

Exercise payment-method switching, repeated initialization, concurrent attempts on different gateways, fee/rate configuration changes, ownership tampering, migration holds, partial-payment restrictions, uncertain creation and expired sessions. Verify authentic callback replays produce one native transaction and one paid-service action; bad signatures, incorrect totals/tax allocations or stale pricing contexts never settle.

Run focused offline gateway, invoice and checkout tests, then the relevant integrated suite. Validate the native browser flow with disposable users, products and invoices. Real sandbox acceptance must verify taxed/fee-bearing provider payment and callback delivery, with credentials and raw provider evidence kept private. Preserve accepted evidence before cleanup and restore the isolated baseline. Candidate and production fingerprint checks must demonstrate that preparation did not mutate held customer billing or activate collection.

Publish generic implementation, migrations, tests and documentation in the Paymenter fork. Keep merchant configuration and private acceptance evidence outside Git. Billing handover remains a separate approved operation.

## Provider references

- [Klarna tax handling](https://docs.klarna.com/acquirer/klarna/web-payments/additional-resources/error-handling-and-validations/tax-handling/)
- [Klarna Consumer FX](https://docs.klarna.com/acquirer/klarna/web-payments/additional-resources/use-cases/consumer-fx/)
- [Wave API schema](https://developer.waveapps.com/hc/en-us/articles/360019968212-API-Reference)
