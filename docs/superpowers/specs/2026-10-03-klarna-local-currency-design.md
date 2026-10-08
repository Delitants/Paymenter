# Klarna local-currency checkout against USD invoices

Status: proposed implementation design; no collection or billing handover authorized by this document.

## Requested outcome

Customers choose the country of their Klarna account. Klarna receives and charges that country's supported currency: for example DE/EUR, GB/GBP or US/USD. Paymenter invoices, fees, tax allocations, receipts and administrative operations remain denominated in USD. A verified local-currency payment settles the corresponding original USD invoice exactly once.

This replaces the proposed Consumer FX flow for new checkouts. That flow sends USD to Klarna and depends on separate merchant enablement. Existing initialized sessions must retain their original contract.

## Currency and rate policy

Country determines currency through Klarna's documented mapping; customers cannot submit an independent currency, amount, locale or exchange rate. An administrator explicitly lists merchant-approved countries. API session creation alone does not prove merchant or hosted checkout acceptance.

The working pricing policy is ECB daily reference rates and zero additional currency markup. ECB publishes informational reference rates and discourages their use as transaction rates. Here they determine the merchant's quoted customer price; they do not guarantee Klarna's bank conversion or USD payout. Paymenter must not represent a recorded USD invoice receipt as proof of an identical USD bank settlement.

Read only the official HTTPS ECB XML endpoint, reject redirects, external XML entities, oversized or malformed responses, duplicate currencies, nonpositive rates and future dates. Use exact decimal arithmetic. Reject a rate older than four calendar days; do not substitute a fabricated rate or silently change provider. A fresh validated response may be cached for one hour. USD/USD uses an identity conversion without a rate request.

Rates are EUR-based. For a target currency C, the USD-to-C pricing rate is `ECB[C] / ECB[USD]`, with EUR represented by 1. Preserve the input rates and their date; avoid binary floating-point arithmetic or rounding an intermediate quotient before calculating monetary amounts.

The rate policy is configurable and described in admin settings. It does not introduce a payable third-party API subscription or hardcoded merchant credentials.

## Quote and customer confirmation

Keep the native payment attempt amount and currency as the frozen USD payable total, including product tax and the existing untaxed gateway fee. Persist a versioned encrypted conversion snapshot in the attempt's provider payload. Bind its fingerprint to the attempt reference, invoice and gateway IDs, merchant fingerprint, country, locale, both currencies, native pricing fingerprint, original USD minor-unit amounts, reference-rate inputs, converted allocation, creation time and confirmation deadline.

Country selection prepares the quote without creating a Klarna session. Show the original USD breakdown and the exact local-currency total on a confirmation page. Show the exchange rate and rate date. State that Paymenter applies the quoted local-currency payment to the original USD invoice; avoid claiming Klarna chooses or guarantees this conversion.

The quote is valid for 30 minutes before provider initialization. The confirmation POST requires the invoice owner's payment permission, CSRF protection, the original attempt reference and conversion fingerprint. It accepts no customer-supplied money. Selection fixes the country. Returning to the quote reuses it; an expired quote or changed pricing requires reconciliation before a replacement attempt is allowed.

Claim initialization durably before the first provider write. Once initialization starts, preserve the original quote and session on every retry, even if rates, admin settings or enabled countries change. A timeout cannot create a second session. An initialized session follows its saved provider expiry; later reference-rate expiry does not invalidate an already captured payment.

## Local-currency order allocation

Convert the complete frozen USD order allocation into local minor units. The order amount must equal the sum of provider lines. Allocate rounding residuals deterministically using largest remainders and stable native line identity, with no artificial rounding surcharge.

Product tax retains the configured native rate in the USD ledger. Preserve the native tax calculation and convert its allocation along with the products. Klarna's integer basis-point tax representation and rounding tolerances are checked before any API write. Never change the native tax to make the provider accept it. Gateway fee lines always have zero tax.

Retain product references and distinguish split unit groups deterministically where a quantity cannot represent a converted total with one integer unit price. Limit generated provider lines to 1,000. Reject an unrepresentable quantity, tax allocation, zero payable total or excessive line count before provider initialization. Existing US sales-tax lines and inclusive-tax markets retain their respective provider conventions.

Save the complete converted order allocation with the quote. Capture and refund checks compare this saved allocation with authenticated provider readback, rather than recalculating it using current rates.

## Checkout, automatic capture and USD receipts

New local-currency sessions send the selected country, its matching purchase currency, the saved locale and the converted order lines. Continue using hosted checkout with `CAPTURE_ORDER` for immediately delivered digital services.

The callback must authenticate the saved callback token, HPP session ID and merchant endpoint. Order readback must prove the saved merchant reference, country, local currency, exact local order and captured amount, accepted fraud state, no refund, and every saved line and tax allocation. Pending, authorized, partially captured, mismatched or uncertain orders remain unpaid.

Only that verified readback can settle the native attempt for its original USD amount and produce one USD receipt. Duplicate callbacks produce no extra receipt, capture or provisioning action. Store the local-currency amount and conversion fingerprint as protected reconciliation evidence associated with that USD receipt.

## Admin capture, refunds and reconciliation

Administrative requests and their native refund allocations remain in USD. Preview the exact local amount that will be sent to Klarna alongside the USD ledger amount. Manual settlement stays explicitly manual and cannot be treated as authenticated Klarna capture evidence.

The Klarna adapter freezes both native and provider money in each operation context. Authenticated outcome verification must bind provider currency, provider amount, original provider amount and conversion fingerprint as well as the existing native identity, amount, currency and request key. Preserve these fields in encrypted retained evidence. An adapter cannot mark an operation successful using only a native USD match.

Contextual manual capture uses the complete saved local authorization amount and records the original USD amount after authenticated capture readback. Preserve prior capture ancestry when refunding such a receipt.

For partial refunds use cumulative proportional allocation against the captured pair of totals, avoiding repeated inverse-rate rounding. If N is captured USD cents and P is captured local minor units, let `R(x) = round_half_up(P * x / N)`. For a native refund of a after previously verified native refunds totaling b, the provider refund is `R(b + a) - R(b)`. This makes the final remaining refund consume exactly the remaining local amount. Refuse a requested refund that maps to zero provider units, exceeds either remaining amount or violates the native fee-refund policy.

Determine b from the matched, succeeded native provider-refund operations and retained authenticated evidence for this original receipt. Authenticate the corresponding remote refund children and reconcile their identities, idempotency references and amounts. An unexplained merchant-portal refund, missing evidence, external manual record without a verified local amount, or provider total drift blocks a new automated refund; do not estimate native refunds by dividing the remote aggregate by today's rate.

During readback of a processing or uncertain operation, permit only its frozen expected provider delta in addition to the verified prior refunds. After success, replay the same request key without another provider write. Preserve existing journal locks and the rule that processing or uncertain operations block overlapping money transitions. Reconciliation uses authenticated reads only.

## Compatibility, configuration and publication

Add a local-currency checkout option disabled by default. It is mutually exclusive with Consumer FX for new attempts. Existing initialized attempts are selected by their saved payload version, not by today's configuration. Legacy USD attempts retain their original checkout, callback, capture and refund behavior. A foreign-currency session without a complete bound conversion snapshot cannot settle a USD invoice.

The change belongs in the Paymenter fork: Klarna quote/allocation helpers, the Klarna gateway and views, its admin operations adapter, narrow shared outcome-evidence verification, tests and operator documentation. No source merchant identifiers, customer data, credentials, wallet names or host details belong in the public patch.

Prepared migration and production collection stay disabled. BILLmanager remains the billing authority. The new option must not release migration holds, change callbacks or mail delivery, create a live order, or enable collection as an installation side effect.

## Acceptance and rollback

Tests must demonstrate DE/EUR and GB/GBP requests from USD invoices; exact product tax and untaxed fees; deterministic unit/rounding allocation; stale and unavailable rate refusal; owner/CSRF/attempt binding; quote expiration; same-quote retries despite changed rates/settings; unknown-session refusal; currency, amount and line mismatch rejection; one USD settlement on repeated callbacks; full and sequential partial refunds; zero-unit refund refusal; external refund drift rejection; uncertain read-only reconciliation; and all legacy USD contracts.

Run focused tests, the migration suite, formatting checks and the frontend build in an isolated application/database without provider network access. Then use only the supplied Klarna playground credentials and disposable synthetic invoices for actual hosted local-currency checkout, capture, callback and admin partial/remaining-refund acceptance. Successful session creation is not the final acceptance gate.

Archive private fixture evidence, remove exact created temporary resources, and prove production, held migration and original QA boundaries unchanged. Do not replace imported gateway settings or install the feature into the held candidate until these checks pass. If rollback becomes necessary after an initialized conversion attempt exists, preserve its saved snapshot and an adapter capable of reconciling it; do not downgrade away the only code that can verify an unresolved payment.

## Primary references

- [Klarna country, currency and locale mapping](https://docs.klarna.com/acquirer/klarna/get-started/data-requirements/puchase-countries-currencies-locales/)
- [Klarna hosted session creation](https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/api-documentation/create-session/)
- [Klarna Order Management API](https://docs.klarna.com/acquirer/klarna/api/ordermanagement/)
- [ECB reference rates and their informational purpose](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html)
