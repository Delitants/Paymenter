<?php

namespace App\Services\BillmanagerMigration\Opening;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OpeningTargetState
{
    private const RECEIPT_TABLES = ['account_opening_batches', 'account_wallets', 'account_opening_receipts', 'credits'];

    private ?OpeningArchiveReadLock $archiveLock = null;

    private array $lockedRows = [];

    public function lockArchives(): void
    {
        if ($this->archiveLock !== null) {
            throw new RuntimeException('Opening archives are already locked.');
        }
        $this->archiveLock = OpeningArchiveReadLock::acquire(DB::connection());
    }

    public function releaseArchives(): void
    {
        $this->lockedRows = [];
        $this->archiveLock = null;
    }

    public function assertArchiveLocks(): void
    {
        $this->archiveLock?->assertCurrent(DB::connection());
    }

    public function capture(int $schemaVersion = 1): array
    {
        if (!in_array($schemaVersion, [1, 2], true)) {
            throw new RuntimeException('Unknown opening target inventory version.');
        }
        $state = ['schema_version' => $schemaVersion, 'tables' => [], 'routines' => [], 'triggers' => [], 'events' => []];
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $inventory = $schemaVersion === 2 ? new OpeningTableInventory($connection) : null;
        $this->assertArchiveLocks();
        foreach ($connection->select('SHOW FULL TABLES') as $row) {
            $values = array_values((array) $row);
            $name = $values[0];
            $type = $values[1];
            $ddlRow = array_values((array) $connection->selectOne('SHOW CREATE TABLE ' . $grammar->wrapTable($name)));
            $ddl = self::normalizeDefinition($ddlRow[1]);
            // SHOW CREATE omits the table counter when it is one. Its presence
            // is not a schema change; read the actual allocator independently.
            $counter = $connection->selectOne('SELECT AUTO_INCREMENT AS allocator FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]);
            $allocator = $counter->allocator === null ? null : (int) $counter->allocator;
            $entry = ['type' => $type, 'ddl' => base64_encode($ddl), 'auto_increment' => $allocator];
            if ($inventory !== null && !in_array($name, self::RECEIPT_TABLES, true)) {
                $rows = $this->archiveLock?->covers($name)
                    ? ($this->lockedRows[$name] ??= $this->archiveLock->fingerprint($inventory, $name))
                    : $inventory->fingerprint($name);
                $state['tables'][$name] = $entry + $rows;
            } else {
                $rows = array_map(fn ($row) => self::encodeRow((array) $row), DB::table($name)->get()->all());
                self::sortRows($rows);
                $state['tables'][$name] = $entry + ['rows' => $rows];
            }
        }
        $this->assertArchiveLocks();
        ksort($state['tables']);
        foreach (['routines' => ['ROUTINES', 'ROUTINE_SCHEMA'], 'triggers' => ['TRIGGERS', 'TRIGGER_SCHEMA'], 'events' => ['EVENTS', 'EVENT_SCHEMA']] as $key => [$table, $schema]) {
            $state[$key] = array_map(fn ($row) => self::encodeRow((array) $row), $connection->select('SELECT * FROM information_schema.' . $table . ' WHERE ' . $schema . ' = DATABASE()'));
            self::sortRows($state[$key]);
        }

        return $state;
    }

    public static function encodeRow(array $row): array
    {
        $encoded = [];
        foreach ($row as $column => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new RuntimeException('Target raw row values are required.');
            }
            $encoded[$column] = ['type' => get_debug_type($value), 'value' => $value === null ? null : base64_encode(is_float($value) ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : (string) $value)];
        }
        ksort($encoded);

        return $encoded;
    }

    public static function normalizeDefinition(string $ddl): string
    {
        return preg_replace('/\sAUTO_INCREMENT=\d+\b/', '', $ddl);
    }

    private static function sortRows(array &$rows): void
    {
        usort($rows, fn ($a, $b) => strcmp(CanonicalPolicy::bytes($a), CanonicalPolicy::bytes($b)));
    }

    public function assertInitial(array $baseline, array $batchAttempts): void
    {
        $current = $this->capture($baseline['schema_version']);
        $expected = $baseline;
        self::assertBatchAllocator($baseline, $current, $batchAttempts);
        $expected['tables']['account_opening_batches']['auto_increment'] = $current['tables']['account_opening_batches']['auto_increment'];
        if ($expected !== $current) {
            throw new RuntimeException('Unreceipted target state changed.');
        }
    }

    private static function assertBatchAllocator(array $baseline, array $current, array $attempts, ?int $batchId = null): void
    {
        $lower = $baseline['tables']['account_opening_batches']['auto_increment'];
        $upper = $lower;
        $matched = $batchId === null;
        foreach ($attempts as $attempt) {
            $before = $attempt['allocators']['account_opening_batches'];
            if (!is_int($before) || $before < $lower || $before > $upper || $attempt['budgets'] !== ['account_opening_batches' => 1]) {
                throw new RuntimeException('Unjournaled opening batch allocation.');
            }
            $matched = $matched || $batchId === $before;
            $lower = $before;
            $upper = $before + 1;
        }
        $actual = $current['tables']['account_opening_batches']['auto_increment'];
        if (!is_int($actual) || $actual < $lower || $actual > $upper || !$matched || ($batchId !== null && $actual <= $batchId)) {
            throw new RuntimeException('Unjournaled opening batch allocation.');
        }
    }

    public function assertExpected(array $baseline, array $committed): void
    {
        $current = $this->capture($baseline['schema_version']);
        if (!$committed) {
            if ($baseline !== $current) {
                throw new RuntimeException('Unreceipted target state changed.');
            }

            return;
        }
        $expected = $baseline;
        $first = $committed[0];
        $batch = $first['batch_row'] ?? null;
        if (!is_array($batch) || !array_key_exists('receipt_row', $first) || $first['receipt_row'] !== null || !isset($first['attempts']) ||
            $batch !== (array) DB::table('account_opening_batches')->where('id', $batch['id'])->sole()) {
            throw new RuntimeException('Verified durable opening receipt validation is required.');
        }
        self::addRow($expected, 'account_opening_batches', $batch);
        $batchCounter = $baseline['tables']['account_opening_batches']['auto_increment'];
        $batchAttempts = $first['batch_attempts'] ?? [];
        if (!$batchAttempts) {
            if ($batch['id'] !== $batchCounter || $current['tables']['account_opening_batches']['auto_increment'] !== $batchCounter + 1) {
                throw new RuntimeException('Unjournaled opening batch allocation.');
            }
        } else {
            self::assertBatchAllocator($baseline, $current, $batchAttempts, $batch['id']);
        }
        $expected['tables']['account_opening_batches']['auto_increment'] = $current['tables']['account_opening_batches']['auto_increment'];
        $receipts = DB::table('account_opening_receipts')->where('batch_id', $batch['id'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        if ($receipts !== array_column(array_slice($committed, 1), 'receipt_row')) {
            throw new RuntimeException('Committed receipt population changed.');
        }
        foreach (array_slice($committed, 1) as $row) {
            if ($row['batch_row'] !== $batch || $row['attempts'] !== $first['attempts'] || OpeningBatchStore::receiptHash($row['receipt_row']) !== $row['receipt_row']['receipt_sha256']) {
                throw new RuntimeException('Durable opening receipt validation changed.');
            }
            $delta = StrictProofJson::decode($row['receipt_row']['delta']);
            foreach (['wallet_row', 'credit_before', 'credit_after'] as $key) {
                if ($row[$key] !== $delta[$key]) {
                    throw new RuntimeException('Caller-supplied opening delta changed.');
                }
            }
            self::addRow($expected, 'account_wallets', $row['wallet_row']);
            self::addRow($expected, 'account_opening_receipts', $row['receipt_row']);
            if ($row['credit_before'] !== null) {
                self::removeRow($expected, 'credits', $row['credit_before']);
            }
            self::addRow($expected, 'credits', $row['credit_after']);
        }
        // Only these three allocators can consume bounded rollback gaps. The
        // journal cannot add or modify rows, schemas, routines or other tables.
        foreach (['account_wallets', 'credits', 'account_opening_receipts'] as $table) {
            $lower = $baseline['tables'][$table]['auto_increment'];
            $upper = $lower;
            foreach ($first['attempts'] as $attempt) {
                $before = $attempt['allocators'][$table];
                $budget = $attempt['budgets'][$table];
                if (!is_int($before) || !is_int($budget) || $before < $lower || $before > $upper || !in_array($budget, $table === 'credits' ? [0, 1] : [1], true)) {
                    throw new RuntimeException('Unexplained opening allocator gap.');
                }
                $lower = $before;
                $upper = $before + $budget;
            }
            $actual = $current['tables'][$table]['auto_increment'];
            if (!is_int($actual) || $actual < $lower || $actual > $upper) {
                throw new RuntimeException('Unexplained opening allocator gap.');
            }
            foreach ($expected['tables'][$table]['rows'] as $encoded) {
                $id = (int) base64_decode($encoded['id']['value'], true);
                if ($actual <= $id) {
                    throw new RuntimeException('Opening allocator moved behind persisted rows.');
                }
            }
            $expected['tables'][$table]['auto_increment'] = $actual;
        }
        foreach ($expected['tables'] as &$table) {
            if (array_key_exists('rows', $table)) {
                self::sortRows($table['rows']);
            }
        }
        unset($table);
        if ($expected !== $current) {
            throw new RuntimeException('Unreceipted target state changed.');
        }
    }

    private static function addRow(array &$state, string $table, array $row): void
    {
        $encoded = self::encodeRow($row);
        foreach ($state['tables'][$table]['rows'] as $original) {
            if ($original['id'] === $encoded['id']) {
                throw new RuntimeException('Opening delta cannot replace an original row.');
            }
        }
        $state['tables'][$table]['rows'][] = $encoded;
    }

    private static function removeRow(array &$state, string $table, array $row): void
    {
        $encoded = self::encodeRow($row);
        $keys = array_keys($state['tables'][$table]['rows'], $encoded, true);
        if (count($keys) !== 1) {
            throw new RuntimeException('Original opening projection is not in the baseline.');
        }
        unset($state['tables'][$table]['rows'][$keys[0]]);
        $state['tables'][$table]['rows'] = array_values($state['tables'][$table]['rows']);
    }
}
