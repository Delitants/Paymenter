<?php

namespace App\Services\Accounts;

use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoicePricing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AccountFundingReversals
{
    private static ?array $counterScope = null;

    private static ?array $invoiceScope = null;

    public function reverse(User $actor, AccountFundingAllocation $allocation, string $amount, string $reason, string $requestKey): AccountMovement
    {
        $principal = AccountAmount::positiveCents($amount)->floorCents();
        $reason = trim($reason);
        if (!Str::isUuid($requestKey) || strlen($reason) < 3 || strlen($reason) > 2000 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new RuntimeException('An account reversal requires a request UUID and bounded reason.');
        }
        $hint = AccountFundingAllocation::findOrFail($allocation->id);

        return DB::transaction(function () use ($actor, $hint, $principal, $reason, $requestKey) {
            $write = function () use ($actor, $hint, $principal, $reason, $requestKey) {
                AccountPaymentLocks::lockFinancialUsers($actor, [$hint->user_id]);
                $allocation = AccountFundingAllocation::whereKey($hint->id)->lockForUpdate()->firstOrFail();
                (new AccountFundingPolicy)->authorizeReversal($actor, $allocation);
                $proof = InternalReversalReceipt::facts($allocation);
                InternalReversalReceipt::assertHistory($allocation);
                $context = AccountWriteContext::internalReversal($actor, $allocation, $principal, $reason, $proof);

                return (new WalletLedger)->post($context, $context->delta(), 'internal_reversal', strtolower($requestKey), $context->fingerprint(strtolower($requestKey)));
            };
            if (AccountPaymentLocks::active()) {
                AccountPaymentLocks::currentInvoices([$hint->invoice_id]);

                return $write();
            }

            return (new AccountPaymentLocks)->during([$hint->invoice_id], fn () => $write());
        }, 3);
    }

    public function canReverse(User $actor, AccountFundingAllocation $allocation): bool
    {
        try {
            return DB::transaction(function () use ($actor, $allocation) {
                $write = function () use ($actor, $allocation) {
                    AccountPaymentLocks::lockFinancialUsers($actor, [$allocation->user_id]);
                    $allocation = AccountFundingAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
                    (new AccountFundingPolicy)->authorizeReversal($actor, $allocation);
                    InternalReversalReceipt::facts($allocation);
                    InternalReversalReceipt::assertHistory($allocation);
                    $quote = (new WalletLedger)->quote($allocation->user, $allocation->currency_code);

                    return $quote && !$quote->blocked && AccountAmount::parse($allocation->amount)->compare(AccountAmount::parse($allocation->reversed_amount)) > 0;
                };

                return AccountPaymentLocks::active() ? $write() : (new AccountPaymentLocks)->during([$allocation->invoice_id], fn () => $write());
            }, 3);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function assertRequestCapacity(AccountWriteContext $context): void
    {
        $allocation = AccountFundingAllocation::whereKey($context->receiptProof['allocation_id'])->lockForUpdate()->firstOrFail();
        InternalReversalReceipt::assertHistory($allocation);
        if (AccountAmount::parse($allocation->reversed_amount)->add(AccountAmount::positiveCents($context->receiptProof['principal']))->compare(AccountAmount::parse($allocation->amount)) > 0) {
            throw new RuntimeException('Internal reversal exceeds the remaining original allocation.');
        }
    }

    public static function completeReceipt(AccountWriteContext $context, AccountMovement $movement): void
    {
        $context->assertReceiptCurrent();
        $allocation = AccountFundingAllocation::whereKey($context->receiptProof['allocation_id'])->lockForUpdate()->firstOrFail();
        $sum = InternalReversalReceipt::reversalSum($allocation);
        if (AccountAmount::parse($allocation->reversed_amount)->add(AccountAmount::parse($context->receiptProof['principal']))->exact() !== $sum->exact() ||
           $movement->reference_id !== $allocation->id || $movement->request_fingerprint !== $context->fingerprint($movement->request_key)) {
            throw new RuntimeException('Internal reversal completion lacks its exact original principal receipt.');
        }
        self::$counterScope = ['id' => $allocation->id, 'before' => $allocation->getAttributes(), 'after' => $sum->floorCents()];
        try {
            $allocation->reversed_amount = $sum->floorCents();
            $allocation->save();
        } finally {
            self::$counterScope = null;
        }
        InternalReversalReceipt::assertHistory($allocation);
        $invoice = Invoice::whereKey($allocation->invoice_id)->lockForUpdate()->firstOrFail();
        if ($invoice->status !== 'cancelled') {
            $pending = clone $invoice;
            $pending->status = 'pending';
            $status = (new InvoicePricing)->summary($pending)->payable === '0.00' ? 'paid' : 'pending';
            self::$invoiceScope = ['id' => $invoice->id, 'before' => $invoice->status, 'after' => $status, 'movement_id' => $movement->id];
            try {
                $invoice->status = $status;
                $invoice->save();
            } finally {
                self::$invoiceScope = null;
            }
        }
    }

    public static function assertCounterWrite(AccountFundingAllocation $record, string $action): void
    {
        $scope = self::$counterScope;
        $stored = $scope ? AccountFundingAllocation::whereKey($scope['id'])->lockForUpdate()->firstOrFail() : null;
        if (!$scope || $action !== 'update' || !$record->exists || $record->id !== $scope['id'] || $stored->getAttributes() !== $scope['before'] ||
            $record->reversed_amount !== $scope['after'] || array_diff(array_keys($record->getDirty()), ['reversed_amount', 'updated_at'])) {
            throw new RuntimeException('Cumulative internal reversals require the exact newly persisted journal receipt.');
        }
    }

    public static function allowsInvoiceWrite(Model $record): bool
    {
        $scope = self::$invoiceScope;

        return $record instanceof Invoice && $scope && $record->exists && $record->id === $scope['id'] &&
         $record->getRawOriginal('status') === $scope['before'] && $record->status === $scope['after'] &&
         array_diff(array_keys($record->getDirty()), ['status', 'updated_at']) === [] &&
         AccountMovement::whereKey($scope['movement_id'])->where('kind', 'internal_reversal')->exists();
    }
}
