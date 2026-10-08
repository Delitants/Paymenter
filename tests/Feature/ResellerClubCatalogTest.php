<?php

namespace Tests\Feature;

use App\Admin\Pages\ResellerClub\PriceSyncSettings;
use App\Console\Commands\ResellerClubSyncPrices;
use App\Models\Category;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\ResellerClub\CatalogSync;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;
use Paymenter\Extensions\Servers\ResellerClub\SyncSchedule;
use Tests\TestCase;

class ResellerClubCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_pins_provider_identity_and_uses_recurring_plans_without_changing_used_plans(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $sync->sync($server, $category, 'customer', '0');
        $product = Product::where('server_id', $server->id)->firstOrFail();
        $metadata = json_decode($product->settings()->where('key', CatalogSync::KEY)->value('value'), true);
        $this->assertSame(hash('sha256', 'test:123'), $metadata['provider_identity'] ?? null);
        $plan = $product->plans()->firstOrFail();
        $this->assertSame('recurring', $plan->type);
        $plan->update(['type' => 'one-time']);
        $service = Service::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '77.00']);
        $before = $service->fresh()->getRawOriginal();
        $sync->sync($server, $category, 'customer', '0');
        $this->assertSame('one-time', $plan->fresh()->type);
        $this->assertSame($before, $service->fresh()->getRawOriginal());
    }

    private function provider(array $changes = []): ResellerClub
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $prices = ['bundle' => ['addnewdomain' => ['1' => '10.005', '2' => '9.50'], 'renewdomain' => ['1' => '14.00'], 'addtransferdomain' => ['1' => '11.00']]];
        Http::fake(array_replace([
            '*/products/details.json*' => Http::response(['bundle' => ['tldlist' => ['test', 'co.test'], 'minregistrationyear' => '1', 'maxregistrationyear' => '10'], 'hosting' => ['name' => 'Hosting']]),
            '*/products/customer-price.json*' => Http::response($prices),
            '*/products/reseller-cost-price.json*' => Http::response($prices),
            '*/resellers/details.json*' => Http::response(['sellingcurrencysymbol' => 'USD', 'parentsellingcurrencysymbol' => 'EUR', 'password' => 'never-return-this']),
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

    public function test_catalog_uses_active_tlds_including_compound_suffixes_without_any_client_domains(): void
    {
        $catalog = $this->provider()->getCatalog();
        $this->assertSame('USD', $catalog['currency']);
        $this->assertSame(['.co.test', '.test'], array_keys($catalog['tlds']));
        $this->assertSame('10.005', $catalog['tlds']['.test']['register'][1]);
        $this->assertSame('14.00', $catalog['tlds']['.test']['renew'][1]);
        $this->assertStringNotContainsString('never-return-this', json_encode($catalog));
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' || str_contains($r->url(), '/domains/'));
    }

    public function test_cost_mode_uses_parent_currency_and_does_not_fetch_customer_selling_prices(): void
    {
        $this->assertSame('EUR', $this->provider()->getCatalog('cost')['currency']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'customer-price'));
    }

    public function test_missing_prices_fail_closed_instead_of_inventing_free_domains(): void
    {
        $extension = $this->provider(['*/products/customer-price.json*' => Http::response(['hosting' => []])]);
        $this->expectException(\RuntimeException::class);
        $extension->getCatalog();
    }

    public function test_currency_and_price_shapes_are_validated(): void
    {
        foreach ([['*/resellers/details.json*' => Http::response(['sellingcurrencysymbol' => '$'])], ['*/products/customer-price.json*' => Http::response(['bundle' => ['addnewdomain' => ['1' => '-1']]])]] as $bad) {
            try {
                $this->provider($bad)->getCatalog();
                $this->fail('Invalid catalog accepted');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_native_drafts_are_idempotent_exactly_scoped_and_keep_existing_services_unchanged(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $unrelated = $this->createProduct();
        $unrelated->product->update(['name' => 'Domain.test']);
        $service = Service::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $unrelated->product->id, 'plan_id' => $unrelated->plan->id, 'price' => '77.00']);
        $before = $service->fresh()->getRawOriginal();
        $sync = new CatalogSync;
        $first = $sync->sync($server, $category, 'customer', '10');
        $this->assertSame(2, $first['created']);
        $products = Product::where('server_id', $server->id)->get();
        $this->assertCount(2, $products);
        foreach ($products as $product) {
            $this->assertTrue((bool) $product->hidden);
            $this->assertSame(0, $product->stock);
            $this->assertSame('11.01', (string) $product->plans()->where('billing_period', 1)->first()->prices()->first()->price);
            $this->assertSame('20.90', (string) $product->plans()->where('billing_period', 2)->first()->prices()->first()->price);
            $this->assertCount(2, $product->plans);
        }
        $ids = $products->modelKeys();
        $this->assertSame(0, $sync->sync($server, $category, 'customer', '10')['created']);
        $this->assertSame($ids, Product::where('server_id', $server->id)->pluck('id')->all());
        $this->assertSame($before, $service->fresh()->getRawOriginal());
        $this->assertSame('10.00', (string) $unrelated->plan->prices()->first()->price);
    }

    public function test_dry_run_has_no_database_writes_and_provider_failure_keeps_last_good_prices(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $before = [Product::count(), Setting::count()];
        $this->assertSame(2, $sync->sync($server, $category, 'customer', '0', dryRun: true)['created']);
        $this->assertSame($before, [Product::count(), Setting::count()]);
        $sync->sync($server, $category, 'customer', '0');
        $before = Product::where('server_id', $server->id)->with('plans.prices', 'settings')->get()->toJson();
        $this->provider(['*/products/customer-price.json*' => Http::response([], 503)]);
        try {
            $sync->sync($server, $category, 'customer', '0');
            $this->fail('Failed provider accepted');
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, Product::where('server_id', $server->id)->with('plans.prices', 'settings')->get()->toJson());
    }

    public function test_removed_tld_is_withdrawn_without_deleting_service_or_touching_other_servers(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $sync->sync($server, $category, 'customer', '0');
        $removed = Product::where('server_id', $server->id)->where('name', 'Domain .co.test')->firstOrFail();
        $removed->update(['hidden' => false, 'stock' => null]);
        $other = Product::factory()->create(['name' => 'Domain .co.test', 'hidden' => false]);
        $service = Service::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $removed->id, 'plan_id' => $removed->plans->first()->id]);
        $this->provider(['*/products/details.json*' => Http::response(['bundle' => ['tldlist' => ['test']]])]);
        $this->assertSame(1, $sync->sync($server, $category, 'customer', '0', tlds: ['.test', '.co.test'])['withdrawn']);
        $this->assertSame(0, $removed->fresh()->stock);
        $this->assertTrue((bool) $removed->fresh()->hidden);
        $this->assertFalse((bool) $other->fresh()->hidden);
        $this->assertNotNull($service->fresh());
    }

    public function test_overlapping_run_is_rejected_before_fetching(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $lock = Cache::lock('resellerclub:catalog:' . $server->id, 600);
        $lock->get();
        try {
            (new CatalogSync)->sync($server, $category, 'customer', '0');
            $this->fail('Lock bypass');
        } catch (\RuntimeException) {
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_schedule_respects_enabled_frequency_day_and_last_success(): void
    {
        $now = CarbonImmutable::parse('2026-09-28 03:00:00', 'UTC');
        $base = ['sync_enabled' => '1', 'sync_frequency' => 'daily'];
        $this->assertTrue(SyncSchedule::due($base, $now));
        $this->assertFalse(SyncSchedule::due(array_replace($base, ['sync_enabled' => '0']), $now));
        $this->assertFalse(SyncSchedule::due($base + ['last_sync' => $now->toIso8601String()], $now));
        $this->assertTrue(SyncSchedule::due(array_replace($base, ['sync_frequency' => 'weekly', 'sync_day' => 'monday']), $now));
        $this->assertFalse(SyncSchedule::due(array_replace($base, ['sync_frequency' => 'weekly', 'sync_day' => 'tuesday']), $now));
        $this->assertFalse(SyncSchedule::due(array_replace($base, ['sync_frequency' => 'monthly']), $now));
        $this->assertTrue(SyncSchedule::due(array_replace($base, ['sync_frequency' => 'monthly']), $now->addDays(3)));
    }

    public function test_command_uses_saved_schedule_and_explicit_native_targets(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $this->artisan('resellerclub:sync-prices', ['--scheduled' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->artisan('resellerclub:sync-prices', ['--server' => $server->id, '--category' => $category->id, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Product::where('server_id', $server->id)->count());
        $this->artisan('resellerclub:sync-prices', ['--server' => $server->id, '--category' => $category->id])->assertSuccessful();
        $this->assertSame(2, Product::where('server_id', $server->id)->count());
        $this->assertSame('success', ResellerClubSyncPrices::settings()['last_status']);
    }

    public function test_empty_selection_and_invalid_markup_do_not_change_catalog(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        foreach ([['customer', '-1', null], ['customer', '0', []], ['customer', '0', ['.not-offered']]] as [$source, $markup, $tlds]) {
            try {
                (new CatalogSync)->sync($server, $category, $source, $markup, tlds: $tlds);
                $this->fail('Invalid selection accepted');
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame(0, Product::where('server_id', $server->id)->count());
    }

    public function test_scheduler_is_wired_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($event) => str_contains($event->command ?? '', 'resellerclub:sync-prices'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--scheduled', $event->command);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_partial_manual_sync_does_not_complete_full_scheduled_catalog(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        foreach (['server_id' => $server->id, 'category_id' => $category->id, 'sync_all_tlds' => '1', 'sync_enabled' => '1'] as $key => $value) {
            ResellerClubSyncPrices::saveSetting($key, $value);
        }
        $this->artisan('resellerclub:sync-prices', ['--tlds' => '.test'])->assertSuccessful();
        $this->assertArrayNotHasKey('last_scheduled_sync', ResellerClubSyncPrices::settings());
        $this->artisan('resellerclub:sync-prices', ['--all' => true])->assertSuccessful();
        $this->assertArrayHasKey('last_scheduled_sync', ResellerClubSyncPrices::settings());
    }

    public function test_boolean_provider_price_is_rejected(): void
    {
        $extension = $this->provider(['*/products/customer-price.json*' => Http::response(['bundle' => ['addnewdomain' => ['1' => true]]])]);
        $this->expectException(\RuntimeException::class);
        $extension->getCatalog();
    }

    public function test_dry_run_rejects_changed_plan_ownership(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        $sync = new CatalogSync;
        $sync->sync($server, $category, 'customer', '0');
        $product = Product::where('server_id', $server->id)->firstOrFail();
        $setting = $product->settings()->where('key', CatalogSync::KEY)->firstOrFail();
        $metadata = json_decode($setting->value, true);
        $metadata['plans'][1] = 999999;
        $setting->update(['value' => json_encode($metadata)]);
        $this->expectException(\RuntimeException::class);
        $sync->sync($server, $category, 'customer', '0', dryRun: true);
    }

    public function test_native_admin_form_renders_and_saves_scheduler_settings(): void
    {
        [$server, $category] = $this->fixture();
        $this->actingAs(User::factory()->create(['role_id' => 1]));
        Livewire::test(PriceSyncSettings::class)
            ->assertSee('Catalog Sync Status')
            ->set('data.server_id', $server->id)->set('data.category_id', $category->id)
            ->set('data.sync_frequency', 'daily')->set('data.sync_enabled', true)
            ->call('save')->assertHasNoErrors();
        $saved = ResellerClubSyncPrices::settings();
        $this->assertSame('1', $saved['sync_enabled']);
        $this->assertSame('daily', $saved['sync_frequency']);
        $this->assertSame((string) $server->id, $saved['server_id']);
    }

    public function test_saved_selection_with_trailing_commas_completes_schedule_scope(): void
    {
        $this->provider();
        [$server, $category] = $this->fixture();
        foreach (['server_id' => $server->id, 'category_id' => $category->id, 'sync_all_tlds' => '0', 'tld_list' => ' .test, , ', 'sync_enabled' => '1'] as $key => $value) {
            ResellerClubSyncPrices::saveSetting($key, $value);
        }
        $this->artisan('resellerclub:sync-prices')->assertSuccessful();
        $this->assertArrayHasKey('last_scheduled_sync', ResellerClubSyncPrices::settings());
    }

    public function test_enabled_schedule_refreshes_prices_and_discovers_new_tlds_on_next_day(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 04:00:00'));
        $this->provider();
        [$server, $category] = $this->fixture();
        foreach (['server_id' => $server->id, 'category_id' => $category->id, 'sync_all_tlds' => '1', 'sync_enabled' => '1', 'sync_frequency' => 'daily'] as $key => $value) {
            ResellerClubSyncPrices::saveSetting($key, $value);
        }
        $this->artisan('resellerclub:sync-prices', ['--scheduled' => true])->assertSuccessful();
        $this->provider();
        $this->artisan('resellerclub:sync-prices', ['--scheduled' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->travel(1)->days();
        $this->provider([
            '*/products/details.json*' => Http::response(['bundle' => ['tldlist' => ['test', 'co.test', 'new.test']]]),
            '*/products/customer-price.json*' => Http::response(['bundle' => ['addnewdomain' => ['1' => '15.00'], 'renewdomain' => ['1' => '16.00'], 'addtransferdomain' => ['1' => '17.00']]]),
        ]);
        $this->artisan('resellerclub:sync-prices', ['--scheduled' => true])->assertSuccessful();
        $products = Product::where('server_id', $server->id)->get();
        $this->assertCount(3, $products);
        foreach ($products as $product) {
            $this->assertSame('15.00', (string) $product->plans()->where('billing_period', 1)->first()->prices()->first()->price);
            $metadata = json_decode($product->settings()->where('key', CatalogSync::KEY)->value('value'), true);
            $this->assertSame('16.00', $metadata['prices']['renew'][1]);
            $this->assertSame('17.00', $metadata['prices']['transfer'][1]);
        }
        $this->travelBack();
    }
}
