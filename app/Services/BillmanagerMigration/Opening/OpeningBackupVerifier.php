<?php

namespace App\Services\BillmanagerMigration\Opening;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OpeningBackupVerifier
{
    public const OPTIONS = ['--skip-comments', '--skip-dump-date', '--hex-blob', '--order-by-primary', '--routines', '--events', '--triggers', '--single-transaction'];

    public function assertReady(OpeningBundle $bundle, array $batchAttempts = []): void
    {
        if (!$bundle->executable()) {
            throw new RuntimeException('Planning input is not backup execution evidence.');
        }
        $process = OpeningProcessContext::capture();
        $process->assertCurrent();
        if ($process->targetIdentity() !== $bundle->target()) {
            throw new RuntimeException('Backup target changed.');
        }
        $trust = config('account-opening.trust_path');
        if (!is_string($trust) || $trust === '') {
            throw new RuntimeException('Backup acceptance issuer is not configured.');
        }
        $acceptance = SignedAttestation::verify($bundle->reference('backup_acceptance'), $bundle->reference('backup_signature'), 'opening-backup-acceptance', $trust, new DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $baseline = PrivateProofFile::read($bundle->reference('target_sql'), 0);
        if ($baseline['sha256'] !== $bundle->data()['baseline_sql_sha256'] || $acceptance['baseline_sql_sha256'] !== $baseline['sha256'] || $acceptance['restored_sql_sha256'] !== $baseline['sha256'] ||
            $acceptance['target_identity'] !== $bundle->target() || $acceptance['release_sha256'] !== $bundle->data()['release_sha256'] || $acceptance['configuration_sha256'] !== ReleaseVerifier::configurationHash() ||
            $acceptance['dump_options'] !== self::OPTIONS) {
            throw new RuntimeException('Backup or cold restore acceptance changed.');
        }
        ProofSchema::date($acceptance['accepted_at']);
        $binary = '/usr/bin/mysqldump';
        if (!is_file($binary) || hash_file('sha256', $binary) !== $acceptance['dump_binary_sha256']) {
            throw new RuntimeException('Accepted local dump binary changed.');
        }
        $connection = DB::connection();
        $env = ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C', 'MYSQL_PWD' => (string) $connection->getConfig('password')];
        $pipes = [];
        $child = proc_open([$binary, '--no-defaults', '--socket=' . $bundle->target()['db_socket_realpath'], '--user=' . $connection->getConfig('username'), ...self::OPTIONS, $bundle->target()['db_database']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, base_path(), $env);
        if (!is_resource($child)) {
            throw new RuntimeException('Local baseline readback failed.');
        }
        fclose($pipes[0]);
        $readback = '';
        $hash = hash_init('sha256');
        try {
            if ($batchAttempts) {
                $readback = stream_get_contents($pipes[1]);
                $complete = $readback !== false;
            } else {
                $complete = hash_update_stream($hash, $pipes[1]) !== false;
            }
            if (!$complete) {
                throw new RuntimeException('Incomplete local baseline readback.');
            }
        } finally {
            fclose($pipes[1]);
            $status = proc_close($child);
        }
        $matches = $batchAttempts
            ? self::withoutBatchAllocator($readback) === self::withoutBatchAllocator($baseline['bytes'])
            : hash_final($hash) === $baseline['sha256'];
        if ($batchAttempts) {
            $inventory = StrictProofJson::decode(PrivateProofFile::read($bundle->reference('target_inventory'), 0)['bytes']);
            (new OpeningTargetState)->assertInitial($inventory, $batchAttempts);
        }
        if ($status !== 0 || !$matches) {
            throw new RuntimeException('Current full baseline SQL differs.');
        }
        $process->assertCurrent();
    }

    private static function withoutBatchAllocator(string $sql): string
    {
        $count = 0;
        $normalized = preg_replace_callback('/^CREATE TABLE `account_opening_batches` \(.*?^\) .*?;$/ms', static fn ($match) => OpeningTargetState::normalizeDefinition($match[0]), $sql, -1, $count);
        if ($count !== 1 || $normalized === null) {
            throw new RuntimeException('Exact opening batch dump definition is required.');
        }

        return $normalized;
    }
}
