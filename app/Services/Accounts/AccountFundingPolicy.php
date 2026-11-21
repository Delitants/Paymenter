<?php

namespace App\Services\Accounts;

use App\Models\AccountFundingAllocation;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Billing\InvoicePricing;
use App\Services\BillmanagerMigration\MigrationHold;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AccountFundingPolicy
{
    public static function managedCredit(Credit $credit): bool
    {
        return AccountWallet::where('user_id', $credit->user_id)->where('currency_code', $credit->currency_code)->exists();
    }

    public static function mutateCredit(Credit $credit, array $attributes, callable $write): mixed
    {
        return DB::transaction(function () use ($credit, $attributes, $write) {
            $scopes = [[$credit->user_id, $credit->currency_code], [$attributes['user_id'] ?? $credit->user_id, $attributes['currency_code'] ?? $credit->currency_code]];
            $ids = array_values(array_unique(array_column($scopes, 0)));
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                User::whereKey($id)->lockForUpdate()->firstOrFail();
            }
            foreach ($scopes as [$owner,$currency]) {
                if (AccountWallet::where('user_id', $owner)->where('currency_code', $currency)->lockForUpdate()->first()) {
                    return response()->json(['message' => 'Managed account cash is a ledger projection. Use an audited account funding reversal instead of a direct credit change.'], 409);
                }
            }
            if ($credit->exists) {
                $stored = Credit::whereKey($credit->id)->lockForUpdate()->firstOrFail();
                if ($stored->user_id !== $credit->user_id || $stored->currency_code !== $credit->currency_code) {
                    return response()->json(['message' => 'Credit identity changed. Reload before editing.'], 409);
                }
            }

            return $write();
        }, 3);
    }

    public function authorizeReversal(User $actor, AccountFundingAllocation $allocation): void
    {
        (new AccountFundingGate)->assertEnabled();
        AccountPaymentLocks::assertFinancialUsers([$actor->id, $allocation->user_id]);
        $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $actor->setRelation('role', $actor->role()->lockForUpdate()->first());
        if (!$actor->hasPermission('admin.invoice_transactions.account_reverse') || (Auth::check() && Auth::id() !== $actor->id)) {
            throw new AuthorizationException('A dedicated account reversal permission is required.');
        }
        MigrationHold::assertAllowed($actor, 'reverse internal payment', true);
        if (!AccountPaymentLocks::covers([$allocation->invoice_id])) {
            throw new RuntimeException('Internal reversal requires the complete original invoice graph.');
        }
        $invoice = Invoice::whereKey($allocation->invoice_id)->lockForUpdate()->firstOrFail();
        if ($invoice->user_id !== $allocation->user_id || $invoice->currency_code !== $allocation->currency_code ||
            !in_array($invoice->status, ['pending', 'paid', 'cancelled'], true) || (new InvoicePricing)->fingerprint($invoice, false) !== $allocation->pricing_fingerprint) {
            throw new RuntimeException('Original funded invoice identity or pricing changed.');
        }
        MigrationHold::assertAllowed($invoice, 'reverse internal payment', true);
        MigrationHold::assertAllowed(User::whereKey($allocation->user_id)->lockForUpdate()->firstOrFail(), 'reverse original account payment', true);
        foreach ($invoice->items()->orderBy('id')->lockForUpdate()->get() as $item) {
            $serviceId = null;
            if ($item->reference_type === Service::class) {
                $serviceId = $item->reference_id;
            } elseif ($item->reference_type === ServiceUpgrade::class) {
                if (!AccountPaymentLocks::coversUpgrade($item->reference_id)) {
                    throw new RuntimeException('Original upgrade is outside the reversal graph.');
                }
                $upgrade = ServiceUpgrade::whereKey($item->reference_id)->lockForUpdate()->firstOrFail();
                if ($upgrade->invoice_id !== $invoice->id) {
                    throw new RuntimeException('Original upgrade invoice changed.');
                }
                $serviceId = $upgrade->service_id;
            }
            if (in_array($item->reference_type, [Service::class, ServiceUpgrade::class], true)) {
                if (!$serviceId) {
                    throw new RuntimeException('Original funded service is missing.');
                }
                AccountPaymentLocks::currentInvoices([], $serviceId);
                $service = Service::whereKey($serviceId)->lockForUpdate()->firstOrFail();
                if ($service->user_id !== $allocation->user_id || $service->currency_code !== $allocation->currency_code) {
                    throw new RuntimeException('Original funded service identity changed.');
                }
                MigrationHold::assertAllowed($service, 'reverse internal payment', true);
            }
        }
    }
}
