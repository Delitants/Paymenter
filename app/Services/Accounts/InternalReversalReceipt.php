<?php

namespace App\Services\Accounts;

use App\Enums\InvoiceTransactionStatus;
use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InternalReversalReceipt
{
    public static function facts(AccountFundingAllocation $allocation): array
    {
        $immutable = $allocation->only(['id', 'wallet_id', 'user_id', 'currency_code', 'movement_id', 'invoice_id', 'invoice_transaction_id', 'amount', 'cash_amount', 'debt_amount', 'pricing_fingerprint']);
        $move = AccountMovement::whereKey($allocation->movement_id);
        $tx = InvoiceTransaction::whereKey($allocation->invoice_transaction_id);
        if (DB::transactionLevel() > 0) {
            $move->lockForUpdate();
            $tx->lockForUpdate();
        }
        $move = $move->first();
        $tx = $tx->first();
        $amount = AccountAmount::positiveCents($allocation->amount);
        if (!$move || !$tx || $move->kind !== 'invoice_funding' || $move->wallet_id !== $allocation->wallet_id || $move->user_id !== $allocation->user_id || $move->currency_code !== $allocation->currency_code ||
            $move->reference_type !== Invoice::class || $move->reference_id !== $allocation->invoice_id || $move->delta !== AccountAmount::parse('0')->subtract($amount)->exact() ||
            ($move->payload['principal'] ?? null) !== $allocation->amount || ($move->payload['cash'] ?? null) !== $allocation->cash_amount || ($move->payload['debt'] ?? null) !== $allocation->debt_amount ||
            ($move->payload['pricing_fingerprint'] ?? null) !== $allocation->pricing_fingerprint || AccountAmount::parse($allocation->cash_amount)->add(AccountAmount::parse($allocation->debt_amount))->exact() !== $amount->exact() ||
            $tx->invoice_id !== $allocation->invoice_id || $tx->amount !== $allocation->amount || $tx->gateway_id !== null || $tx->transaction_id !== null || $tx->fee !== null || $tx->original_allocation !== null ||
            !$tx->is_credit_transaction || $tx->status !== InvoiceTransactionStatus::Succeeded || $tx->settlement_origin !== 'account_funding' || $tx->settlement_state !== 'settled') {
            throw new RuntimeException('Original internal allocation lacks its immutable funding and native payment receipts.');
        }

        return ['allocation' => $immutable, 'movement_fingerprint' => $move->request_fingerprint];
    }

    public static function reversalSum(AccountFundingAllocation $allocation): AccountAmount
    {
        $original = self::facts($allocation);
        $hash = hash('sha256', json_encode($original, JSON_THROW_ON_ERROR));
        $query = AccountMovement::where('reference_type', AccountFundingAllocation::class)->where('reference_id', $allocation->id)->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $sum = AccountAmount::parse('0');
        foreach ($query->get() as $movement) {
            if ($movement->kind !== 'internal_reversal' || $movement->wallet_id !== $allocation->wallet_id || $movement->user_id !== $allocation->user_id || $movement->currency_code !== $allocation->currency_code ||
                $movement->linked_reversal_id !== $allocation->movement_id || $movement->origin !== 'admin' || !$movement->actor_id || ($movement->payload['original_fingerprint'] ?? null) !== $hash ||
                AccountAmount::positiveCents($movement->payload['principal'] ?? null)->exact() !== $movement->delta || !is_string($movement->payload['reason'] ?? null) || strlen($movement->payload['reason']) < 3) {
                throw new RuntimeException('Internal reversal history does not match its original allocation.');
            }
            $sum = $sum->add(AccountAmount::parse($movement->delta));
        }
        if ($sum->compare(AccountAmount::parse($allocation->amount)) > 0) {
            throw new RuntimeException('Internal reversal history exceeds original principal.');
        }

        return $sum;
    }

    public static function assertHistory(AccountFundingAllocation $allocation): void
    {
        if (self::reversalSum($allocation)->floorCents() !== $allocation->reversed_amount) {
            throw new RuntimeException('Internal reversal counter differs from retained journal history.');
        }
    }

    public static function effectiveAmount(InvoiceTransaction $transaction): string
    {
        $query = AccountFundingAllocation::where('invoice_transaction_id', $transaction->id);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $allocation = $query->first();
        // The transaction-created observer reads before its bounded allocation link is completed.
        if (!$allocation) {
            if ((new AccountWriteGuard)->isNewInternalReceipt($transaction)) {
                return $transaction->amount;
            }throw new RuntimeException('Internal payment lacks its protected original allocation.');
        }
        self::assertHistory($allocation);

        return AccountAmount::parse($allocation->amount)->subtract(AccountAmount::parse($allocation->reversed_amount))->floorCents();
    }
}
