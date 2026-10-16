<?php

use App\Models\BillmanagerServiceDetail;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $planIds = [];
        if (Schema::hasTable('billmanager_service_details')) {
            $imports = DB::table('billmanager_service_details as d')
                ->leftJoin('billmanager_imports as i', 'i.id', '=', 'd.import_id')
                ->leftJoin('billmanager_mappings as m', function ($join) {
                    $join->on('m.source_host', '=', 'i.source_host')->on('m.source_id', '=', 'd.source_id')
                        ->on('m.target_id', '=', 'd.service_id')->where('m.source_table', 'items')->where('m.target_table', 'services');
                })->select('d.id', 'd.service_id', 'i.source_host', 'm.id as mapping_id')->get();
            // Validate before MariaDB's nontransactional ALTER TABLE. A shared
            // or drifted plan needs reconciliation, never a silent price change.
            foreach ($imports as $import) {
                $service = DB::table('services')->where('id', $import->service_id)->first();
                $plan = $service ? DB::table('plans')->where('id', $service->plan_id)->first() : null;
                $prices = $plan ? DB::table('prices')->where('plan_id', $plan->id)->get() : collect();
                $details = BillmanagerServiceDetail::findOrFail($import->id)->details;
                $original = $details['original'] ?? [];
                $expected = $details['price'] ?? [];
                $productKey = ($original['pricelist'] ?? '') . ':' . ($original['processingmodule'] ?? 'manual');
                $productId = DB::table('billmanager_mappings')->where('source_host', $import->source_host)
                    ->where('source_table', 'service_products')->where('source_id', $productKey)->where('target_table', 'products')->value('target_id');
                if (!$import->mapping_id || !$plan || $plan->priceable_type !== Product::class ||
                    $service->product_id != $productId || !DB::table('products')->where('id', $productId)->where('hidden', true)->where('stock', 0)->exists() ||
                    $plan->priceable_id != $service->product_id || DB::table('services')->where('plan_id', $plan->id)->count() !== 1 ||
                    $service->price !== ($expected['native_amount'] ?? null) || $plan->type !== ($expected['type'] ?? null) ||
                    $plan->billing_period != ($expected['billing_period'] ?? null) || $plan->billing_unit !== ($expected['billing_unit'] ?? null) ||
                    $prices->count() !== 1 || $prices[0]->currency_code !== $service->currency_code ||
                    $prices[0]->price !== $service->price || $prices[0]->setup_fee !== '0.00' ||
                    (isset($plan->price_includes_tax) && !(bool) $plan->price_includes_tax)) {
                    throw new RuntimeException('Imported renewal requires a dedicated, unchanged mapped plan before tax basis migration.');
                }
                $planIds[] = $plan->id;
            }
        }
        if (!Schema::hasColumn('plans', 'price_includes_tax')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->boolean('price_includes_tax')->nullable();
            });
        }
        DB::transaction(fn () => DB::table('plans')->whereIn('id', $planIds)->whereNull('price_includes_tax')->update(['price_includes_tax' => true]));
    }

    public function down(): void
    {
        if (DB::table('plans')->whereNotNull('price_includes_tax')->exists()) {
            throw new RuntimeException('Cannot discard an explicit plan tax basis; restore the reviewed backup or reconcile it first.');
        }
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('price_includes_tax');
        });
    }
};
