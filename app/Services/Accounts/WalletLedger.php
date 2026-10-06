<?php

namespace App\Services\Accounts;

use App\Models\AccountMovement;
use App\Models\AccountPostingIssue;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\User;
use App\Services\Billing\InvoicePricing;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\InvoicePaymentDependencies;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WalletLedger
{
    public function initialize(OpeningEvidence $evidence, OpeningAuthority $authority): AccountWallet
    {
        return DB::transaction(function () use ($evidence, $authority) {
            (new AccountFundingGate)->assertEnabled();
            if ($authority !== app(OpeningAuthority::class)) {
                throw new RuntimeException('Opening requires the bound accepted release authority.');
            }
            $authority->assertApproved($evidence);
            $owner = User::whereKey($evidence->ownerId)->lockForUpdate()->firstOrFail();
            MigrationHold::assertAllowed($owner, 'initialize wallet', true);
            $context = AccountWriteContext::opening($evidence, $authority);
            $expected = $context->openingAttributes();
            if (DB::table('currencies')->where('code', $evidence->currency)->sharedLock()->first() === null) {
                throw new RuntimeException('Opening currency identity is missing.');
            }
            $identity = AccountWallet::where('opening_identity', $expected['opening_identity'])->lockForUpdate()->first();
            $wallet = AccountWallet::where('user_id', $evidence->ownerId)->where('currency_code', $evidence->currency)->lockForUpdate()->first();
            if ($identity && (!$wallet || $identity->id !== $wallet->id)) {
                throw new RuntimeException('Source opening identity is already assigned to another owner.');
            }
            if ($wallet) {
                $immutable = array_diff(array_keys($expected), ['balance', 'reconciliation_required']);
                if ($wallet->only($immutable) !== array_intersect_key($expected, array_flip($immutable))) {
                    throw new RuntimeException('Opening evidence changed; a second opening is refused.');
                }
                MigrationHold::assertAllowed($wallet, 'replay opening', true);
                if ($this->quoteLocked($wallet, false)->blocked) {
                    throw new RuntimeException('Opening replay requires reconciled wallet and cash history.');
                }

                return $wallet;
            }
            $cash = Credit::where('user_id', $owner->id)->where('currency_code', $evidence->currency)->orderBy('id')->lockForUpdate()->limit(2)->get();
            if ($cash->count() > 1) {
                throw new RuntimeException('Duplicate native cash rows require reconciliation before opening.');
            }
            if ($cash->isNotEmpty() && AccountAmount::parse($cash->first()->getRawOriginal('amount'))->compare(AccountAmount::parse('0')) !== 0) {
                throw new RuntimeException('Unmatched native cash cannot be overwritten by an opening.');
            }

            return (new AccountWriteGuard)->duringVerifiedWrite($context, function () use ($context, $expected) {
                $wallet = AccountWallet::create($expected);
                $this->refreshProjection($context);

                return $wallet;
            });
        }, 3);
    }

    public function quote(User $owner, string $currency): ?WalletQuote
    {
        return DB::transaction(function () use ($owner, $currency) {
            if (!User::whereKey($owner->id)->lockForUpdate()->first()) {
                throw new RuntimeException('Wallet owner identity is missing.');
            }
            $wallet = AccountWallet::where('user_id', $owner->id)->where('currency_code', $currency)->lockForUpdate()->first();

            return $wallet ? $this->quoteLocked($wallet) : null;
        }, 3);
    }

    public function quoteIncoming(AccountWriteContext $context): WalletQuote
    {
        $context->assertReceiptFactsCurrent();
        User::whereKey($context->ownerId)->lockForUpdate()->firstOrFail();
        $wallet = AccountWallet::where('user_id', $context->ownerId)->where('currency_code', $context->currency)->lockForUpdate()->firstOrFail();

        return $this->quoteLocked($wallet, true, true, DepositReversals::knownPostingIssues($wallet));
    }

    public function quoteForTransition(AccountWriteContext $context): WalletQuote
    {
        $context->assertReceiptCurrent();
        User::whereKey($context->ownerId)->lockForUpdate()->firstOrFail();
        $wallet = AccountWallet::where('user_id', $context->ownerId)->where('currency_code', $context->currency)->lockForUpdate()->firstOrFail();

        return $this->quoteLocked($wallet, true, in_array($context->action, ['deposit', 'downgrade', 'deposit_refund'], true), ($context->outgoing() && DepositReversals::allowsKnownIssue($context, $wallet)) || (in_array($context->action, ['deposit', 'downgrade'], true) && DepositReversals::knownPostingIssues($wallet)));
    }

    private function quoteLocked(AccountWallet $wallet, bool $requireActive = true, bool $incoming = false, bool $knownIssue = false): WalletQuote
    {
        $zero = AccountAmount::parse('0');
        $balance = AccountAmount::parse($wallet->balance);
        $limit = AccountAmount::nonnegative($wallet->borrowing_limit);
        $computed = AccountAmount::parse($wallet->opening_balance);
        $reconciled = true;
        foreach ($wallet->movements()->orderBy('id')->lockForUpdate()->get() as $movement) {
            $next = $computed->add(AccountAmount::parse($movement->delta));
            if ($movement->user_id !== $wallet->user_id || $movement->currency_code !== $wallet->currency_code ||
                $computed->exact() !== $movement->balance_before || $next->exact() !== $movement->balance_after) {
                $reconciled = false;
            }
            $computed = $next;
        }
        $reconciled = $reconciled && $computed->exact() === $balance->exact();
        $reservationQuote = DepositReversals::quotedReservations($wallet);
        $reserved = AccountAmount::parse($reservationQuote['principal']);
        $positive = $balance->compare($reserved) > 0 ? $balance->subtract($reserved) : $zero;
        $cashAvailable = $positive->floorCents();
        $cash = Credit::where('user_id', $wallet->user_id)->where('currency_code', $wallet->currency_code)->orderBy('id')->lockForUpdate()->limit(2)->get();
        $reconciled = $reconciled && $cash->count() === 1 && $cash->first()->amount === $cashAvailable;
        $debt = $balance->compare($zero) < 0 ? $zero->subtract($balance) : $zero;
        $allowance = $limit->subtract($debt);
        $excess = $allowance->compare($zero) < 0 ? $zero->subtract($allowance) : $zero;
        $allowance = $allowance->compare($zero) > 0 ? $allowance : $zero;
        $funding = $balance->add($limit)->subtract($reserved);
        $funding = $funding->compare($zero) > 0 ? $funding : $zero;
        $pendingIncoming = AccountPostingIssue::where('wallet_id', $wallet->id)->whereNull('resolved_movement_id')->orderBy('id')->lockForUpdate()->get();
        $blocked = $reservationQuote['busy'] || (!$incoming && $pendingIncoming->isNotEmpty()) || !$reconciled || ($requireActive && !$wallet->active) || ($wallet->reconciliation_required && !$knownIssue) ||
            MigrationHold::isHeld($wallet, true) || DB::table('currencies')->where('code', $wallet->currency_code)->sharedLock()->first() === null;
        try {
            (new AccountFundingGate)->assertEnabled();
        } catch (RuntimeException) {
            $blocked = true;
        }

        return new WalletQuote($balance->exact(), $debt->exact(), $limit->exact(), $allowance->exact(), $excess->exact(), $reserved->exact(),
            $positive->subtract(AccountAmount::parse($cashAvailable))->exact(), $blocked ? '0.00' : $cashAvailable, $blocked ? '0.00' : $funding->floorCents(), $blocked);
    }

    public function post(AccountWriteContext $context, mixed $delta, string $kind, string $requestKey, string $fingerprint): AccountMovement
    {
        if (!in_array($context->action, AccountWriteContext::MOVEMENTS, true)) {
            throw new RuntimeException('A verified original receipt is required before posting account movements.');
        }

        (new AccountFundingGate)->assertEnabled();

        return DB::transaction(function () use ($context, $delta, $kind, $requestKey, $fingerprint) {
            if ($context->action === 'invoice_funding') {
                if (AccountPaymentLocks::active()) {
                    return $this->postLocked($context, $delta, $kind, $requestKey, $fingerprint, AccountPaymentLocks::currentInvoices([$context->invoiceId]));
                }

                return (new AccountPaymentLocks)->during([$context->invoiceId], fn ($locked) => $this->postLocked($context, $delta, $kind, $requestKey, $fingerprint, $locked));
            }

            $ids = array_filter([$context->invoiceId]);
            if (AccountPaymentLocks::active()) {
                AccountPaymentLocks::currentInvoices($ids);

                return $this->postLocked($context, $delta, $kind, $requestKey, $fingerprint);
            }
            $services = $context->action === 'downgrade' ? [$context->receiptProof['original_service']['id']] : [];
            $upgrades = $context->action === 'downgrade' ? [$context->receiptProof['upgrade_id']] : [];

            return (new AccountPaymentLocks)->during($ids, fn () => $this->postLocked($context, $delta, $kind, $requestKey, $fingerprint), [], $services, $upgrades);
        }, 3);
    }

    private function postLocked(AccountWriteContext $context, mixed $delta, string $kind, string $requestKey, string $fingerprint, ?Collection $locked = null): AccountMovement
    {
        (new AccountFundingGate)->assertEnabled();
        $context->assertReceiptCurrent();
        $owner = User::whereKey($context->ownerId)->lockForUpdate()->firstOrFail();
        MigrationHold::assertAllowed($owner, 'post account receipt', true);
        $wallet = AccountWallet::where('user_id', $context->ownerId)->where('currency_code', $context->currency)->lockForUpdate()->firstOrFail();
        $principal = AccountAmount::parse($delta)->exact();
        if ($kind !== $context->action || $principal !== $context->delta() || $fingerprint !== $context->fingerprint($requestKey)) {
            throw new RuntimeException('Requested movement does not match the original deposit receipt.');
        }
        $quote = $this->quoteForTransition($context);
        if ($quote->blocked) {
            throw new RuntimeException('Original receipt posting requires active reconciled account history.');
        }
        $replay = AccountMovement::where('wallet_id', $wallet->id)->where('request_key', $requestKey)->lockForUpdate()->first();
        $source = $context->sourceKey() === null ? null : AccountMovement::where('source_key', $context->sourceKey())->lockForUpdate()->first();
        if ($replay || $source) {
            if (!$replay || ($context->sourceKey() !== null && (!$source || $replay->id !== $source->id)) ||
                $replay->request_fingerprint !== $fingerprint || $replay->kind !== $kind || $replay->delta !== $principal) {
                throw new RuntimeException('Original deposit receipt or request has already been used with different evidence.');
            }

            if ($context->action === 'invoice_funding') {
                (new AccountWriteGuard)->assertCompleteAllocation($context, $replay);
            }

            return $replay;
        }
        if ($context->action === 'invoice_funding') {
            $invoice = InvoiceFundingReceipt::assertCurrent($context);
            (new InvoicePaymentDependencies)->assertCollectable($invoice, $locked);
            $summary = (new InvoicePricing)->summary($invoice);
            $outstanding = AccountAmount::parse($summary->unpaidNet)->add(AccountAmount::parse($summary->unpaidTax));
            $requested = AccountAmount::positiveCents($context->receiptProof['principal']);
            if ($invoice->status !== 'pending' || $requested->compare($outstanding) > 0 ||
                ($context->receiptProof['origin'] === 'scheduler' && $requested->compare($outstanding) !== 0)) {
                throw new RuntimeException('Internal funding receipt exceeds the current pending invoice principal.');
            }
            if ($requested->compare(AccountAmount::parse($quote->fundingAvailable)) > 0) {
                throw new InsufficientAccountFunding('Insufficient available account funding for this invoice.');
            }
            $invoice->items()->where('kind', 'gateway_fee')->get()->each->delete();
        }
        if ($context->action === 'internal_reversal') {
            AccountFundingReversals::assertRequestCapacity($context);
        }
        $expected = $context->movementAttributes($wallet, $requestKey);

        return (new AccountWriteGuard)->duringVerifiedWrite($context, function () use ($wallet, $context, $expected) {
            $movement = AccountMovement::create($expected);
            if ($context->outgoing()) {
                DepositReversals::consume($context, $movement);
            }
            $wallet->balance = $movement->balance_after;
            $wallet->save();
            $this->refreshProjection($context);
            if ($context->action === 'invoice_funding') {
                (new InvoiceFunding)->completeReceipt($context, $movement);
            } elseif ($context->action === 'internal_reversal') {
                AccountFundingReversals::completeReceipt($context, $movement);
            } elseif (in_array($context->action, ['deposit', 'downgrade', 'deposit_refund'], true)) {
                NativePostingIssue::resolve($context, $movement);
            }

            return $movement;
        }, $requestKey);
    }

    public function refreshProjection(AccountWriteContext $context): void
    {
        $guard = new AccountWriteGuard;
        $wallet = $guard->projectionWallet($context);
        $balance = AccountAmount::parse($wallet->balance);
        $amount = DepositReversals::cash($wallet);
        $cash = Credit::where('user_id', $wallet->user_id)->where('currency_code', $wallet->currency_code)->orderBy('id')->lockForUpdate()->limit(2)->get();
        if ($cash->count() > 1) {
            throw new RuntimeException('Duplicate cash cannot become a managed projection.');
        }
        $credit = $cash->first();
        if ($credit) {
            $credit->amount = $amount;
            $credit->save();
        } else {
            $credit = Credit::create(['user_id' => $wallet->user_id, 'currency_code' => $wallet->currency_code, 'amount' => $amount]);
        }
        $guard->acknowledgeProjection($context, $credit);
    }
}
