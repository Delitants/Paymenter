# Native administrative payment operations

BILLmanager remains authoritative until the separate billing handover. Preparation does not authorize real refunds, captures, customer mail, provisioning, live callbacks or production billing changes.

Administrative payment operations default to disabled for every gateway, independently of customer checkout enablement. Enable **Enable admin refunds and settlement operations** only after separately verifying merchant permissions and reconciliation. Migration holds still apply. Assign dedicated Invoice Transactions permissions for manual settlement, manual reversal, refund, capture and reconciliation; ordinary invoice editing or transaction creation permission is insufficient.

## Native invoice controls

Open an invoice's **Transactions** tab. **Record manual settlement** records money already received outside Paymenter with gateway/method, external reference, reason and effective UTC date. Paymenter supplies the invoice currency. It labels the received amount **Manually settled — admin recorded**. The former generic transaction Create control is replaced by this audited action. Imported history retains its original provenance.

The transaction row action menu offers **Record completed external refund**, **Refund at provider** where authorized API support exists, **Mark manual receipt unsettled**, and **Restore manual settlement** where applicable. Manual correction preserves the original amount and reference; it does not issue a provider refund or void. Provider-confirmed receipts require a genuine provider reversal. Managed records cannot be edited or deleted through ordinary or bulk actions.

Refunds use the original received allocation. Select **Full eligible amount** or enter the **Total partial refund amount**. **Allow refund of customer gateway fee** raises the eligible maximum; a partial amount consumes remaining product net and tax first, then an untaxed customer fee only when needed. The preview displays actual net, tax, fee, eligible maximum and remaining amount. Processor deductions are separate expenses. Missing original allocation evidence explicitly refuses a historical refund; current invoice prices cannot reconstruct it.

**Review authorization capture** appears for a supported gateway with one existing native open authorization. Opening it performs authenticated read-only eligibility discovery for that exact original reference, money, currency, merchant and frozen quote. Confirmation rechecks eligibility and claims the request durably before any provider write. No authorization is created by the form. An admin capture is labelled **Manually settled — gateway verified**; normal automatic capture retains its origin.

The **Payment operation history** tab displays safe actor, reason, reference, effective/recorded UTC time, amount, currency, result and state fields. Original provider transaction status is retained separately from the settlement label. **Reserved** amounts include queued, processing, pending and uncertain refunds. Pending/unknown responses require reconciliation and must not be treated as success or submitted as another money write. A verified provider void is labelled separately from a settled-payment refund.

A completed refund does not reopen invoice collection or replay service, upgrade or wallet actions. Manual reversal/restoration also preserves once-only paid processing. Stale payment forms refuse changed financial identity. After an operation, the invoice editor refreshes its payment status while retaining unrelated unsaved edits. A stale invoice Save, including a partial component save, refuses before changing items if the stored payment status changed; refresh the invoice before continuing. Intentional native status edits remain available when the displayed status is current.

Native processing receipts also block manual settlement and correction until their original callback succeeds or fails. Processing funds do not count as received, and terminal callbacks retain the original transaction and provenance.

A **Queued — not executed** operation can be continued through **Resume unstarted request** in payment operation history. A currently authorized refund/capture administrator confirms the original immutable request; current gateway configuration, native identity, reservation and holds are checked again. The executor is recorded separately from the original administrator. Competing resumes use one durable execution claim. Once execution starts, only readback is allowed, including after an interruption; unknown writes are never retried.

## Read-only provider reconciliation

Use the operation history action **Reconcile by provider readback**, or run:

```sh
php artisan payment-operation:reconcile 123 --actor=456
```

Replace both IDs with the existing operation and authorized administrator. The command requires the current dedicated reconciliation permission, gateway enablement, ownership and hold checks. It reads the existing provider request and can update its native journal outcome; it never retries a refund/capture write. Pending or uncertain readback returns failure so the operator keeps the reservation visible. No scheduler is enabled.

PayPal readback can recover a lost refund response only when the authenticated original order/capture graph contains exactly one child with the original request UUID and independently verified identity, amount and currency. Missing, duplicate or mismatched evidence remains uncertain without another refund write.

## Gateway limits

Wave and notification-only PayPal IPN do not acquire refund/capture authority merely by receiving payments; use explicitly recorded external accounting when an authorized operation is unavailable. WebMoney refund requires separately configured authorized WMID/purse and encrypted WebPro certificate/key/passphrase, beyond a notification secret; test-mode money movement and capture are unsupported. Keep merchant credentials in gateway configuration, never in action forms. Use only placeholder configuration in public examples.

Stripe processor expenses in a different settlement currency have an explicit unsupported metadata readback; automatic fee conversion is not promised, and the original customer allocation is preserved. Each provider's actual merchant and payment-method acceptance is separate from offline adapter fixtures or synthetic native browser acceptance.
