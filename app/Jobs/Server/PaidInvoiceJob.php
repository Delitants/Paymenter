<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Service\PaidServiceLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/** Opt-in server lifecycle, invoked only after the invoice transaction commits. */
class PaidInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 480;

    public $tries = 1;

    public function __construct(public int $invoiceItemId) {}

    public function handle(): void
    {
        $item = InvoiceItem::findOrFail($this->invoiceItemId);
        $invoice = $item->invoice;
        if (!$invoice || $invoice->status !== Invoice::STATUS_PAID) {
            throw new RuntimeException('Lifecycle requires a paid invoice');
        }
        $service = $item->reference;
        if ($item->reference_type !== Service::class || !($service instanceof Service) ||
            (int) $invoice->user_id !== (int) $service->user_id || $invoice->currency_code !== $service->currency_code) {
            throw new RuntimeException('Paid service identity changed');
        }
        MigrationHold::assertAllowed($item, 'paid service lifecycle');
        MigrationHold::assertAllowed($service, 'paid service lifecycle');
        $server = $service->product?->server;
        if (!$server || !$server->enabled || !ExtensionHelper::hasFunction($server, 'handlePaidInvoice')) {
            throw new RuntimeException('Paid service server is disabled or unavailable');
        }
        if (PaidServiceLifecycle::claim($service, $item)) {
            ExtensionHelper::call($server, 'handlePaidInvoice', [$service, $item]);
            PaidServiceLifecycle::complete($service, $item);
        }
    }
}
