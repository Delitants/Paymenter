<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_opening_batches', function (Blueprint $table) {
            $table->id();
            $table->char('bundle_sha256', 64)->unique();
            $table->char('grant_sha256', 64)->unique();
            $table->string('source_identity', 190);
            $table->foreignId('import_id')->constrained('billmanager_imports')->restrictOnDelete();
            $table->longText('target_identity');
            foreach (['release', 'baseline_sql', 'policy', 'scope', 'snapshot', 'freeze_receipt', 'journal_header'] as $name) {
                $table->char($name . '_sha256', 64);
            }
            $table->string('freeze_id', 190);
            $table->text('journal_path');
            $table->unsignedBigInteger('journal_device');
            $table->unsignedBigInteger('journal_inode');
            $table->enum('state', ['opening', 'sealed']);
            $table->timestamp('created_at');
            $table->timestamp('sealed_at')->nullable();
            $table->char('verification_sha256', 64)->nullable();
        });
        Schema::create('account_opening_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('account_opening_batches')->restrictOnDelete();
            $table->foreignId('wallet_id')->unique()->constrained('account_wallets')->restrictOnDelete();
            $table->char('opening_identity', 64)->unique();
            $table->string('source_account', 190);
            $table->char('currency', 3);
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->char('evidence_sha256', 64);
            $table->char('attempt_sha256', 64);
            $table->longText('delta');
            $table->char('receipt_sha256', 64);
            $table->timestamp('created_at');
            $table->unique(['batch_id', 'source_account', 'currency'], 'opening_account_once');
        });
    }

    public function down(): void
    {
        if (DB::table('account_opening_batches')->exists() || DB::table('account_opening_receipts')->exists()) {
            throw new RuntimeException('Populated opening history cannot be removed.');
        }
        Schema::dropIfExists('account_opening_receipts');
        Schema::dropIfExists('account_opening_batches');
    }
};
