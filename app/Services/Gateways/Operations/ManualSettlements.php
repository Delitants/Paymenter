<?php

namespace App\Services\Gateways\Operations;

use App\Enums\InvoiceTransactionStatus;
use App\Models\AccountMovement;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\Accounts\AccountPaymentLocks;
use App\Services\Billing\InvoicePricing;
use App\Services\Gateways\InvoicePaymentDependencies;
use App\Services\Gateways\PaymentWriteGuard;
use App\Services\Invoice\ProcessPaidInvoiceService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ManualSettlements
{
    public function record(User $actor, Invoice $invoice, Gateway $gateway, string $amount, string $reference, string $reason, string $effectiveAt, string $requestKey): PaymentOperation
    {
        $policy = new OperationPolicy;
        $amount = $policy->amount($amount);
        [$requestKey, $reason, $effectiveAt] = $policy->request($requestKey, $reason, $effectiveAt);
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 190) {
            throw new RuntimeException('An external receipt reference is required.');
        }
        $journal = new OperationJournal;
        $identity = $invoice->only(['user_id', 'currency_code']);
        $fingerprint = $journal->fingerprint(['kind' => 'manual_receipt', 'actor' => $actor->id, 'invoice' => $invoice->id, 'gateway' => $gateway->id,
            'amount' => $amount, 'currency' => $invoice->currency_code, 'reference' => $reference, 'reason' => $reason, 'effective_at' => $effectiveAt]);

        return DB::transaction(function () use ($actor, $invoice, $gateway, $amount, $reference, $reason, $effectiveAt, $requestKey, $policy, $journal, $fingerprint, $identity) {
            $write = function ($locked) use ($actor, $invoice, $gateway, $amount, $reference, $reason, $effectiveAt, $requestKey, $policy, $journal, $fingerprint, $identity) {
                AccountPaymentLocks::lockFinancialUsers($actor);
                $gateway = Gateway::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
                $dependencies = new InvoicePaymentDependencies;
                $invoice = $locked->firstWhere('id', $invoice->id);
                $policy->authorize($actor, 'manual_settle', $invoice, $gateway);
                if ($invoice->currency_code !== $identity['currency_code'] || (int) $invoice->user_id !== (int) $identity['user_id']) {
                    throw new RuntimeException('The invoice identity changed; refresh it before recording this receipt.');
                }
                if ($existing = $journal->existing($requestKey, $fingerprint)) {
                    return $existing;
                }
                $dependencies->assertCollectable($invoice, $locked);
                $this->assertNoUnresolved($invoice);
                $pricing = new InvoicePricing;
                $summary = $pricing->summary($invoice);
                if ($invoice->status !== 'pending' || BigDecimal::of($amount)->isGreaterThan($summary->payable)) {
                    throw new RuntimeException('The receipt exceeds the outstanding payable balance.');
                }
                $pricing->freezeLegacy($invoice);
                $product = BigDecimal::of($summary->unpaidNet)->plus($summary->unpaidTax);
                $fee = BigDecimal::of($amount)->minus($product);
                $fee = $fee->isNegative() ? BigDecimal::of('0.00') : $fee;
                $gross = BigDecimal::of($amount)->minus($fee);
                $tax = $product->isZero() ? BigDecimal::of('0.00') : $gross->multipliedBy($summary->unpaidTax)->dividedBy($product, 2, RoundingMode::HALF_UP);
                $allocation = ['net' => (string) $gross->minus($tax)->toScale(2), 'tax' => (string) $tax->toScale(2), 'fee' => (string) $fee->toScale(2)];
                $source = hash('sha256', $gateway->id . ':' . $reference);
                $operation = $journal->claim($actor, $invoice, $gateway, 'manual_receipt', $amount, $requestKey, $reason, $effectiveAt, $fingerprint,
                    ['reference' => $reference, 'allocation' => $allocation, 'invoice_identity' => $identity,
                        'pricing_evidence' => ['schema_version' => 1, 'principal_fingerprint' => $pricing->fingerprint($invoice, false),
                            'fees' => $invoice->items()->where('kind', 'gateway_fee')->orderBy('id')->get()->map(fn ($item) => $item->only([
                                'id', 'kind', 'gateway_id', 'price', 'tax_amount', 'quantity', 'reference_type', 'reference_id',
                            ]))->all()]], sourceFingerprint: $source);
                $transaction = (new PaymentWriteGuard)->duringOperation($operation, fn () => $invoice->transactions()->create([
                    'gateway_id' => $gateway->id, 'amount' => $amount, 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => false,
                    'transaction_id' => 'manual:' . $gateway->id . ':' . $source, 'settlement_origin' => 'manual_record', 'settlement_state' => 'settled',
                ]));
                $operation->recordManualResult('manual_recorded', $transaction);
                (new ProcessPaidInvoiceService)->handleRecordedIncoming($operation);

                return $operation->fresh();
            };
            if (AccountPaymentLocks::active()) {
                AccountPaymentLocks::assertGateways([$gateway->id]);

                return $write(AccountPaymentLocks::currentInvoices([$invoice->id]));
            }

            return (new AccountPaymentLocks)->during([$invoice->id], $write, [$gateway->id]);
        });
    }

    public function unsettle(User $actor, InvoiceTransaction $transaction, string $reason, string $effectiveAt, string $requestKey): PaymentOperation
    {
        return $this->transition($actor, $transaction, 'manual_unsettle', 'unsettled', $reason, $effectiveAt, $requestKey);
    }

    public function restore(User $actor, InvoiceTransaction $transaction, string $reason, string $effectiveAt, string $requestKey): PaymentOperation
    {
        return $this->transition($actor, $transaction, 'manual_restore', 'settled', $reason, $effectiveAt, $requestKey);
    }

    private function transition(User $actor, InvoiceTransaction $transaction, string $kind, string $state, string $reason, string $effectiveAt, string $requestKey): PaymentOperation
    {
        $policy = new OperationPolicy;
        [$requestKey, $reason, $effectiveAt] = $policy->request($requestKey, $reason, $effectiveAt);
        $journal = new OperationJournal;
        $fingerprint = $journal->fingerprint(['kind' => $kind, 'actor' => $actor->id, 'transaction' => $transaction->id, 'amount' => $transaction->amount,
            'reason' => $reason, 'effective_at' => $effectiveAt]);

        $sourceOwners = AccountMovement::where('kind', 'deposit')->where('reference_id', $transaction->invoice_id)->pluck('user_id')->all();

        return DB::transaction(function () use ($actor, $transaction, $kind, $state, $reason, $effectiveAt, $requestKey, $policy, $journal, $fingerprint, $sourceOwners) {
            return (new AccountPaymentLocks)->during([$transaction->invoice_id], function () use ($actor, $transaction, $kind, $state, $reason, $effectiveAt, $requestKey, $policy, $journal, $fingerprint, $sourceOwners) {
                AccountPaymentLocks::lockFinancialUsers($actor, $sourceOwners);
                $gateway = Gateway::whereKey($transaction->gateway_id)->lockForUpdate()->firstOrFail();
                $invoice = (new InvoicePaymentDependencies)->lock([$transaction->invoice_id])->firstWhere('id', $transaction->invoice_id);
                $policy->authorize($actor, $state === 'unsettled' ? 'manual_unsettle' : 'manual_settle', $invoice, $gateway);
                $transaction = InvoiceTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
                if ($transaction->invoice_id !== $invoice->id || $transaction->gateway_id !== $gateway->id) {
                    throw new RuntimeException('The original payment identity changed.');
                }
                if ($existing = $journal->existing($requestKey, $fingerprint)) {
                    return $existing;
                }
                $this->assertNoUnresolved($invoice, allowPaid: true);
                if ($transaction->settlement_origin !== 'manual_record' || $transaction->status !== InvoiceTransactionStatus::Succeeded ||
                    $transaction->settlement_state !== ($state === 'unsettled' ? 'settled' : 'unsettled') ||
                    PaymentOperation::where('original_transaction_id', $transaction->id)->whereIn('kind', ['provider_refund', 'external_refund'])
                        ->whereIn('state', ['queued', 'processing', 'pending', 'uncertain', 'succeeded'])->exists()) {
                    throw new RuntimeException('Only an unrefunded manually recorded receipt can change settlement state.');
                }
                $operation = $journal->claim($actor, $invoice, $gateway, $kind, $transaction->amount, $requestKey, $reason, $effectiveAt, $fingerprint,
                    ['target_state' => $state], $transaction);
                (new PaymentWriteGuard)->duringOperation($operation, function () use ($transaction, $invoice, $state) {
                    $transaction->update(['settlement_state' => $state]);
                    $invoice = $invoice->fresh();
                    // A paid status masks outstanding balance in the display summary.
                    $paid = $invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded)->where(function ($q) {
                        $q->whereNull('settlement_state')->orWhere('settlement_state', 'settled');
                    })->sum('amount');
                    $total = (new InvoicePricing)->summary($invoice)->total;
                    $status = BigDecimal::of((string) $paid)->isLessThan($total) ? 'pending' : 'paid';
                    if ($invoice->status !== $status) {
                        $invoice->update(['status' => $status]);
                    }
                });
                $operation->recordManualResult($state, $transaction);

                return $operation->fresh();
            }, [$transaction->gateway_id]);
        });
    }

    private function assertNoUnresolved(Invoice $invoice, bool $allowPaid = false): void
    {
        if (InvoiceTransaction::where('invoice_id', $invoice->id)->where('status', InvoiceTransactionStatus::Processing)->lockForUpdate()->exists() ||
            GatewayPaymentAttempt::where('invoice_id', $invoice->id)->whereIn('state', $allowPaid ? ['open', 'initializing'] : ['open', 'initializing', 'paid'])->lockForUpdate()->exists() ||
            PaymentOperation::where('invoice_id', $invoice->id)->whereIn('state', ['queued', 'processing', 'pending', 'uncertain'])->lockForUpdate()->exists()) {
            throw new RuntimeException('An outstanding provider operation requires reconciliation before manual settlement.');
        }
    }
}
