<?php

namespace Tests\Feature;

use App\Models\BillmanagerServiceDetail;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

class PlanTaxMigrationTest extends TestCase
{
    use UsesCommittedDatabase;

    private function importedService(): Service
    {
        Bus::fake();
        Mail::fake();
        $fixture = $this->createProduct(['hidden' => true, 'stock' => 0]);
        $fixture->plan->prices()->first()->update(['setup_fee' => '0.00']);
        $service = Service::factory()->create(['user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id, 'plan_id' => $fixture->plan->id, 'price' => '10.00']);
        $importId = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10',
            'snapshot_sha256' => str_repeat('a', 64), 'status' => 'completed']);
        BillmanagerServiceDetail::create(['import_id' => $importId, 'service_id' => $service->id,
            'source_id' => '40', 'source_status' => 'active', 'details' => [
                'original' => ['id' => '40', 'pricelist' => '20', 'processingmodule' => '5'],
                'price' => ['native_amount' => '10.00', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month'],
            ]]);
        DB::table('billmanager_mappings')->insert(['import_id' => $importId, 'source_host' => '192.0.2.10',
            'source_table' => 'items', 'source_id' => '40', 'target_table' => 'services', 'target_id' => $service->id]);
        DB::table('billmanager_mappings')->insert(['import_id' => $importId, 'source_host' => '192.0.2.10',
            'source_table' => 'service_products', 'source_id' => '20:5', 'target_table' => 'products', 'target_id' => $service->product_id]);

        return $service;
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_000001_preserve_imported_plan_tax_basis.php');
    }

    public function test_existing_import_backfill_preserves_amounts_and_unrelated_plan_policy(): void
    {
        $service = $this->importedService();
        $other = $this->createProduct();
        $before = DB::table('prices')->orderBy('id')->get()->toJson();
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('price_includes_tax'));
        $this->migration()->up();
        $this->assertTrue($service->fresh()->plan->price_includes_tax);
        $this->assertNull($other->plan->fresh()->price_includes_tax);
        $this->assertSame('10.00', $service->fresh()->price);
        $this->assertSame($before, DB::table('prices')->orderBy('id')->get()->toJson());
        $this->migration()->up();
        $this->assertTrue($service->fresh()->plan->price_includes_tax);
    }

    public function test_shared_plan_is_rejected_before_schema_or_data_change(): void
    {
        $service = $this->importedService();
        Service::factory()->create(['user_id' => User::factory()->create()->id,
            'product_id' => $service->product_id, 'plan_id' => $service->plan_id, 'price' => '10.00']);
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('price_includes_tax'));
        try {
            $this->migration()->up();
            $this->fail('A shared imported plan was changed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dedicated', $e->getMessage());
            $this->assertFalse(Schema::hasColumn('plans', 'price_includes_tax'));
        } finally {
            if (!Schema::hasColumn('plans', 'price_includes_tax')) {
                Schema::table('plans', fn (Blueprint $table) => $table->boolean('price_includes_tax')->nullable());
            }
        }
    }

    public function test_rollback_refuses_to_erase_a_plan_price_basis(): void
    {
        $service = $this->importedService();
        $service->plan->update(['price_includes_tax' => true]);
        try {
            $this->migration()->down();
            $this->fail('An active plan tax basis was discarded');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tax basis', $e->getMessage());
            $this->assertTrue($service->fresh()->plan->price_includes_tax);
        } finally {
            if (!Schema::hasColumn('plans', 'price_includes_tax')) {
                Schema::table('plans', fn (Blueprint $table) => $table->boolean('price_includes_tax')->nullable());
            }
        }
    }

    #[DataProvider('importedPlanDrift')]
    public function test_archived_plan_drift_still_requires_reconciliation(string $change): void
    {
        $service = $this->importedService();
        if ($change === 'price') {
            DB::table('services')->where('id', $service->id)->update(['price' => '99.00']);
            DB::table('prices')->where('plan_id', $service->plan_id)->update(['price' => '99.00']);
        } elseif ($change === 'period') {
            DB::table('plans')->where('id', $service->plan_id)->update(['billing_period' => 12]);
        } else {
            DB::table('products')->where('id', $service->product_id)->update(['hidden' => false, 'stock' => null]);
        }
        try {
            $this->migration()->up();
            $this->fail('A changed imported price was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unchanged', $e->getMessage());
            $this->assertNull($service->fresh()->plan->price_includes_tax);
        }
    }

    public static function importedPlanDrift(): array
    {
        return [['price'], ['period'], ['catalog']];
    }
}
