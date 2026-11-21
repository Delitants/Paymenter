<?php

namespace App\Services\Service;

use App\Models\ExtensionOperation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reserve paid service work before dispatch, including the interval before a provider claim exists. */
final class PaidServiceLifecycle
{
    private const KIND = 'paid-service';

    public static function pending(Service $service): bool
    {
        return ExtensionOperation::where('service_id', $service->id)->where('kind', self::KIND)
            ->where('status', '!=', 'complete')->lockForUpdate()->first() !== null;
    }

    /** False means this exact paid item already completed; a callback replay must not reopen it. */
    public static function claim(Service $service, InvoiceItem $item): bool
    {
        return DB::transaction(function () use ($service, $item) {
            [$service, $item, $context] = self::current($service, $item);
            $operation = self::operation($item);
            if ($operation) {
                self::validate($operation, $service, $item, $context);

                return $operation->status !== 'complete';
            }
            if (self::pending($service)) {
                throw new RuntimeException('An earlier paid service operation requires confirmation');
            }
            ExtensionOperation::create(['identity' => self::identity($item), 'kind' => self::KIND, 'status' => 'pending',
                'extension_id' => $service->product->server_id, 'user_id' => $service->user_id,
                'service_id' => $service->id, 'invoice_item_id' => $item->id, 'payload' => $context]);

            return true;
        });
    }

    /** Registrar reconciliation calls this in the same transaction as its confirmed native expiry. */
    public static function complete(Service $service, InvoiceItem $item): void
    {
        DB::transaction(function () use ($service, $item) {
            [$service, $item, $context] = self::current($service, $item);
            $operation = self::operation($item);
            if (!$operation) {
                throw new RuntimeException('Native paid service claim is missing');
            }
            self::validate($operation, $service, $item, $context);
            $operation->update(['status' => 'complete']);
        });
    }

    private static function identity(InvoiceItem $item): string
    {
        return hash('sha256', self::KIND . ':' . $item->id);
    }

    private static function operation(InvoiceItem $item): ?ExtensionOperation
    {
        return ExtensionOperation::where('identity', self::identity($item))->lockForUpdate()->first();
    }

    private static function current(Service $service, InvoiceItem $item): array
    {
        $service = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
        $item = InvoiceItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
        $invoice = Invoice::whereKey($item->invoice_id)->lockForUpdate()->firstOrFail();
        MigrationHold::assertAllowed($service, 'paid service lifecycle');
        MigrationHold::assertAllowed($item, 'paid service lifecycle');
        if ($invoice->status !== Invoice::STATUS_PAID || $item->reference_type !== Service::class ||
            (int) $item->reference_id !== (int) $service->id || (int) $invoice->user_id !== (int) $service->user_id ||
            $invoice->currency_code !== $service->currency_code || !$service->product->server_id) {
            throw new RuntimeException('Paid service identity changed');
        }

        return [$service, $item, ['invoice_id' => $invoice->id, 'price' => (string) $item->price,
            'quantity' => (int) $item->quantity, 'currency' => $invoice->currency_code,
            'product_id' => $service->product_id, 'plan_id' => $service->plan_id]];
    }

    private static function validate(ExtensionOperation $operation, Service $service, InvoiceItem $item, array $context): void
    {
        if ($operation->kind !== self::KIND || (int) $operation->service_id !== (int) $service->id ||
            (int) $operation->invoice_item_id !== (int) $item->id || (int) $operation->user_id !== (int) $service->user_id ||
            (int) $operation->extension_id !== (int) $service->product->server_id || $operation->payload !== $context) {
            throw new RuntimeException('Native paid service claim identity changed');
        }
    }
}
