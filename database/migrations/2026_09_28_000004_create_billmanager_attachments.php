<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billmanager_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('billmanager_imports');
            $table->string('source_id', 128);
            $table->unsignedBigInteger('source_account_id');
            $table->string('filename');
            $table->string('path');
            $table->unsignedBigInteger('filesize');
            $table->string('sha256', 64);
            $table->boolean('deleted_message');
            $table->unique(['import_id', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billmanager_attachments');
    }
};
