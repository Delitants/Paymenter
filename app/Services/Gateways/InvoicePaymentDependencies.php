<?php

namespace App\Services\Gateways;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Accounts\AccountPaymentLocks;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Lock connected pending renewal/upgrade invoices before touching a service. */
final class InvoicePaymentDependencies
{
    public function lock(array $invoiceIds): Collection
    {
        if ($held = AccountPaymentLocks::currentInvoices($invoiceIds)) {
            return $held;
        }
        $ids = $this->discover($invoiceIds)['invoices'];
        $invoices = new Collection;
        foreach ($ids as $id) {
            $invoices->push(Invoice::whereKey($id)->lockForUpdate()->firstOrFail());
        }

        return $invoices;
    }

    public function discover(array $invoiceIds, bool $currentRead = false, array $serviceIds = [], array $upgradeIds = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
        $services = $upgrades = [];
        do {
            $previous = count($ids);
            $query = InvoiceItem::whereIn('invoice_id', $ids)->orderBy('id');
            if ($currentRead) {
                $query->lockForUpdate();
            }
            $items = $query->get(['reference_type', 'reference_id']);
            $services = array_merge($serviceIds, $items->where('reference_type', Service::class)->pluck('reference_id')->all());
            $upgrades = array_merge($upgradeIds, $items->where('reference_type', ServiceUpgrade::class)->pluck('reference_id')->all());
            $upgradeQuery = ServiceUpgrade::whereIn('id', $upgrades)->orderBy('id');
            if ($currentRead) {
                $upgradeQuery->lockForUpdate();
            }
            $services = array_merge($services, $upgradeQuery->pluck('service_id')->all());
            $ids = array_values(array_unique(array_merge($ids, $this->pendingForServices($services, $currentRead))));
        } while (count($ids) !== $previous);
        $graph = ['invoices' => $ids, 'services' => $services, 'upgrades' => $upgrades];
        foreach ($graph as &$entries) {
            $entries = array_values(array_unique(array_filter(array_map('intval', $entries))));
            sort($entries, SORT_NUMERIC);
        }
        unset($entries);

        return $graph;
    }

    public function lockService(int $serviceId, ?int $invoiceId = null): Collection
    {
        if ($held = AccountPaymentLocks::currentInvoices(array_filter([$invoiceId]), $serviceId)) {
            return $held;
        }

        return $this->lock(array_merge($this->pendingForServices([$serviceId]), [$invoiceId]));
    }

    private function pendingForServices(array $serviceIds, bool $currentRead = false): array
    {
        $serviceIds = array_values(array_unique(array_filter($serviceIds)));
        if (!$serviceIds) {
            return [];
        }
        $upgrades = ServiceUpgrade::whereIn('service_id', $serviceIds)->orderBy('id');
        if ($currentRead) {
            $upgrades->lockForUpdate();
        }
        $upgradeIds = $upgrades->pluck('id');

        if ($currentRead) {
            // A joined locking read observes newly committed pending parents too;
            // a repeatable-read subquery could otherwise miss graph expansion.
            return DB::table('invoice_items as items')->join('invoices', 'invoices.id', '=', 'items.invoice_id')->where('invoices.status', 'pending')
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('items.reference_type', Service::class)->whereIn('items.reference_id', $serviceIds))
                    ->orWhere(fn ($q) => $q->where('items.reference_type', ServiceUpgrade::class)->whereIn('items.reference_id', $upgradeIds)))
                ->orderBy('items.invoice_id')->orderBy('items.id')->lockForUpdate()->pluck('items.invoice_id')->all();
        }

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
