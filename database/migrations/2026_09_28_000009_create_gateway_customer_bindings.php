<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_customer_bindings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('merchant_fingerprint', 64);
            $table->foreignId('user_id')->constrained();
            $table->string('contact_fingerprint', 64);
            $table->string('state', 24)->default('initializing');
            $table->text('provider_reference')->nullable();
            $table->string('provider_reference_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'merchant_fingerprint', 'user_id'], 'gateway_customer_identity_unique');
            $table->unique(['provider', 'merchant_fingerprint', 'provider_reference_fingerprint'], 'gateway_customer_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_customer_bindings');
    }
};
