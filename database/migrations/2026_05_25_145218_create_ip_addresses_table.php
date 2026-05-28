<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_pool_id')->constrained('ip_pools')->onDelete('cascade');
            $table->string('ip_address')->unique();
            $table->boolean('is_assigned')->default(false);
            $table->string('assigned_to_type')->nullable(); // Model type (e.g., App\Models\Service)
            $table->unsignedBigInteger('assigned_to_id')->nullable(); // Model ID
            $table->index(['assigned_to_type', 'assigned_to_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
    }
};
