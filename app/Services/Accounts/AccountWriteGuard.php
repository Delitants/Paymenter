<?php

namespace App\Services\Accounts;

use App\Enums\InvoiceTransactionStatus;
use App\Models\AccountDowngradeReceipt;
use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\AccountPostingIssue;
use App\Models\AccountReversalReservation;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Billing\InvoicePricing;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\InvoicePaymentDependencies;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AccountWriteGuard
{
    private static ?AccountWriteContext $current = null;

    private static ?Credit $pendingCreditProjection = null;

    private static ?array $pendingCreditAttributes = null;

    private static ?AccountWallet $pendingOpening = null;

    private static ?int $openedWalletId = null;

    private static bool $projectionWritten = false;

    private static ?array $expectedMovement = null;

    private static ?AccountMovement $pendingMovement = null;

    private static ?int $postedMovementId = null;

    private static ?AccountWallet $originalWallet = null;

    private static string $projectionBefore = '0.00';

    private static ?AccountFundingAllocation $pendingAllocation = null;

    private static ?int $postedAllocationId = null;

    private static ?InvoiceTransaction $pendingInternalTransaction = null;

    private static ?int $internalTransactionId = null;

    public function duringVerifiedWrite(AccountWriteContext $context, Closure $write, ?string $requestKey = null): mixed
    {
        if (self::$current !== null) {
            throw new RuntimeException('Nested account write contexts are not allowed.');
        }
        $this->assertCurrent($context);
        self::$current = $context;
        try {
            if (in_array($context->action, AccountWriteContext::MOVEMENTS, true)) {
                $wallet = AccountWallet::where('user_id', $context->ownerId)->where('currency_code', $context->currency)->lockForUpdate()->first();
                if (!$wallet || $requestKey === null) {
                    throw new RuntimeException('A managed wallet and original deposit receipt request are required.');
                }
                $quote = (new WalletLedger)->quoteForTransition($context);
                if (!$quote || $quote->blocked) {
                    throw new RuntimeException('Deposit receipt posting requires reconciled active account history.');
                }
                if ($context->action === 'invoice_funding') {
                    $invoice = InvoiceFundingReceipt::assertCurrent($context);
                    (new InvoicePaymentDependencies)->assertCollectable($invoice, AccountPaymentLocks::currentInvoices([$invoice->id]));
                    $summary = (new InvoicePricing)->summary($invoice);
                    $remaining = AccountAmount::parse($summary->unpaidNet)->add(AccountAmount::parse($summary->unpaidTax));
                    $principal = AccountAmount::positiveCents($context->receiptProof['principal']);
                    if ($invoice->status !== 'pending' || $principal->compare($remaining) > 0 || $principal->compare(AccountAmount::parse($quote->fundingAvailable)) > 0 ||
                        ($context->receiptProof['origin'] === 'scheduler' && $principal->compare($remaining) !== 0)) {
                        throw new RuntimeException('Internal funding receipt exceeds current invoice principal or account capacity.');
                    }
                }
                self::$originalWallet = $wallet;
                self::$projectionBefore = $quote->cashAvailable;
                self::$expectedMovement = $context->movementAttributes($wallet, $requestKey);
                if (($context->sourceKey() !== null && AccountMovement::where('source_key', $context->sourceKey())->lockForUpdate()->first() !== null) ||
                    AccountMovement::where('wallet_id', $wallet->id)->where('request_key', $requestKey)->lockForUpdate()->first() !== null) {
                    throw new RuntimeException('Original movement receipt has already been posted.');
                }

                return DB::transaction(function () use ($context, $write) {
                    $result = $write();
                    if (!self::$projectionWritten) {
                        throw new RuntimeException('Incomplete account movement cannot be committed without its wallet and cash projection.');
                    }
                    $this->assertCurrent($context);
                    if ($context->action === 'invoice_funding') {
                        $this->assertCompleteAllocation($context, AccountMovement::findOrFail(self::$postedMovementId));
                    }
                    if ($context->action === 'internal_reversal') {
                        InternalReversalReceipt::assertHistory(AccountFundingAllocation::findOrFail($context->receiptProof['allocation_id']));
                    }
                    $quote = (new WalletLedger)->quoteForTransition($context);
                    if (!$quote || $quote->blocked || $quote->balance !== self::$expectedMovement['balance_after']) {
                        throw new RuntimeException('Incomplete account movement does not reconcile to its original receipt.');
                    }

                    return $result;
                });
            }

            if ($context->heldOpeningPermit) {
                return DB::transaction(function () use ($context, $write) {
                    $result = $write();
                    if (!self::$projectionWritten || self::$openedWalletId === null) {
                        throw new RuntimeException('Incomplete held opening requires its complete cash projection.');
                    }
                    $this->assertCurrent($context);

                    return $result;
                });
            }

            return $write();
        } finally {
            self::$current = null;
            self::$pendingOpening = null;
            self::$openedWalletId = null;
            self::$projectionWritten = false;
            self::$expectedMovement = null;
            self::$pendingMovement = null;
            self::$postedMovementId = null;
            self::$originalWallet = null;
            self::$projectionBefore = '0.00';
            self::$pendingAllocation = null;
            self::$postedAllocationId = null;
            self::$pendingInternalTransaction = null;
            self::$internalTransactionId = null;
        }
    }

    public function assertRecordWrite(Model $record, string $action): void
    {
        if ($record instanceof AccountReversalReservation || ($record instanceof AccountWallet && DepositReversals::hasRecordScope($record))) {
            DepositReversals::assertRecordWrite($record, $action);

            return;
        }
        if ($record instanceof AccountDowngradeReceipt) {
            NativeDowngradeReceipt::assertRecordWrite($record, $action);

            return;
        }
        if ($record instanceof AccountPostingIssue) {
            NativePostingIssue::assertRecordWrite($record, $action);

            return;
        }
        if ($action === 'delete' || ($record instanceof AccountMovement && $action !== 'create')) {
            throw new RuntimeException('Account journal identity and history are immutable.');
        }
        $context = self::$current ?? throw new RuntimeException('Account records require a verified write context.');
        if ($context->action === 'internal_reversal' && $record instanceof AccountFundingAllocation) {
            $this->assertCurrent($context);
            $this->assertPostedMovement();
            if (!self::$projectionWritten || $record->id !== $context->receiptProof['allocation_id']) {
                throw new RuntimeException('Internal reversal requires its complete projection and original allocation.');
            }
            AccountFundingReversals::assertCounterWrite($record, $action);

            return;
        }
        if ($context->action === 'invoice_funding' && $record instanceof AccountFundingAllocation) {
            $this->assertCurrent($context);
            $this->assertPostedMovement();
            $expected = $context->allocationAttributes(AccountMovement::findOrFail(self::$postedMovementId));
            if ($action === 'create' && self::$projectionWritten && self::$postedAllocationId === null && self::$pendingAllocation === null &&
                !$record->exists && $record->getKey() === null && !array_diff(array_keys($record->getAttributes()), array_keys($expected)) &&
                $record->only(array_keys($expected)) === $expected) {
                self::$pendingAllocation = $record;

                return;
            }
            if ($action === 'update' && self::$internalTransactionId !== null && $record->id === self::$postedAllocationId &&
                $record->invoice_transaction_id === self::$internalTransactionId && !array_diff(array_keys($record->getDirty()), ['invoice_transaction_id', 'updated_at']) &&
                AccountFundingAllocation::whereKey($record->id)->lockForUpdate()->value('invoice_transaction_id') === null) {
                return;
            }
            throw new RuntimeException('Internal allocation requires its exact new movement and complete native receipt.');
        }
        if (in_array($context->action, AccountWriteContext::MOVEMENTS, true)) {
            $this->assertCurrent($context);
            $expected = self::$expectedMovement ?? throw new RuntimeException('Account movement requires derived receipt attributes.');
            if ($record instanceof AccountMovement && $action === 'create' && self::$postedMovementId === null && self::$pendingMovement === null &&
                !$record->exists && $record->getKey() === null && !array_diff(array_keys($record->getAttributes()), array_keys($expected)) && $record->only(array_keys($expected)) === $expected) {
                self::$pendingMovement = $record;

                return;
            }
            if ($record instanceof AccountWallet && $action === 'update' && !self::$projectionWritten && self::$originalWallet &&
                $record->id === self::$originalWallet->id && $record->balance === $expected['balance_after'] &&
                !array_diff(array_keys($record->getDirty()), ['balance', 'updated_at'])) {
                $this->assertPostedMovement();
                $stored = AccountWallet::whereKey($record->id)->lockForUpdate()->firstOrFail();
                if ($stored->getAttributes() === self::$originalWallet->getAttributes() && $stored->balance === $expected['balance_before']) {
                    return;
                }
            }
            throw new RuntimeException('Account transition does not match its persisted movement receipt.');
        }
        if ($context->action !== 'opening' || !$record instanceof AccountWallet || $action !== 'create') {
            throw new RuntimeException('This account record transition has no verified supporting receipt.');
        }
        $this->assertCurrent($context);
        $expected = $context->openingAttributes();
        if ($record->exists || $record->getKey() !== null ||
            array_diff(array_keys($record->getAttributes()), array_keys($expected)) ||
            $record->only(array_keys($expected)) !== $expected) {
            throw new RuntimeException('Account opening does not match the approved exact identity and values.');
        }
        $this->assertOpeningHold($context, $record, 'create account opening');
        if (AccountWallet::where('user_id', $context->ownerId)->where('currency_code', $context->currency)->lockForUpdate()->first() !== null ||
            AccountWallet::where('opening_identity', $expected['opening_identity'])->lockForUpdate()->first() !== null) {
            throw new RuntimeException('Account opening identity has already been initialized.');
        }
        $cash = Credit::where('user_id', $context->ownerId)->where('currency_code', $context->currency)->orderBy('id')->lockForUpdate()->limit(2)->get();
        if ($cash->count() > 1 || ($cash->isNotEmpty() && AccountAmount::parse($cash->first()->getRawOriginal('amount'))->compare(AccountAmount::parse('0')) !== 0)) {
            throw new RuntimeException('Unmatched or duplicate native cash conflicts with the opening.');
        }
        self::$pendingOpening = $record;
    }

    public function recordCreated(Model $record): void
    {
        if ($record instanceof AccountReversalReservation || $record instanceof AccountDowngradeReceipt || $record instanceof AccountPostingIssue) {
            return;
        }

        if ($record instanceof AccountFundingAllocation && self::$pendingAllocation === $record && $record->exists && self::$current?->action === 'invoice_funding') {
            $expected = self::$current->allocationAttributes(AccountMovement::findOrFail(self::$postedMovementId));
            $stored = AccountFundingAllocation::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($stored->only(array_keys($expected)) !== $expected) {
                throw new RuntimeException('Persisted internal allocation changed from its original movement.');
            }
            self::$postedAllocationId = $record->id;
            self::$pendingAllocation = null;

            return;
        }
        if ($record instanceof AccountMovement && self::$pendingMovement === $record && $record->exists && in_array(self::$current?->action, AccountWriteContext::MOVEMENTS, true)) {
            self::$postedMovementId = $record->id;
            $this->assertPostedMovement();
            self::$pendingMovement = null;

            return;
        }
        if (!$record instanceof AccountWallet || self::$pendingOpening !== $record || !$record->exists || self::$current === null) {
            throw new RuntimeException('Account creation is missing its persisted opening proof.');
        }
        $stored = AccountWallet::whereKey($record->id)->lockForUpdate()->firstOrFail();
        $expected = self::$current->openingAttributes();
        if ($stored->only(array_keys($expected)) !== $expected) {
            throw new RuntimeException('Persisted opening does not match its approved evidence.');
        }
        self::$openedWalletId = $stored->id;
        self::$pendingOpening = null;
    }

    public function projectionWallet(AccountWriteContext $context): AccountWallet
    {
        if (self::$current !== $context || self::$projectionWritten) {
            throw new RuntimeException('Cash projection requires the newly persisted opening receipt.');
        }
        $this->assertCurrent($context);
        if (in_array($context->action, AccountWriteContext::MOVEMENTS, true)) {
            $this->assertPostedMovement();
            $original = self::$originalWallet ?? throw new RuntimeException('Cash projection has no original wallet receipt.');
            $wallet = AccountWallet::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $expected = $original->getAttributes();
            $expected['balance'] = self::$expectedMovement['balance_after'];
            unset($expected['updated_at']);
            if (array_diff_assoc($expected, $wallet->getAttributes())) {
                throw new RuntimeException('Cash projection source movement or wallet changed.');
            }
            MigrationHold::assertAllowed($wallet, 'write cash projection', true);

            return $wallet;
        }
        if (self::$openedWalletId === null) {
            throw new RuntimeException('Cash projection requires the newly persisted opening receipt.');
        }
        $wallet = AccountWallet::whereKey(self::$openedWalletId)->lockForUpdate()->firstOrFail();
        if ($wallet->only(array_keys($context->openingAttributes())) !== $context->openingAttributes() ||
            $wallet->movements()->lockForUpdate()->first() !== null || $wallet->reservations()->lockForUpdate()->first() !== null) {
            throw new RuntimeException('Cash projection source opening changed.');
        }
        $this->assertOpeningHold($context, $wallet, 'write cash projection');

        return $wallet;
    }

    public function withCreditWrite(Credit $credit, string $action, Closure $write): mixed
    {
        return DB::transaction(function () use ($credit, $action, $write) {
            $scopes = [[$credit->user_id, $credit->currency_code]];
            if ($credit->exists) {
                $scopes[] = [$credit->getRawOriginal('user_id'), $credit->getRawOriginal('currency_code')];
            }
            $ids = array_values(array_unique(array_column($scopes, 0)));
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                if (!User::whereKey($id)->lockForUpdate()->first()) {
                    throw new RuntimeException('Cash owner identity is missing.');
                }
            }
            $managed = false;
            foreach ($scopes as [$owner, $currency]) {
                $managed = AccountWallet::where('user_id', $owner)->where('currency_code', $currency)->lockForUpdate()->first() !== null || $managed;
            }
            if (self::$current?->action === 'opening') {
                $this->assertOpeningHold(self::$current, $credit, 'write cash');
            } else {
                MigrationHold::assertAllowed($credit, 'write cash', true);
            }
            if ($credit->exists) {
                $original = new Credit;
                $original->setRawAttributes($credit->getRawOriginal());
                if (self::$current?->action === 'opening') {
                    $this->assertOpeningHold(self::$current, $original, 'write original cash');
                } else {
                    MigrationHold::assertAllowed($original, 'write original cash', true);
                }
            }
            if (!$managed) {
                return $write();
            }
            if ($action === 'arithmetic') {
                throw new RuntimeException('Managed cash arithmetic requires an exact verified projection write.');
            }
            if (DepositReversals::authorizeProjection($credit, $action)) {
                return $write();
            }
            $context = self::$current ?? throw new RuntimeException('Managed cash requires verified projection authority.');
            $wallet = $this->projectionWallet($context);
            $cash = Credit::where('user_id', $wallet->user_id)->where('currency_code', $wallet->currency_code)->orderBy('id')->lockForUpdate()->limit(2)->get();
            $amount = AccountAmount::parse($wallet->balance);
            $expected = DepositReversals::cash($wallet);
            if ($action === 'delete' || $credit->user_id !== $wallet->user_id || $credit->currency_code !== $wallet->currency_code ||
                $cash->count() > 1 || ($cash->isNotEmpty() && $cash->first()->getRawOriginal('amount') !== self::$projectionBefore) ||
                $credit->amount !== $expected ||
                ($action === 'create' ? ($credit->getKey() !== null || $cash->isNotEmpty() || array_diff(array_keys($credit->getAttributes()), ['user_id', 'currency_code', 'amount'])) :
                    ($cash->isEmpty() || $cash->first()->id !== $credit->id || array_diff(array_keys($credit->getDirty()), ['amount', 'updated_at'])))) {
                throw new RuntimeException('Managed cash does not match its exact opening projection.');
            }

            if ($context->heldOpeningPermit !== null) {
                if (self::$pendingCreditProjection !== null) {
                    throw new RuntimeException('Nested cash projection events are denied.');
                }
                self::$pendingCreditProjection = $credit;
                self::$pendingCreditAttributes = $credit->getAttributes();
                try {
                    return $write();
                } finally {
                    self::$pendingCreditProjection = null;
                    self::$pendingCreditAttributes = null;
                }
            }

            return $write();
        }, self::$current?->heldOpeningPermit !== null ? 1 : 3);
    }

    public function assertCreditMigrationEvent(Credit $credit, string $event): void
    {
        $context = self::$current;
        if ($context?->action === 'opening' && $context->heldOpeningPermit !== null && self::$pendingCreditProjection === $credit &&
            self::$pendingCreditAttributes === $credit->getAttributes() && in_array($event, ['creating', 'updating'], true)) {
            $this->assertOpeningHold($context, $credit, 'write cash');

            return;
        }
        MigrationHold::assertAllowed($credit, $event, true);
    }

    public function acknowledgeProjection(AccountWriteContext $context, Credit $credit): void
    {
        $wallet = $this->projectionWallet($context);
        $amount = AccountAmount::parse($wallet->balance);
        $expected = DepositReversals::cash($wallet);
        $cash = Credit::where('user_id', $wallet->user_id)->where('currency_code', $wallet->currency_code)->lockForUpdate()->get();
        if ($cash->count() !== 1 || $cash->first()->id !== $credit->id || $cash->first()->amount !== $expected) {
            throw new RuntimeException('Completed cash projection does not match its opening receipt.');
        }
        self::$projectionWritten = true;
    }

    private function assertCurrent(AccountWriteContext $context): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Account writes require an existing locked transaction.');
        }
        if ($context->action === 'opening' && $context->heldOpeningPermit !== null && $context->evidence !== null) {
            if ($context->authority !== app(OpeningAuthority::class) || $context->ownerId !== $context->evidence->ownerId || $context->currency !== $context->evidence->currency) {
                throw new RuntimeException('Account context does not match its accepted opening authority.');
            }
            // The permit verifies the funding flag, bound authority, transaction and
            // complete current release/approval/fence; do not repeat that same check.
            $context->heldOpeningPermit->assertFor($context->evidence);
        } else {
            (new AccountFundingGate)->assertEnabled();
            if (in_array($context->action, AccountWriteContext::MOVEMENTS, true)) {
                $context->assertReceiptCurrent();
            } elseif ($context->action !== 'opening' || $context->evidence === null ||
                $context->authority !== app(OpeningAuthority::class) ||
                $context->ownerId !== $context->evidence->ownerId || $context->currency !== $context->evidence->currency) {
                throw new RuntimeException('Account context does not match its accepted opening authority.');
            } else {
                $context->openingAttributes();
                $context->authority->assertApproved($context->evidence);
            }
        }
        $owner = User::whereKey($context->ownerId)->lockForUpdate()->first();
        if (!$owner || DB::table('currencies')->where('code', $context->currency)->sharedLock()->first() === null) {
            throw new RuntimeException('Account owner or currency identity is missing.');
        }
        $this->assertOpeningHold($context, $owner, 'write account records');
    }

    public function assertOpeningHold(AccountWriteContext $context, Model $model, string $operation): void
    {
        if ($context->action === 'opening' && $context->heldOpeningPermit !== null && $context->evidence !== null) {
            $context->heldOpeningPermit->assertIdentityFor($context->evidence);
            $context->heldOpeningPermit->assertHold($model, $operation);

            return;
        }
        MigrationHold::assertAllowed($model, $operation, true);
    }

    private function assertPostedMovement(): void
    {
        if (self::$postedMovementId === null || self::$expectedMovement === null) {
            throw new RuntimeException('Cash and balance writes require a newly persisted movement receipt.');
        }
        $movement = AccountMovement::whereKey(self::$postedMovementId)->lockForUpdate()->firstOrFail();
        if ($movement->only(array_keys(self::$expectedMovement)) !== self::$expectedMovement) {
            throw new RuntimeException('Persisted movement changed from its derived original receipt.');
        }
    }

    private function internalTransactionAttributes(AccountFundingAllocation $allocation): array
    {
        return ['invoice_id' => $allocation->invoice_id, 'gateway_id' => null, 'transaction_id' => null, 'amount' => $allocation->amount,
            'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => true, 'settlement_origin' => 'account_funding', 'settlement_state' => 'settled'];
    }

    public function isNewInternalReceipt(InvoiceTransaction $record): bool
    {
        if (self::$current?->action !== 'invoice_funding' || self::$postedAllocationId === null || !self::$pendingInternalTransaction?->exists || self::$pendingInternalTransaction->id !== $record->id) {
            return false;
        }
        $allocation = AccountFundingAllocation::whereKey(self::$postedAllocationId)->lockForUpdate()->firstOrFail();

        return $allocation->invoice_transaction_id === null && $record->only(array_keys($this->internalTransactionAttributes($allocation))) === $this->internalTransactionAttributes($allocation);
    }

    public function authorizeInternalTransaction(InvoiceTransaction $record): void
    {
        $context = self::$current;
        if ($context?->action !== 'invoice_funding' || !self::$projectionWritten || self::$postedAllocationId === null ||
            self::$internalTransactionId !== null || $record->exists || $record->getKey() !== null ||
            (self::$pendingInternalTransaction !== null && self::$pendingInternalTransaction !== $record)) {
            throw new RuntimeException('Internal payment requires the exact new account allocation receipt.');
        }
        $this->assertCurrent($context);
        $this->assertPostedMovement();
        $allocation = AccountFundingAllocation::whereKey(self::$postedAllocationId)->lockForUpdate()->firstOrFail();
        $expected = $this->internalTransactionAttributes($allocation);
        if ($allocation->invoice_transaction_id !== null || array_diff(array_keys($record->getAttributes()), array_keys($expected)) || $record->only(array_keys($expected)) !== $expected) {
            throw new RuntimeException('Internal payment fields do not match their persisted allocation.');
        }
        self::$pendingInternalTransaction = $record;
    }

    public function confirmInternalTransaction(AccountWriteContext $context, InvoiceTransaction $record): void
    {
        if (self::$current !== $context || self::$pendingInternalTransaction !== $record || !$record->exists || self::$postedAllocationId === null) {
            throw new RuntimeException('Internal native receipt is missing its bounded creation proof.');
        }
        $stored = InvoiceTransaction::whereKey($record->id)->lockForUpdate()->firstOrFail();
        $expected = $this->internalTransactionAttributes(AccountFundingAllocation::findOrFail(self::$postedAllocationId));
        if ($stored->only(array_keys($expected)) !== $expected || $stored->fee !== null || $stored->original_allocation !== null) {
            throw new RuntimeException('Persisted internal native receipt does not match its allocation.');
        }
        self::$internalTransactionId = $stored->id;
    }

    public function assertCompleteAllocation(AccountWriteContext $context, AccountMovement $movement): void
    {
        $expected = $context->allocationAttributes($movement);
        unset($expected['invoice_transaction_id'],$expected['reversed_amount']);
        $allocation = AccountFundingAllocation::where('movement_id', $movement->id)->lockForUpdate()->first();
        if (!$allocation || $allocation->only(array_keys($expected)) !== $expected || $allocation->invoice_transaction_id === null) {
            throw new RuntimeException('Incomplete internal allocation cannot be committed or replayed.');
        }
        InternalReversalReceipt::assertHistory($allocation);
        $transaction = InvoiceTransaction::whereKey($allocation->invoice_transaction_id)->lockForUpdate()->first();
        $receipt = $this->internalTransactionAttributes($allocation);
        if (!$transaction || $transaction->only(array_keys($receipt)) !== $receipt || $transaction->fee !== null || $transaction->original_allocation !== null) {
            throw new RuntimeException('Incomplete internal native payment receipt cannot be committed or replayed.');
        }
    }
}
