<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_effects', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->char('effect_key', 64)->unique();
            $table->char('economic_sha256', 64);
            $table->text('identity_json');
            $table->text('economic_json');
            $table->timestamp('created_at');
        });
        Schema::create('legacy_observations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('effect_id')->constrained('legacy_effects')->restrictOnDelete();
            $table->char('observation_key', 64)->unique();
            $table->mediumText('payload_bytes');
            $table->mediumText('signature_bytes');
            foreach (['payload_sha256', 'signature_sha256', 'economic_sha256', 'policy_sha256'] as $column) {
                $table->char($column, 64);
            }
            $table->enum('disposition', ['pending_source_qualification', 'economic_conflict']);
            $table->timestamp('created_at');
        });
        Schema::create('legacy_receipt_conflicts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('effect_id')->constrained('legacy_effects')->restrictOnDelete();
            $table->foreignId('observation_id')->unique()->constrained('legacy_observations')->restrictOnDelete();
            $table->char('expected_economic_sha256', 64);
            $table->char('observed_economic_sha256', 64);
            $table->enum('reason', ['economic_facts_changed']);
            $table->timestamp('created_at');
        });
        Schema::create('legacy_receipt_budgets', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('store_key', 64)->unique();
            $table->unsignedBigInteger('retained_bytes');
            $table->unsignedBigInteger('retained_observations');
        });
    }

    public function down(): void
    {
        $tables = array_values(array_filter(['legacy_receipt_conflicts', 'legacy_observations', 'legacy_effects', 'legacy_receipt_budgets'], fn ($table) => Schema::hasTable($table)));
        if (!$tables) {
            return;
        }
        // Close the check/drop race with concurrent retention. These are evidence
        // tables only; no business table is locked, changed or restored.
        DB::unprepared('LOCK TABLES ' . implode(', ', array_map(fn ($table) => '`' . $table . '` WRITE', $tables)));
        try {
            foreach ($tables as $table) {
                $query = DB::table($table);
                $occupied = $table === 'legacy_receipt_budgets'
                    ? $query->where(fn ($q) => $q->where('retained_bytes', '<>', 0)->orWhere('retained_observations', '<>', 0))->exists()
                    : $query->exists();
                if ($occupied) {
                    throw new DomainException('Retained legacy evidence or usage prevents destructive schema rollback.');
                }
            }
            foreach ($tables as $table) {
                Schema::drop($table);
            }
        } finally {
            DB::unprepared('UNLOCK TABLES');
        }
    }
};
