# Klarna local-currency implementation plan

> **For agentic workers:** Use superpowers:executing-plans with the user's selected native execution method. Follow each task's RED/GREEN checks.

**Goal:** Charge Klarna in the country-linked currency and reconcile the original USD invoice, captures and refunds.

**Architecture:** Save a bound conversion snapshot inside the encrypted attempt payload; preserve native USD fields. Convert provider amounts only inside Klarna, and bind both amounts in retained operation evidence.

**Tech Stack:** Laravel, Brick Math exact rational arithmetic, authenticated Klarna HPP, ECB XML, PHPUnit.

**Spec:** ../specs/2026-10-03-klarna-local-currency-design.md

## Global constraints

- Zero extra currency markup; ECB reference pricing, not a guaranteed bank payout.
- Four-calendar-day rate freshness; one-hour cache; thirty-minute pre-initialization quote.
- Country-linked currency, invoice-owner permission, CSRF and frozen attempts.
- Configured native tax; no tax on fees; maximum 1,000 converted order lines.
- Preserve legacy USD/Consumer FX attempts and all processing/uncertain operation locks.
- No production billing, delivery, callbacks, holds or live money operations.

## Review focus

- Tiny refunds mapping to zero provider units must refuse before claim/write.
- Unknown session responses preserve the quote and block another write.
- Provider refund drift blocks automated refunds, including after partial native refunds.
- Altered or incomplete conversion snapshots cannot settle an invoice or refund money.
- Changed rates and settings cannot change a previously confirmed provider session.

### Task 1: Exact quote and allocation

**Files:** new Klarna ReferenceRates.php, ConversionQuote.php; tests/Unit/KlarnaConversionQuoteTest.php.
**Interfaces:** ReferenceRates::get(string): array returns dated EUR-based rational inputs; ConversionQuote::create(GatewayPaymentAttempt,array,array): array binds identity and allocation; ConversionQuote::validate(GatewayPaymentAttempt): array verifies saved snapshot; ConversionQuote::refund(array,int,int): int produces cumulative refund delta.
- [x] Write tests for dated rates, exact country conversion, bound identity, deterministic quantity/tax rounding, stale/malformed rates and cumulative refunds.
- [x] Run tests on original implementation and record the missing feature failure.
- [x] Implement exact decimal/rational quote, validated XML cache and stable line allocation.
- [x] Run helper tests and existing Klarna allocation tests; expected PASS.

### Task 2: Native local-currency checkout and settlement

**Files:** Klarna.php, Markets.php, routes.php and pay/summary views; KlarnaTest.php.
**Interfaces:** Saves conversion_quote in provider_payload; quote POST prepares confirmation; confirmation POST fixes provider session. Legacy payloads unchanged.
- [x] Add native tests for DE/EUR and GB/GBP selection/confirmation, unchanged USD receipts, retries, mismatched callback amounts, expiry and permissions.
- [x] Record RED against current gateway.
- [x] Implement disabled-by-default local_currency_enabled mode, bound confirmation and callback provider verification.
- [x] Run all Klarna feature/helper tests; expected PASS.

### Task 3: Admin capture/refunds and evidence

**Files:** Klarna operations adapter; OperationResult.php; ProviderOperations.php preview; native admin views/tests.
**Interfaces:** Provider context adds provider_currency, provider_amount, provider_original_amount and conversion_fingerprint; native amount/currency unchanged. Saved cumulative refund history determines provider delta.
- [x] Add tests for local capture, partial/remaining refunds, drift, zero-unit refusal, uncertain read-only recovery and retained evidence tampering.
- [x] Record RED against current adapter/evidence checks.
- [x] Implement frozen provider context and authenticated refund child/history reconciliation, preserving native journal locks.
- [x] Run admin operation and complete migration suite; expected PASS.

### Task 4: Native browser/provider acceptance and publication

**Files:** operator documentation and private QA operators; fork patch.
- [x] Run formatting/build and full suite in disposable isolated application/database.
- [x] Review whole branch with one fresh reviewer; fix material findings with RED/GREEN tests.
- [x] Test actual playground local checkout/capture/callback and partial/remaining refund, or retain the precise merchant acceptance blocker.
- [x] Archive and remove exact created fixtures; prove original QA, held and production unchanged.
- [x] Publish privacy-scanned code to the existing draft fork PR; retain disabled collection and pending handover.
