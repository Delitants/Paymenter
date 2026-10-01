<?php

namespace App\Services\Invoice;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ProcessPaidInvoiceService
{
    /**
     * Handle the processing of a paid invoice.
     */
    public function handle(Invoice $invoice): void
    {
        MigrationHold::assertAllowed($invoice, 'process payment');
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
