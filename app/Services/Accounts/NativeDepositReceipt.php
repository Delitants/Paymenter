<?php

namespace App\Services\Accounts;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\InvoicePaymentDependencies;
use App\Services\Gateways\PaymentWriteGuard;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The original received principal of a native standalone deposit. */
final class NativeDepositReceipt
{
    public static function read(int $invoiceId): array
    {
        return self::facts($invoiceId, true);
    }

    public static function readFacts(int $invoiceId): array
    {
        return self::facts($invoiceId, false);
    }

    private static function facts(int $invoiceId, bool $enforceHolds): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Deposit receipt verification requires a locked transaction.');
        }
        $gatewayIds = InvoiceTransaction::where('invoice_id', $invoiceId)->pluck('gateway_id')->filter()->unique()->sort()->values()->all();
        foreach ($gatewayIds as $id) {
            $gateway = Gateway::withTrashed()->whereKey($id)->lockForUpdate()->first();
            if (!$gateway || $gateway->trashed()) {
                throw new RuntimeException('Original deposit receipt gateway is missing.');
            }
        }
        $invoice = (new InvoicePaymentDependencies)->lock([$invoiceId])->firstWhere('id', $invoiceId);
        $processing = InvoicePaidProcessing::whereKey($invoiceId)->lockForUpdate()->first();
        if (!$invoice || $invoice->status !== 'paid' || !$processing || $processing->origin !== 'native' || !$processing->processed_at) {
            throw new RuntimeException('A native paid deposit receipt is required.');
        }
        if ($enforceHolds) {
            MigrationHold::assertAllowed($invoice, 'verify deposit receipt', true);
        }
        $items = $invoice->items()->orderBy('id')->lockForUpdate()->get();
        $transactions = $invoice->transactions()->orderBy('id')->lockForUpdate()->get();
        if (array_diff($transactions->pluck('gateway_id')->filter()->all(), $gatewayIds)) {
            throw new RuntimeException('Deposit receipt dependencies changed; restart verification.');
        }
        try {
            $zero = AccountAmount::parse('0');
            $principal = $fees = $receivedPrincipal = $receivedFees = $zero;
            $depositCount = 0;
            $lines = $slices = $originals = [];
            foreach ($items as $item) {
                if ($item->quantity !== 1 || $item->tax_amount !== '0.00') {
                    throw new RuntimeException('Deposit receipt requires untaxed single-quantity lines.');
                }
                if ($item->kind === 'credit_allocation' && $item->reference_type === Credit::class && $item->reference_id === null && $item->gateway_id === null) {
                    $depositCount++;
                    $principal = $principal->add(AccountAmount::positiveCents($item->price));
                } elseif ($item->kind === 'gateway_fee' && in_array($item->gateway_id, $gatewayIds, true) && $item->reference_type === null && $item->reference_id === null) {
                    $fees = $fees->add(AccountAmount::nonnegative($item->price));
                } else {
                    throw new RuntimeException('Deposit receipt must contain only standalone principal and legitimate gateway fees.');
                }
                $lines[] = $item->only(['id', 'kind', 'price', 'tax_amount', 'quantity', 'gateway_id', 'reference_type', 'reference_id']);
            }
            if ($depositCount !== 1 || $transactions->isEmpty()) {
                throw new RuntimeException('A single paid deposit principal receipt is required.');
            }
            foreach ($transactions as $transaction) {
                if ($enforceHolds) {
                    MigrationHold::assertAllowed($transaction, 'verify deposit receipt', true);
                }
                $allocation = $transaction->original_allocation;
                if ($transaction->status !== InvoiceTransactionStatus::Succeeded || $transaction->is_credit_transaction || !$transaction->gateway_id ||
                    !is_string($transaction->transaction_id) || trim($transaction->transaction_id) === '' || $transaction->settlement_state === 'unsettled' ||
                    !is_array($allocation) || array_keys($allocation) !== ['net', 'tax', 'fee']) {
                    throw new RuntimeException('A settled immutable original deposit allocation receipt is required.');
                }
                $net = AccountAmount::nonnegative($allocation['net']);
                $tax = AccountAmount::nonnegative($allocation['tax']);
                $fee = AccountAmount::nonnegative($allocation['fee']);
                foreach ($allocation as $value) {
                    if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,14})\.[0-9]{2}$/D', $value)) {
                        throw new RuntimeException('Original deposit receipt allocation must contain exact cents.');
                    }
                }
                if ($tax->compare($zero) !== 0 || $net->add($tax)->add($fee)->compare(AccountAmount::positiveCents($transaction->amount)) !== 0) {
                    throw new RuntimeException('Original deposit receipt does not conserve untaxed principal and fees.');
                }
                $receivedPrincipal = $receivedPrincipal->add($net);
                $receivedFees = $receivedFees->add($fee);
                $slices[] = ['transaction_id' => $transaction->id, 'principal' => $net->exact(), 'fee' => $fee->floorCents()];
                // Processor deductions can be appended later; they are never principal.
                $original = $transaction->only(['id', 'invoice_id', 'gateway_id', 'transaction_id', 'amount', 'is_credit_transaction', 'settlement_origin', 'settlement_state', 'original_allocation']);
                $original['status'] = $transaction->status->value;
                $originals[] = ['source' => self::source($invoice, $transaction)] + $original;
            }
            if ($principal->compare($receivedPrincipal) !== 0 || $fees->compare($receivedFees) !== 0) {
                throw new RuntimeException('Original deposit receipts do not exactly cover the standalone principal and fees.');
            }

            return ['invoice_id' => $invoice->id, 'owner_id' => $invoice->user_id, 'currency' => $invoice->currency_code,
                'processed_at' => $processing->processed_at->format('Y-m-d H:i:s'), 'origin' => $processing->origin,
                'pricing' => $invoice->only(['pricing_tax_rate', 'pricing_tax_name', 'pricing_tax_country', 'pricing_tax_inclusive']),
                'principal' => $principal->exact(), 'lines' => $lines, 'originals' => $originals, 'deposit_slices' => $slices];
        } catch (DomainException $exception) {
            throw new RuntimeException('Original deposit receipt money is invalid.', previous: $exception);
        }
    }

    private static function source(Invoice $invoice, InvoiceTransaction $transaction): array
    {
        if (in_array($transaction->settlement_origin, ['manual_record', 'manual_capture'], true)) {
            $operation = (new PaymentWriteGuard)->incomingOperation($transaction);
            if (!$operation || $operation->invoice_id !== $invoice->id || $operation->gateway_id !== $transaction->gateway_id ||
                $operation->currency_code !== $invoice->currency_code || $operation->amount !== $transaction->amount ||
                ($operation->payload['invoice_identity'] ?? null) !== $invoice->only(['user_id', 'currency_code']) ||
                ($operation->payload['allocation'] ?? null) !== $transaction->original_allocation ||
                ($transaction->settlement_origin === 'manual_record' && ($operation->kind !== 'manual_receipt' ||
                    $transaction->transaction_id !== 'manual:' . $operation->gateway_id . ':' . $operation->source_fingerprint)) ||
                ($transaction->settlement_origin === 'manual_capture' && $operation->kind !== 'provider_capture')) {
                throw new RuntimeException('Deposit receipt requires its original authorized incoming operation identity.');
            }

            return $operation->only(['id', 'kind', 'invoice_id', 'gateway_id', 'currency_code', 'amount', 'request_fingerprint', 'source_fingerprint']);
        }
        $attempts = GatewayPaymentAttempt::where('invoice_id', $invoice->id)->where('gateway_id', $transaction->gateway_id)
            ->whereIn('state', ['open', 'initializing', 'paid'])->orderBy('id')->lockForUpdate()->get()->filter(fn ($attempt) => $attempt->provider_transaction_id && $transaction->transaction_id === 'gateway:' . $attempt->gateway_id . ':' . $attempt->provider_transaction_id);
        if ($attempts->count() !== 1 || $attempts->first()->user_id !== $invoice->user_id || $attempts->first()->currency_code !== $invoice->currency_code ||
            $attempts->first()->amount !== $transaction->amount || !$attempts->first()->pricing_fingerprint) {
            throw new RuntimeException('Deposit receipt requires its original verified provider payment identity.');
        }

        return $attempts->first()->only(['id', 'invoice_id', 'gateway_id', 'user_id', 'currency_code', 'amount', 'merchant_fingerprint', 'pricing_fingerprint', 'provider_transaction_id']);
    }
}
