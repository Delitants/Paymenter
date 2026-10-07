<?php

namespace Tests\Fixtures\Opening;

use App\Services\BillmanagerMigration\Opening\PrivateProofFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit externally supplied, owned synthetic lifecycle; never a product restore API. */
final class ColdRestoreProbe
{
    public static function baseline(): array
    {
        self::lifecycle();
        $parent = getenv('OPENING_FIXTURE_ROOT');
        if (!$parent || !is_dir($parent)) {
            throw new RuntimeException('An explicit owned cold-restore fixture directory is required.');
        }
        $dir = $parent . '/cold-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException('Owned cold-restore fixture could not be created.');
        }
        ProofFactory::$directories[] = $dir;

        return [...self::run('snapshot', $dir), 'directory' => $dir];
    }

    public static function restore(array $baseline): array
    {
        return self::run('restore', $baseline['directory']);
    }

    private static function run(string $action, string $dir): array
    {
        $script = self::lifecycle();
        $environment = getenv();
        $environment['OPENING_QA_PARENT_PID'] = (string) getmypid();
        DB::disconnect();
        $pipes = [];
        $child = proc_open(['/usr/bin/python3', $script, $action, $dir], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        if (!is_resource($child)) {
            throw new RuntimeException('Owned cold-restore probe could not start.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($child);
        DB::purge();
        if ($exit !== 0) {
            throw new RuntimeException('Owned cold-restore probe failed: ' . $errors);
        }

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function lifecycle(): string
    {
        $script = getenv('OPENING_COLD_RESTORE_PROBE');
        if (PHP_SAPI !== 'cli' || !app()->environment('testing') || posix_geteuid() !== 0 || DB::connection()->getDatabaseName() !== 'paymenter_test' ||
            DB::transactionLevel() !== 0 || !$script) {
            throw new RuntimeException('Cold restore requires an explicitly owned isolated testing lifecycle.');
        }
        PrivateProofFile::read($script, 0);

        return $script;
    }
}
