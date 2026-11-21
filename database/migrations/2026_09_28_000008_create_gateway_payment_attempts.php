<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->constrained('extensions');
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('reference', 36)->unique();
            $table->string('merchant_fingerprint', 64);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 3);
            $table->string('state', 24)->default('open');
            $table->string('provider_reference')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->longText('provider_payload')->nullable();
            $table->string('provider_webhook_reference')->nullable();
            $table->unique(['gateway_id', 'provider_webhook_reference'], 'gateway_attempt_webhook_reference_unique');
            $table->unique(['gateway_id', 'provider_transaction_id'], 'gateway_attempt_provider_transaction_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payment_attempts');
    }
};
