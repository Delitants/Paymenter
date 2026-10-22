<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->char('opening_identity', 64)->nullable()->unique();
            $table->decimal('opening_balance', 19, 4);
            $table->decimal('balance', 19, 4);
            $table->decimal('borrowing_limit', 19, 4);
            $table->boolean('active')->default(false);
            $table->boolean('reconciliation_required')->default(false);
            $table->json('opening_evidence');
            $table->timestamps();
            $table->unique(['user_id', 'currency_code']);
        });
        Schema::create('account_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('account_wallets')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->enum('kind', ['invoice_funding', 'deposit', 'downgrade', 'deposit_refund', 'manual_unsettle', 'manual_restore', 'internal_reversal']);
            $table->decimal('delta', 19, 4);
            $table->decimal('balance_before', 19, 4);
            $table->decimal('balance_after', 19, 4);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('origin', 30);
            $table->string('request_key', 128);
            $table->char('request_fingerprint', 64);
            $table->char('source_key', 64)->nullable()->unique();
            $table->string('reference_type', 60);
            $table->unsignedBigInteger('reference_id');
            $table->foreignId('linked_reversal_id')->nullable()->constrained('account_movements')->restrictOnDelete();
            $table->json('payload');
            $table->timestamps();
            $table->unique(['wallet_id', 'request_key']);
            $table->index(['wallet_id', 'id']);
        });
        Schema::create('account_funding_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('account_wallets')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreignId('movement_id')->unique()->constrained('account_movements')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_transaction_id')->nullable()->unique()->constrained('invoice_transactions')->restrictOnDelete();
            $table->decimal('amount', 17, 2);
            $table->decimal('cash_amount', 19, 4);
            $table->decimal('debt_amount', 19, 4);
            $table->char('pricing_fingerprint', 64);
            $table->decimal('reversed_amount', 17, 2)->default('0.00');
            $table->timestamps();
        });
        Schema::create('account_reversal_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('account_wallets')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreignId('deposit_movement_id')->constrained('account_movements')->restrictOnDelete();
            $table->foreignId('payment_operation_id')->unique()->constrained('payment_operations')->restrictOnDelete();
            $table->decimal('principal', 19, 4);
            $table->enum('state', ['reserved', 'released', 'consumed'])->default('reserved');
            $table->foreignId('posted_movement_id')->nullable()->unique()->constrained('account_movements')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['account_wallets', 'account_movements', 'account_funding_allocations', 'account_reversal_reservations'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Account funding history must be preserved before rollback.');
            }
        }
        Schema::dropIfExists('account_reversal_reservations');
        Schema::dropIfExists('account_funding_allocations');
        Schema::dropIfExists('account_movements');
        Schema::dropIfExists('account_wallets');
    }
};
