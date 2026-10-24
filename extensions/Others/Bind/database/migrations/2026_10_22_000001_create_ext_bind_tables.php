<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_bind_zones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $t->string('zone', 254);
            $t->boolean('enabled')->default(false);
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['service_id', 'zone']);
        });
        Schema::create('ext_bind_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('binding_id')->constrained('ext_bind_zones')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 32);
            $t->string('request_hash', 64);
            $t->string('state', 32);
            $t->string('code', 64)->nullable();
            $t->unsignedBigInteger('serial')->nullable();
            $t->string('revision', 64)->nullable();
            $t->json('response')->nullable();
            $t->timestamps();
            $t->index(['binding_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_bind_operations');
        Schema::dropIfExists('ext_bind_zones');
    }
};
