<?php

namespace App\Services\Gateways\Operations;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\Billing\InvoicePricing;
use App\Services\Gateways\InvoicePaymentDependencies;
use App\Services\Gateways\PaymentAttempts;
use App\Services\Gateways\PaymentWriteGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ProviderOperations
{
    public function refund(User $actor, InvoiceTransaction $transaction, string $amount, bool $includeFee, string $reason, string $requestKey): PaymentOperation
    {
        $this->outsideTransaction();
        $policy = new OperationPolicy;
        $amount = $policy->amount($amount);
        [$requestKey, $reason, $effectiveAt] = $policy->request($requestKey, $reason, now()->utc()->format('Y-m-d\TH:i:s\Z'));
        $journal = new OperationJournal;
        $fingerprint = $journal->fingerprint(['kind' => 'provider_refund', 'actor' => $actor->id, 'transaction' => $transaction->id,
            'amount' => $amount, 'include_fee' => $includeFee, 'reason' => $reason]);
        $identity = $this->transactionIdentity($transaction);
        $invoice = $transaction->invoice;
        $gateway = $transaction->gateway;
        if (!$gateway) {
            throw new RuntimeException('Original native payment gateway is missing.');
        }
        $policy->authorize($actor, 'refund', $invoice, $gateway);
        if ($existing = $this->replay($actor, $invoice, $gateway, 'refund', $requestKey, $fingerprint)) {
            return $this->resume($actor, $existing);
        }
        if ($transaction->settlement_origin === 'manual_record' || $transaction->is_credit_transaction || !$transaction->transaction_id) {
            throw new RuntimeException('A manually recorded receipt is not original provider-payment evidence.');
        }
        (new RefundAllocation)->quote($transaction, $amount, $includeFee);
        $adapter = app(GatewayOperations::class)->for($gateway);
        $this->capability($adapter, 'refund');
        $providerReference = $this->originalReference($transaction);
        $merchantFingerprint = $adapter->fingerprint();
        $context = $adapter->prepare($invoice, $transaction, 'provider_refund', $providerReference, $amount, $invoice->currency_code);
        $this->assertContext($context, $invoice, $gateway, $amount, $providerReference, $transaction);
        $operation = DB::transaction(function () use ($actor, $transaction, $invoice, $gateway, $policy, $journal, $amount, $includeFee, $reason, $effectiveAt, $requestKey, $fingerprint, $identity, $context, $merchantFingerprint) {
            [$invoice, $gateway] = $this->lock($invoice->id, $gateway->id);
            $policy->authorize($actor, 'refund', $invoice, $gateway);
            $transaction = InvoiceTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($this->transactionIdentity($transaction) !== $identity || $transaction->invoice_id !== $invoice->id || $transaction->gateway_id !== $gateway->id) {
                throw new RuntimeException('Original native refund payment identity changed.');
            }
            if ($existing = $journal->existing($requestKey, $fingerprint)) {
                return $existing;
            }
            $this->sameGateway($gateway, $merchantFingerprint);
            $this->assertContext($context, $invoice, $gateway, $amount, $this->originalReference($transaction), $transaction);
            if (PaymentOperation::where('original_transaction_id', $transaction->id)->whereIn('state', ['processing', 'uncertain'])->lockForUpdate()->exists()) {
                throw new RuntimeException('An uncertain refund requires reconciliation before another refundable claim.');
            }
            $quote = (new RefundAllocation)->quote($transaction, $amount, $includeFee);
            if (!BigDecimal::of($context['already_refunded'])->isEqualTo($transaction->refunded_amount)) {
                throw new RuntimeException('Unexplained external provider refunds require reconciliation.');
            }

            return $journal->claim($actor, $invoice, $gateway, 'provider_refund', $amount, $requestKey, $reason, $effectiveAt, $fingerprint,
                ['include_fee' => $includeFee, 'allocation' => $quote['allocation'], 'original_allocation' => $quote['original_allocation'],
                    'original_identity' => $identity, 'invoice_identity' => $invoice->only(['user_id', 'currency_code']),
                    'gateway_extension' => $gateway->extension, 'gateway_fingerprint' => $merchantFingerprint, 'provider_context' => $context], $transaction);
        });

        return $this->execute($actor, $operation);
    }

    /** Authenticated discovery only; the submit path rechecks the entire durable claim. */
    public function capturePreview(User $actor, Invoice $invoice): array
    {
        $this->outsideTransaction();
        $attempts = GatewayPaymentAttempt::where('invoice_id', $invoice->id)->whereIn('state', ['open', 'initializing', 'paid'])->get();
        if ($attempts->count() !== 1 || $attempts->first()->state !== 'open') {
            throw new RuntimeException('Capture requires exactly one current open native authorization.');
        }
        $attempt = $attempts->first();
        $gateway = Gateway::findOrFail($attempt->gateway_id);
        $policy = new OperationPolicy;
        $policy->authorize($actor, 'capture', $invoice, $gateway);
        $reference = $attempt->provider_reference;
        if (!is_string($reference) || $reference === '' || strlen($reference) > 190) {
            throw new RuntimeException('The native authorization has no original provider reference.');
        }
        $attempt = $this->captureAttempt($invoice, $gateway, $reference);
        $amount = $policy->amount($attempt->amount);
        $adapter = app(GatewayOperations::class)->for($gateway);
        $this->capability($adapter, 'capture');
        $fingerprint = $adapter->fingerprint();
        $context = $adapter->prepare($invoice, null, 'provider_capture', $reference, $amount, $invoice->currency_code);
        $this->assertContext($context, $invoice, $gateway, $amount, $reference);
        $this->assertAttemptContext($context, $attempt);

        return DB::transaction(function () use ($actor, $invoice, $gateway, $reference, $context, $fingerprint, $amount) {
            [$current, $gateway] = $this->lock($invoice->id, $gateway->id, true);
            (new OperationPolicy)->authorize($actor, 'capture', $current, $gateway);
            if ($current->only(['user_id', 'currency_code']) !== $invoice->only(['user_id', 'currency_code'])) {
                throw new RuntimeException('Capture invoice identity changed.');
            }
            $this->sameGateway($gateway, $fingerprint);
            $attempt = $this->captureAttempt($current, $gateway, $reference);
            $this->assertContext($context, $current, $gateway, $amount, $reference);
            $this->assertAttemptContext($context, $attempt);
            if (PaymentOperation::where('invoice_id', $current->id)->whereIn('state', ['queued', 'processing', 'pending', 'uncertain'])->lockForUpdate()->exists()) {
                throw new RuntimeException('An outstanding operation requires reconciliation before capture.');
            }

            return ['gateway_id' => $gateway->id, 'reference' => $reference, 'amount' => $amount, 'currency' => $current->currency_code];
        });
    }

    public function capture(User $actor, Invoice $invoice, Gateway $gateway, string $providerReference, string $reason, string $requestKey): PaymentOperation
    {
        $this->outsideTransaction();
        $policy = new OperationPolicy;
        [$requestKey, $reason, $effectiveAt] = $policy->request($requestKey, $reason, now()->utc()->format('Y-m-d\TH:i:s\Z'));
        if (trim($providerReference) !== $providerReference || $providerReference === '' || strlen($providerReference) > 190) {
            throw new RuntimeException('An original authorization reference is required.');
        }
        $journal = new OperationJournal;
        $fingerprint = $journal->fingerprint(['kind' => 'provider_capture', 'actor' => $actor->id, 'invoice' => $invoice->id,
            'gateway' => $gateway->id, 'reference' => $providerReference, 'reason' => $reason]);
        $identity = $invoice->only(['user_id', 'currency_code']);
        $policy->authorize($actor, 'capture', $invoice, $gateway);
        if ($existing = $this->replay($actor, $invoice, $gateway, 'capture', $requestKey, $fingerprint)) {
            return $this->resume($actor, $existing);
        }
        $attempt = $this->captureAttempt($invoice, $gateway, $providerReference);
        $amount = $policy->amount($attempt->amount);
        $adapter = app(GatewayOperations::class)->for($gateway);
        $this->capability($adapter, 'capture');
        $merchantFingerprint = $adapter->fingerprint();
        $context = $adapter->prepare($invoice, null, 'provider_capture', $providerReference, $amount, $invoice->currency_code);
        $this->assertContext($context, $invoice, $gateway, $amount, $providerReference);
        $this->assertAttemptContext($context, $attempt);
        $operation = DB::transaction(function () use ($actor, $invoice, $gateway, $policy, $journal, $providerReference, $reason, $effectiveAt, $requestKey, $fingerprint, $identity, $amount, $context, $merchantFingerprint) {
            [$invoice, $gateway] = $this->lock($invoice->id, $gateway->id, true);
            $policy->authorize($actor, 'capture', $invoice, $gateway);
            if ($invoice->only(['user_id', 'currency_code']) !== $identity) {
                throw new RuntimeException('Capture invoice identity changed.');
            }
            if ($existing = $journal->existing($requestKey, $fingerprint)) {
                return $existing;
            }
            $this->sameGateway($gateway, $merchantFingerprint);
            $attempt = $this->captureAttempt($invoice, $gateway, $providerReference);
            $this->assertContext($context, $invoice, $gateway, $amount, $providerReference);
            $this->assertAttemptContext($context, $attempt);
            if (PaymentOperation::where('invoice_id', $invoice->id)->whereIn('state', ['queued', 'processing', 'pending', 'uncertain'])->lockForUpdate()->exists()) {
                throw new RuntimeException('An outstanding provider operation requires reconciliation before capture.');
            }
            $pricing = $attempt->pricing_payload;
            $allocation = ['net' => $pricing['unpaid_net'], 'tax' => $pricing['unpaid_tax'], 'fee' => $pricing['gateway_fee']];
            if (!BigDecimal::of($allocation['net'])->plus($allocation['tax'])->plus($allocation['fee'])->isEqualTo($amount)) {
                throw new RuntimeException('Original authorization allocation is inconsistent.');
            }

            return $journal->claim($actor, $invoice, $gateway, 'provider_capture', $amount, $requestKey, $reason, $effectiveAt, $fingerprint,
                ['allocation' => $allocation, 'invoice_identity' => $identity, 'gateway_extension' => $gateway->extension,
                    'gateway_fingerprint' => $merchantFingerprint, 'provider_context' => $context]);
        });

        return $this->execute($actor, $operation);
    }

    /** Resume only a durable claim that never entered the provider write phase. */
    public function resume(User $actor, PaymentOperation $operation): PaymentOperation
    {
        $this->outsideTransaction();

        return $this->execute($actor, $operation);
    }

    private function execute(User $actor, PaymentOperation $operation): PaymentOperation
    {
        $claimed = DB::transaction(function () use ($actor, $operation) {
            [$invoice, $gateway] = $this->lock($operation->invoice_id, $operation->gateway_id);
            (new OperationPolicy)->authorize($actor, $operation->kind === 'provider_capture' ? 'capture' : 'refund', $invoice, $gateway);
            $stored = PaymentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (!in_array($stored->kind, ['provider_refund', 'provider_capture'], true) || $stored->request_fingerprint !== $operation->request_fingerprint ||
                $stored->invoice_id !== $invoice->id || $stored->gateway_id !== $gateway->id) {
                throw new RuntimeException('Only the original provider request can be resumed.');
            }
            $operation = $stored;
            $this->assertFrozenNative($operation, $invoice, $gateway);
            // A competing executor may already have written or completed. Never retry it.
            if ($operation->state !== 'queued') {
                return false;
            }
            $this->capability(app(GatewayOperations::class)->for($gateway), $operation->kind === 'provider_capture' ? 'capture' : 'refund');
            $this->sameGateway($gateway, $operation->payload['gateway_fingerprint']);
            if ($operation->kind === 'provider_capture') {
                $attempt = $this->captureAttempt($invoice, $gateway, $operation->payload['provider_context']['original_reference']);
                $this->assertAttemptContext($operation->payload['provider_context'], $attempt);
            } else {
                if (PaymentOperation::where('original_transaction_id', $operation->original_transaction_id)->where('id', '!=', $operation->id)
                    ->whereIn('state', ['processing', 'uncertain'])->lockForUpdate()->exists()) {
                    throw new RuntimeException('An uncertain refund requires reconciliation before another refundable claim.');
                }
                // Verify every reserved component including this claim exactly once.
                // Quoting this amount again would incorrectly spend its own reservation twice.
                (new RefundAllocation)->available(InvoiceTransaction::whereKey($operation->original_transaction_id)->lockForUpdate()->firstOrFail(), true);
            }

            return $operation->startExecution($actor);
        });
        if (!$claimed) {
            return $operation->fresh();
        }
        $operation = $operation->fresh();
        $permission = $operation->kind === 'provider_capture' ? 'capture' : 'refund';
        try {
            $adapter = app(GatewayOperations::class)->for($operation->gateway);
            $this->sameGateway($operation->gateway, $operation->payload['gateway_fingerprint']);
            (new OperationPolicy)->authorize($actor, $permission, $operation->invoice, $operation->gateway);
            $result = $adapter->execute($operation);
            // A write response is only a discovery hint. Separate GET/query is mandatory.
            $hint = new OperationResult($result->state === 'uncertain' ? 'uncertain' : 'pending', $result->providerReference, outcomeCode: 'awaiting_readback');
            $this->finalize($actor, $operation, $hint, $permission);
        } catch (Throwable) {
            return $this->finalize($actor, $operation->fresh(), new OperationResult('uncertain', outcomeCode: 'write_outcome_unknown'), $permission);
        }

        return $this->readback($actor, $operation->fresh(), $permission);
    }

    public function reconcile(User $actor, PaymentOperation $operation): PaymentOperation
    {
        return $this->readback($actor, $operation, 'reconcile');
    }

    private function readback(User $actor, PaymentOperation $operation, string $permission): PaymentOperation
    {
        $this->outsideTransaction();
        $operation = DB::transaction(function () use ($actor, $operation, $permission) {
            [$invoice, $gateway] = $this->lock($operation->invoice_id, $operation->gateway_id);
            (new OperationPolicy)->authorize($actor, $permission, $invoice, $gateway);
            $stored = PaymentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (!in_array($stored->kind, ['provider_refund', 'provider_capture'], true) || $stored->request_fingerprint !== $operation->request_fingerprint) {
                throw new RuntimeException('Only the original provider operation can be reconciled.');
            }
            $this->assertFrozenNative($stored, $invoice, $gateway);

            return $stored;
        });
        if (in_array($operation->state, ['succeeded', 'failed'], true)) {
            return $operation;
        }
        if ($operation->state === 'queued') {
            throw new RuntimeException('A queued provider request has not been executed.');
        }
        $readbackFingerprint = null;
        try {
            $gateway = $operation->gateway;
            $adapter = app(GatewayOperations::class)->for($gateway);
            $this->capability($adapter, 'reconcile');
            $readbackFingerprint = $adapter->fingerprint();
            (new OperationPolicy)->authorize($actor, $permission, $operation->invoice, $gateway);
            $result = $adapter->reconcile($operation);
            $result->assertVerified($operation);
        } catch (Throwable) {
            $result = new OperationResult('uncertain', $operation->provider_reference, outcomeCode: 'readback_unverified');
        }

        return $this->finalize($actor, $operation, $result, $permission, $readbackFingerprint);
    }

    private function finalize(User $actor, PaymentOperation $operation, OperationResult $result, string $permission, ?string $readbackFingerprint = null): PaymentOperation
    {
        return DB::transaction(function () use ($actor, $operation, $result, $permission, $readbackFingerprint) {
            [$invoice, $gateway] = $this->lock($operation->invoice_id, $operation->gateway_id);
            (new OperationPolicy)->authorize($actor, $permission, $invoice, $gateway);
            $operation = PaymentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (in_array($operation->state, ['succeeded', 'failed'], true)) {
                return $operation;
            }
            $this->assertFrozenNative($operation, $invoice, $gateway);
            if ($readbackFingerprint !== null) {
                try {
                    $this->sameGateway($gateway, $readbackFingerprint);
                } catch (Throwable) {
                    $result = new OperationResult('uncertain', $operation->provider_reference, outcomeCode: 'configuration_changed_readback');
                }
            }
            $transaction = null;
            if ($result->state === 'succeeded') {
                $result->assertVerified($operation);
                if ($operation->kind === 'provider_capture') {
                    $attempt = $this->captureAttempt($invoice, $gateway, $operation->payload['provider_context']['original_reference']);
                    $this->assertAttemptContext($operation->payload['provider_context'], $attempt);
                    if (GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('provider_transaction_id', $result->providerReference)->exists()) {
                        throw new RuntimeException('Provider payment is already assigned to a native attempt.');
                    }
                    $transaction = (new PaymentWriteGuard)->duringOperation($operation, fn () => $invoice->transactions()->create([
                        'gateway_id' => $gateway->id, 'amount' => $operation->amount, 'status' => InvoiceTransactionStatus::Succeeded,
                        'transaction_id' => 'gateway:' . $gateway->id . ':' . $result->providerReference, 'is_credit_transaction' => false,
                        'settlement_origin' => 'manual_capture', 'settlement_state' => 'settled',
                    ]), $result);
                    if ($invoice->fresh()->status !== 'paid') {
                        throw new RuntimeException('Verified native capture did not settle the invoice.');
                    }
                    $attempt->update(['state' => 'paid', 'provider_transaction_id' => $result->providerReference]);
                }
            }
            $operation->recordProviderResult($result, $actor, $transaction);

            return $operation->fresh();
        });
    }

    private function replay(User $actor, Invoice $invoice, Gateway $gateway, string $permission, string $requestKey, string $fingerprint): ?PaymentOperation
    {
        return DB::transaction(function () use ($actor, $invoice, $gateway, $permission, $requestKey, $fingerprint) {
            [$storedInvoice, $gateway] = $this->lock($invoice->id, $gateway->id);
            (new OperationPolicy)->authorize($actor, $permission, $storedInvoice, $gateway);
            if ($storedInvoice->only(['user_id', 'currency_code']) !== $invoice->only(['user_id', 'currency_code'])) {
                throw new RuntimeException('Payment invoice identity changed.');
            }

            return (new OperationJournal)->existing($requestKey, $fingerprint);
        });
    }

    private function lock(int $invoiceId, int $gatewayId, bool $collectable = false): array
    {
        $gateway = Gateway::whereKey($gatewayId)->lockForUpdate()->firstOrFail();
        $dependencies = new InvoicePaymentDependencies;
        $locked = $dependencies->lock([$invoiceId]);
        $invoice = $locked->firstWhere('id', $invoiceId);
        if ($collectable) {
            $dependencies->assertCollectable($invoice, $locked);
        }

        return [$invoice, $gateway];
    }

    private function assertFrozenNative(PaymentOperation $operation, Invoice $invoice, Gateway $gateway): void
    {
        if ($invoice->only(['user_id', 'currency_code']) !== $operation->payload['invoice_identity'] || $gateway->extension !== $operation->payload['gateway_extension'] ||
            $operation->currency_code !== $invoice->currency_code) {
            throw new RuntimeException('Frozen payment invoice or gateway identity changed.');
        }
        if ($operation->original_transaction_id) {
            $transaction = InvoiceTransaction::whereKey($operation->original_transaction_id)->lockForUpdate()->firstOrFail();
            if ($this->transactionIdentity($transaction) !== $operation->payload['original_identity'] ||
                (new RefundAllocation)->original($transaction) !== $operation->payload['original_allocation']) {
                throw new RuntimeException('Frozen original refund payment changed.');
            }
        }
    }

    private function transactionIdentity(InvoiceTransaction $transaction): array
    {
        $identity = $transaction->only(['invoice_id', 'gateway_id', 'transaction_id', 'amount', 'status', 'is_credit_transaction', 'settlement_origin', 'settlement_state']);
        $identity['status'] = $transaction->status->value;

        return $identity;
    }

    private function originalReference(InvoiceTransaction $transaction): string
    {
        $attempts = GatewayPaymentAttempt::where('invoice_id', $transaction->invoice_id)->where('gateway_id', $transaction->gateway_id)->where('state', 'paid')->get()
            ->filter(fn ($a) => $a->provider_transaction_id && $transaction->transaction_id === 'gateway:' . $a->gateway_id . ':' . $a->provider_transaction_id);
        if ($attempts->count() > 1) {
            throw new RuntimeException('Original provider payment identity is ambiguous.');
        }

        return $attempts->count() === 1 ? $attempts->first()->provider_transaction_id : $transaction->transaction_id;
    }

    private function captureAttempt(Invoice $invoice, Gateway $gateway, string $reference): GatewayPaymentAttempt
    {
        $attempts = GatewayPaymentAttempt::where('invoice_id', $invoice->id)->whereIn('state', ['open', 'initializing', 'paid'])->orderBy('id')->lockForUpdate()->get();
        $attempt = $attempts->first();
        if ($attempts->count() !== 1 || $attempt->gateway_id !== $gateway->id || $attempt->state !== 'open' || $attempt->provider_reference !== $reference ||
            $attempt->user_id !== $invoice->user_id || $attempt->currency_code !== $invoice->currency_code || !$attempt->pricing_payload || !$attempt->pricing_fingerprint ||
            !hash_equals($attempt->pricing_fingerprint, (new InvoicePricing)->fingerprint($invoice)) || $invoice->status !== 'pending' ||
            (new PaymentAttempts)->remaining($invoice) !== $attempt->amount) {
            throw new RuntimeException('Capture requires one matching open native authorization attempt with frozen pricing.');
        }

        return $attempt;
    }

    private function assertContext(array $context, Invoice $invoice, Gateway $gateway, string $amount, string $reference, ?InvoiceTransaction $transaction = null): void
    {
        if (($context['authenticated'] ?? false) !== true || ($context['invoice_id'] ?? null) !== $invoice->id || ($context['gateway_id'] ?? null) !== $gateway->id ||
            ($context['transaction_id'] ?? null) !== $transaction?->id || ($context['currency'] ?? null) !== $invoice->currency_code ||
            ($context['original_reference'] ?? null) !== $reference) {
            throw new RuntimeException('Authenticated provider original identity does not match the native payment.');
        }
        foreach (['merchant', 'environment', 'provider_object_type', 'original_reference', 'original_amount', 'amount', 'currency'] as $key) {
            if (!isset($context[$key]) || !is_string($context[$key]) || $context[$key] === '') {
                throw new RuntimeException('Authenticated provider original evidence is incomplete.');
            }
        }
        foreach (['transaction_id', 'attempt_id', 'attempt_reference', 'merchant_fingerprint'] as $key) {
            if (!array_key_exists($key, $context)) {
                throw new RuntimeException('Authenticated provider native linkage evidence is incomplete.');
            }
        }
        (new OperationPolicy)->amount($context['amount']);
        (new OperationPolicy)->amount($context['original_amount']);
        if (!BigDecimal::of($context['amount'])->isEqualTo($amount) ||
            !BigDecimal::of($context['original_amount'])->isEqualTo($transaction?->amount ?? $amount) ||
            !isset($context['already_refunded']) || !is_string($context['already_refunded']) || !preg_match('/^[0-9]+\.[0-9]{2}$/D', $context['already_refunded'])) {
            throw new RuntimeException('Authenticated provider original amount does not match native money.');
        }
    }

    private function assertAttemptContext(array $context, GatewayPaymentAttempt $attempt): void
    {
        if ($context['attempt_id'] !== $attempt->id || $context['attempt_reference'] !== $attempt->reference || $context['merchant_fingerprint'] !== $attempt->merchant_fingerprint ||
            !BigDecimal::of($context['amount'])->isEqualTo($attempt->amount)) {
            throw new RuntimeException('Provider authorization is not bound to its original native attempt.');
        }
    }

    private function capability(Adapter $adapter, string $kind): void
    {
        if (($adapter->capabilities()[$kind] ?? false) !== true) {
            throw new RuntimeException('This gateway has no authorized provider ' . $kind . ' operation.');
        }
    }

    private function sameGateway(Gateway $gateway, string $fingerprint): void
    {
        if (!hash_equals($fingerprint, app(GatewayOperations::class)->for($gateway)->fingerprint())) {
            throw new RuntimeException('Frozen provider merchant or environment changed.');
        }
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider operations require a durable claim outside an enclosing transaction.');
        }
    }
}
