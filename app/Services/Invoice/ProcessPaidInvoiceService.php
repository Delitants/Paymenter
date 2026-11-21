<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice\Paid;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Accounts\AccountPaymentLocks;
use App\Services\Accounts\DepositLifecycle;
use App\Services\Accounts\IncomingPostingRequired;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\InvoicePaymentDependencies;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessPaidInvoiceService
{
    /**
     * Handle the processing of a paid invoice.
     */
    public function handle(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice) {
            $write = function () use ($invoice) {
                $invoice = (new InvoicePaymentDependencies)->lock([$invoice->id])->firstWhere('id', $invoice->id);
                MigrationHold::assertAllowed($invoice, 'process payment');
                if ($invoice->status !== 'paid' || InvoicePaidProcessing::whereKey($invoice->id)->lockForUpdate()->exists()) {
                    return false;
                }
                InvoicePaidProcessing::create(['invoice_id' => $invoice->id, 'origin' => 'native', 'processed_at' => now()]);
                $this->process($invoice);

                return true;
            };
            if (AccountPaymentLocks::active()) {
                return $write();
            }

            return (new AccountPaymentLocks)->during([$invoice->id], fn () => $write());
        });
    }

    /** Incoming operation evidence is finalized before its paid lifecycle. */
    public function handleRecordedIncoming(PaymentOperation $operation): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Recorded incoming processing requires its native operation transaction.');
        }
        $operation = PaymentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
        $invoice = Invoice::whereKey($operation->invoice_id)->lockForUpdate()->firstOrFail();
        $receipt = InvoiceTransaction::whereKey($operation->result_transaction_id)->lockForUpdate()->first();
        if ($operation->state !== 'succeeded' || !in_array($operation->kind, ['manual_receipt', 'provider_capture'], true) ||
            !$receipt || $receipt->invoice_id !== $invoice->id || $receipt->gateway_id !== $operation->gateway_id ||
            $receipt->amount !== $operation->amount || $receipt->status !== InvoiceTransactionStatus::Succeeded || $receipt->is_credit_transaction ||
            $receipt->settlement_state !== 'settled' || $operation->currency_code !== $invoice->currency_code ||
            ($operation->payload['invoice_identity'] ?? null) !== $invoice->only(['user_id', 'currency_code'])) {
            throw new RuntimeException('Incoming processing requires its exact completed operation and native payment receipt.');
        }
        if ($invoice->status !== 'paid') {
            return false;
        }
        $processed = $this->handle($invoice);
        if ($processed) {
            event(new Paid($invoice));
        }

        return $processed;
    }

    private function process(Invoice $invoice): void
    {
        // Update services if invoice is paid (suspended -> active etc.)
        $invoice->items->each(function ($item) use ($invoice) {
            if ($item->reference_type == Service::class) {
                $service = $item->reference;
                if (!$service || !($service instanceof Service)) {
                    return;
                }
                (new RenewServiceService)->handle($service, $item);
            } elseif ($item->reference_type == ServiceUpgrade::class) {
                $serviceUpgrade = $item->reference;
                if (!$serviceUpgrade || $serviceUpgrade->status !== ServiceUpgrade::STATUS_PENDING || !($serviceUpgrade instanceof ServiceUpgrade)) {
                    return;
                }

                // Handle the upgrade
                (new ServiceUpgradeService)->handle($serviceUpgrade);
            } elseif ($item->reference_type == Credit::class) {
                try {
                    if ((new DepositLifecycle)->creditPaidInvoice($invoice) !== null) {
                        return;
                    }
                } catch (IncomingPostingRequired $exception) {
                    // Only verified retained income takes this path. All other
                    // source errors still roll back native payment processing.
                    if (!$exception->issue->exists || $exception->issue->resolved_movement_id !== null) {
                        throw $exception;
                    }

                    return;
                }
                DB::transaction(function () use ($invoice, $item) {
                    $credit = $invoice->user->credits()->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
                    if ($credit) {
                        $credit->amount = (string) BigDecimal::of((string) $credit->getRawOriginal('amount'))->plus($item->price)->toScale(2);
                        $credit->save();
                    } else {
                        $invoice->user->credits()->create([
                            'currency_code' => $invoice->currency_code,
                            'amount' => $item->price,
                        ]);
                    }
                });
            }
        });
    }
}
