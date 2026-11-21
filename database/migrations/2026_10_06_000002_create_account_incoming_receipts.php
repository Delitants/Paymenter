<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_downgrade_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_upgrade_id')->unique()->constrained('service_upgrades')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->decimal('principal', 19, 4);
            $table->json('proof');
            $table->enum('provider_state', ['unneeded', 'prepared', 'processing', 'failed', 'uncertain', 'confirmed']);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('account_posting_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('account_wallets')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->char('source_key', 64)->unique();
            $table->string('kind', 30);
            $table->decimal('principal', 19, 4);
            $table->json('proof');
            $table->string('reason_code', 40);
            $table->foreignId('resolved_movement_id')->nullable()->unique()->constrained('account_movements')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['account_posting_issues', 'account_downgrade_receipts'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Incoming account evidence must be preserved before rollback.');
            }
        }
        Schema::dropIfExists('account_posting_issues');
        Schema::dropIfExists('account_downgrade_receipts');
    }
};
