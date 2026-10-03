<?php

namespace App\Services\Gateways\Operations;

use App\Models\Gateway;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\Gateways\InvoicePaymentDependencies;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Refunds
{
    public function recordExternal(User $actor, InvoiceTransaction $transaction, string $amount, bool $includeFee, string $reference, string $reason, string $effectiveAt, string $requestKey): PaymentOperation
    {
        $policy = new OperationPolicy;
        $amount = $policy->amount($amount);
        [$requestKey, $reason, $effectiveAt] = $policy->request($requestKey, $reason, $effectiveAt);
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 190) {
            throw new RuntimeException('An external refund reference is required.');
        }
        $journal = new OperationJournal;
        $identity = $transaction->only(['invoice_id', 'gateway_id', 'transaction_id', 'amount', 'status', 'is_credit_transaction', 'settlement_origin', 'settlement_state']);
        $fingerprint = $journal->fingerprint(['kind' => 'external_refund', 'actor' => $actor->id, 'transaction' => $transaction->id,
            'amount' => $amount, 'include_fee' => $includeFee, 'reference' => $reference, 'reason' => $reason, 'effective_at' => $effectiveAt]);

        return DB::transaction(function () use ($actor, $transaction, $amount, $includeFee, $reference, $reason, $effectiveAt, $requestKey, $policy, $journal, $identity, $fingerprint) {
            $gateway = Gateway::whereKey($transaction->gateway_id)->lockForUpdate()->firstOrFail();
            $invoice = (new InvoicePaymentDependencies)->lock([$transaction->invoice_id])->firstWhere('id', $transaction->invoice_id);
            $policy->authorize($actor, 'refund', $invoice, $gateway);
            $transaction = InvoiceTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($transaction->only(array_keys($identity)) !== $identity || $transaction->invoice_id !== $invoice->id || $transaction->gateway_id !== $gateway->id) {
                throw new RuntimeException('Original refund payment identity changed.');
            }
            if ($existing = $journal->existing($requestKey, $fingerprint)) {
                return $existing;
            }
            if (PaymentOperation::where('original_transaction_id', $transaction->id)->whereIn('state', ['processing', 'uncertain'])->lockForUpdate()->exists()) {
                throw new RuntimeException('An uncertain refund requires reconciliation before another refundable claim.');
            }
            $quote = (new RefundAllocation)->quote($transaction, $amount, $includeFee);
            $source = hash('sha256', 'external_refund:' . $gateway->id . ':' . $reference);
            $operation = $journal->claim($actor, $invoice, $gateway, 'external_refund', $amount, $requestKey, $reason, $effectiveAt, $fingerprint,
                ['reference' => $reference, 'include_fee' => $includeFee, 'allocation' => $quote['allocation'], 'original_allocation' => $quote['original_allocation'],
                    'original_identity' => $identity], $transaction, $source);
            $operation->recordManualResult('external_recorded');

            return $operation->fresh();
        });
    }

    public function submit(User $actor, InvoiceTransaction $transaction, string $amount, bool $includeFee, string $reason, string $requestKey): PaymentOperation
    {
        return (new ProviderOperations)->refund($actor, $transaction, $amount, $includeFee, $reason, $requestKey);
    }
}
