<?php

namespace App\Services\Accounts;

use App\Enums\InvoiceTransactionStatus;
use App\Models\AccountFundingAllocation;
use App\Models\AccountWallet;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePaidProcessing;
use App\Models\PaymentOperation;
use App\Services\Billing\InvoicePricing;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\GatewayFeePolicy;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** Original allocations and any prior paid receipts stay frozen during collection. */
final class AccountRecollection
{
    private static ?array $fee = null;

    public static function exists(Invoice $invoice): bool
    {
        return AccountFundingAllocation::where('invoice_id', $invoice->id)->where('reversed_amount', '>', '0')->exists();
    }

    public static function assertHistory(Invoice $invoice): void
    {
        $processing = InvoicePaidProcessing::find($invoice->id);
        if (!self::exists($invoice) || ($processing && $processing->origin !== 'native') ||
            (!$processing && ($invoice->status !== 'pending' || $invoice->snapshot()->exists()))) {
            throw new RuntimeException('Recollection requires proven reversals and original native payment history.');
        }
        if (PaymentOperation::where('invoice_id', $invoice->id)->whereIn('state', ['queued', 'processing', 'pending', 'uncertain'])->exists()) {
            throw new RuntimeException('Unresolved external payment operations require reconciliation before recollection.');
        }
        $pricing = new InvoicePricing;
        $allocations = AccountFundingAllocation::where('invoice_id', $invoice->id)->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $allocations->lockForUpdate();
        }
        foreach ($allocations->get() as $allocation) {
            InternalReversalReceipt::assertHistory($allocation);
            if ($allocation->user_id !== $invoice->user_id || $allocation->currency_code !== $invoice->currency_code ||
                !hash_equals($allocation->pricing_fingerprint, $pricing->fingerprint($invoice, false))) {
                throw new RuntimeException('Original account funding identity or pricing changed.');
            }
            $wallet = AccountWallet::findOrFail($allocation->wallet_id);
            MigrationHold::assertAllowed($wallet, 'collect reversed account principal', DB::transactionLevel() > 0);
        }
        $attempts = GatewayPaymentAttempt::where('invoice_id', $invoice->id)->whereIn('state', ['paid', 'open', 'initializing'])->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $attempts->lockForUpdate();
        }
        $fees = [];
        foreach ($attempts->get() as $attempt) {
            $payload = $attempt->pricing_payload;
            if (!$attempt->pricing_fingerprint || !is_array($payload) || ($payload['schema_version'] ?? null) !== 1 ||
                $attempt->user_id !== $invoice->user_id || $attempt->currency_code !== $invoice->currency_code || !is_array($payload['lines'] ?? null)) {
                throw new RuntimeException('Original native collection pricing evidence is required.');
            }
            foreach ($payload['lines'] as $line) {
                $current = $invoice->items()->whereKey($line['id'])->first();
                if (!$current || self::line($current, $pricing) !== self::snapshot($line)) {
                    throw new RuntimeException('Original native collection line changed.');
                }
                if ($line['kind'] === 'gateway_fee') {
                    $fees[] = (int) $line['id'];
                }
            }
            if ($attempt->state === 'paid') {
                $transaction = $invoice->transactions()->where('gateway_id', $attempt->gateway_id)
                    ->where('transaction_id', 'gateway:' . $attempt->gateway_id . ':' . $attempt->provider_transaction_id)->first();
                $allocation = ['net' => $payload['unpaid_net'], 'tax' => $payload['unpaid_tax'], 'fee' => $payload['gateway_fee']];
                if (!$attempt->provider_transaction_id || !$transaction || $transaction->amount !== $attempt->amount ||
                    $transaction->status !== InvoiceTransactionStatus::Succeeded || $transaction->is_credit_transaction ||
                    $transaction->settlement_state === 'unsettled' || $transaction->original_allocation !== $allocation ||
                    !BigDecimal::of($allocation['net'])->plus($allocation['tax'])->plus($allocation['fee'])->isEqualTo($attempt->amount)) {
                    throw new RuntimeException('Original received collection allocation changed.');
                }
            }
        }
        $manual = PaymentOperation::where('invoice_id', $invoice->id)->where('kind', 'manual_receipt')->where('state', 'succeeded')->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $manual->lockForUpdate();
        }
        foreach ($manual->get() as $operation) {
            $evidence = $operation->payload['pricing_evidence'] ?? null;
            $allocation = $operation->payload['allocation'] ?? null;
            $transaction = $invoice->transactions()->whereKey($operation->result_transaction_id)->first();
            if (!is_array($evidence) || ($evidence['schema_version'] ?? null) !== 1 ||
                !is_string($evidence['principal_fingerprint'] ?? null) || !is_array($evidence['fees'] ?? null) ||
                !hash_equals($evidence['principal_fingerprint'], $pricing->fingerprint($invoice, false)) ||
                ($operation->payload['invoice_identity'] ?? null) !== $invoice->only(['user_id', 'currency_code']) ||
                $operation->currency_code !== $invoice->currency_code || !$transaction ||
                $transaction->gateway_id !== $operation->gateway_id || $transaction->amount !== $operation->amount ||
                $transaction->transaction_id !== 'manual:' . $operation->gateway_id . ':' . $operation->source_fingerprint ||
                $transaction->settlement_origin !== 'manual_record' || $transaction->settlement_state !== 'settled' ||
                $transaction->status !== InvoiceTransactionStatus::Succeeded || $transaction->is_credit_transaction ||
                !is_array($allocation) || array_keys($allocation) !== ['net', 'tax', 'fee'] ||
                $transaction->original_allocation !== $allocation ||
                !BigDecimal::of($allocation['net'])->plus($allocation['tax'])->plus($allocation['fee'])->isEqualTo($operation->amount)) {
                throw new RuntimeException('Original manual collection pricing evidence is required.');
            }
            foreach ($evidence['fees'] as $line) {
                $current = $invoice->items()->whereKey($line['id'])->first();
                if (!$current || $line['kind'] !== 'gateway_fee' || self::line($current, $pricing) !== $line) {
                    throw new RuntimeException('Original manual collection fee changed.');
                }
                $fees[] = (int) $line['id'];
            }
        }
        foreach ($invoice->items()->where('kind', 'gateway_fee')->get() as $fee) {
            if (!in_array($fee->id, $fees, true) && !self::allowsFee($fee, false)) {
                throw new RuntimeException('Unclaimed invoice fee cannot be part of recollection.');
            }
        }
    }

    private static function snapshot(array $line): array
    {
        return ['id' => $line['id'], 'kind' => $line['kind'], 'gateway_id' => $line['gateway_id'], 'price' => $line['unit_gross'],
            'tax_amount' => $line['tax_amount'], 'quantity' => $line['quantity'], 'reference_type' => $line['reference_type'], 'reference_id' => $line['reference_id']];
    }

    private static function line(InvoiceItem $item, InvoicePricing $pricing): array
    {
        return ['id' => $item->id, 'kind' => $item->kind, 'gateway_id' => $item->gateway_id, 'price' => $item->price,
            'tax_amount' => $item->tax_amount ?? $pricing->lineTax($item->invoice, $item->price, $item->quantity), 'quantity' => $item->quantity,
            'reference_type' => $item->reference_type, 'reference_id' => $item->reference_id];
    }

    public static function withNewFee(Invoice $invoice, Gateway $gateway, Closure $write): mixed
    {
        if (self::$fee !== null || !AccountPaymentLocks::covers([$invoice->id])) {
            throw new RuntimeException('Recollection fee creation requires its complete native dependency frame.');
        }
        Gate::authorize('update', $invoice);
        self::assertHistory($invoice);
        $base = (new InvoicePricing)->summary($invoice);
        $quote = (new GatewayFeePolicy)->quote($base, $gateway);
        $newFee = BigDecimal::of($quote->gatewayFee)->minus($base->retainedGatewayFee);
        self::$fee = ['invoice_id' => $invoice->id, 'kind' => 'gateway_fee', 'gateway_id' => $gateway->id,
            'description' => 'Payment gateway fee', 'price' => (string) $newFee->toScale(2), 'quantity' => 1,
            'tax_amount' => '0.00', 'reference_type' => null, 'reference_id' => null];
        try {
            return $write($quote, self::$fee);
        } finally {
            self::$fee = null;
        }
    }

    public static function allowsFee(mixed $record, bool $creatingOnly = true): bool
    {
        return $record instanceof InvoiceItem && (!$creatingOnly || !$record->exists) && self::$fee !== null &&
            DB::transactionLevel() > 0 && AccountPaymentLocks::covers([$record->invoice_id]) &&
            BigDecimal::of(self::$fee['price'])->isPositive() && $record->only(array_keys(self::$fee)) === self::$fee &&
            !array_diff(array_keys($record->getAttributes()), [...array_keys(self::$fee), 'id', 'created_at', 'updated_at']);
    }
}
