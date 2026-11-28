<?php

namespace Paymenter\Extensions\Servers\SSLStore;

use App\Helpers\ExtensionHelper;
use App\Models\ExtensionOperation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Service\PaidServiceLifecycle;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SSLStoreOrders
{
    public const QUOTE = PaidServiceLifecycle::QUOTE;

    public const INVITATION = 'sslstore-invite';

    public const RENEWAL = 'sslstore-renewal';

    public function quote(Product $product, Plan $plan, string $currency, bool $checkStock = true): array
    {
        if ($product->hidden || ($checkStock && $product->stock !== null && (int) $product->stock < 1)) {
            throw new RuntimeException('This certificate is unavailable for purchase');
        }
        [$api, $terms] = $this->terms($product, $plan, $currency);
        if (!$api->ordersEnabled()) {
            throw new RuntimeException('New SSLStore orders are disabled');
        }

        return [$api, $terms];
    }

    public function fulfill(Service $service, InvoiceItem $item): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('SSLStore ordering must run after the invoice transaction commits');
        }
        [$operation, $api, $submit] = DB::transaction(function () use ($service, $item) {
            [$service, $api, $binding] = $this->paidContext($service, $item);
            $operation = ExtensionOperation::where('identity', $this->identity($service))->lockForUpdate()->first();
            if ($operation) {
                $this->matchOperation($operation, $service, $item, $binding);

                return [$operation, $api, false];
            }
            if ($service->status !== Service::STATUS_PENDING || !$api->ordersEnabled() || $service->product->hidden) {
                throw new RuntimeException('SSLStore can purchase only an enabled, pending certificate order');
            }
            $email = $service->user()->value('email');
            if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A valid certificate requester email is required');
            }
            $operation = ExtensionOperation::create([
                'identity' => $this->identity($service), 'kind' => self::INVITATION, 'status' => 'submitted',
                'extension_id' => $binding['terms']['server_id'], 'user_id' => $service->user_id,
                'service_id' => $service->id, 'invoice_item_id' => $item->id,
                'payload' => [
                    'binding' => $binding, 'custom_id' => 'paymenter-' . Str::lower((string) Str::ulid()), 'email' => $email,
                    'due_at' => $service->calculateNextDueDate()->toDateTimeString(),
                ],
            ]);

            return [$operation, $api, true];
        }, attempts: 3);
        if ($operation->status === 'complete') {
            return;
        }

        // A committed claim survives an ambiguous provider response. Replays only look it up.
        $payload = $operation->payload;
        $terms = $payload['binding']['terms'];
        $receipt = $submit
            ? $api->invite($payload['custom_id'], $terms, $payload['email'])
            : $api->orderInfo($payload['custom_id'], $terms);
        if (in_array($receipt['status'], ['CANCELLED', 'EXPIRED'], true)) {
            throw new RuntimeException('The SSLStore order requires review before activation');
        }
        DB::transaction(function () use ($service, $item, $operation, $receipt) {
            [$service, , $binding] = $this->paidContext($service, $item);
            $current = ExtensionOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $this->matchOperation($current, $service, $item, $binding);
            $payload = $current->payload;
            if ($current->status === 'complete') {
                if (($payload['receipt']['order_id'] ?? null) !== $receipt['order_id']) {
                    throw new RuntimeException('SSLStore order identity changed');
                }

                return;
            }
            if ($service->status !== Service::STATUS_PENDING) {
                throw new RuntimeException('Certificate service changed while the order was pending');
            }
            $service->forceFill(['expires_at' => $payload['due_at'], 'status' => Service::STATUS_ACTIVE])->save();
            unset($payload['email']);
            $current->update(['status' => 'complete', 'payload' => $payload + ['receipt' => $receipt]]);
            PaidServiceLifecycle::complete($service, $item);
        }, attempts: 3);
    }

    public function confirmed(Service $service, bool $allowSuspended = false): ?ExtensionOperation
    {
        return $this->entitlements($service, $allowSuspended)->first();
    }

    public function hasPreviousCertificate(Service $service): bool
    {
        return $this->entitlements($service)->skip(1)->contains(
            fn (ExtensionOperation $operation) => !in_array($operation->payload['receipt']['status'], ['EXPIRED', 'CANCELLED'], true),
        );
    }

    private function entitlements(Service $service, bool $allowSuspended = false, ?int $expectedId = null, ?int $lockedInvoiceId = null): Collection
    {
        if (($service->status !== Service::STATUS_ACTIVE && !($allowSuspended && $service->status === Service::STATUS_SUSPENDED))
            || MigrationHold::isHeld($service, currentRead: $lockedInvoiceId !== null)) {
            return new Collection;
        }

        return ExtensionOperation::where([
            'status' => 'complete',
            'service_id' => $service->id, 'user_id' => $service->user_id, 'extension_id' => $service->product->server_id,
        ])->whereIn('kind', [self::INVITATION, self::RENEWAL])
            ->when($expectedId !== null, fn ($query) => $query->whereKey($expectedId))
            ->when($lockedInvoiceId !== null, fn ($query) => $query->lockForUpdate())
            ->latest('id')->get()
            ->filter(fn (ExtensionOperation $operation) => $this->entitled($service, $operation, $lockedInvoiceId));
    }

    private function entitled(Service $service, ExtensionOperation $operation, ?int $lockedInvoiceId): bool
    {
        $currentRead = $lockedInvoiceId !== null;
        $item = InvoiceItem::whereKey($operation->invoice_item_id)->when($currentRead, fn ($query) => $query->lockForUpdate())->first();
        $binding = $operation->payload['binding'];
        if (!$item || (int) $item->invoice_id !== $binding['invoice_id']
            || ($currentRead && (int) $item->invoice_id !== $lockedInvoiceId)) {
            return false;
        }
        $invoice = $item->invoice()->when($currentRead, fn ($query) => $query->lockForUpdate())->first();
        if ($item->reference_type !== Service::class || (int) $item->reference_id !== (int) $service->id
            || $invoice?->status !== Invoice::STATUS_PAID || (int) $invoice->user_id !== (int) $service->user_id
            || MigrationHold::isHeld($item, currentRead: $currentRead) || $item->price !== $binding['price'] || $item->quantity !== 1
            || $invoice->currency_code !== $service->currency_code
            || $service->currency_code !== $binding['terms']['currency'] || (int) $service->product_id !== $binding['terms']['product_id']
            || (int) $service->plan_id !== $binding['terms']['plan_id']) {
            return false;
        }

        return true;
    }

    public function open(Service $service): string
    {
        [$operation, $api] = $this->confirmedOrder($service);
        $payload = $operation->payload;
        if ($service->status !== Service::STATUS_ACTIVE || $payload['receipt']['status'] !== 'INITIAL') {
            throw new RuntimeException('Certificate enrollment is unavailable');
        }
        $receipt = $this->supplierReceipt($api, $operation);
        if ($receipt['status'] !== 'INITIAL') {
            throw new RuntimeException('SSLStore has already processed the enrollment link');
        }
        $this->assertUnchangedEntitlement($service, $operation);

        return $api->enrollmentLink($receipt['link']);
    }

    public function refresh(Service $service): void
    {
        [$operation, $api] = $this->confirmedOrder($service);
        $payload = $operation->payload;
        $receipt = $this->supplierReceipt($api, $operation);
        DB::transaction(function () use ($operation, $service, $payload, $receipt) {
            $currentService = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $current = ExtensionOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            MigrationHold::assertAllowed($currentService, 'SSLStore status', currentRead: true);
            $this->assertUnchangedEntitlement($currentService, $operation);
            // A cached enrollment response must never reopen a completed enrollment link.
            $previous = $payload['receipt']['status'];
            if (!in_array($previous, ['EXPIRED', 'CANCELLED'], true)
                && ($previous === 'INITIAL' || $receipt['status'] !== 'INITIAL')
                && ($previous !== 'ACTIVE' || $receipt['status'] !== 'PENDING')) {
                $payload['receipt'] = $receipt;
                $current->update(['payload' => $payload]);
            }
        });
    }

    public function download(Service $service): string
    {
        foreach ($this->entitlements($service)->take(2) as $operation) {
            if (in_array($operation->payload['receipt']['status'], ['CANCELLED', 'EXPIRED'], true)) {
                continue;
            }
            [, $api] = $this->confirmedOrder($service, expected: $operation);
            try {
                $receipt = $this->supplierReceipt($api, $operation);
                if ($receipt['status'] !== 'ACTIVE') {
                    continue;
                }
                $archive = $api->download($receipt);
            } catch (RuntimeException) {
                // A failed renewal lookup must leave the previous certificate available.
                continue;
            }
            $this->assertUnchangedEntitlement($service, $operation);

            return $archive;
        }

        throw new RuntimeException('An issued certificate is not ready to download');
    }

    public function confirmedOrder(Service $service, bool $allowSuspended = false, ?ExtensionOperation $expected = null, ?int $lockedInvoiceId = null): array
    {
        $operation = $this->entitlements($service, $allowSuspended, $expected?->id, $lockedInvoiceId)->first();
        $server = $service->product->server;
        if (!$operation || !$server?->enabled || $server->extension !== 'SSLStore') {
            throw new RuntimeException('Certificate order is unavailable');
        }
        $api = new SSLStoreApi(ExtensionHelper::settingsToArray($server->settings));
        if ($operation->payload['binding']['terms']['merchant'] !== $api->merchant()) {
            throw new RuntimeException('Certificate provider changed');
        }

        return [$operation, $api];
    }

    private function assertUnchangedEntitlement(Service $service, ExtensionOperation $operation): void
    {
        $service = $service->fresh() ?? throw new RuntimeException('Certificate service was removed');
        [$current] = $this->confirmedOrder($service, expected: $operation);
        if ($current->id !== $operation->id || $current->payload !== $operation->payload) {
            throw new RuntimeException('Certificate order changed during provider lookup');
        }
    }

    private function supplierReceipt(SSLStoreApi $api, ExtensionOperation $operation): array
    {
        $payload = $operation->payload;

        return $api->orderInfo($payload['custom_id'], $payload['binding']['terms'], $payload['receipt']['order_id'],
            invitation: $operation->kind === self::INVITATION, parent: $payload['parent'] ?? null);
    }

    public function terms(Product $product, Plan $plan, string $currency): array
    {
        $server = $product->server;
        $currentRead = DB::transactionLevel() > 0;
        $codes = $product->settings()->where('key', 'product_code')->when($currentRead, fn ($query) => $query->lockForUpdate())->pluck('value');
        $code = $codes->count() === 1 ? $codes->first() : null;
        if (!$server?->enabled || $server->extension !== 'SSLStore' || !is_string($code)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/', $code)
            || $plan->priceable_type !== $product->getMorphClass() || (int) $plan->priceable_id !== (int) $product->id
            || $plan->type !== 'recurring' || $plan->billing_unit !== 'month' || !in_array($plan->billing_period, [12, 24, 36], true)
            || !$plan->prices()->where('currency_code', $currency)->where('price', '>', 0)
                ->when($currentRead, fn ($query) => $query->lockForUpdate())->first()) {
            throw new RuntimeException('Certificate product or billing term is unavailable');
        }
        $api = new SSLStoreApi(ExtensionHelper::settingsToArray($server->settings));

        return [$api, [
            'server_id' => (int) $server->id, 'extension' => $server->extension, 'merchant' => $api->merchant(), 'product_code' => $code,
            'months' => $plan->billing_period, 'currency' => $currency,
            'product_id' => (int) $product->id, 'plan_id' => (int) $plan->id,
        ]];
    }

    private function paidContext(Service $service, InvoiceItem $item): array
    {
        $invoice = Invoice::whereKey($item->invoice_id)->lockForUpdate()->firstOrFail();
        $service = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
        $item = InvoiceItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
        MigrationHold::assertAllowed($service, 'SSLStore order', currentRead: true);
        MigrationHold::assertAllowed($item, 'SSLStore order', currentRead: true);
        if ((int) $item->invoice_id !== (int) $invoice->id || $invoice->status !== Invoice::STATUS_PAID || $invoice->currency_code !== $service->currency_code
            || (int) $invoice->user_id !== (int) $service->user_id || $item->reference_type !== Service::class
            || (int) $item->reference_id !== (int) $service->id || (int) $service->quantity !== 1
            || $item->quantity !== 1 || !BigDecimal::of($item->price)->isPositive()) {
            throw new RuntimeException('SSLStore requires the matching paid certificate invoice item');
        }
        $product = $service->product()->lockForUpdate()->firstOrFail();
        $server = $product->server()->lockForUpdate()->first();
        $server?->setRelation('settings', $server->settings()->lockForUpdate()->get());
        $product->setRelation('server', $server);
        $service->setRelation('product', $product);
        $service->setRelation('plan', $service->plan()->lockForUpdate()->firstOrFail());
        [$api, $terms] = $this->terms($product, $service->plan, $invoice->currency_code);
        $properties = $service->properties()->where('key', self::QUOTE)->lockForUpdate()->pluck('value');
        $quoted = $properties->count() === 1 ? json_decode((string) $properties->first(), true) : null;
        if (!is_array($quoted) || ($quoted['line_price'] ?? null) !== $item->price
            || array_diff_key($quoted, ['line_price' => true, 'cart_item_id' => true]) !== $terms) {
            throw new RuntimeException('The paid certificate terms no longer match the checkout quote');
        }

        return [$service, $api, ['terms' => $terms, 'invoice_id' => (int) $invoice->id, 'price' => $item->price]];
    }

    private function identity(Service $service): string
    {
        return hash('sha256', self::INVITATION . ':' . $service->id);
    }

    private function matchOperation(ExtensionOperation $operation, Service $service, InvoiceItem $item, array $binding): void
    {
        if ($operation->kind !== self::INVITATION || !in_array($operation->status, ['submitted', 'complete'], true)
            || (int) $operation->extension_id !== $binding['terms']['server_id'] || (int) $operation->user_id !== (int) $service->user_id
            || (int) $operation->service_id !== (int) $service->id || (int) $operation->invoice_item_id !== (int) $item->id
            || ($operation->payload['binding'] ?? null) !== $binding) {
            throw new RuntimeException('The SSLStore order claim no longer matches the paid service');
        }
    }
}
