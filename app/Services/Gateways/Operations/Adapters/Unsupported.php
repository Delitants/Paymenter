<?php

namespace App\Services\Gateways\Operations\Adapters;

use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\Operations\OperationResult;
use RuntimeException;

class Unsupported implements Adapter
{
    public function __construct(protected Gateway $gateway) {}

    public function capabilities(): array
    {
        return ['refund' => false, 'capture' => false, 'reconcile' => false, 'reason' => 'authorized_provider_operations_unavailable'];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->gateway->id, $this->gateway->extension, $this->gateway->settings->pluck('value', 'key')->sortKeys()->all()], JSON_THROW_ON_ERROR));
    }

    public function prepare(Invoice $invoice, ?InvoiceTransaction $transaction, string $kind, string $providerReference, string $amount, string $currency): array
    {
        throw new RuntimeException('Authorized provider operations are unavailable; record a completed external refund instead.');
    }

    public function execute(PaymentOperation $operation): OperationResult
    {
        return new OperationResult('uncertain', outcomeCode: 'unsupported_operation');
    }

    public function reconcile(PaymentOperation $operation): OperationResult
    {
        return new OperationResult('uncertain', outcomeCode: 'unsupported_reconciliation');
    }
}
