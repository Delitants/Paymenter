<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NATIVE_KINDS = ['invoice_funding', 'deposit', 'downgrade', 'deposit_refund', 'manual_unsettle', 'manual_restore', 'internal_reversal'];

    public function up(): void
    {
        Schema::table('account_movements', function (Blueprint $table) {
            $table->enum('kind', [...self::NATIVE_KINDS, 'legacy_effect'])->change();
        });
        Schema::create('legacy_posting_results', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('effect_id')->unique()->constrained('legacy_effects')->restrictOnDelete();
            $table->foreignId('observation_id')->constrained('legacy_observations')->restrictOnDelete();
            $table->foreignId('opening_receipt_id')->constrained('account_opening_receipts')->restrictOnDelete();
            $table->foreignId('parent_effect_id')->nullable()->constrained('legacy_effects')->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->unique()->constrained('account_movements')->restrictOnDelete();
            $table->enum('disposition', ['included', 'applied', 'applied_zero']);
            $table->decimal('net_delta', 19, 4);
            foreach (['economic_sha256', 'membership_sha256', 'receipt_sha256'] as $column) {
                $table->char($column, 64);
            }
            foreach (['authority_sha256', 'exclusion_sha256', 'parent_cap_sha256'] as $column) {
                $table->char($column, 64)->nullable();
            }
            $table->longText('parent_cap_json')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            $connection->transaction(static function (): void {
                if (DB::table('legacy_posting_results')->useWritePdo()->exists() ||
                    DB::table('account_movements')->useWritePdo()->where('kind', 'legacy_effect')->exists()) {
                    throw new RuntimeException('Legacy posting history must be preserved before rollback.');
                }
                Schema::table('account_movements', function (Blueprint $table) {
                    $table->enum('kind', self::NATIVE_KINDS)->change();
                });
                Schema::drop('legacy_posting_results');
            });

            return;
        }
        $grammar = $connection->getQueryGrammar();
        $movements = $grammar->wrapTable('account_movements');
        $results = $grammar->wrapTable('legacy_posting_results');
        $nativeKinds = "'" . implode("','", self::NATIVE_KINDS) . "'";
        $restore = "ALTER TABLE $movements MODIFY `kind` ENUM($nativeKinds) NOT NULL";
        $drop = "DROP TABLE $results";
        if ($connection->pretending()) {
            $connection->unprepared($restore);
            $connection->unprepared($drop);

            return;
        }

        // These locks belong to the PDO session. Framework-level reconnection
        // must never replay a destructive statement on a session without them.
        $pdo = $connection->getPdo();
        if ($connection->transactionLevel() !== 0 || $pdo->inTransaction() ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('Legacy schema rollback requires its own table locks.');
        }
        $pdo->exec("LOCK TABLES $movements WRITE, $results WRITE");
        try {
            if ($pdo->query("SELECT 1 FROM $results LIMIT 1")->fetchColumn() !== false ||
                $pdo->query("SELECT 1 FROM $movements WHERE `kind` = 'legacy_effect' LIMIT 1")->fetchColumn() !== false) {
                throw new RuntimeException('Legacy posting history must be preserved before rollback.');
            }
            $pdo->exec($restore);
            $pdo->exec($drop);
        } finally {
            $pdo->exec('UNLOCK TABLES');
        }
    }
};
