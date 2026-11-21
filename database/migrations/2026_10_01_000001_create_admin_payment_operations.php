<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_transactions', function (Blueprint $table) {
            $table->string('settlement_origin', 30)->nullable();
            $table->string('settlement_state', 20)->nullable();
            $table->text('original_allocation')->nullable();
        });
        Schema::create('payment_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('kind', 30);
            $table->string('state', 20)->default('queued');
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('gateway_id')->constrained('extensions')->restrictOnDelete();
            $table->foreignId('original_transaction_id')->nullable()->constrained('invoice_transactions')->restrictOnDelete();
            $table->foreignId('result_transaction_id')->nullable()->constrained('invoice_transactions')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('actor_snapshot');
            $table->decimal('amount', 17, 2);
            $table->string('currency_code', 3);
            $table->text('reason');
            $table->timestamp('effective_at');
            $table->string('request_fingerprint', 64);
            $table->string('source_fingerprint', 64)->nullable()->unique();
            $table->text('payload');
            $table->string('provider_reference', 190)->nullable();
            $table->string('outcome_code', 60)->nullable();
            $table->longText('outcome_evidence')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'state']);
            $table->index(['original_transaction_id', 'kind', 'state'], 'payment_operation_refund_lookup');
        });
        Schema::create('invoice_paid_processings', function (Blueprint $table) {
            $table->foreignId('invoice_id')->primary()->constrained()->restrictOnDelete();
            $table->string('origin', 20);
            $table->timestamp('processed_at')->nullable();
        });
        // Existing paid invoices have already delivered their lifecycle effects.
        DB::table('invoice_paid_processings')->insertUsing(['invoice_id', 'origin', 'processed_at'],
            DB::table('invoices')->where('status', 'paid')->select('id')->selectRaw("'legacy', CURRENT_TIMESTAMP"));
    }

    public function down(): void
    {
        if (DB::table('payment_operations')->exists() || DB::table('invoice_transactions')->whereNotNull('original_allocation')->exists() ||
            DB::table('invoice_paid_processings')->where('origin', '!=', 'legacy')->exists()) {
            throw new RuntimeException('Payment operation history must be preserved before rollback.');
        }
        Schema::dropIfExists('payment_operations');
        Schema::dropIfExists('invoice_paid_processings');
        Schema::table('invoice_transactions', fn (Blueprint $table) => $table->dropColumn(['settlement_origin', 'settlement_state', 'original_allocation']));
    }
};
