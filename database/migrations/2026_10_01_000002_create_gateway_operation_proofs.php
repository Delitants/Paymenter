<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_operation_sequences', function (Blueprint $table) {
            $table->string('scope', 64)->primary();
            $table->unsignedBigInteger('last_reqn');
        });
        Schema::create('gateway_operation_requests', function (Blueprint $table) {
            $table->uuid('request_key')->primary();
            $table->foreignId('payment_operation_id')->unique()->constrained('payment_operations')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('action', 40);
            $table->string('sequence_scope', 64)->nullable();
            $table->foreign('sequence_scope')->references('scope')->on('gateway_operation_sequences')->restrictOnDelete();
            $table->unsignedBigInteger('reqn')->nullable();
            $table->string('request_digest', 64);
            $table->longText('accepted_proof')->nullable();
            $table->timestamp('created_at');
            $table->unique(['sequence_scope', 'reqn']);
        });
    }

    public function down(): void
    {
        if (DB::table('gateway_operation_requests')->exists() || DB::table('gateway_operation_sequences')->exists()) {
            throw new RuntimeException('Durable provider request proofs and sequence numbers must be preserved before rollback.');
        }
        Schema::dropIfExists('gateway_operation_requests');
        Schema::dropIfExists('gateway_operation_sequences');
    }
};
