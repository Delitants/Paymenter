<?php

namespace App\Services\ServiceUpgrade;

use App\Jobs\Server\UpgradeJob;
use App\Models\AccountDowngradeReceipt;
use App\Models\AccountWallet;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Accounts\AccountPaymentLocks;
use App\Services\Accounts\DepositLifecycle;
use App\Services\Accounts\NativeDowngradeReceipt;
use App\Services\Gateways\PaymentWriteGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ServiceUpgradeService
{
    /**
     * Handle the uploaded extension file.
     * The added file is always a zip file.
     *
     * @return void
     */
    public function handle(ServiceUpgrade $serviceUpgrade)
    {
        return DB::transaction(function () use ($serviceUpgrade) {
            $snapshot = ServiceUpgrade::findOrFail($serviceUpgrade->id);
            $write = fn () => $this->completeLocked($snapshot->id);
            if (AccountPaymentLocks::active()) {
                AccountPaymentLocks::currentInvoices(array_filter([$snapshot->invoice_id]), $snapshot->service_id);
                if (!AccountPaymentLocks::coversUpgrade($snapshot->id)) {
                    throw new RuntimeException('Upgrade graph expanded before native completion.');
                }

                return $write();
            }

            return (new AccountPaymentLocks)->during(array_filter([$snapshot->invoice_id]), fn () => $write(), [], [$snapshot->service_id], [$snapshot->id]);
        }, 3);
    }

    private function completeLocked(int $id): void
    {
        $serviceUpgrade = ServiceUpgrade::whereKey($id)->lockForUpdate()->firstOrFail();
        $invoices = AccountPaymentLocks::currentInvoices(array_filter([$serviceUpgrade->invoice_id]), $serviceUpgrade->service_id);
        foreach ($invoices as $invoice) {
            if ($invoice->status === 'pending') {
                (new PaymentWriteGuard)->assertEditable($invoice);
            }
        }
        $serviceUpgrade->setRelation('service', Service::whereKey($serviceUpgrade->service_id)->lockForUpdate()->firstOrFail());
        User::whereKey($serviceUpgrade->service->user_id)->lockForUpdate()->firstOrFail();
        $managed = AccountWallet::where('user_id', $serviceUpgrade->service->user_id)->where('currency_code', $serviceUpgrade->service->currency_code)->lockForUpdate()->first() !== null;
        if ($serviceUpgrade->status === ServiceUpgrade::STATUS_COMPLETED) {
            $receipt = AccountDowngradeReceipt::where('service_upgrade_id', $id)->lockForUpdate()->first();
            if ($managed && $receipt && in_array($receipt->provider_state, ['unneeded', 'confirmed'], true)) {
                (new DepositLifecycle)->creditCompletedDowngrade($serviceUpgrade);
            }

            return;
        }
        if ($serviceUpgrade->status !== ServiceUpgrade::STATUS_PENDING) {
            throw new RuntimeException('Only a pending native upgrade can complete.');
        }
        $receipt = $managed ? NativeDowngradeReceipt::complete($serviceUpgrade, fn () => $this->apply($serviceUpgrade)) : null;
        if (!$managed) {
            $this->apply($serviceUpgrade);
        }
        $service = $serviceUpgrade->service->fresh();
        if ($service->product->server) {
            UpgradeJob::dispatch($service, true, $receipt?->service_upgrade_id)->afterCommit();
        } elseif ($receipt) {
            (new DepositLifecycle)->creditCompletedDowngrade($serviceUpgrade->fresh());
        }
    }

    private function apply(ServiceUpgrade $serviceUpgrade): void
    {
        $serviceUpgrade->status = ServiceUpgrade::STATUS_COMPLETED;
        $serviceUpgrade->save();
        // Check if old product stock should be increased
        $service = $serviceUpgrade->service;
        if ($service->product->stock !== null) {
            $serviceUpgrade->service->product->increment('stock', $serviceUpgrade->service->quantity);
        }

        $service->plan_id = $serviceUpgrade->plan_id;
        $service->product_id = $serviceUpgrade->product_id;
        $service->save();

        $service->refresh();

        // Decrease stock of new product if applicable
        if ($service->product->stock !== null) {
            $service->product->decrement('stock', $service->quantity);
        }

        // Update service configurations - remove old configs and add new ones
        $newConfigOptionIds = $serviceUpgrade->configs->pluck('config_option_id')->toArray();

        // Delete configs that are no longer applicable
        $service->configs()
            ->whereNotIn('config_option_id', $newConfigOptionIds)
            ->delete();

        // Update or create new configs
        foreach ($serviceUpgrade->configs as $config) {
            $service->configs()->updateOrCreate(
                ['config_option_id' => $config->config_option_id],
                ['config_value_id' => $config->config_value_id]
            );
        }

        $service->refresh();

        $service->price = $service->calculatePrice();
        $service->save();

        // Is there a pending renewal invoice? Update it.
        $pendingInvoice = $service->invoices()
            ->where('status', 'pending')
            ->first();

        if ($pendingInvoice) {
            $item = $pendingInvoice->items()
                ->where('reference_type', Service::class)
                ->where('reference_id', $service->id)
                ->first();
            if ($item) {
                $item->price = $service->price;
                $item->save();
            }
        }

    }
}
