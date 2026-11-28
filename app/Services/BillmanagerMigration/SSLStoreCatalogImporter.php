<?php

namespace App\Services\BillmanagerMigration;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Server;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SSLStoreCatalogImporter
{
    private const MONTHS = [12, 24, 36];

    public function stage(array $entries, int $serverId, int $categoryId): array
    {
        $entries = $this->validate($entries);

        return DB::transaction(function () use ($entries, $serverId, $categoryId) {
            $server = Server::whereKey($serverId)->lockForUpdate()->first();
            if (!$server || $server->extension !== 'SSLStore' || $server->enabled) {
                throw new DomainException('The target must be a disabled SSLStore server.');
            }
            if (!Category::whereKey($categoryId)->lockForUpdate()->first()) {
                throw new DomainException('The target category does not exist.');
            }
            $currencies = array_unique(array_column($entries, 'currency'));
            if (Currency::whereKey($currencies)->count() !== count($currencies)) {
                throw new DomainException('The target is missing a catalog currency.');
            }

            $created = 0;
            foreach ($entries as $entry) {
                $slug = 'billmanager-sslstore-' . $serverId . '-' . $entry['source_plan_id'];
                $settings = [
                    'billmanager_sslstore_catalog_key' => $slug,
                    'billmanager_sslstore_source_code' => $entry['code'],
                    'product_code' => $entry['supplier_product_code'],
                ];
                $attributes = [
                    'slug' => $slug, 'name' => $entry['name'], 'server_id' => $serverId, 'category_id' => $categoryId,
                    'stock' => 0, 'hidden' => true, 'allow_quantity' => 'disabled',
                ];
                $products = Product::where(function ($query) use ($slug, $serverId, $entry) {
                    $query->where('slug', $slug)
                        ->orWhereHas('settings', fn ($settings) => $settings->where('key', 'billmanager_sslstore_catalog_key')->where('value', $slug))
                        ->orWhere(fn ($sameSku) => $sameSku->where('server_id', $serverId)
                            ->whereHas('settings', fn ($settings) => $settings->where('key', 'product_code')->where('value', $entry['supplier_product_code'])));
                })->lockForUpdate()->get();
                if ($products->count() > 1) {
                    throw new DomainException('Multiple products claim a certificate catalog entry.');
                }
                $product = $products->first();
                if (!$product) {
                    $product = Product::create($attributes);
                    foreach ($settings as $key => $value) {
                        $product->settings()->create(['key' => $key, 'value' => $value]);
                    }
                    foreach ($entry['prices'] as $months => $price) {
                        $plan = $product->plans()->create([
                            'name' => $months . ' months', 'type' => 'recurring', 'billing_unit' => 'month',
                            'billing_period' => $months, 'sort' => $months,
                        ]);
                        $plan->prices()->create(['currency_code' => $entry['currency'], 'price' => $price, 'setup_fee' => $entry['setup']]);
                    }
                    $created++;
                }
                $this->assertStaged($product, $attributes, $settings, $entry);
            }

            return ['created' => $created, 'already_staged' => count($entries) - $created, 'prices' => count($entries) * count(self::MONTHS)];
        });
    }

    private function validate(array $entries): array
    {
        if (!$entries || !array_is_list($entries)) {
            throw new DomainException('The reviewed certificate catalog must contain a list of plans.');
        }
        foreach ($entries as &$entry) {
            if (!is_array($entry) || ($entry['provider_catalog_state'] ?? null) !== 'mapped'
                || !is_int($entry['source_plan_id'] ?? null) || $entry['source_plan_id'] < 1
                || !is_string($entry['name'] ?? null) || trim($entry['name']) === '' || mb_strlen($entry['name']) > 255
                || !is_string($entry['currency'] ?? null) || !preg_match('/\A[A-Z]{3}\z/', $entry['currency'])) {
                throw new DomainException('A reviewed certificate entry is invalid.');
            }
            foreach (['code', 'supplier_product_code'] as $field) {
                if (!is_string($entry[$field] ?? null) || !preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,63}\z/i', $entry[$field])) {
                    throw new DomainException('A certificate entry is missing a valid product code.');
                }
            }
            $prices = $entry['prices'] ?? null;
            if (!is_array($prices)) {
                throw new DomainException('A certificate entry is missing its term prices.');
            }
            ksort($prices);
            if (array_keys($prices) !== self::MONTHS) {
                throw new DomainException('A certificate entry must have 12-, 24-, and 36-month prices.');
            }
            $entry['setup'] = $this->money($entry['setup'] ?? '0');
            $entry['prices'] = array_map(fn ($price) => $this->money($price), $prices);
            if (in_array('0.00', $entry['prices'], true)) {
                throw new DomainException('Certificate term prices must be positive.');
            }
        }
        unset($entry);
        foreach (['source_plan_id', 'code', 'supplier_product_code'] as $field) {
            $keys = array_map(fn ($value) => strtolower((string) $value), array_column($entries, $field));
            if (count(array_unique($keys)) !== count($entries)) {
                throw new DomainException('The reviewed certificate catalog contains duplicate identities.');
            }
        }

        return $entries;
    }

    private function money(mixed $amount): string
    {
        // The source exports four decimal places, while Paymenter stores two.
        if (!is_string($amount) || !preg_match('/\A(0|[1-9][0-9]{0,14})(?:\.([0-9]{1,2})0{0,2})?\z/', $amount, $parts)) {
            throw new DomainException('A certificate price cannot be stored exactly in Paymenter.');
        }

        return $parts[1] . '.' . str_pad($parts[2] ?? '', 2, '0');
    }

    private function assertStaged(Product $product, array $attributes, array $settings, array $entry): void
    {
        if ($product->slug !== $attributes['slug'] || $product->name !== $attributes['name']
            || (int) $product->server_id !== $attributes['server_id'] || (int) $product->category_id !== $attributes['category_id']
            || $product->stock === null || (int) $product->stock !== 0 || !$product->hidden
            || $product->allow_quantity !== 'disabled' || $product->services()->exists()) {
            throw new DomainException('A matching certificate product differs from the reviewed staging configuration.');
        }
        $stored = $product->settings()->whereIn('key', array_keys($settings))->get();
        $storedValues = $stored->pluck('value', 'key')->all();
        ksort($storedValues);
        ksort($settings);
        if ($stored->count() !== count($settings) || $storedValues !== $settings) {
            throw new DomainException('A matching certificate product has different provider details.');
        }
        $plans = $product->plans()->with('prices')->get();
        if ($plans->pluck('billing_period')->all() !== self::MONTHS) {
            throw new DomainException('A matching certificate product has different terms.');
        }
        foreach ($plans as $plan) {
            $price = $plan->prices->count() === 1 ? $plan->prices->first() : null;
            if ($plan->type !== 'recurring' || $plan->billing_unit !== 'month' || $plan->price_includes_tax !== null
                || !$price || $price->currency_code !== $entry['currency']
                || $this->money((string) $price->price) !== $entry['prices'][$plan->billing_period]
                || $this->money((string) $price->setup_fee) !== $entry['setup']) {
                throw new DomainException('A matching certificate product has different pricing.');
            }
        }
    }
}
