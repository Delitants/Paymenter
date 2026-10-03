<?php

namespace App\Services\Gateways;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/** Lock connected pending renewal/upgrade invoices before touching a service. */
final class InvoicePaymentDependencies
{
    public function lock(array $invoiceIds): Collection
    {
        $ids = array_values(array_unique(array_filter($invoiceIds)));
        do {
            $previous = count($ids);
            $items = InvoiceItem::whereIn('invoice_id', $ids)->get(['reference_type', 'reference_id']);
            $services = $items->where('reference_type', Service::class)->pluck('reference_id')->all();
            $upgrades = $items->where('reference_type', ServiceUpgrade::class)->pluck('reference_id');
            $services = array_merge($services, ServiceUpgrade::whereIn('id', $upgrades)->pluck('service_id')->all());
            $ids = array_values(array_unique(array_merge($ids, $this->pendingForServices($services))));
        } while (count($ids) !== $previous);
        sort($ids, SORT_NUMERIC);
        $invoices = new Collection;
        foreach ($ids as $id) {
            $invoices->push(Invoice::whereKey($id)->lockForUpdate()->firstOrFail());
        }

        return $invoices;
    }

    public function lockService(int $serviceId, ?int $invoiceId = null): Collection
    {
        return $this->lock(array_merge($this->pendingForServices([$serviceId]), [$invoiceId]));
    }

    private function pendingForServices(array $serviceIds): array
    {
        $serviceIds = array_values(array_unique(array_filter($serviceIds)));
        if (!$serviceIds) {
            return [];
        }
        $upgradeIds = ServiceUpgrade::whereIn('service_id', $serviceIds)->pluck('id');

        return InvoiceItem::whereHas('invoice', fn ($q) => $q->where('status', 'pending'))
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('reference_type', Service::class)->whereIn('reference_id', $serviceIds))
                ->orWhere(fn ($q) => $q->where('reference_type', ServiceUpgrade::class)->whereIn('reference_id', $upgradeIds)))
            ->pluck('invoice_id')->all();
    }

    public function assertCollectable(Invoice $invoice, Collection $locked): void
    {
        foreach ($locked as $related) {
            if ($related->id !== $invoice->id && $related->status === 'pending' && (new PaymentWriteGuard)->isFrozen($related)) {
                throw new RuntimeException('A related service payment requires reconciliation before collection.');
            }
        }
    }
}
