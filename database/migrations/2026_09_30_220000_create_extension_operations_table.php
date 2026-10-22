<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_operations', function (Blueprint $table) {
            $table->id();
            $table->char('identity', 64)->unique();
            $table->foreignId('extension_id')->nullable()->constrained('extensions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->longText('payload');
            $table->timestamps();
            $table->index(['service_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_operations');
    }
};
