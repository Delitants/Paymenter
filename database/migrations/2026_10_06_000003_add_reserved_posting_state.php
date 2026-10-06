<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_reversal_reservations', function (Blueprint $table) {
            $table->boolean('posting_required')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('account_reversal_reservations')->exists()) {
            throw new RuntimeException('Reserved posting evidence must be retained.');
        }
        Schema::table('account_reversal_reservations', function (Blueprint $table) {
            $table->dropColumn('posting_required');
        });
    }
};
