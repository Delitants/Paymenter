<?php

namespace App\Services\Accounts;

use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\InvoicePaymentDependencies;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AccountPaymentLocks
{
    private static ?array $frame = null;

    private ?array $lockedGraph = null;

    private bool $enforceHolds = true;

    public function during(array $invoiceIds, Closure $write, array $gatewayIds = [], array $serviceIds = [], array $upgradeIds = []): mixed
    {
        if (self::$frame !== null) {
            throw new RuntimeException('Nested account payment lock frames are not allowed.');
        }
        $invoices = $this->lock($invoiceIds, $gatewayIds, $serviceIds, $upgradeIds);
        self::$frame = $this->lockedGraph;
        try {
            return $write($invoices);
        } finally {
            self::$frame = null;
        }
    }

    /** Lock evidence only; every monetary context still checks current holds. */
    public function duringIncomingEvidence(array $invoiceIds, Closure $write, array $gatewayIds = [], array $serviceIds = [], array $upgradeIds = []): mixed
    {
        $this->enforceHolds = false;
        try {
            return $this->during($invoiceIds, $write, $gatewayIds, $serviceIds, $upgradeIds);
        } finally {
            $this->enforceHolds = true;
        }
    }

    public static function active(): bool
    {
        return self::$frame !== null && DB::transactionLevel() > 0;
    }

    public static function covers(array $invoiceIds): bool
    {
        return self::active() && array_diff(array_filter(array_map('intval', $invoiceIds)), self::$frame['invoices']) === [];
    }

    public static function assertGateways(array $ids): void
    {
        if (!self::active() || array_diff($ids, self::$frame['gateways'])) {
            throw new RuntimeException('Account payment gateway graph expanded; restart before owner acquisition.');
        }
    }

    public static function coversUpgrade(int $upgradeId): bool
    {
        return self::active() && in_array($upgradeId, self::$frame['upgrades'], true);
    }

    public static function currentInvoices(array $invoiceIds, ?int $serviceId = null): ?Collection
    {
        if (!self::active()) {
            return null;
        }
        if (!self::covers($invoiceIds) || ($serviceId !== null && !in_array($serviceId, self::$frame['services'], true))) {
            throw new RuntimeException('Account payment graph expanded after wallet acquisition; restart the entire transaction.');
        }

        return Invoice::whereIn('id', self::$frame['invoices'])->orderBy('id')->lockForUpdate()->get();
    }

    public static function lockFinancialUsers(User $actor, array $sourceOwnerIds = []): void
    {
        if (!self::active()) {
            throw new RuntimeException('Financial owners require the complete dependency frame.');
        }
        $ids = array_values(array_unique(array_merge([$actor->id], $sourceOwnerIds, self::currentInvoices(self::$frame['invoices'])->pluck('user_id')->all())));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            User::whereKey($id)->lockForUpdate()->firstOrFail();
        }
        self::$frame['financial_users'] = $ids;
    }

    public static function assertFinancialUsers(array $ids): void
    {
        if (!self::active() || array_diff($ids, self::$frame['financial_users'] ?? [])) {
            throw new RuntimeException('Financial owner graph expanded before reversal posting.');
        }
    }

    public function lock(array $invoiceIds, array $gatewayIds = [], array $serviceIds = [], array $upgradeIds = []): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Account payment dependencies require an existing transaction.');
        }
        if (self::active()) {
            throw new RuntimeException('Account payment graph must be acquired before its financial lock frame.');
        }
        $this->lockedGraph = null;
        $dependencies = new InvoicePaymentDependencies;
        $graph = $dependencies->discover($invoiceIds, false, $serviceIds, $upgradeIds);
        $gateways = $this->gateways($graph['invoices'], $gatewayIds);
        foreach ($gateways as $id) {
            $gateway = Gateway::withTrashed()->whereKey($id)->lockForUpdate()->first();
            if (!$gateway || $gateway->trashed()) {
                throw new RuntimeException('Account payment gateway identity is missing.');
            }
        }
        $invoices = new Collection;
        foreach ($graph['invoices'] as $id) {
            $invoices->push(Invoice::whereKey($id)->lockForUpdate()->firstOrFail());
        }
        foreach ($graph['services'] as $id) {
            $service = Service::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($this->enforceHolds) {
                // Preflight only: acquiring absent hold gaps here, before the
                // owner lock, can deadlock a new hold inserted by that owner
                // lock's holder. Monetary contexts recheck current holds after
                // acquiring their financial owners.
                MigrationHold::assertAllowed($service, 'lock account payment service');
            }
        }
        foreach ($graph['upgrades'] as $id) {
            ServiceUpgrade::whereKey($id)->lockForUpdate()->firstOrFail();
        }
        if ($dependencies->discover($invoiceIds, true, $serviceIds, $upgradeIds) !== $graph || $this->gateways($graph['invoices'], $gatewayIds, true) !== $gateways) {
            throw new RuntimeException('Account payment dependency graph expanded; restart before locking a wallet.');
        }
        foreach ($invoices as $invoice) {
            if ($this->enforceHolds) {
                MigrationHold::assertAllowed($invoice, 'lock account payment invoice');
            }
        }
        $this->lockedGraph = $graph + ['gateways' => $gateways];

        return $invoices;
    }

    private function gateways(array $invoiceIds, array $provided, bool $currentRead = false): array
    {
        $ids = $provided;
        foreach ([InvoiceTransaction::class, GatewayPaymentAttempt::class, InvoiceItem::class] as $class) {
            $query = $class::whereIn('invoice_id', $invoiceIds)->orderBy('id');
            if ($currentRead) {
                $query->lockForUpdate();
            }
            $ids = array_merge($ids, $query->pluck('gateway_id')->all());
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
