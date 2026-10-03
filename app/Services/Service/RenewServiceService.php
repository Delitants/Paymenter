<?php

namespace App\Services\Service;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\PaidInvoiceJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;

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
        if ($service->product->server && ExtensionHelper::hasFunction($service->product->server, 'handlePaidInvoice')) {
            if (!$item || $item->reference_type !== Service::class || (int) $item->reference_id !== (int) $service->id) {
                throw new \RuntimeException('This service requires a paid invoice item');
            }
            if (PaidServiceLifecycle::claim($service, $item)) {
                PaidInvoiceJob::dispatch($item->id)->afterCommit();
            }

            return;
        }
        if ($service->product->server) {
            if ($service->status == Service::STATUS_SUSPENDED) {
                UnsuspendJob::dispatch($service);
            } elseif ($service->status == Service::STATUS_PENDING) {
                CreateJob::dispatch($service);
            }
        }

        $service->expires_at = $service->calculateNextDueDate();
        $service->status = Service::STATUS_ACTIVE;
        $service->save();
    }
}
