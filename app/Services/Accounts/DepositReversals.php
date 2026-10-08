<?php

namespace App\Services\Accounts;

use App\Models\AccountMovement;
use App\Models\AccountReversalReservation;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\Gateways\Operations\OperationPolicy;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Narrow reservation/evidence persistence; no arbitrary wallet balance authority. */
final class DepositReversals
{
    private static ?array $expected = null;

    private static ?string $recordClass = null;

    private static ?int $recordId = null;

    private static ?array $projection = null;

    public static function authorize(PaymentOperation $operation, bool $retry): void
    {
        if (PaymentOperation::hasDepositOutcomeScope($operation->id) || (!$retry && PaymentOperation::hasClaimScope($operation))) {
            return;
        }
        $actor = Auth::user();
        if (!$actor) {
            throw new RuntimeException('An authenticated reconciliation administrator is required.');
        }
        (new OperationPolicy)->authorize($actor, $retry ? 'reconcile' : 'refund', $operation->invoice, $operation->gateway);
    }

    public static function reserve(PaymentOperation $operation): ?AccountReversalReservation
    {
        $proof = DepositReversalReceipt::facts($operation);
        if (!$proof || !in_array($operation->kind, ['provider_refund', 'external_refund'], true)) {
            return null;
        }
        self::authorize($operation, false);
        $wallet = AccountWallet::whereKey($proof['wallet_id'])->lockForUpdate()->firstOrFail();
        if ($existing = AccountReversalReservation::where('payment_operation_id', $operation->id)->lockForUpdate()->first()) {
            self::prove($existing);

            return $existing;
        }
        DepositReversalReceipt::assertMoney($proof);
        $quote = (new WalletLedger)->quote(User::findOrFail($proof['owner_id']), $proof['currency']);
        if ($operation->state !== 'queued' || $quote->blocked) {
            throw new RuntimeException('New principal reservations require a reconciled active original wallet.');
        }
        $total = AccountAmount::parse($proof['principal']);
        foreach ($wallet->reservations()->orderBy('id')->lockForUpdate()->get() as $other) {
            $otherProof = self::prove($other);
            if ($otherProof['original_transaction_id'] === $proof['original_transaction_id'] && $other->state !== 'released') {
                $total = $total->add(AccountAmount::parse($other->principal));
            }
        }
        if ($total->compare(AccountAmount::parse($proof['slice']['principal'])) > 0) {
            throw new RuntimeException('Reserved refunds exceed credited original principal.');
        }
        $attributes = ['wallet_id' => $wallet->id, 'user_id' => $wallet->user_id, 'currency_code' => $wallet->currency_code,
            'deposit_movement_id' => $proof['deposit_movement_id'], 'payment_operation_id' => $operation->id, 'principal' => $proof['principal'],
            'state' => 'reserved', 'posted_movement_id' => null, 'posting_required' => false];
        $before = self::cash($wallet);
        $reservation = self::write(AccountReversalReservation::class, null, $attributes, fn () => AccountReversalReservation::create($attributes));
        self::project($wallet, $before, $proof);

        return $reservation;
    }

    public static function prove(AccountReversalReservation $reservation): array
    {
        $operation = PaymentOperation::whereKey($reservation->payment_operation_id)->lockForUpdate()->firstOrFail();
        $proof = DepositReversalReceipt::facts($operation, false, true);
        if (!$proof || $reservation->wallet_id !== $proof['wallet_id'] || $reservation->user_id !== $proof['owner_id'] ||
            $reservation->currency_code !== $proof['currency'] || $reservation->deposit_movement_id !== $proof['deposit_movement_id'] ||
            $reservation->principal !== $proof['principal']) {
            throw new RuntimeException('Reservation original principal provenance changed.');
        }
        if ($reservation->state === 'reserved') {
            if (!in_array($operation->state, ['queued', 'processing', 'pending', 'uncertain', 'succeeded', 'failed'], true) || $reservation->posted_movement_id !== null) {
                throw new RuntimeException('Reserved operation state is invalid.');
            }
        } elseif ($reservation->state === 'released') {
            DepositReversalReceipt::assertTerminal($operation);
            if ($operation->state !== 'failed' || $reservation->posted_movement_id !== null) {
                throw new RuntimeException('Released principal lacks a verified failure.');
            }
        } elseif ($reservation->state === 'consumed') {
            DepositReversalReceipt::assertTerminal($operation);
            if ($operation->state !== 'succeeded') {
                throw new RuntimeException('Consumed principal lacks a verified success.');
            }
            if ($proof['principal'] === '0.0000') {
                if ($reservation->posted_movement_id !== null) {
                    throw new RuntimeException('A fee-only refund cannot have principal movements.');
                }
            } else {
                $movement = AccountMovement::whereKey($reservation->posted_movement_id)->lockForUpdate()->firstOrFail();
                if ($movement->source_key !== DepositReversalReceipt::sourceKey($operation->id) || $movement->wallet_id !== $reservation->wallet_id ||
                    $movement->kind !== 'deposit_refund' || $movement->delta !== AccountAmount::parse('0')->subtract(AccountAmount::parse($proof['principal']))->exact() ||
                    $movement->reference_id !== $operation->id) {
                    throw new RuntimeException('Consumed principal lacks its exact original movement.');
                }
            }
        } else {
            throw new RuntimeException('Unknown reservation state blocks account funding.');
        }

        return $proof;
    }

    public static function reserved(AccountWallet $wallet): string
    {
        return self::reservationTotal($wallet, false)['principal'];
    }

    /** Readable history remains available; an unproven busy receipt grants no money. */
    public static function quotedReservations(AccountWallet $wallet): array
    {
        return self::reservationTotal($wallet, true);
    }

    private static function reservationTotal(AccountWallet $wallet, bool $allowBusy): array
    {
        $total = AccountAmount::parse('0');
        $busy = false;
        foreach ($wallet->reservations()->orderBy('id')->lockForUpdate()->get() as $reservation) {
            try {
                self::prove($reservation);
            } catch (AccountReceiptBusy $exception) {
                if (!$allowBusy) {
                    throw $exception;
                }
                $busy = true;
            }
            if ($reservation->state === 'reserved') {
                $total = $total->add(AccountAmount::parse($reservation->principal));
            }
        }

        return ['principal' => $total->exact(), 'busy' => $busy];
    }

    public static function cash(AccountWallet $wallet): string
    {
        $balance = AccountAmount::parse($wallet->balance);
        $reserved = AccountAmount::parse(self::reserved($wallet));

        return $balance->compare($reserved) > 0 ? $balance->subtract($reserved)->floorCents() : '0.00';
    }

    public static function consume(AccountWriteContext $context, AccountMovement $movement): void
    {
        if ($context->action !== 'deposit_refund') {
            return;
        }
        $reservation = AccountReversalReservation::where('payment_operation_id', $context->receiptProof['operation_id'])->lockForUpdate()->firstOrFail();
        self::prove($reservation);
        $stored = AccountMovement::whereKey($movement->id)->lockForUpdate()->firstOrFail();
        if ($reservation->state !== 'reserved' || $stored->source_key !== $context->sourceKey() || $stored->delta !== $context->delta() ||
            $stored->wallet_id !== $reservation->wallet_id || $stored->request_fingerprint !== $context->fingerprint($stored->request_key)) {
            throw new RuntimeException('Reservation consumption requires its actual new principal movement.');
        }
        self::write(AccountReversalReservation::class, $reservation->id, ['state' => 'consumed', 'posted_movement_id' => $stored->id], fn () => $reservation->update(['state' => 'consumed', 'posted_movement_id' => $stored->id]));
    }

    public static function releaseOrFeeOnly(PaymentOperation $operation): void
    {
        $reservation = AccountReversalReservation::where('payment_operation_id', $operation->id)->lockForUpdate()->firstOrFail();
        $proof = self::prove($reservation);
        DepositReversalReceipt::assertTerminal($operation);
        DepositReversalReceipt::assertMoney($proof);
        $wallet = AccountWallet::whereKey($proof['wallet_id'])->lockForUpdate()->firstOrFail();
        $state = $operation->state === 'failed' ? 'released' : 'consumed';
        if ($operation->state !== 'failed' && $proof['principal'] !== '0.0000') {
            throw new RuntimeException('Positive principal consumption requires a journal receipt.');
        }
        if ($reservation->state === $state) {
            self::clearIssue($operation);

            return;
        }
        if ($reservation->state !== 'reserved') {
            throw new RuntimeException('Terminal principal reservation cannot change outcome.');
        }
        $before = self::cash($wallet);
        self::write(AccountReversalReservation::class, $reservation->id, ['state' => $state], fn () => $reservation->update(['state' => $state]));
        self::project($wallet, $before, $proof);
        self::clearIssue($operation);
    }

    public static function markIssue(PaymentOperation $operation): void
    {
        $reservation = AccountReversalReservation::where('payment_operation_id', $operation->id)->lockForUpdate()->firstOrFail();
        $proof = self::prove($reservation);
        DepositReversalReceipt::assertTerminal($operation);
        if ($reservation->state !== 'reserved') {
            throw new RuntimeException('Only retained original principal can require posting.');
        }
        self::write(AccountReversalReservation::class, $reservation->id, ['posting_required' => true], fn () => $reservation->update(['posting_required' => true]));
        $wallet = AccountWallet::whereKey($proof['wallet_id'])->lockForUpdate()->firstOrFail();
        self::write(AccountWallet::class, $wallet->id, ['reconciliation_required' => true], fn () => $wallet->update(['reconciliation_required' => true]));
    }

    public static function clearIssue(PaymentOperation $operation): void
    {
        $reservation = AccountReversalReservation::where('payment_operation_id', $operation->id)->lockForUpdate()->firstOrFail();
        self::prove($reservation);
        if (!$reservation->posting_required) {
            return;
        }
        if ($reservation->state === 'reserved') {
            throw new RuntimeException('Unposted principal cannot release reconciliation.');
        }
        self::write(AccountReversalReservation::class, $reservation->id, ['posting_required' => false], fn () => $reservation->update(['posting_required' => false]));
        $wallet = AccountWallet::whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();
        $required = $wallet->reservations()->where('posting_required', true)->lockForUpdate()->exists();
        self::write(AccountWallet::class, $wallet->id, ['reconciliation_required' => $required], fn () => $wallet->update(['reconciliation_required' => $required]));
    }

    public static function allowsKnownIssue(AccountWriteContext $context, AccountWallet $wallet): bool
    {
        if ($context->action !== 'deposit_refund') {
            return false;
        }
        $own = $wallet->reservations()->where('payment_operation_id', $context->receiptProof['operation_id'])->lockForUpdate()->firstOrFail();
        self::prove($own);

        return $own->posting_required;
    }

    public static function knownPostingIssues(AccountWallet $wallet): bool
    {
        $issues = $wallet->reservations()->where('posting_required', true)->orderBy('id')->lockForUpdate()->get();
        if ($issues->isEmpty()) {
            return false;
        }
        foreach ($issues as $issue) {
            self::prove($issue);
            DepositReversalReceipt::assertTerminal(PaymentOperation::findOrFail($issue->payment_operation_id));
        }

        return true;
    }

    private static function project(AccountWallet $wallet, string $before, array $proof): void
    {
        DepositReversalReceipt::assertMoney($proof);
        (new AccountFundingGate)->assertEnabled();
        if (!$wallet->active) {
            throw new RuntimeException('A reserved principal projection requires the active original wallet.');
        }
        $credit = Credit::where('user_id', $wallet->user_id)->where('currency_code', $wallet->currency_code)->lockForUpdate()->sole();
        if ($credit->amount !== $before) {
            throw new RuntimeException('Original reservation cash projection requires reconciliation.');
        }
        if (self::$projection !== null) {
            throw new RuntimeException('Nested reservation projections are refused.');
        }
        self::$projection = ['wallet_id' => $wallet->id, 'credit_id' => $credit->id, 'before' => $before, 'after' => self::cash($wallet)];
        try {
            $credit->update(['amount' => self::$projection['after']]);
        } finally {
            self::$projection = null;
        }
    }

    public static function authorizeProjection(Credit $credit, string $action): bool
    {
        if (self::$projection === null) {
            return false;
        }
        $wallet = AccountWallet::whereKey(self::$projection['wallet_id'])->lockForUpdate()->firstOrFail();
        $stored = Credit::whereKey(self::$projection['credit_id'])->lockForUpdate()->firstOrFail();
        if ($action !== 'update' || $credit->id !== $stored->id || $credit->user_id !== $wallet->user_id || $credit->currency_code !== $wallet->currency_code ||
            $stored->amount !== self::$projection['before'] || $credit->amount !== self::$projection['after'] || self::cash($wallet) !== $credit->amount ||
            array_diff(array_keys($credit->getDirty()), ['amount', 'updated_at'])) {
            throw new RuntimeException('Reservation cash transition differs from its original source.');
        }

        return true;
    }

    private static function write(string $class, ?int $id, array $attributes, Closure $callback): mixed
    {
        if (DB::transactionLevel() === 0 || self::$expected !== null) {
            throw new RuntimeException('Reservation evidence requires a scoped native transaction.');
        }
        self::$recordClass = $class;
        self::$recordId = $id;
        self::$expected = $attributes;
        try {
            return $callback();
        } finally {
            self::$recordClass = null;
            self::$recordId = null;
            self::$expected = null;
        }
    }

    public static function hasRecordScope(Model $record): bool
    {
        return self::$expected !== null && self::$recordClass === $record::class && self::$recordId === $record->getKey();
    }

    public static function assertRecordWrite(Model $record, string $action): void
    {
        if (!self::hasRecordScope($record) || $action === 'delete' || $record->only(array_keys(self::$expected)) !== self::$expected ||
            ($action === 'create' ? array_diff(array_keys($record->getAttributes()), array_keys(self::$expected)) : array_diff(array_keys($record->getDirty()), [...array_keys(self::$expected), 'updated_at']))) {
            throw new RuntimeException('Reservation and posting evidence require exact native receipt transitions.');
        }
    }
}
