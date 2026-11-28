<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\Category;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SSLStoreCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Category $category;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'SSL Store staging', 'enabled' => false, 'extension' => 'SSLStore', 'type' => 'server',
        ]);
        $this->category = Category::factory()->create();
        $this->file = tempnam(sys_get_temp_dir(), 'sslstore-catalog-');
        Http::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function entry(array $changes = []): array
    {
        return array_replace([
            'source_plan_id' => 42,
            'name' => 'Synthetic certificate',
            'code' => 'legacy_sku',
            'supplier_product_code' => 'current_sku',
            'provider_catalog_state' => 'mapped',
            'currency' => 'USD',
            'setup' => '0.0000',
            'prices' => ['12' => '10.9900', '24' => '19.9800', '36' => '29.9700'],
        ], $changes);
    }

    private function stage(array $entries, bool $success = true, ?string $digest = null): void
    {
        $bytes = json_encode(['result' => ['accepted' => true], 'plans' => $entries], JSON_THROW_ON_ERROR);
        file_put_contents($this->file, $bytes);
        $this->artisan('billmanager:sslstore:stage', [
            'catalog' => $this->file,
            'server' => (string) $this->server->id,
            'category' => (string) $this->category->id,
            '--sha256' => $digest ?? hash('sha256', $bytes),
        ])->assertExitCode($success ? 0 : 1);
        Http::assertNothingSent();
    }

    public function test_import_keeps_exact_prices_and_is_idempotent(): void
    {
        $entry = $this->entry(['setup' => '2.3400']);
        $this->stage([$entry]);
        $product = Product::where('server_id', $this->server->id)->sole();
        $this->assertSame('Synthetic certificate', $product->name);
        $this->assertEquals($this->category->id, $product->category_id);
        $this->assertTrue((bool) $product->hidden);
        $this->assertSame(0, $product->stock);
        $this->assertFalse((bool) $this->server->fresh()->enabled);
        $this->assertSame('current_sku', $product->settings->where('key', 'product_code')->sole()->value);
        $this->assertSame([12, 24, 36], $product->plans->pluck('billing_period')->all());
        foreach ($product->plans as $plan) {
            $this->assertSame('month', $plan->billing_unit);
            $price = $plan->prices->sole();
            $this->assertSame('USD', $price->currency_code);
            $this->assertSame(0, bccomp($entry['prices'][$plan->billing_period], (string) $price->price, 4));
            $this->assertSame(0, bccomp('2.34', (string) $price->setup_fee, 4));
        }

        $this->stage([$entry]);
        $this->assertEquals($product->id, Product::where('server_id', $this->server->id)->sole()->id);
        $this->assertDatabaseCount('prices', 3);
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_unverified_or_duplicate_input_is_rejected(): void
    {
        $this->stage([$this->entry()], false, str_repeat('0', 64));
        $this->stage([$this->entry(), $this->entry(['source_plan_id' => 43])], false);
        $this->stage([$this->entry(['prices' => ['12' => '1.0001', '24' => '2.00', '36' => '3.00']])], false);
        $this->stage([$this->entry(['provider_catalog_state' => 'unmatched'])], false);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_enabled_server_or_changed_staged_product_refuses_the_entire_batch(): void
    {
        $this->server->update(['enabled' => true]);
        $this->stage([$this->entry()], false);
        $this->assertDatabaseCount('products', 0);

        $this->server->update(['enabled' => false]);
        $this->stage([$this->entry()]);
        $product = Product::where('server_id', $this->server->id)->sole();
        $product->update(['slug' => 'manual-edit']);
        $this->stage([
            $this->entry(['source_plan_id' => 43, 'code' => 'another', 'supplier_product_code' => 'other_sku']),
            $this->entry(),
        ], false);

        $this->assertDatabaseCount('products', 1);
        $this->assertSame('manual-edit', $product->fresh()->slug);
    }
}
