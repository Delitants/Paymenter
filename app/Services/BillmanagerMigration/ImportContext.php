<?php

namespace App\Services\BillmanagerMigration;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ImportContext
{
    public function __construct(public readonly int $importId, public readonly string $sourceHost) {}

    private function mapping(string $table, string $id)
    {
        return DB::table('billmanager_mappings')->where([
            'source_host' => $this->sourceHost, 'source_table' => $table, 'source_id' => $id,
        ]);
    }

    public function mappedId(string $sourceTable, string $sourceId): ?int
    {
        $value = $this->mapping($sourceTable, $sourceId)->value('target_id');

        return $value === null ? null : (int) $value;
    }

    public function recordMapping(string $sourceTable, string $sourceId, string $targetTable, int $targetId): void
    {
        $existing = $this->mapping($sourceTable, $sourceId)->first();
        if ($existing) {
            if ($existing->target_table !== $targetTable || (int) $existing->target_id !== $targetId) {
                throw new RuntimeException('Conflicting migration mapping: ' . $sourceTable);
            }

            return;
        }
        DB::table('billmanager_mappings')->insert([
            'import_id' => $this->importId, 'source_host' => $this->sourceHost,
            'source_table' => $sourceTable, 'source_id' => $sourceId,
            'target_table' => $targetTable, 'target_id' => $targetId,
        ]);
    }

    public function archive(string $table, string $id, array $row, ?int $accountId = null): void
    {
        $payload = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $identity = ['import_id' => $this->importId, 'source_table' => $table, 'source_id' => $id];
        $existing = DB::table('billmanager_records')->where($identity)->first();
        $digest = hash('sha256', $payload);
        if ($existing) {
            if (!hash_equals($existing->payload_sha256, $digest) || $existing->source_account_id != $accountId) {
                throw new RuntimeException('Archived source record changed within an immutable snapshot');
            }

            return;
        }
        DB::table('billmanager_records')->insert($identity + [
            'source_account_id' => $accountId, 'payload' => Crypt::encryptString($payload), 'payload_sha256' => $digest,
        ]);
    }
}
