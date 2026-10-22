# Admin refunds and payment settlement — approved design

Status: user approved this written design on 2026-10-01. Implementation and isolated verification follow; billing handover remains separate.

## Requested outcome

Administrators manage refunds from Paymenter and explicitly identify payments as manually settled or unsettled. The same admin interface and accounting rules apply to every installed payment gateway. Generic improvements belong in the public Paymenter fork; credentials, customer data and deployment configuration stay private. BILLmanager remains authoritative until the separate billing handover.

The approved design supports both recording money received outside the gateway and capturing an existing gateway authorization as separate actions, with distinct provenance labels.

## Recommended approach

Add shared admin actions, a durable payment-operation journal and gateway capability methods. Provider adapters implement actual refund/capture/readback only where the provider exposes and authorizes the required operation. Manual recording is available across gateways with explicit admin attribution; it never claims that Paymenter moved money at the provider.

Extending each gateway with unrelated buttons would duplicate permissions, arithmetic, audit and retry rules. Calling provider APIs directly from editable transaction forms would risk repeating financial writes after a timeout. The shared journal makes the request and its outcome independently reviewable.

## Admin actions and states

Invoice Transactions and the invoice's Transactions tab expose payment amount, original gateway, provider reference, settlement origin/status, refunded amount and remaining refundable amount.

- **Refund:** choose a full or partial amount in the original currency, enter a reason, preview affected product/tax/untaxed-fee amounts, then confirm. A provider-supported operation submits against the verified original transaction. Pending and uncertain responses remain visibly pending or needing reconciliation; they are never displayed as completed refunds.
- **Record completed external refund:** for a refund already made outside Paymenter, require an external reference, amount, effective date and reason. Label it as manually recorded and preserve the actor. This is the universal accounting path when a gateway has no authorized refund API.
- **Record manual settlement:** record an externally received payment with amount, original currency, selected gateway/method, effective date, reference and reason. Show **Manually settled**. Only a confirmed/recorded received amount contributes to the invoice's paid balance.
- **Mark unsettled:** record the withdrawal or correction of a manual settlement with an audit entry rather than deleting the original transaction. Show **Unsettled** and exclude that amount from effective paid funds. Changing this state does not issue a refund or cancel a provider payment. A provider-confirmed received payment cannot be presented as unreceived merely by changing a label; an actual provider reversal requires its separate verified refund/void operation.
- **Capture authorization:** a separate provider action, offered only for a verified, unexpired authorization and a gateway supporting capture. An admin-requested capture shows **Manually settled — gateway verified**, with the admin actor and authenticated provider result. A manually recorded receipt shows **Manually settled — admin recorded**. Automatic capture remains the normal flow and keeps its automatic origin.

Pending authorizations and uncertain provider operations must be reconciled before an admin can count an overlapping manual payment. The system must not silently replace an outstanding provider claim or change its frozen tax/fee allocation.

Full and partial refunds link to the original payment and never exceed its captured/received amount less completed and reserved refunds. Financial history remains immutable. An administrator explicitly chooses whether to include any refundable customer gateway fee; the refund preview preserves that fee's zero tax. Processor deductions remain separate and are not promised back to the customer.

## Accounting and lifecycle behavior

Keep original transaction status and provider identity intact; add explicit settlement provenance/state and append-only operation/refund records instead of overloading `Succeeded` or deleting payments. Imported history is not backfilled as manually settled merely because it lacks native provider evidence.

Refunded funds are recorded separately from received funds. A completed refund does not automatically reopen the invoice for collection or terminate a service. Refund and outstanding-balance summaries must distinguish a deliberate refund from an unpaid purchase. No new customer charge is initiated because an admin issued a refund.

Reversing a manual settlement can expose an outstanding balance, but repeating settlement on the same invoice must not renew a service, apply an upgrade or credit a wallet twice. Add durable per-invoice paid-processing evidence for new operations. Existing paid invoices are treated as previously processed; do not replay historical lifecycle actions. Do not automatically undo service provisioning or prior wallet use.

Existing source migration holds apply to manual and provider operations, including invoice/service ownership. Preparation stays disabled, creates no live refunds/captures/payments, and does not change source callbacks, schedules or mail delivery.

## Authorization, claims and reconciliation

Require dedicated admin permissions for refunds, manual settlements, settlement reversal and provider capture. Check them in the backend action/service, not just button visibility. Customer ownership permission is insufficient. Record actor, request UUID, reason, original transaction, exact amount/currency and effective timestamp.

Acquire the existing gateway and connected-invoice locks in their established order, then reserve the operation and refundable amount durably before any external write. Freeze merchant identity, environment, original provider ID, currency and request details. Provider calls run outside an enclosing database transaction. Repeated submits reuse the original operation, provider-supported idempotency key and original amount. Unknown outcomes block another operation until authenticated reconciliation establishes what happened.

Verify provider business/merchant ownership, original transaction identity, currency, captured amount and operation reference before reflecting provider outcomes in native accounting. Callbacks/reconciliation may update the existing operation but cannot create another refund or restore a manually reversed settlement from a stale callback.

Protect managed financial entries from the existing delete and bulk-delete actions. The audit trail includes original payments, refunds and settlement corrections; no raw provider secrets or sensitive card details are stored in logs or browser URLs.

## Provider feasibility checked against current official documentation

| Gateway | Provider operation | Preparation/acceptance requirement |
| --- | --- | --- |
| Klarna | Order Management refund; capture of an authorized order | Bind original order/merchant and USD allocation. Use documented idempotency and authenticated order/refund readback. Consumer FX merchant acceptance remains separate. |
| Authorize.Net | Refund a settled transaction; void an unsettled capture; prior-authorization capture | Read back original transaction and payment type. Clearly distinguish a void from a refund. Never request/store a full card number; use provider-required original payment details only. |
| Stripe | Refund a captured charge/payment intent; capture supported authorization | Verify exact connected merchant/environment, original payment and currency. Record asynchronous refund status and use idempotency. |
| PayPal API | Refund capture; capture supported authorization | Bind original capture, merchant and currency; use API credentials and operation identity. |
| Mollie | Payment refund; capture for eligible authorization/payment method | Bind original payment, currency and supported method; pending refund is not completion. |
| WebMoney | X14 refund against original incoming transaction | X14 requires WinPro signing or WebPro certificate authorization, beyond the current merchant-notification secret. Official documentation excludes merchant test-mode transactions; real acceptance is a separate explicit operation. Unknown partial outcomes must not be retried blindly. |
| Wave | Public GraphQL supports manual-payment accounting and payment readback | The documented mutation list does not expose a processor refund/capture operation. Treat actual money movement as unsupported unless Wave provides an authorized API; offer an explicitly recorded external refund/settlement. |
| PayPal IPN | Current adapter accepts email/IPN notifications | Email/IPN verification does not authorize refund API writes. Require separate authorized API configuration and verified original merchant identity, or use the explicitly recorded external path. |
| Other plugins | Shared manual accounting; capability-declared provider operations | Never assume a generic plugin supports refund or capture merely because it accepts payment. |

## Implementation and verification scope

Changes cover the gateway base contract and adapters, additive operation/refund/settlement schema, native transaction/admin policies and tables, invoice accounting summaries, callback/write guards and once-only paid processing. Use existing native admin components; review any new custom UI with Superdesign.

Tests must prove admin authorization, migration holds, exact money/currency validation, full/partial refund limits, simultaneous refund requests, provider identity checks, asynchronous/unknown outcomes, repeated requests/callbacks, manual reversal/re-settlement, unchanged tax/untaxed fees, historical compatibility and once-only service/wallet processing. Offline synthetic fixtures come first; isolated provider sandbox acceptance remains distinct from those tests.

Publish generic code, tests and documentation in the Paymenter fork. Back up and verify the held candidate before additive installation, comparing financial history, credentials, migration holds, jobs and production hashes. No production billing handover or real refund/capture is part of preparation.

## Official references

- [Klarna Order Management](https://docs.klarna.com/acquirer/klarna/api/ordermanagement/)
- [Authorize.Net payment transactions](https://developer.authorize.net/api/reference/features/payment-transactions.html)
- [Stripe refunds](https://docs.stripe.com/api/refunds/create) and [authorization capture](https://docs.stripe.com/api/payment_intents/capture)
- [PayPal Payments v2](https://developer.paypal.com/api/payments/v2)
- [Mollie payment refunds](https://docs.mollie.com/reference/create-refund) and [capture](https://docs.mollie.com/reference/create-capture)
- [WebMoney X14](https://en.webmoney.wiki/projects/webmoney/wiki/Interface_X14)
- [Wave public API schema](https://developer.waveapps.com/hc/en-us/articles/360019968212-API-Reference)
