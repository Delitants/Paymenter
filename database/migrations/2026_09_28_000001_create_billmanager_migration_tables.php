<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billmanager_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source_host', 45);
            $table->string('snapshot_sha256', 64)->unique();
            $table->string('status');
            $table->json('report')->nullable();
            $table->timestamps();
        });
        Schema::create('billmanager_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('billmanager_imports');
            $table->string('source_host', 45);
            $table->string('source_table', 64);
            $table->string('source_id', 128);
            $table->string('target_table', 64);
            $table->unsignedBigInteger('target_id');
            $table->unique(['source_host', 'source_table', 'source_id'], 'billmanager_source_identity');
        });
        Schema::create('billmanager_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('billmanager_imports');
            $table->string('source_table', 64);
            $table->string('source_id', 128);
            $table->unsignedBigInteger('source_account_id')->nullable()->index();
            $table->longText('payload'); // Encrypted by the import context.
            $table->string('payload_sha256', 64);
            $table->unique(['import_id', 'source_table', 'source_id'], 'billmanager_record_identity');
        });
        Schema::create('billmanager_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_account_id')->unique();
            $table->foreignId('owner_user_id')->constrained('users');
            $table->timestamps();
        });
        Schema::create('billmanager_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('billmanager_accounts');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('source_user_id')->unique();
            $table->unique(['account_id', 'user_id']);
        });
        Schema::create('billmanager_holds', function (Blueprint $table) {
            $table->id();
            $table->string('model_type', 191);
            $table->unsignedBigInteger('model_id');
            $table->string('reason');
            $table->timestamp('released_at')->nullable();
            $table->unique(['model_type', 'model_id']);
        });
        Schema::create('billmanager_legacy_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->text('credential'); // Encrypted; never included in ordinary exports.
            $table->timestamp('upgraded_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['legacy_credentials', 'holds', 'members', 'accounts', 'records', 'mappings', 'imports'] as $name) {
            Schema::dropIfExists('billmanager_' . $name);
        }
    }
};
