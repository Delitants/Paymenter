<?php

namespace App\Services\BillmanagerMigration\Opening;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class OpeningProcessContext
{
    private const COMMANDS = ['billmanager:openings:prepare', 'billmanager:openings:apply', 'billmanager:openings:verify'];

    private function __construct(private array $identity) {}

    public static function capture(): self
    {
        return new self(self::inspect());
    }

    public function assertCurrent(): void
    {
        if ($this->identity !== self::inspect()) {
            throw new RuntimeException('Operator process or target identity changed.');
        }
    }

    public function targetIdentity(): array
    {
        return $this->identity;
    }

    private static function inspect(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || !is_dir('/proc/self') ||
            !isset($argv[0], $argv[1]) || basename($argv[0]) !== 'artisan' || !in_array($argv[1], self::COMMANDS, true)) {
            throw new RuntimeException('An isolated root native opening console is required.');
        }
        $namespace = readlink('/proc/self/ns/net');
        if ($namespace === false || $namespace === readlink('/proc/1/ns/net')) {
            throw new RuntimeException('Opening requires an outbound-disabled network namespace.');
        }
        $interfaces = array_map(fn ($line) => trim(explode(':', $line, 2)[0]), array_filter(array_slice(file('/proc/net/dev'), 2), fn ($line) => str_contains($line, ':')));
        if (array_diff($interfaces, ['lo']) || preg_match('/^\S+\s+(?!0{8}\s)/m', implode("\n", array_slice(file('/proc/net/route'), 1)))) {
            throw new RuntimeException('Opening network interfaces or routes are unsafe.');
        }

        return [...self::runtimeTargetIdentity(), 'network_namespace_inode' => $namespace, 'operator_uid' => posix_geteuid()];
    }

    public static function runtimeTargetIdentity(): array
    {
        $connection = DB::connection();
        $socket = $connection->getConfig('unix_socket');
        if (!is_string($socket) || $socket === '' || !$socketPath = realpath($socket)) {
            throw new RuntimeException('Opening requires an identified local database socket.');
        }
        $row = (array) $connection->selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@server_id AS server_id, @@socket AS socket, @@datadir AS datadir, @@version AS version');
        $pdo = $connection->getPdo()->getAttribute(\PDO::ATTR_CONNECTION_STATUS);
        if (!str_contains(strtolower($pdo), 'unix') || $row['db'] !== $connection->getConfig('database') || realpath($row['socket']) !== $socketPath) {
            throw new RuntimeException('Authenticated database target identity conflicts.');
        }

        return ['machine_id_sha256' => hash_file('sha256', '/etc/machine-id'), 'runtime_realpath' => realpath(base_path()),
            'db_database' => $row['db'], 'db_socket_realpath' => $socketPath, 'db_server_uuid_or_fingerprint' => hash('sha256', CanonicalPolicy::bytes($row))];
    }
}
