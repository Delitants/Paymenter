<?php

namespace Tests\Fixtures\Opening;

use App\Services\Accounts\OpeningEvidence;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\OpeningBackupVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningPreparation;
use App\Services\BillmanagerMigration\Opening\OpeningProcessContext;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use App\Services\BillmanagerMigration\Opening\ReleaseVerifier;
use Illuminate\Support\Facades\DB;

/** Generated, fictional external-custodian proofs; never a product approval API. */
final class AcceptedFixture
{
    public static function make(string $opening = '12.3400', string $limit = '0.0000', ?string $captured = null, bool $heartbeat = true, bool $realBackup = false, array $tables = []): array
    {
        $openedAt = gmdate('Y-m-d\TH:i:s\Z');
        $captured ??= $openedAt;
        $p = MigrationFixture::input(array_replace(['subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => $opening, 'creditlimit' => $limit, 'allowpostpaid' => 'off', 'active' => 'on']]], $tables), $captured, (new \DateTimeImmutable($captured))->modify('-2 years')->format('Y-m-d H:i:s'));
        $dir = $p['dir'];
        $process = OpeningProcessContext::capture();
        $target = $process->targetIdentity();
        $input = OpeningBundle::load($p['path']);
        $report = (new OpeningPreparation)->prepare($input);
        $scope = array_values(array_filter($report['accounts'], fn ($row) => $row['disposition'] === 'candidate'));
        $write = static fn ($name, $value) => ProofFactory::write($dir . '/' . $name . '.json', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $write('preparation', $report);
        $write('inventory', (new OpeningTargetState)->capture());
        // These are generated synthetic proofs. Independent cold restore is a
        // separate acceptance, not implied by this test-only issuer.
        ProofFactory::write($dir . '/baseline.sql', $realBackup ? self::dump($target) : '-- synthetic permit fixture only');
        $baseline = hash_file('sha256', $dir . '/baseline.sql');
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $name = substr($file->getPathname(), strlen(base_path()) + 1);
            if (!str_starts_with($name, 'storage/logs/') && !str_starts_with($name, 'storage/framework/cache/data/') && !str_starts_with($name, 'storage/framework/sessions/')) {
                $files[$name] = hash_file('sha256', $file->getPathname());
            }
        }
        $write('manifest', ['schema_version' => 1, 'purpose' => 'account-funding-file-manifest', 'files' => $files, 'generated_paths' => ['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions']]);
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $purposes = ['account-funding-release', 'account-opening-fence', 'opening-backup-acceptance', 'billmanager-inactive-opening-approval'];
        $write('trust', ['schema_version' => 1, 'purpose' => 'opening-trust', 'keys' => ['fixture' => ['issuer' => 'fixture-custodian', 'public_key' => openssl_pkey_get_details($key)['key'], 'purposes' => $purposes]], 'revoked_keys' => [], 'revoked_nonces' => []]);
        $stamp = gmdate('Y-m-d\TH:i:s\Z');
        $sign = static function ($name, $purpose, $fields) use ($dir, $key, $stamp) {
            $payload = ['schema_version' => 1, 'purpose' => $purpose, 'issuer' => 'fixture-custodian', 'not_before' => $stamp, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + ($purpose === 'account-opening-fence' ? 60 : 3600)), 'nonce' => 'fixture-' . $name, ...$fields];
            $bytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            openssl_sign($bytes, $signature, $key, OPENSSL_ALGO_SHA256);
            ProofFactory::write($dir . '/' . $name . '.json', $bytes);
            ProofFactory::write($dir . '/' . $name . '-signature.json', json_encode(['schema_version' => 1, 'key_id' => 'fixture', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($signature)], JSON_THROW_ON_ERROR));
        };
        $configuration = ReleaseVerifier::configurationHash();
        $sign('release', 'account-funding-release', ['manifest_path' => $dir . '/manifest.json', 'manifest_sha256' => hash_file('sha256', $dir . '/manifest.json'), 'target_identity' => $target, 'configuration_sha256' => $configuration, 'decision_ref_sha256' => str_repeat('a', 64)]);
        $release = hash_file('sha256', $dir . '/release.json');
        $sign('fence', 'account-opening-fence', [...ProofFactory::fence(), 'issued_at' => $openedAt, 'not_before' => $openedAt, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($openedAt) + 60), 'target_identity' => $target, 'source_population_fingerprint' => $report['population_fingerprint'], 'target_baseline_sql_sha256' => $baseline]);
        $sequence = 1;
        $renew = static function () use ($sign, $target, $report, $baseline, &$sequence) {
            $stamp = gmdate('Y-m-d\TH:i:s\Z');
            $sign('live-fence', 'account-opening-fence', [...ProofFactory::fence(), 'sequence' => ++$sequence, 'issued_at' => $stamp, 'not_before' => $stamp, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 60),
                'target_identity' => $target, 'source_population_fingerprint' => $report['population_fingerprint'], 'target_baseline_sql_sha256' => $baseline]);
        };
        $renew();
        $sign('backup', 'opening-backup-acceptance', ['baseline_sql_sha256' => $baseline, 'target_identity' => $target, 'configuration_sha256' => $configuration, 'release_sha256' => $release, 'restored_sql_sha256' => $baseline, 'accepted_at' => $stamp, 'dump_binary_sha256' => hash_file('sha256', '/usr/bin/mysqldump'), 'dump_options' => ['--skip-comments', '--skip-dump-date', '--hex-blob', '--order-by-primary', '--routines', '--events', '--triggers', '--single-transaction']]);
        $refs = $p['input']['references'];
        foreach (['preparation_input' => 'input.json', 'preparation' => 'preparation.json', 'target_inventory' => 'inventory.json', 'target_sql' => 'baseline.sql', 'backup_acceptance' => 'backup.json', 'backup_signature' => 'backup-signature.json', 'release' => 'release.json', 'release_signature' => 'release-signature.json', 'freeze_receipt' => 'fence.json', 'freeze_signature' => 'fence-signature.json'] as $name => $file) {
            $refs[$name] = ['path' => $dir . '/' . $file, 'sha256' => hash_file('sha256', $dir . '/' . $file)];
        }
        $data = [...$p['input'], 'purpose' => 'billmanager-inactive-opening-bundle', 'scope' => $scope, 'references' => $refs, 'target_identity' => $target, 'baseline_sql_sha256' => $baseline, 'release_sha256' => $release, 'freeze_id' => 'fixture-freeze', 'freeze_receipt_sha256' => hash_file('sha256', $dir . '/fence.json'), 'policy_sha256' => $report['policy_sha256'], 'scope_fingerprint' => hash('sha256', CanonicalPolicy::bytes($scope))];
        $write('bundle', $data);
        $sign('approval', 'billmanager-inactive-opening-approval', ['bundle_sha256' => hash_file('sha256', $dir . '/bundle.json'), 'release_sha256' => $release, 'target_identity' => $target, 'baseline_sql_sha256' => $baseline, 'freeze_id' => $data['freeze_id'], 'freeze_receipt_sha256' => $data['freeze_receipt_sha256'], 'login_cutoff' => $data['login_cutoff'], 'activation_cutoff' => $data['activation_cutoff'], 'policy_sha256' => $data['policy_sha256'], 'scope_fingerprint' => $data['scope_fingerprint'], 'accounts' => $scope, 'capabilities' => ['inactive_opening'], 'status' => 'approved', 'human_decision_sha256' => str_repeat('b', 64)]);
        config(['account-opening.fence_index_path' => null, 'account-opening.trust_path' => $dir . '/trust.json', 'account-opening.fence_path' => $dir . '/live-fence.json', 'account-opening.fence_signature_path' => $dir . '/live-fence-signature.json']);
        if ($heartbeat) {
            if (!function_exists('pcntl_fork')) {
                throw new \RuntimeException('Synthetic custodian requires the installed CLI process extension.');
            }
            $publish = static function () use ($sign, $dir, $target, $report, $baseline, &$sequence) {
                $name = 'lease-' . bin2hex(random_bytes(8));
                $stamp = gmdate('Y-m-d\TH:i:s\Z');
                $sign($name, 'account-opening-fence', [...ProofFactory::fence(), 'sequence' => ++$sequence, 'issued_at' => $stamp, 'not_before' => $stamp, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 60),
                    'target_identity' => $target, 'source_population_fingerprint' => $report['population_fingerprint'], 'target_baseline_sql_sha256' => $baseline]);
                $reference = ['schema_version' => 1, 'purpose' => 'account-opening-fence-reference'];
                foreach (['payload' => $name . '.json', 'signature' => $name . '-signature.json'] as $field => $file) {
                    $reference[$field] = ['path' => $dir . '/' . $file, 'sha256' => hash_file('sha256', $dir . '/' . $file)];
                }
                $temporary = $dir . '/next-reference-' . bin2hex(random_bytes(8)) . '.json';
                ProofFactory::write($temporary, json_encode($reference, JSON_THROW_ON_ERROR));
                rename($temporary, $dir . '/current-lease.json');
            };
            $publish();
            config(['account-opening.fence_index_path' => $dir . '/current-lease.json']);
            $pid = pcntl_fork();
            if ($pid < 0) {
                throw new \RuntimeException('Synthetic custodian process could not start.');
            }
            if ($pid === 0) {
                // This branch never calls the application, DB, network or opening API.
                while (true) {
                    usleep(10000000);
                    $publish();
                }
            }
            ProofFactory::$children[] = $pid;
        }
        $evidence = new OpeningEvidence('billmanager:source.example.invalid', '10', $p['owner_id'], 'USD', $scope[0]['opening'], $scope[0]['limit_exact'], $report['snapshot_sha256'], $data['policy_sha256'], hash_file('sha256', $dir . '/approval.json'), false);

        return [...$p, 'bundle' => OpeningBundle::load($dir . '/bundle.json'), 'data' => $data, 'evidence' => $evidence, 'process' => $process, 'sign' => $sign, 'renew' => $renew];
    }

    private static function dump(array $target): string
    {
        $connection = DB::connection();
        $pipes = [];
        $child = proc_open(['/usr/bin/mysqldump', '--no-defaults', '--socket=' . $target['db_socket_realpath'], '--user=' . $connection->getConfig('username'),
            ...OpeningBackupVerifier::OPTIONS, $target['db_database']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, base_path(), ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C', 'MYSQL_PWD' => (string) $connection->getConfig('password')]);
        if (!is_resource($child)) {
            throw new \RuntimeException('Synthetic baseline dump failed.');
        }
        fclose($pipes[0]);
        $bytes = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($child) !== 0 || $bytes === false) {
            throw new \RuntimeException('Synthetic baseline dump failed.');
        }

        return $bytes;
    }
}
