<?php

namespace App\Services\Gateways\Operations;

use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OperationJournal
{
    public function existing(string $requestKey, string $fingerprint): ?PaymentOperation
    {
        $operation = PaymentOperation::where('request_key', $requestKey)->lockForUpdate()->first();
        if ($operation && !hash_equals($operation->request_fingerprint, $fingerprint)) {
            throw new RuntimeException('This request was already used with different payment details.');
        }

        return $operation;
    }

    public function fingerprint(array $request): string
    {
        return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
    }

    public function claim(User $actor, Invoice $invoice, Gateway $gateway, string $kind, string $amount, string $requestKey,
        string $reason, string $effectiveAt, string $fingerprint, array $payload, ?InvoiceTransaction $original = null, ?string $sourceFingerprint = null): PaymentOperation
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('An operation claim requires its locked invoice transaction.');
        }
        if ($existing = $this->existing($requestKey, $fingerprint)) {
            return $existing;
        }
        if ($sourceFingerprint && PaymentOperation::where('source_fingerprint', $sourceFingerprint)->exists()) {
            throw new RuntimeException('This external receipt is already assigned to a payment.');
        }

        return PaymentOperation::claimLockedRequest($actor, $invoice, $gateway, ['request_key' => $requestKey, 'kind' => $kind, 'state' => 'queued',
            'invoice_id' => $invoice->id, 'gateway_id' => $gateway->id, 'original_transaction_id' => $original?->id,
            'actor_id' => $actor->id, 'actor_snapshot' => ['id' => $actor->id, 'name' => $actor->name],
            'amount' => $amount, 'currency_code' => $invoice->currency_code, 'reason' => $reason, 'effective_at' => $effectiveAt,
            'request_fingerprint' => $fingerprint, 'source_fingerprint' => $sourceFingerprint, 'payload' => $payload]);
    }
}
