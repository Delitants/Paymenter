<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rates', fn (Blueprint $table) => $table->decimal('rate', 7, 4)->change());
        Schema::table('invoice_snapshots', fn (Blueprint $table) => $table->decimal('tax_rate', 7, 4)->nullable()->change());
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('pricing_tax_rate', 7, 4)->nullable();
            $table->string('pricing_tax_name')->nullable();
            $table->string('pricing_tax_country')->nullable();
            $table->boolean('pricing_tax_inclusive')->nullable();
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('kind')->default('product');
            $table->decimal('tax_amount', 18, 2)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['tax_rates' => 'rate', 'invoice_snapshots' => 'tax_rate', 'invoices' => 'pricing_tax_rate'] as $table => $column) {
            if (DB::table($table)->whereRaw("$column <> ROUND($column, 2) OR ABS($column) > 999.99")->exists()) {
                throw new RuntimeException('Tax precision must be reconciled before rollback.');
            }
        }
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn(['kind', 'tax_amount']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['pricing_tax_rate', 'pricing_tax_name', 'pricing_tax_country', 'pricing_tax_inclusive']));
        Schema::table('tax_rates', fn (Blueprint $table) => $table->decimal('rate', 5, 2)->change());
        Schema::table('invoice_snapshots', fn (Blueprint $table) => $table->decimal('tax_rate', 5, 2)->nullable()->change());
    }
};
