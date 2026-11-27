<?php

namespace App\Services\Service;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\PaidInvoiceJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;
use Illuminate\Support\Facades\DB;
use Throwable;

class RenewServiceService
{
    /**
     * Handle the service renewal.
     *
     * @return void
     */
    public function handle(Service $service, ?InvoiceItem $item = null)
    {
        MigrationHold::assertAllowed($service, 'renew service');
        $server = $service->product->server;
        $nativeLifecycle = $server && ExtensionHelper::hasFunction($server, 'handlePaidInvoice');
        $quotedRenewal = $item?->invoice?->properties()->where('key', ExtensionHelper::SERVICE_RENEWAL_QUOTE)->exists();
        if ($nativeLifecycle || $quotedRenewal) {
            if (!$item || $item->reference_type !== Service::class || (int) $item->reference_id !== (int) $service->id) {
                throw new \RuntimeException('This service requires a paid invoice item');
            }
            if (!$nativeLifecycle || PaidServiceLifecycle::claim($service, $item)) {
                PaidInvoiceJob::dispatch($item->id)->afterCommit();
            }

            return;
        }
        $restoreIspmanager = false;
        if ($service->product->server) {
            if ($service->status == Service::STATUS_SUSPENDED) {
                $restoreIspmanager = $service->product->server->extension === 'ISPmanager';
                if (!$restoreIspmanager) {
                    UnsuspendJob::dispatch($service);
                }
            } elseif ($service->status == Service::STATUS_PENDING) {
                CreateJob::dispatch($service);
            }
        }

        $service->expires_at = $service->calculateNextDueDate();
        $service->status = Service::STATUS_ACTIVE;
        $service->save();
        if ($restoreIspmanager) {
            DB::afterCommit(function () use ($service): void {
                try {
                    UnsuspendJob::dispatch($service);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
    }
}
