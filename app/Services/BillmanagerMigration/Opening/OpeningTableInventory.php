<?php

namespace App\Services\BillmanagerMigration\Opening;

use Illuminate\Database\Connection;
use PDO;

final class OpeningTableInventory
{
    private array $primaryColumns = [];

    public function __construct(private Connection $connection)
    {
        foreach ($connection->selectFromWriteConnection("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY'
            ORDER BY TABLE_NAME, ORDINAL_POSITION") as $column) {
            $this->primaryColumns[$column->table_name][] = $column->column_name;
        }
    }

    /** @return array{row_count:int,rows_sha256:string} */
    public function fingerprint(string $table, ?PDO $reader = null): array
    {
        $grammar = $this->connection->getQueryGrammar();
        $keys = array_map($grammar->wrap(...), $this->primaryColumns[$table] ?? []);
        $query = 'SELECT * FROM ' . $grammar->wrapTable($table);
        if ($keys) {
            $query .= ' ORDER BY ' . implode(', ', $keys);
        }
        $pdo = $reader ?? $this->connection->getPdo();
        $previous = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = null;
        $count = 0;
        $hash = hash_init('sha256');
        $digests = [];
        try {
            $statement = $pdo->query($query);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $digest = hash('sha256', CanonicalPolicy::bytes(OpeningTargetState::encodeRow($row)), true);
                $count++;
                if ($keys) {
                    hash_update($hash, $digest);
                } else {
                    $digests[] = $digest;
                }
            }
            // A table without a primary key has no stable native row order.
            // Sort its fixed-size digests and preserve duplicates.
            sort($digests, SORT_STRING);
            foreach ($digests as $digest) {
                hash_update($hash, $digest);
            }

            return ['row_count' => $count, 'rows_sha256' => hash_final($hash)];
        } finally {
            try {
                $statement?->closeCursor();
            } finally {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $previous);
            }
        }
    }
}
