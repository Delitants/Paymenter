<?php

namespace App\Services\BillmanagerMigration\Opening;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OpeningBackupVerifier
{
    public const OPTIONS = ['--skip-comments', '--skip-dump-date', '--hex-blob', '--order-by-primary', '--routines', '--events', '--triggers', '--single-transaction'];

    public function assertReady(OpeningBundle $bundle, array $batchAttempts = [], ?OpeningTargetState $state = null): void
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
        $reference = $bundle->referenceRecord('target_sql');
        $ignoreAllocator = $batchAttempts !== [];
        $baseline = PrivateProofFile::inspect($reference['path'], 0, static fn ($file) => self::dumpFingerprints($file, $ignoreAllocator));
        if (!hash_equals($reference['sha256'], $baseline['sha256']) || $baseline['sha256'] !== $bundle->data()['baseline_sql_sha256'] ||
            $acceptance['baseline_sql_sha256'] !== $baseline['sha256'] || $acceptance['restored_sql_sha256'] !== $baseline['sha256'] ||
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
        $env = ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C'];
        $user = config('account-opening.backup_socket_user');
        if ($user === null) {
            $user = (string) $connection->getConfig('username');
            $env['MYSQL_PWD'] = (string) $connection->getConfig('password');
        } elseif (!is_string($user) || !preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,79}$/D', $user)) {
            throw new RuntimeException('Invalid local backup socket user.');
        }
        $pipes = [];
        $child = proc_open([$binary, '--no-defaults', '--protocol=socket', '--socket=' . $bundle->target()['db_socket_realpath'], '--user=' . $user, ...self::OPTIONS, $bundle->target()['db_database']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, base_path(), $env);
        if (!is_resource($child)) {
            throw new RuntimeException('Local baseline readback failed.');
        }
        fclose($pipes[0]);
        try {
            $readback = self::dumpFingerprints($pipes[1], $ignoreAllocator);
        } finally {
            fclose($pipes[1]);
            $status = proc_close($child);
        }
        if ($status !== 0 || !hash_equals($baseline['comparison_sha256'], $readback['comparison_sha256'])) {
            throw new RuntimeException('Current full baseline SQL differs.');
        }
        if ($batchAttempts) {
            $inventory = StrictProofJson::decode(PrivateProofFile::read($bundle->reference('target_inventory'), 0)['bytes']);
            ($state ?? new OpeningTargetState)->assertInitial($inventory, $batchAttempts);
        }
        $process->assertCurrent();
    }

    /** @param resource $stream */
    private static function dumpFingerprints($stream, bool $ignoreAllocator): array
    {
        $raw = hash_init('sha256');
        if (!$ignoreAllocator) {
            if (hash_update_stream($raw, $stream) === false || !feof($stream)) {
                throw new RuntimeException('Incomplete local baseline readback.');
            }
            $digest = hash_final($raw);

            return ['sha256' => $digest, 'comparison_sha256' => $digest];
        }

        $normalized = hash_init('sha256');
        $atLineStart = true;
        $definition = null;
        $definitions = 0;
        while (($chunk = fgets($stream, 65537)) !== false) {
            hash_update($raw, $chunk);
            if ($definition === null && $atLineStart && str_starts_with($chunk, 'CREATE TABLE `account_opening_batches` (')) {
                $definition = '';
            }
            if ($definition === null) {
                hash_update($normalized, $chunk);
            } else {
                $definition .= $chunk;
                if (strlen($definition) > 262144) {
                    throw new RuntimeException('Opening batch dump definition exceeds its bound.');
                }
                if ($atLineStart && str_starts_with($chunk, ') ') && str_ends_with(rtrim($chunk, "\n"), ';')) {
                    hash_update($normalized, OpeningTargetState::normalizeDefinition($definition));
                    $definitions++;
                    $definition = null;
                }
            }
            $atLineStart = str_ends_with($chunk, "\n");
        }
        if (!feof($stream) || $definition !== null || $definitions !== 1) {
            throw new RuntimeException('Exact opening batch dump definition is required.');
        }

        return ['sha256' => hash_final($raw), 'comparison_sha256' => hash_final($normalized)];
    }
}
