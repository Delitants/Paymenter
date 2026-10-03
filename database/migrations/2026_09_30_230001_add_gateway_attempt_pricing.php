<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('gateway_id')->nullable()->constrained('extensions');
        });
        Schema::table('gateway_payment_attempts', function (Blueprint $table) {
            $table->text('pricing_payload')->nullable();
            $table->string('pricing_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gateway_payment_attempts', fn (Blueprint $table) => $table->dropColumn(['pricing_payload', 'pricing_fingerprint']));
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('gateway_id'));
    }
};
