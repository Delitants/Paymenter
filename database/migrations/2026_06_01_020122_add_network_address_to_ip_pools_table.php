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
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->string('network_address')->nullable()->after('name');
            $table->string('broadcast_address')->nullable()->after('gateway');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->dropColumn(['network_address', 'broadcast_address']);
        });
    }
};
