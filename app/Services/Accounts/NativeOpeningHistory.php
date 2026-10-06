<?php

namespace App\Services\Accounts;

use App\Models\AccountDowngradeReceipt;
use App\Models\AccountWallet;
use App\Models\InvoicePaidProcessing;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Immutable original receipts already present in the accepted opening. */
final class NativeOpeningHistory
{
    public static function forOpening(int $ownerId, string $currency): array
    {
        $query = AccountWallet::where('user_id', $ownerId)->where('currency_code', $currency);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $wallet = $query->first();
        if ($wallet) {
            return self::stored($wallet);
        }
        $table = (new InvoicePaidProcessing)->getTable();

        return ['deposit' => DB::table($table . ' as receipts')->join('invoices', 'invoices.id', '=', 'receipts.invoice_id')
            ->where('invoices.user_id', $ownerId)->where('invoices.currency_code', $currency)->where('receipts.origin', 'native')
            ->whereNotNull('receipts.processed_at')->orderBy('receipts.invoice_id')->pluck('receipts.invoice_id')->map(fn ($id) => (int) $id)->all(),
            'downgrade' => AccountDowngradeReceipt::where('user_id', $ownerId)->where('currency_code', $currency)
                ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all()];
    }

    private static function stored(AccountWallet $wallet): array
    {
        $history = $wallet->opening_evidence['native_income_exclusions'] ?? null;
        if (!is_array($history) || array_keys($history) !== ['deposit', 'downgrade']) {
            throw new RuntimeException('Original native opening history is missing.');
        }
        foreach ($history as $ids) {
            if (!is_array($ids) || !array_is_list($ids)) {
                throw new RuntimeException('Original native opening history is invalid.');
            }
            $last = 0;
            foreach ($ids as $id) {
                if (!is_int($id) || $id <= $last) {
                    throw new RuntimeException('Original native opening receipt identities are invalid.');
                }
                $last = $id;
            }
        }

        return $history;
    }

    public static function assertEligible(AccountWallet $wallet, string $kind, array $proof): void
    {
        $history = self::stored($wallet);
        $id = $kind === 'deposit' ? $proof['invoice_id'] : ($proof['receipt_id'] ?? null);
        if (!in_array($kind, ['deposit', 'downgrade'], true) || !is_int($id) || in_array($id, $history[$kind], true) ||
            $proof['processed_at'] < $wallet->created_at->format('Y-m-d H:i:s')) {
            throw new RuntimeException('Income predating the original opening cannot be credited again.');
        }
    }
}
