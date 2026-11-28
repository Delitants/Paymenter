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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SSLStoreOrders
{
    public const QUOTE = PaidServiceLifecycle::QUOTE;

    private const KIND = 'sslstore-invite';

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
                'identity' => $this->identity($service), 'kind' => self::KIND, 'status' => 'submitted',
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

    public function confirmed(Service $service): ?ExtensionOperation
    {
        if ($service->status !== Service::STATUS_ACTIVE || MigrationHold::isHeld($service)) {
            return null;
        }

        $operation = ExtensionOperation::where([
            'identity' => $this->identity($service), 'kind' => self::KIND, 'status' => 'complete',
            'service_id' => $service->id, 'user_id' => $service->user_id, 'extension_id' => $service->product->server_id,
        ])->first();
        if (!$operation) {
            return null;
        }
        $item = InvoiceItem::with('invoice')->find($operation->invoice_item_id);
        $binding = $operation->payload['binding'];
        if (!$item || $item->reference_type !== Service::class || (int) $item->reference_id !== (int) $service->id
            || $item->invoice?->status !== Invoice::STATUS_PAID || (int) $item->invoice->user_id !== (int) $service->user_id
            || MigrationHold::isHeld($item) || $item->price !== $binding['price'] || $item->quantity !== 1
            || (int) $item->invoice_id !== $binding['invoice_id'] || $item->invoice->currency_code !== $service->currency_code
            || $service->currency_code !== $binding['terms']['currency'] || (int) $service->product_id !== $binding['terms']['product_id']
            || (int) $service->plan_id !== $binding['terms']['plan_id']) {
            return null;
        }

        return $operation;
    }

    public function open(Service $service): string
    {
        [$operation, $api] = $this->confirmedOrder($service);
        $payload = $operation->payload;
        if ($service->status !== Service::STATUS_ACTIVE || $payload['receipt']['status'] !== 'INITIAL') {
            throw new RuntimeException('Certificate enrollment is unavailable');
        }
        $receipt = $api->orderInfo($payload['custom_id'], $payload['binding']['terms'], $payload['receipt']['order_id']);
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
        $receipt = $api->orderInfo($payload['custom_id'], $payload['binding']['terms'], $payload['receipt']['order_id']);
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
        [$operation, $api] = $this->confirmedOrder($service);
        $payload = $operation->payload;
        if (in_array($payload['receipt']['status'], ['CANCELLED', 'EXPIRED'], true)) {
            throw new RuntimeException('The certificate order has ended');
        }
        $receipt = $api->orderInfo($payload['custom_id'], $payload['binding']['terms'], $payload['receipt']['order_id']);
        if ($receipt['status'] !== 'ACTIVE') {
            throw new RuntimeException('The certificate is not ready to download');
        }

        $archive = $api->download($receipt);
        $this->assertUnchangedEntitlement($service, $operation);

        return $archive;
    }

    private function confirmedOrder(Service $service): array
    {
        $operation = $this->confirmed($service);
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
        [$current] = $this->confirmedOrder($service);
        if ($current->id !== $operation->id || $current->payload !== $operation->payload) {
            throw new RuntimeException('Certificate order changed during provider lookup');
        }
    }

    private function terms(Product $product, Plan $plan, string $currency): array
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
        return hash('sha256', self::KIND . ':' . $service->id);
    }

    private function matchOperation(ExtensionOperation $operation, Service $service, InvoiceItem $item, array $binding): void
    {
        if ($operation->kind !== self::KIND || !in_array($operation->status, ['submitted', 'complete'], true)
            || (int) $operation->extension_id !== $binding['terms']['server_id'] || (int) $operation->user_id !== (int) $service->user_id
            || (int) $operation->service_id !== (int) $service->id || (int) $operation->invoice_item_id !== (int) $item->id
            || ($operation->payload['binding'] ?? null) !== $binding) {
            throw new RuntimeException('The SSLStore order claim no longer matches the paid service');
        }
    }
}
