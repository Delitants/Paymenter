<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('legacy_reference')->nullable();
        });
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->longText('message')->change();
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->json('legacy_author')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('ticket_messages')->whereNull('user_id')->orWhereRaw('LENGTH(message) > 65535')->exists()) {
            throw new RuntimeException('Legacy ticket data must be preserved before reverting its schema');
        }
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->text('message')->change();
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->dropColumn('legacy_author');
        });
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('legacy_reference'));
    }
};
