<?php

namespace App\Services\BillmanagerMigration\Opening;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\MariaDbConnector;
use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use RuntimeException;

/** A dedicated local connection retains READ locks throughout an opening batch. */
final class OpeningArchiveReadLock
{
    private const TABLES = ['billmanager_records', 'billmanager_financial_records'];

    private const DATABASE_IDENTITY = 'SELECT DATABASE(), @@hostname, @@server_id, @@socket, @@datadir, @@version';

    private function __construct(private PDO $writer, private PDO $reader, private string $token, private int $session) {}

    public static function acquire(Connection $connection): self
    {
        $writer = $connection->getPdo();
        $config = $connection->getConfig();
        if ($writer->inTransaction() || !str_contains(strtolower($writer->getAttribute(PDO::ATTR_CONNECTION_STATUS)), 'unix') ||
            !is_string($config['unix_socket'] ?? null) || realpath($config['unix_socket']) === false) {
            throw new RuntimeException('Opening archive locks require the original local database before a write transaction.');
        }
        $config['options'][PDO::ATTR_PERSISTENT] = false;
        $connector = $connection->getDriverName() === 'mariadb' ? new MariaDbConnector : new MySqlConnector;
        $reader = $connector->connect($config);
        if ($reader->query(self::DATABASE_IDENTITY)->fetch(PDO::FETCH_NUM) !== $writer->query(self::DATABASE_IDENTITY)->fetch(PDO::FETCH_NUM) ||
            (int) $reader->query('SELECT @@innodb_table_locks')->fetchColumn() !== 1) {
            throw new RuntimeException('Opening archive lock database differs or does not support InnoDB table locks.');
        }
        $cluster = $reader->query("SHOW VARIABLES LIKE 'wsrep_on'")->fetch(PDO::FETCH_NUM);
        if ($cluster && strtoupper($cluster[1]) !== 'OFF') {
            throw new RuntimeException('Opening archive locks require a local standalone database.');
        }
        $tables = $reader->query("SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE = 'InnoDB'
              AND TABLE_NAME IN ('billmanager_records', 'billmanager_financial_records')")->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) !== count(self::TABLES)) {
            throw new RuntimeException('Opening archive lock tables differ.');
        }
        $reader->exec('SET SESSION lock_wait_timeout = 5');
        $reader->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $reader->exec('SET SESSION autocommit = 0');
        $token = 'opening-archive-' . bin2hex(random_bytes(16));
        $session = (int) $reader->query('SELECT CONNECTION_ID()')->fetchColumn();
        if ($session <= 0 || $session === (int) $writer->query('SELECT CONNECTION_ID()')->fetchColumn() ||
            (int) $reader->query("SELECT GET_LOCK('" . $token . "', 0)")->fetchColumn() !== 1) {
            throw new RuntimeException('A separate opening archive lock session is required.');
        }
        $reader->exec('LOCK TABLES `billmanager_records` READ, `billmanager_financial_records` READ');
        $lock = new self($writer, $reader, $token, $session);
        $lock->assertCurrent($connection);

        return $lock;
    }

    public function covers(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    public function fingerprint(OpeningTableInventory $inventory, string $table): array
    {
        if (!$this->covers($table)) {
            throw new RuntimeException('The requested opening table is outside the archive locks.');
        }

        return $inventory->fingerprint($table, $this->reader);
    }

    public function assertCurrent(Connection $connection): void
    {
        $row = $this->reader->query("SELECT CONNECTION_ID(), IS_USED_LOCK('" . $this->token . "'), @@autocommit")->fetch(PDO::FETCH_NUM);
        if ($connection->getPdo() !== $this->writer || !$row ||
            array_map('intval', $row) !== [$this->session, $this->session, 0]) {
            throw new RuntimeException('Opening archive lock session changed.');
        }
    }
}
