<?php

namespace App\Services\BillmanagerMigration;

use App\Models\BillmanagerServiceDetail;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ServiceImporter
{
    public function import(Snapshot $snapshot, ImportContext $context): ImportReport
    {
        if ($snapshot->sourceHost() !== $context->sourceHost) {
            throw new RuntimeException('Snapshot source does not match import context');
        }
        $timezone = $snapshot->sourceTimezone() ?? config('billmanager-migration.source_timezone');
        if (!$timezone || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException('An explicit source timezone is required for historical timestamps');
        }
        $date = static fn ($value) => !$value || str_starts_with($value, '0000-') ? null : Carbon::parse($value, $timezone)->utc()->format('Y-m-d H:i:s');

        return DB::transaction(function () use ($snapshot, $context, $date) {
            $catalog = array_column($snapshot->rows('pricelists'), null, 'id');
            $currencies = array_column($snapshot->rows('currencies'), 'iso', 'id');
            $counts = ['services' => 0, 'active' => 0, 'ordered' => 0, 'processing' => 0, 'suspended' => 0, 'fractional_cent' => 0, 'expense_matched' => 0];
            $categoryId = $context->mappedId('service_category', 'imported');
            if ($categoryId === null) {
                $categoryId = DB::table('categories')->insertGetId([
                    'name' => 'Imported services', 'slug' => 'imported-' . substr(hash('sha256', $context->sourceHost), 0, 16),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $context->recordMapping('service_category', 'imported', 'categories', $categoryId);
            }
            $accounts = [];
            foreach ($snapshot->rows('items') as $row) {
                $owner = DB::table('billmanager_accounts')->where('id', $context->mappedId('accounts', (string) $row['account']))->value('owner_user_id');
                if (!$owner) {
                    throw new RuntimeException('Service account has no imported owner');
                }
                $currency = $currencies[$row['currency']] ?? null;
                if (!$currency || !Currency::where('code', $currency)->exists()) {
                    throw new RuntimeException('Service currency is not configured');
                }
                $sourceStatus = match ((string) $row['status']) {
                    '1' => 'ordered', '2' => 'active', '3' => 'suspended', '5' => 'processing',
                    default => throw new RuntimeException('Unsupported source service status'),
                };
                $price = (new PriceReconciler)->resolve($row, $snapshot);
                $addons = array_values(array_filter($snapshot->rows('addons'), fn ($a) => (string) $a['parent'] === (string) $row['id']));
                $parameters = array_values(array_filter($snapshot->rows('itemparams'), fn ($p) => (string) $p['item'] === (string) $row['id']));
                $details = ['original' => $row, 'price' => $price, 'addons' => $addons, 'parameters' => $parameters];
                $expected = [
                    'user_id' => (int) $owner, 'currency_code' => $currency, 'quantity' => 1,
                    'price' => $price['native_amount'], 'status' => in_array($sourceStatus, ['ordered', 'processing'], true) ? 'pending' : $sourceStatus,
                    'expires_at' => $date($row['expiredate'] ?? null),
                ];
                $id = $context->mappedId('items', (string) $row['id']);
                if ($id === null) {
                    $productKey = $row['pricelist'] . ':' . ($row['processingmodule'] ?? 'manual');
                    $productId = $context->mappedId('service_products', $productKey);
                    if ($productId === null) {
                        $productId = DB::table('products')->insertGetId([
                            'category_id' => $categoryId, 'name' => $catalog[$row['pricelist']]['name'] ?? throw new RuntimeException('Missing service catalog row'),
                            'hidden' => true, 'stock' => 0, 'server_id' => null, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $context->recordMapping('service_products', $productKey, 'products', $productId);
                    }
                    // Per-service plans retain negotiated totals without changing
                    // another client's price or enabling a public orderable plan.
                    $planId = DB::table('plans')->insertGetId([
                        'name' => 'Imported renewal', 'priceable_type' => Product::class, 'priceable_id' => $productId,
                        'type' => $price['type'], 'billing_period' => $price['billing_period'], 'billing_unit' => $price['billing_unit'],
                    ]);
                    DB::table('prices')->insert(['plan_id' => $planId, 'currency_code' => $currency, 'price' => $price['native_amount'], 'setup_fee' => '0.00']);
                    $orderId = DB::table('orders')->insertGetId(['user_id' => $owner, 'currency_code' => $currency, 'created_at' => $date($row['createdate']), 'updated_at' => $date($row['createdate'])]);
                    $id = DB::table('services')->insertGetId($expected + [
                        'product_id' => $productId, 'plan_id' => $planId, 'order_id' => $orderId,
                        'label' => $row['name'] ?? null, 'created_at' => $date($row['createdate']), 'updated_at' => $date($row['updatedate'] ?? $row['createdate']),
                    ]);
                    $context->recordMapping('items', (string) $row['id'], 'services', $id);
                    DB::table('billmanager_service_details')->insert([
                        'import_id' => $context->importId, 'service_id' => $id, 'source_id' => (string) $row['id'], 'source_status' => $sourceStatus,
                        'details' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                    ]);
                    DB::table('billmanager_holds')->insert(['model_type' => Service::class, 'model_id' => $id, 'reason' => 'Awaiting pricing and provider handover']);
                } else {
                    $native = DB::table('services')->where('id', $id)->first();
                    $metadata = BillmanagerServiceDetail::where('service_id', $id)->first();
                    if (!$native || !$metadata || $metadata->source_status !== $sourceStatus || $metadata->details !== $details) {
                        throw new RuntimeException('Mapped service changed; explicit reconciliation required');
                    }
                    foreach ($expected as $key => $value) {
                        if ($value != $native->$key) {
                            throw new RuntimeException('Mapped service changed; explicit reconciliation required');
                        }
                    }
                    $plan = DB::table('plans')->where('id', $native->plan_id)->first();
                    $nativePrices = DB::table('prices')->where('plan_id', $native->plan_id)->get();
                    if (!$plan || $plan->priceable_type !== Product::class || $plan->priceable_id != $native->product_id ||
                        $plan->type !== $price['type'] || $plan->billing_period != $price['billing_period'] || $plan->billing_unit !== $price['billing_unit'] ||
                        $nativePrices->count() !== 1 || $nativePrices[0]->currency_code !== $currency || $nativePrices[0]->price !== $price['native_amount'] || $nativePrices[0]->setup_fee !== '0.00') {
                        throw new RuntimeException('Mapped service plan changed; explicit reconciliation required');
                    }
                    $productKey = $row['pricelist'] . ':' . ($row['processingmodule'] ?? 'manual');
                    if ($native->product_id != $context->mappedId('service_products', $productKey) ||
                        !DB::table('products')->where('id', $native->product_id)->where('hidden', true)->where('stock', 0)->exists() ||
                        !DB::table('orders')->where('id', $native->order_id)->where('user_id', $owner)->where('currency_code', $currency)->exists()) {
                        throw new RuntimeException('Mapped service catalog or order changed; explicit reconciliation required');
                    }
                }
                $accounts[$row['id']] = (int) $row['account'];
                $context->archive('items', (string) $row['id'], $row, (int) $row['account']);
                $counts['services']++;
                $counts[$sourceStatus]++;
                $counts['fractional_cent'] += in_array('fractional_cent', $price['review_reasons'], true) ? 1 : 0;
                $counts['expense_matched'] += $price['expense_comparison'] === 'matched' ? 1 : 0;
            }
            foreach (['addons', 'itemparams', 'pricelists', 'prices', 'pricelistprices', 'fixedprices', 'fixedpricesprice', 'itemtypes', 'discounts', 'discountprices'] as $table) {
                $occurrences = [];
                foreach ($snapshot->rows($table) as $row) {
                    $account = match ($table) {
                        'addons' => $accounts[$row['parent']] ?? throw new RuntimeException('Addon parent was not imported'),
                        'itemparams' => $accounts[$row['item']] ?? throw new RuntimeException('Parameter parent was not imported'),
                        'discounts' => isset($row['account']) ? (int) $row['account'] : null,
                        default => null,
                    };
                    $hash = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                    $occurrences[$hash] = ($occurrences[$hash] ?? 0) + 1;
                    $context->archive($table, (string) ($row['id'] ?? $hash . ':' . $occurrences[$hash]), $row, $account);
                }
            }
            // Tickets were imported before service mappings existed. Link only
            // mapped tickets and verify both records belong to the same owner.
            foreach ($snapshot->rows('tickets') as $ticket) {
                $serviceId = empty($ticket['item']) ? null : $context->mappedId('items', (string) $ticket['item']);
                $ticketId = $context->mappedId('tickets', (string) $ticket['id']);
                if ($serviceId && $ticketId) {
                    $owner = DB::table('services')->where('id', $serviceId)->value('user_id');
                    $nativeTicket = DB::table('tickets')->where('id', $ticketId)->first();
                    if (!$nativeTicket || $nativeTicket->user_id != $owner || ($nativeTicket->service_id !== null && $nativeTicket->service_id != $serviceId)) {
                        throw new RuntimeException('Ticket service ownership conflicts');
                    }
                    DB::table('tickets')->where('id', $ticketId)->update(['service_id' => $serviceId]);
                }
            }

            return new ImportReport('services_imported_held', $counts);
        });
    }
}
