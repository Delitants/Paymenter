<?php

namespace Tests\Fixtures\Accounts;

use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\Operations\OperationResult;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Support\Facades\DB;

class RaceRefundAdapter implements Adapter
{
    public string $mode = 'pending';

    public ?string $crashBoundary = null;

    public bool $interruptQueued = false;

    public string $merchant = 'synthetic-merchant';

    public ?string $omitEvidence = null;

    public string $credentialVersion = 'synthetic-version-1';

    public bool $callbackDuringWrite = false;

    public bool $callbackBlocked = false;

    public int $writes = 0;

    public int $reads = 0;

    public ?\Closure $afterRead = null;

    public function capabilities(): array
    {
        return ['refund' => true, 'capture' => true, 'reconcile' => true];
    }

    public function fingerprint(): string
    {
        if ($this->crashBoundary === 'queued' && ($queued = PaymentOperation::where('state', 'queued')->first())) {
            echo 'boundary:queued:' . $queued->id . "\n";
            flush();
            while (true) {
                usleep(10000);
            }
        }

        if ($this->interruptQueued && PaymentOperation::where('state', 'queued')->exists()) {
            throw new \RuntimeException('Synthetic interruption before execution');
        }

        return hash('sha256', $this->merchant . ':' . $this->credentialVersion);
    }

    public function prepare(Invoice $invoice, ?InvoiceTransaction $transaction, string $kind, string $providerReference, string $amount, string $currency): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Read-only preparation ran under locks');
        }
        $attempt = GatewayPaymentAttempt::where('invoice_id', $invoice->id)->where('state', 'open')->first();

        return ['authenticated' => true, 'merchant' => $this->merchant, 'environment' => 'synthetic', 'original_reference' => $providerReference,
            'provider_object_type' => $transaction ? 'payment' : 'authorization', 'original_amount' => $transaction?->amount ?? $amount,
            'amount' => $amount, 'currency' => $currency, 'invoice_id' => $invoice->id, 'gateway_id' => $transaction?->gateway_id ?? $attempt?->gateway_id,
            'transaction_id' => $transaction?->id, 'already_refunded' => $transaction?->refunded_amount ?? '0.00', 'attempt_id' => $attempt?->id, 'attempt_reference' => $attempt?->reference,
            'merchant_fingerprint' => $attempt?->merchant_fingerprint];
    }

    public function execute(PaymentOperation $operation): OperationResult
    {
        if (DB::transactionLevel() !== 0 || PaymentOperation::findOrFail($operation->id)->state !== 'processing') {
            throw new \LogicException('Write lacks durable claim');
        }
        $this->writes++;
        if ($this->callbackDuringWrite) {
            $context = $operation->payload['provider_context'];
            try {
                (new PaymentAttempts)->settle($operation->gateway, $context['attempt_reference'], $context['merchant_fingerprint'], $operation->amount, $operation->currency_code, 'synthetic-provider-operation');
            } catch (\RuntimeException $e) {
                if (!str_contains($e->getMessage(), 'capture')) {
                    throw $e;
                }
                $this->callbackBlocked = true;
            }
        }
        if ($this->mode === 'throw') {
            throw new \RuntimeException('Synthetic lost provider response');
        }

        return new OperationResult('pending', 'synthetic-provider-operation');
    }

    public function reconcile(PaymentOperation $operation): OperationResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Readback ran under locks');
        }
        $this->reads++;
        if ($this->afterRead) {
            ($this->afterRead)();
        }
        $evidence = $operation->payload['provider_context'] + ['request_key' => $operation->request_key];
        $evidence['authenticated'] = true;
        $evidence['merchant'] = $this->merchant;
        $evidence['provider_reference'] = 'synthetic-provider-operation';
        $evidence['raw_provider_body'] = 'synthetic-private-provider-body';
        if ($this->omitEvidence !== null) {
            unset($evidence[$this->omitEvidence]);
        }
        if ($this->mode === 'mismatch') {
            $evidence['currency'] = 'EUR';
        }
        if ($this->mode === 'failed') {
            $evidence['failure_proven'] = true;
        }

        return new OperationResult(in_array($this->mode, ['succeeded', 'mismatch'], true) ? 'succeeded' : ($this->mode === 'failed' ? 'failed' : 'pending'), 'synthetic-provider-operation', $evidence, 'synthetic_readback');
    }
}
