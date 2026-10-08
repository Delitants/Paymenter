<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billmanager_financial_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('billmanager_imports');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('source_account_id');
            $table->string('source_table', 64);
            $table->string('source_id', 128);
            $table->string('number')->nullable();
            $table->string('currency_code', 3);
            $table->string('status');
            $table->string('amount', 80); // Exact source decimal; never cast through a float.
            $table->longText('details'); // Encrypted original customer-visible record.
            $table->unique(['import_id', 'source_table', 'source_id'], 'billmanager_financial_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billmanager_financial_records');
    }
};
