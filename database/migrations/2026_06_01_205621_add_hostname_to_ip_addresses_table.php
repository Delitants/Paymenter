<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The 2026_05_30 migration already owns this column.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversing this duplicate must not remove another migration's column.
    }
};
