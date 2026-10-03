<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billmanager_service_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('billmanager_imports');
            $table->foreignId('service_id')->unique()->constrained('services');
            $table->string('source_id', 128);
            $table->string('source_status');
            $table->longText('details');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billmanager_service_details');
    }
};
