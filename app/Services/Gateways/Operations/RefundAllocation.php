<?php

namespace App\Services\Gateways\Operations;

use App\Enums\InvoiceTransactionStatus;
use App\Models\GatewayPaymentAttempt;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

final class RefundAllocation
{
    public const RESERVED = ['queued', 'processing', 'pending', 'uncertain', 'succeeded'];

    public function original(InvoiceTransaction $transaction): array
    {
        if ($transaction->status !== InvoiceTransactionStatus::Succeeded || $transaction->is_credit_transaction || $transaction->settlement_state === 'unsettled') {
            throw new RuntimeException('Only received external payments are refundable.');
        }
        $original = $transaction->original_allocation;
        if ($original === null) {
            $receipt = PaymentOperation::where('result_transaction_id', $transaction->id)->where('kind', 'manual_receipt')->where('state', 'succeeded')->first();
            $original = $receipt?->payload['allocation'] ?? null;
        }
        if ($original === null) {
            $attempts = GatewayPaymentAttempt::where('invoice_id', $transaction->invoice_id)->where('gateway_id', $transaction->gateway_id)
                ->where('state', 'paid')->get()->filter(fn ($a) => $a->provider_transaction_id &&
                    $transaction->transaction_id === 'gateway:' . $a->gateway_id . ':' . $a->provider_transaction_id &&
                    $a->currency_code === $transaction->invoice->currency_code && BigDecimal::of($a->amount)->isEqualTo($transaction->amount));
            if ($attempts->count() === 1 && ($pricing = $attempts->first()->pricing_payload)) {
                $original = ['net' => $pricing['unpaid_net'], 'tax' => $pricing['unpaid_tax'], 'fee' => $pricing['gateway_fee']];
            }
        }
        if ($original === null) {
            $frozen = PaymentOperation::where('original_transaction_id', $transaction->id)->whereIn('kind', ['provider_refund', 'external_refund'])->orderBy('id')->first();
            $original = $frozen?->payload['original_allocation'] ?? null;
        }
        if ($original === null) {
            throw new RuntimeException('Missing original allocation evidence; historical invoice prices cannot reconstruct this payment.');
        }
        $total = BigDecimal::of('0.00');
        foreach (['net', 'tax', 'fee'] as $key) {
            if (!isset($original[$key]) || !is_string($original[$key]) || !preg_match('/^[0-9]+\.[0-9]{2}$/D', $original[$key])) {
                throw new RuntimeException('Original payment allocation evidence is invalid.');
            }
            $total = $total->plus($original[$key]);
        }
        if (!$total->isEqualTo($transaction->amount)) {
            throw new RuntimeException('Original payment allocation does not match its received amount.');
        }

        return $original;
    }

    public function quote(InvoiceTransaction $transaction, string $amount, bool $includeFee): array
    {
        $amount = (new OperationPolicy)->amount($amount);
        $original = $this->original($transaction);
        $remaining = $this->remainingAllocation($transaction, $original);
        $product = $remaining['net']->plus($remaining['tax']);
        $available = $product->plus($includeFee ? $remaining['fee'] : '0.00');
        $requested = BigDecimal::of($amount);
        if ($requested->isGreaterThan($available)) {
            throw new RuntimeException('The refund exceeds the remaining refundable amount.');
        }
        $gross = $requested->isGreaterThan($product) ? $product : $requested;
        $tax = $gross->isEqualTo($product) ? $remaining['tax'] : $gross->multipliedBy($remaining['tax'])->dividedBy($product, 2, RoundingMode::HALF_UP);
        $allocation = ['net' => (string) $gross->minus($tax)->toScale(2), 'tax' => (string) $tax->toScale(2), 'fee' => (string) $requested->minus($gross)->toScale(2)];

        return ['allocation' => $allocation, 'original_allocation' => $original, 'remaining' => (string) $available->toScale(2),
            'remaining_after' => (string) $available->minus($requested)->toScale(2)];
    }

    public function available(InvoiceTransaction $transaction, bool $includeFee): string
    {
        $remaining = $this->remainingAllocation($transaction, $this->original($transaction));

        return (string) $remaining['net']->plus($remaining['tax'])->plus($includeFee ? $remaining['fee'] : '0.00')->toScale(2);
    }

    private function remainingAllocation(InvoiceTransaction $transaction, array $original): array
    {
        $remaining = array_map(fn ($v) => BigDecimal::of($v), $original);
        $operations = PaymentOperation::where('original_transaction_id', $transaction->id)->whereIn('kind', ['provider_refund', 'external_refund'])
            ->whereIn('state', self::RESERVED)->orderBy('id')->lockForUpdate()->get();
        foreach ($operations as $operation) {
            foreach (['net', 'tax', 'fee'] as $key) {
                $remaining[$key] = $remaining[$key]->minus($operation->payload['allocation'][$key]);
                if ($remaining[$key]->isNegative()) {
                    throw new RuntimeException('Reserved refunds exceed the original payment allocation.');
                }
            }
        }

        return $remaining;
    }
}
