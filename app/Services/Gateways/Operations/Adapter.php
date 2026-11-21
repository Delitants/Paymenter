<?php

namespace App\Services\Gateways\Operations;

use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;

interface Adapter
{
    /** Capability support is separate from prepare's authenticated eligibility. */
    public function capabilities(): array;

    /** Pure local configuration/credential-version digest; this must perform zero HTTP. */
    public function fingerprint(): string;

    /** Authenticated read-only discovery; never create an authorization or payment. */
    public function prepare(Invoice $invoice, ?InvoiceTransaction $transaction, string $kind, string $providerReference, string $amount, string $currency): array;

    /** Recheck eligibility and perform at most one write using the frozen request. */
    public function execute(PaymentOperation $operation): OperationResult;

    /** Authenticated readback only; never substitute a write retry for discovery. */
    public function reconcile(PaymentOperation $operation): OperationResult;
}
