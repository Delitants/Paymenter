<?php

namespace App\Services\Accounts;

use App\Enums\InvoiceTransactionStatus;
use App\Models\AccountMovement;
use App\Models\AccountWallet;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\Operations\OperationResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Original protected principal, independent of a mutable invoice display state. */
final class DepositReversalReceipt
{
    public static function facts(PaymentOperation $request, bool $terminal = false, bool $metadataOnly = false): ?array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Deposit reversals require the complete original dependency frame.');
        }
        $operation = PaymentOperation::whereKey($request->id)->lockForUpdate()->firstOrFail();
        if (!in_array($operation->kind, ['provider_refund', 'external_refund', 'manual_unsettle', 'manual_restore'], true)) {
            return null;
        }
        $hint = AccountMovement::where('kind', 'deposit')->where('reference_type', InvoicePaidProcessing::class)->where('reference_id', $operation->invoice_id)->first();
        if (!$hint) {
            $invoice = Invoice::findOrFail($operation->invoice_id);
            if ($invoice->items()->where('kind', 'credit_allocation')->exists() && AccountWallet::where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)->exists()) {
                throw new RuntimeException('Managed deposit refunds require already posted original principal.');
            }

            return null;
        }
        if (!$metadataOnly && !AccountPaymentLocks::covers([$operation->invoice_id])) {
            throw new RuntimeException('Managed deposit reversals require the original dependency frame.');
        }
        if (!$metadataOnly) {
            AccountPaymentLocks::assertFinancialUsers([$hint->user_id]);
        }
        User::whereKey($hint->user_id)->lockForUpdate()->firstOrFail();
        $wallet = AccountWallet::whereKey($hint->wallet_id)->lockForUpdate()->firstOrFail();
        $deposit = AccountMovement::whereKey($hint->id)->lockForUpdate()->firstOrFail();
        // A quote already owns the wallet. Another original-deposit frame may
        // own this receipt while waiting for that owner; never wait back on it.
        // Keep a current locked proof, or let the quote deny spending briefly.
        try {
            $transaction = InvoiceTransaction::whereKey($operation->original_transaction_id)
                ->lock($metadataOnly ? 'for update nowait' : 'for update')->firstOrFail();
        } catch (QueryException $exception) {
            if (!$metadataOnly || ($exception->errorInfo[1] ?? null) !== 1205) {
                throw $exception;
            }
            throw new AccountReceiptBusy('An original refund receipt is busy; retry after reconciliation.', previous: $exception);
        }
        $slice = collect($deposit->payload['deposit_slices'] ?? [])->where('transaction_id', $transaction->id)->sole();
        $original = $transaction->original_allocation;
        if ($deposit->user_id !== $wallet->user_id || $deposit->currency_code !== $wallet->currency_code ||
            $operation->currency_code !== $wallet->currency_code || $operation->invoice_id !== $transaction->invoice_id ||
            $operation->gateway_id !== $transaction->gateway_id || $transaction->status !== InvoiceTransactionStatus::Succeeded ||
            $transaction->is_credit_transaction || !is_array($original) || $original['tax'] !== '0.00' ||
            AccountAmount::parse($original['net'])->exact() !== $slice['principal'] || $original['fee'] !== $slice['fee']) {
            throw new RuntimeException('Original credited deposit slice identity changed.');
        }
        $refund = in_array($operation->kind, ['provider_refund', 'external_refund'], true);
        $principal = AccountAmount::parse($slice['principal']);
        $linked = $deposit->id;
        if ($refund) {
            $allocation = $operation->payload['allocation'] ?? [];
            if (($operation->payload['original_allocation'] ?? null) !== $original || ($allocation['tax'] ?? null) !== '0.00' ||
                AccountAmount::nonnegative($allocation['net'] ?? null)->add(AccountAmount::nonnegative($allocation['fee'] ?? null))->compare(AccountAmount::positiveCents($operation->amount)) !== 0) {
                throw new RuntimeException('Refund principal requires its original immutable allocation.');
            }
            $principal = AccountAmount::nonnegative($allocation['net']);
            if ($principal->compare(AccountAmount::parse($slice['principal'])) > 0) {
                throw new RuntimeException('Refund principal exceeds the original credited slice.');
            }
        } else {
            if ($transaction->settlement_origin !== 'manual_record' || $operation->amount !== $transaction->amount ||
                $operation->result_transaction_id !== $transaction->id || $operation->state !== 'succeeded' ||
                $operation->outcome_code !== ($operation->kind === 'manual_unsettle' ? 'unsettled' : 'settled') ||
                $transaction->settlement_state !== ($operation->kind === 'manual_unsettle' ? 'unsettled' : 'settled') ||
                PaymentOperation::where('original_transaction_id', $transaction->id)->whereIn('kind', ['provider_refund', 'external_refund'])
                    ->whereIn('state', ['queued', 'processing', 'pending', 'uncertain', 'succeeded'])->exists()) {
                throw new RuntimeException('Manual deposit transition requires its succeeded unrefunded original receipt.');
            }
            if ($operation->kind === 'manual_restore') {
                $prior = AccountMovement::where('wallet_id', $wallet->id)->whereIn('kind', ['manual_unsettle', 'manual_restore'])
                    ->orderByDesc('id')->lockForUpdate()->get()->first(fn ($row) => ($row->payload['original_transaction_id'] ?? null) === $transaction->id);
                // An exact retry retains the same original unmatched reversal link.
                $replay = AccountMovement::where('source_key', self::sourceKey($operation->id))->lockForUpdate()->first();
                if ($replay) {
                    $prior = AccountMovement::whereKey($replay->linked_reversal_id)->lockForUpdate()->firstOrFail();
                }
                if (!$prior || $prior->kind !== 'manual_unsettle' || $prior->delta !== AccountAmount::parse('0')->subtract($principal)->exact()) {
                    throw new RuntimeException('Restoration requires the exact preceding principal unsettlement.');
                }
                $linked = $prior->id;
            }
        }
        if ($terminal) {
            self::assertTerminal($operation);
        }

        return ['operation_id' => $operation->id, 'kind' => $refund ? 'deposit_refund' : $operation->kind, 'owner_id' => $wallet->user_id, 'currency' => $wallet->currency_code,
            'wallet_id' => $wallet->id, 'deposit_movement_id' => $deposit->id, 'deposit_fingerprint' => $deposit->request_fingerprint,
            'original_transaction_id' => $transaction->id, 'principal' => $principal->exact(), 'linked_reversal_id' => $linked,
            'request' => $operation->only(['kind', 'invoice_id', 'gateway_id', 'original_transaction_id', 'amount', 'currency_code', 'request_fingerprint', 'source_fingerprint']),
            'slice' => $slice, 'original_allocation' => $original, 'actor_id' => $operation->actor_id,
            ...($terminal ? ['outcome' => $operation->only(['state', 'provider_reference', 'outcome_code', 'outcome_evidence', 'result_transaction_id'])] : [])];
    }

    public static function assertTerminal(PaymentOperation $operation): void
    {
        if ($operation->kind === 'provider_refund') {
            $last = collect($operation->outcome_evidence ?? [])->last();
            if (!in_array($operation->state, ['succeeded', 'failed'], true) || ($last['state'] ?? null) !== $operation->state) {
                throw new RuntimeException('A stored verified terminal refund outcome is required.');
            }
            (new OperationResult($operation->state, $operation->provider_reference, $last['evidence'] ?? [], $operation->outcome_code))->assertVerified($operation);
        } elseif ($operation->state !== 'succeeded' || !in_array($operation->outcome_code, ['external_recorded', 'unsettled', 'settled'], true)) {
            throw new RuntimeException('A stored authorized manual outcome is required.');
        }
    }

    public static function assertMoney(array $proof): void
    {
        $invoice = AccountPaymentLocks::currentInvoices([$proof['request']['invoice_id']])->firstWhere('id', $proof['request']['invoice_id']);
        if ($invoice->user_id !== $proof['owner_id'] || $invoice->currency_code !== $proof['currency']) {
            throw new RuntimeException('Current deposit financial identity requires reconciliation.');
        }
        foreach ([$invoice, User::findOrFail($proof['owner_id']), AccountWallet::findOrFail($proof['wallet_id']), InvoiceTransaction::findOrFail($proof['original_transaction_id'])] as $record) {
            MigrationHold::assertAllowed($record, 'post original deposit reversal', true);
        }
    }

    public static function sourceKey(int $id): string
    {
        return hash('sha256', 'native-deposit-operation:' . $id);
    }
}
