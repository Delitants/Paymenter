<?php

namespace App\Services\Accounts;

use App\Enums\InvoiceTransactionStatus;
use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\AccountWallet;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoicePricing;
use App\Services\Gateways\PaymentWriteGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InvoiceFunding
{
    public function fundAvailable(User $actor, Invoice $invoice, string $requestKey): ?AccountFundingAllocation
    {
        (new AccountFundingGate)->assertEnabled();

        return DB::transaction(function () use ($actor, $invoice, $requestKey) {
            $write = function ($locked) use ($actor, $invoice, $requestKey) {
                $invoice = $locked->firstWhere('id', $invoice->id);
                if (!$invoice || $actor->id !== $invoice->user_id) {
                    throw new RuntimeException('Only the current invoice owner may request available funding.');
                }
                User::whereKey($invoice->user_id)->lockForUpdate()->firstOrFail();
                $wallet = AccountWallet::where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->firstOrFail();
                $quote = (new WalletLedger)->quote($actor, $invoice->currency_code);
                if (!$quote || $quote->blocked) {
                    throw new RuntimeException('Available account funding requires active reconciled history.');
                }
                (new PaymentWriteGuard)->assertEditable($invoice);
                $replay = AccountMovement::where('wallet_id', $wallet->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($replay) {
                    $allocation = AccountFundingAllocation::where('movement_id', $replay->id)->lockForUpdate()->sole();

                    return $this->post(AccountWriteContext::invoiceFunding($invoice, $actor, $allocation->amount, maximum: true), $requestKey);
                }
                $summary = (new InvoicePricing)->summary($invoice);
                $remaining = AccountAmount::parse($summary->unpaidNet)->add(AccountAmount::parse($summary->unpaidTax));
                $available = AccountAmount::parse($quote->fundingAvailable);
                $amount = $available->compare($remaining) < 0 ? $available : $remaining;
                if ($invoice->status !== 'pending' || $amount->compare(AccountAmount::parse('0')) === 0) {
                    return null;
                }

                return $this->post(AccountWriteContext::invoiceFunding($invoice, $actor, $amount->floorCents(), maximum: true), $requestKey);
            };

            return AccountPaymentLocks::active() ? $write(AccountPaymentLocks::currentInvoices([$invoice->id])) : (new AccountPaymentLocks)->during([$invoice->id], $write);
        }, 3);
    }

    public function fund(User $actor, Invoice $invoice, mixed $amount, string $requestKey): AccountFundingAllocation
    {
        return $this->post(AccountWriteContext::invoiceFunding($invoice, $actor, $amount), $requestKey);
    }

    public function fundAutomatic(Invoice $invoice, string $requestKey): ?AccountFundingAllocation
    {
        try {
            return $this->post(AccountWriteContext::invoiceFunding($invoice, null, null, true), $requestKey);
        } catch (InsufficientAccountFunding) {
            return null;
        }
    }

    private function post(AccountWriteContext $context, string $requestKey): AccountFundingAllocation
    {
        $movement = (new WalletLedger)->post($context, $context->delta(), 'invoice_funding', $requestKey, $context->fingerprint($requestKey));

        return AccountFundingAllocation::where('movement_id', $movement->id)->sole();
    }

    public function completeReceipt(AccountWriteContext $context, AccountMovement $movement): void
    {
        $allocation = AccountFundingAllocation::create($context->allocationAttributes($movement));
        $transaction = Invoice::findOrFail($context->invoiceId)->transactions()->create([
            'amount' => $allocation->amount, 'gateway_id' => null, 'transaction_id' => null, 'is_credit_transaction' => true,
            'status' => InvoiceTransactionStatus::Succeeded, 'settlement_origin' => 'account_funding', 'settlement_state' => 'settled',
        ]);
        (new AccountWriteGuard)->confirmInternalTransaction($context, $transaction);
        $allocation->invoice_transaction_id = $transaction->id;
        $allocation->save();
    }
}
