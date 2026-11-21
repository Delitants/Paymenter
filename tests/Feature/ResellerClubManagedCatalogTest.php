<?php

namespace Tests\Feature;

use App\Admin\Pages\ResellerClub\PriceSyncSettings;
use App\Console\Commands\ResellerClubSyncPrices;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\ResellerClub\CatalogSync;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;
use RuntimeException;
use Tests\TestCase;

class ResellerClubManagedCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $retail = '12', string $cost = '10', string $costCurrency = 'USD', array $changes = []): ResellerClub
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(array_replace([
            '*/products/details.json*' => Http::response(['bundle' => ['tldlist' => ['test', 'co.test']]]),
            '*/products/customer-price.json*' => Http::response(['bundle' => ['addnewdomain' => [1 => $retail, 2 => $retail], 'renewdomain' => [1 => $retail], 'addtransferdomain' => [1 => $retail]]]),
            '*/products/reseller-cost-price.json*' => Http::response(['bundle' => ['addnewdomain' => [1 => $cost], 'renewdomain' => [1 => $cost], 'addtransferdomain' => [1 => $cost]]]),
            '*/resellers/details.json*' => Http::response(['sellingcurrencysymbol' => 'USD', 'parentsellingcurrencysymbol' => $costCurrency, 'password' => 'private-account-field']),
        ], $changes));

        return new ResellerClub(['api_key' => 'synthetic-key', 'reseller_id' => '123', 'environment' => 'test']);
    }

    private function fixture(): array
    {
        $server = Server::create(['name' => 'Synthetic registrar', 'extension' => 'ResellerClub', 'type' => 'server', 'enabled' => false]);
        foreach (['api_key' => 'synthetic-key', 'reseller_id' => '123', 'environment' => 'test'] as $key => $value) {
            $server->settings()->create(compact('key', 'value'));
        }

        return [$server, Category::factory()->create()];
    }

    private function metadata(Product $product): array
    {
        return json_decode($product->settings()->where('key', CatalogSync::KEY)->value('value'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_managed_catalog_pairs_four_read_only_apis_and_discards_account_details(): void
    {
        $catalog = $this->provider()->getCatalog('managed');
        $this->assertSame('USD', $catalog['currency']);
        $this->assertSame([1 => '10'], $catalog['tlds']['.test']['cost']['register']);
        $this->assertSame(64, strlen($catalog['provider_identity']));
        $this->assertStringNotContainsString('private-account-field', json_encode($catalog));
        $this->assertStringNotContainsString('synthetic-key', json_encode($catalog));
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || str_contains($request->url(), '/domains/'));
    }

    public function test_catalog_caches_actual_privacy_eligibility_and_wholesale_cost_without_reusing_retail_price(): void
    {
        $provider = $this->provider(changes: [
            '*/products/details.json*' => Http::response(['bundle' => ['tldlist' => ['test'], 'isprivacyprotectionallowed' => 'true']]),
            '*/products/reseller-cost-price.json*' => Http::response(['bundle' => ['addnewdomain' => [1 => '10'], 'renewdomain' => [1 => '10']], 'privacy_protection' => '5.005']),
            '*/products/customer-price.json*' => Http::response(['bundle' => ['addnewdomain' => [1 => '12'], 'renewdomain' => [1 => '12']], 'privacy_protection' => '99']),
        ]);
        $quote = $provider->getCatalog('managed')['tlds']['.test'];
        $this->assertSame(['supported' => true, 'annual_cost' => '5.005', 'currency' => 'USD'], $quote['privacy']);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        [$server, $category] = $this->fixture();
        (new CatalogSync)->sync($server, $category, 'managed', '0');
        $this->assertSame(['supported' => true, 'annual_cost' => '5.005', 'currency' => 'USD'], $this->metadata(Product::where('server_id', $server->id)->sole())['prices']['privacy']);
    }

    public function test_different_retail_and_cost_currencies_are_rejected_without_implicit_conversion(): void
    {
        $extension = $this->provider(costCurrency: 'EUR');
        $this->expectException(RuntimeException::class);
        $extension->getCatalog('managed');
    }

    public function test_daily_sync_preserves_markup_and_existing_customer_amounts(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 04:00:00'));
        $this->provider();
        [$server, $category] = $this->fixture();
        foreach (['server_id' => $server->id, 'category_id' => $category->id, 'price_source' => 'managed', 'markup_percentage' => '0', 'sync_all_tlds' => '1', 'sync_enabled' => '1'] as $key => $value) {
            ResellerClubSyncPrices::saveSetting($key, $value);
        }
        $this->artisan('resellerclub:sync-prices', ['--scheduled' => true])->assertSuccessful();
        $product = Product::where('server_id', $server->id)->firstOrFail();
        $plan = $product->plans()->where('billing_period', 1)->firstOrFail();
        $service = Service::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '77.00']);
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id]);
        $before = [$service->fresh()->getRawOriginal(), $invoice->fresh()->getRawOriginal()];
        $ids = Product::where('server_id', $server->id)->pluck('id')->all();
        $this->travel(1)->days();
        $this->provider(cost: '15');
        $this->artisan('resellerclub:sync-prices', ['--scheduled' => true])->assertSuccessful();
        $this->assertSame('18.00', (string) $plan->prices()->first()->price);
        $this->assertSame($ids, Product::where('server_id', $server->id)->pluck('id')->all());
        $this->assertSame($before, [$service->fresh()->getRawOriginal(), $invoice->fresh()->getRawOriginal()]);
        $this->assertSame('36.00', $this->metadata($product)['prices']['register'][2]);
        $this->travelBack();
    }

    public function test_dry_run_does_not_capture_or_advance_policy_and_replay_is_stable(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $this->assertSame(8, $sync->sync($server, $category, 'managed', '0', true)['captured']);
        $this->assertSame(0, Product::where('server_id', $server->id)->count());
        $sync->sync($server, $category, 'managed', '0');
        $product = Product::where('server_id', $server->id)->firstOrFail();
        $before = $this->metadata($product);
        $this->provider(cost: '15');
        $this->assertSame(8, $sync->sync($server, $category, 'managed', '0', true)['raised']);
        $this->assertSame($before, $this->metadata($product));
        $sync->sync($server, $category, 'managed', '0');
        $this->assertSame(0, $sync->sync($server, $category, 'managed', '0')['raised']);
        $this->provider('16', '15');
        $this->assertSame(8, $sync->sync($server, $category, 'managed', '0')['adopted']);
        $this->assertSame('16.00', $this->metadata($product)['prices']['register'][1]);
        $this->provider('16', '10');
        $sync->sync($server, $category, 'managed', '0');
        $this->assertSame('16.00', $this->metadata($product)['prices']['register'][1]);
    }

    public function test_review_hides_product_preserves_existing_quote_and_reports_status(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        (new CatalogSync)->sync($server, $category, 'customer', '0');
        $product = Product::where('server_id', $server->id)->firstOrFail();
        $product->update(['hidden' => false, 'stock' => null]);
        $this->provider('9', '10');
        $this->artisan('resellerclub:sync-prices', ['--server' => $server->id, '--category' => $category->id, '--source' => 'managed', '--markup' => '0'])->assertSuccessful();
        $this->assertTrue((bool) $product->fresh()->hidden);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame('12.00', $this->metadata($product)['prices']['register'][1]);
        $this->assertSame('review_required', ResellerClubSyncPrices::settings()['last_status']);
        $this->assertSame('2', ResellerClubSyncPrices::settings()['last_review_count']);
    }

    public function test_corrupt_later_product_baseline_rolls_back_all_prices_even_during_dry_run(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $sync->sync($server, $category, 'managed', '0');
        $product = Product::where('server_id', $server->id)->orderByDesc('id')->firstOrFail();
        $metadata = $this->metadata($product);
        $metadata['pricing_policy']['entries']['register'][1]['cost'] = '0';
        $product->settings()->where('key', CatalogSync::KEY)->update(['value' => json_encode($metadata)]);
        $before = Product::where('server_id', $server->id)->with('settings', 'plans.prices')->get()->toJson();
        $this->provider(cost: '15');
        foreach ([true, false] as $dryRun) {
            $rejected = false;
            try {
                $sync->sync($server, $category, 'managed', '0', $dryRun);
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Corrupt policy accepted');
            $this->assertSame($before, Product::where('server_id', $server->id)->with('settings', 'plans.prices')->get()->toJson());
        }
    }

    public function test_managed_mode_rejects_extra_local_markup_before_any_provider_request(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $rejected = false;
        try {
            (new CatalogSync)->sync($server, $category, 'managed', '5');
        } catch (RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Extra markup accepted');
        Http::assertNothingSent();
        $this->assertSame(0, Product::where('server_id', $server->id)->count());
    }

    public function test_native_settings_clear_local_markup_when_managed_policy_is_selected(): void
    {
        [$server, $category] = $this->fixture();
        $this->actingAs(User::factory()->create(['role_id' => 1]));
        Livewire::test(PriceSyncSettings::class)->fillForm(['server_id' => $server->id, 'category_id' => $category->id, 'price_source' => 'managed', 'markup_percentage' => '50'])->call('save')->assertHasNoFormErrors();
        $saved = ResellerClubSyncPrices::settings();
        $this->assertSame('managed', $saved['price_source']);
        $this->assertSame('0', $saved['markup_percentage']);
    }

    public function test_switching_price_source_does_not_discard_previous_managed_policy(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $sync->sync($server, $category, 'managed', '0');
        $product = Product::where('server_id', $server->id)->firstOrFail();
        $before = $this->metadata($product)['pricing_policy'];
        $sync->sync($server, $category, 'customer', '0');
        $this->assertSame($before, $this->metadata($product)['pricing_policy']);
        $this->provider(cost: '15');
        $sync->sync($server, $category, 'managed', '0');
        $this->assertSame('18.00', $this->metadata($product)['prices']['register'][1]);
    }

    public function test_missing_wholesale_product_holds_its_quote_while_valid_tld_still_syncs(): void
    {
        $products = ['bundle' => ['tldlist' => ['co.test']], 'valid' => ['tldlist' => ['test']]];
        $retail = ['bundle' => ['addnewdomain' => [1 => '12']], 'valid' => ['addnewdomain' => [1 => '16']]];
        $cost = ['bundle' => ['addnewdomain' => [1 => '10']], 'valid' => ['addnewdomain' => [1 => '10']]];
        $changes = ['*/products/details.json*' => Http::response($products),
            '*/products/customer-price.json*' => Http::response($retail),
            '*/products/reseller-cost-price.json*' => Http::response($cost)];
        $this->provider(changes: $changes);
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $sync->sync($server, $category, 'managed', '0');
        $held = Product::where('server_id', $server->id)->where('name', 'Domain .co.test')->firstOrFail();
        $held->update(['hidden' => false, 'stock' => null]);
        $changes['*/products/reseller-cost-price.json*'] = Http::response(['valid' => ['addnewdomain' => [1 => '11']]]);
        $this->provider(changes: $changes);
        $result = $sync->sync($server, $category, 'managed', '0');
        $this->assertSame(1, $result['review_tlds']);
        $this->assertSame('12.00', $this->metadata($held)['prices']['register'][1]);
        $this->assertTrue((bool) $held->fresh()->hidden);
        $this->assertSame(0, $held->fresh()->stock);
        $valid = Product::where('server_id', $server->id)->where('name', 'Domain .test')->firstOrFail();
        $this->assertSame('17.60', $this->metadata($valid)['prices']['register'][1]);
    }

    public function test_currency_or_product_drift_before_first_capture_is_rejected_atomically(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $sync->sync($server, $category, 'customer', '0');
        $product = Product::where('server_id', $server->id)->firstOrFail();
        $original = $this->metadata($product);
        foreach (['currency', 'product_key'] as $key) {
            $metadata = $original;
            if ($key === 'currency') {
                $metadata['currency'] = 'EUR';
            } else {
                $metadata['prices']['product_key'] = 'other';
            }
            $product->settings()->where('key', CatalogSync::KEY)->update(['value' => json_encode($metadata)]);
            $before = $this->metadata($product);
            $this->provider('9', '10');
            $rejected = false;
            try {
                $sync->sync($server, $category, 'managed', '0');
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Changed saved quote identity accepted');
            $this->assertSame($before, $this->metadata($product));
        }
    }

    public function test_missing_or_null_persisted_managed_policy_cannot_recapture_and_lower_price(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $sync->sync($server, $category, 'managed', '0');
        $this->provider(cost: '15');
        $sync->sync($server, $category, 'managed', '0');
        $product = Product::where('server_id', $server->id)->firstOrFail();
        $original = $this->metadata($product);
        $this->provider(cost: '10');
        foreach ([false, true] as $nullPolicy) {
            $metadata = $original;
            unset($metadata['pricing_policy']);
            if ($nullPolicy) {
                $metadata['pricing_policy'] = null;
            }
            $product->settings()->where('key', CatalogSync::KEY)->update(['value' => json_encode($metadata)]);
            foreach ([true, false] as $dryRun) {
                $rejected = false;
                try {
                    $sync->sync($server, $category, 'managed', '0', $dryRun);
                } catch (RuntimeException) {
                    $rejected = true;
                }
                $this->assertTrue($rejected, 'Lost managed policy accepted');
                $this->assertSame('18.00', (string) $product->plans()->where('billing_period', 1)->first()->prices()->first()->price);
                $this->assertSame($metadata, $this->metadata($product));
            }
        }
    }
}
