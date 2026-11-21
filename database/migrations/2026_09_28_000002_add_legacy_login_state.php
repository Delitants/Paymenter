<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billmanager_legacy_credentials', function (Blueprint $table) {
            $table->string('password_fingerprint', 64)->nullable();
            $table->boolean('login_blocked')->default(true);
            $table->string('block_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billmanager_legacy_credentials', function (Blueprint $table) {
            $table->dropColumn(['password_fingerprint', 'login_blocked', 'block_reason']);
        });
    }
};
