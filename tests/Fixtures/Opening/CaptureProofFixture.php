<?php

namespace Tests\Fixtures\Opening;

use App\Services\Accounts\OpeningEvidence;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningProcessContext;

/** Fictional structural records. These fixed dates never prove native chronology. */
final class CaptureProofFixture
{
    public static function ready(array $overrides = []): array
    {
        $p = self::executable($overrides);
        $data = $p['data'];
        $approval = json_decode(file_get_contents($p['dir'] . '/approval.json'), true, flags: JSON_THROW_ON_ERROR);
        $approval['schema_version'] = 2;
        $approval['nonce'] = 'fixture-v2-approval';
        $approval['bundle_sha256'] = $p['bundle']->digest();
        foreach (['release_sha256', 'freeze_receipt_sha256', 'source_capture_id', 'control_epoch_sha256', 'capture_fence_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256'] as $field) {
            $approval[$field] = $data[$field];
        }
        $pair = ($p['sign'])('approval-v2', $approval);
        $head = $p['history']['records']['leases'][2];
        $pointer = ['schema_version' => 2, 'purpose' => 'account-opening-fence-reference', ...$head, 'history' => $p['history']['history_ref']];
        $pointerRef = ($p['history']['records']['write'])('current-pointer', $pointer);
        config(['account-opening.fence_index_path' => $pointerRef['path'], 'account-opening.journal_directory' => $p['dir']]);
        $account = $data['scope'][0];
        $evidence = new OpeningEvidence($account['source_system'], $account['source_account'], $account['owner_id'], $account['currency'], $account['opening'], $account['limit_exact'],
            $p['bundle']->snapshot()->checksum(), $data['policy_sha256'], $pair['payload']['sha256'], false);

        return [...$p, 'approval_path' => $pair['payload']['path'], 'signature_path' => $pair['signature']['path'], 'approval' => $approval, 'evidence' => $evidence,
            'pointer_path' => $pointerRef['path'], 'pointer_record' => $pointer];
    }

    /** Fictional structural renewal. Actual durable independent publication is Task 8. */
    public static function publish(array $p, array $changes = []): array
    {
        $pairs = $p['history']['records']['leases'];
        $prior = $pairs[array_key_last($pairs)];
        $value = json_decode(file_get_contents($prior['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        $now = time();
        $sequence = count($pairs) + 1;
        $issuedNs = (int) $value['issued_monotonic_ns'] + max(1, ($now - strtotime($value['issued_at'])) * 1000000000);
        $value = array_replace($value, ['sequence' => $sequence, 'nonce' => 'fixture-ready-' . $sequence, 'previous_lease_sha256' => $prior['payload']['sha256'],
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'not_before' => gmdate('Y-m-d\TH:i:s\Z', $now), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 60),
            'issued_monotonic_ns' => (string) $issuedNs, 'expires_monotonic_ns' => (string) ($issuedNs + 60000000000)], $changes);
        $head = ($p['sign'])('ready-' . $sequence . '-' . bin2hex(random_bytes(4)), $value);
        $pairs[] = $head;
        $history = ($p['history']['records']['write'])('history-' . $sequence . '-' . bin2hex(random_bytes(4)), ['schema_version' => 1, 'purpose' => 'account-opening-lease-history', 'leases' => $pairs]);
        $pointer = ['schema_version' => 2, 'purpose' => 'account-opening-fence-reference', ...$head, 'history' => $history];
        ProofFactory::write($p['pointer_path'], json_encode($pointer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $p['pointer_record'] = $pointer;
        $p['history']['history_ref'] = $history;
        $p['history']['records']['leases'] = $pairs;

        return $p;
    }

    /** Structural-only executable bundle; never genuine chronological acceptance. */
    public static function executable(array $overrides = []): array
    {
        $origin = time() - 30;
        $stamp = static fn (int $offset) => gmdate('Y-m-d\TH:i:s\Z', $origin + $offset);
        $p = AcceptedFixture::make(captured: $stamp(2), heartbeat: false, realBackup: true);
        $readyOffset = time() - $origin;
        $runtime = OpeningProcessContext::runtimeTargetIdentity();
        $target = $p['process']->targetIdentity();
        $scenario = $overrides['scenario'] ?? '';
        $acceptance = ['runtime_target_identity' => $runtime, 'snapshot_sha256' => $p['bundle']->snapshot()->checksum(), 'source_population_fingerprint' => $p['data']['references']['population_review']['sha256'],
            'login_cutoff' => $p['data']['login_cutoff'], 'activation_cutoff' => $p['data']['activation_cutoff']];
        $ready = ['target_identity' => $target, 'source_population_fingerprint' => $acceptance['source_population_fingerprint'], 'target_baseline_sql_sha256' => $p['data']['baseline_sql_sha256'],
            'issued_at' => $stamp($readyOffset), 'not_before' => $stamp($readyOffset), 'expires_at' => $stamp($readyOffset + 60),
            'issued_monotonic_ns' => (string) (1000000000 + $readyOffset * 1000000000), 'expires_monotonic_ns' => (string) (61000000000 + $readyOffset * 1000000000)];
        $collector = ['snapshot_sha256' => $acceptance['snapshot_sha256'], ...($overrides['collector'] ?? [])];
        $epoch = ['runtime_target_identity' => $runtime];
        $leases = [1 => ['runtime_target_identity' => $runtime], 2 => ['runtime_target_identity' => $runtime]];
        if ($scenario === 'acceptance_before_collector') {
            $acceptance['issued_at'] = $acceptance['not_before'] = $stamp(2);
        } elseif ($scenario === 'ready_before_acceptance') {
            $ready = array_replace($ready, ['issued_at' => $stamp(14), 'not_before' => $stamp(14), 'expires_at' => $stamp(74), 'issued_monotonic_ns' => '15000000000', 'expires_monotonic_ns' => '75000000000']);
        } elseif ($scenario === 'ready_before_backup') {
            $ready = array_replace($ready, ['issued_at' => $stamp(20), 'not_before' => $stamp(20), 'expires_at' => $stamp(80), 'issued_monotonic_ns' => '21000000000', 'expires_monotonic_ns' => '81000000000']);
        } elseif ($scenario === 'ready_at_acceptance_expiry') {
            $acceptance['expires_at'] = $ready['issued_at'];
        } elseif (in_array($scenario, ['missing_preparation_coverage', 'missing_preparation_utc', 'missing_preparation_monotonic'], true)) {
            if ($scenario !== 'missing_preparation_monotonic') {
                $leases[2]['expires_at'] = $stamp(17);
            }
            if ($scenario !== 'missing_preparation_utc') {
                $leases[2]['expires_monotonic_ns'] = '18000000000';
            }
        } elseif ($scenario === 'preparation_meets_ready') {
            $leases[2]['expires_at'] = $ready['issued_at'];
            $leases[2]['expires_monotonic_ns'] = $ready['issued_monotonic_ns'];
        } elseif ($scenario === 'source_boot_id') {
            $collector['source_boot_id'] = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        } elseif ($scenario === 'runtime_database' || $scenario === 'runtime_socket') {
            $leases[1]['runtime_target_identity'][$scenario === 'runtime_database' ? 'db_database' : 'db_socket_realpath'] = $scenario === 'runtime_database' ? 'changed_database' : '/fixture/changed.sock';
        } elseif ($scenario === 'capture_id') {
            $acceptance['source_capture_id'] = 'changed-capture';
        } elseif ($scenario === 'snapshot_digest') {
            $acceptance['snapshot_sha256'] = str_repeat('f', 64);
        } elseif ($scenario === 'population_digest') {
            $acceptance['source_population_fingerprint'] = $ready['source_population_fingerprint'] = str_repeat('f', 64);
        } elseif ($scenario === 'source_identity') {
            $acceptance['source_identity'] = 'different.example.invalid';
        } elseif ($scenario === 'login_cutoff' || $scenario === 'activation_cutoff') {
            $acceptance[$scenario] = '2024-01-02 00:00:00';
        }
        $leases[3] = $ready;
        $h = self::history(['origin_at' => $stamp(0), 'epoch' => $epoch, 'collector' => $collector, 'acceptance' => $acceptance, 'lease_changes' => $leases,
            'preparation_prefix_length' => $scenario === 'preparation_prefix' ? 1 : 2]);
        $sign = $h['records']['sign'];
        $read = static fn ($path) => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $release = $sign('release', $read($p['bundle']->reference('release')));
        $backupValue = $read($p['bundle']->reference('backup_acceptance'));
        $backup = $sign('backup', [...$backupValue, 'release_sha256' => $release['payload']['sha256']]);
        $refs = $p['data']['references'];
        foreach (['release' => $release['payload'], 'release_signature' => $release['signature'], 'backup_acceptance' => $backup['payload'], 'backup_signature' => $backup['signature'],
            'control_epoch' => $h['records']['epoch_ref'], 'collector_receipt' => $h['records']['collector_ref'], 'capture_fence' => $h['records']['leases'][0]['payload'], 'capture_fence_signature' => $h['records']['leases'][0]['signature'],
            'capture_history' => $h['records']['capture_history_ref'], 'preparation_history' => $h['records']['preparation_history_ref'], 'capture_acceptance' => $h['records']['capture_acceptance_ref'], 'capture_acceptance_signature' => $h['records']['capture_acceptance_signature_ref'],
            'freeze_receipt' => $h['records']['first_ready_ref'], 'freeze_signature' => $h['records']['first_ready_signature_ref']] as $name => $ref) {
            $refs[$name] = $ref;
        }
        $data = [...$p['data'], 'schema_version' => 2, 'references' => $refs, 'release_sha256' => $release['payload']['sha256'], 'freeze_receipt_sha256' => $refs['freeze_receipt']['sha256'],
            'source_capture_id' => 'fixture-capture', 'control_epoch_sha256' => $refs['control_epoch']['sha256'], 'capture_fence_sha256' => $refs['capture_fence']['sha256'],
            'capture_acceptance_sha256' => $refs['capture_acceptance']['sha256'], 'preparation_history_sha256' => $refs['preparation_history']['sha256']];
        if (in_array($scenario, ['observer_boot_id', 'control_profile'], true)) {
            $value = $read($refs['control_epoch']['path']);
            if ($scenario === 'observer_boot_id') {
                $value['observer_identity']['boot_id'] = '11111111-2222-3333-4444-555555555555';
            } else {
                $value['control_profile_sha256'] = str_repeat('f', 64);
            }
            ProofFactory::write($refs['control_epoch']['path'], json_encode($value, JSON_THROW_ON_ERROR));
            $data['references']['control_epoch']['sha256'] = hash_file('sha256', $refs['control_epoch']['path']);
        }
        ProofFactory::write($p['dir'] . '/bundle-v2.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        config(['account-opening.trust_path' => $h['trust_path']]);

        return [...$p, 'bundle' => OpeningBundle::load($p['dir'] . '/bundle-v2.json'), 'data' => $data, 'trust_path' => $h['trust_path'], 'history' => $h, 'sign' => $sign, 'scenario' => $scenario, 'fictional_structural_fixture' => true];
    }

    /** Complete fictional parser graph; fixed dates do not establish real custody. */
    public static function history(array $overrides = []): array
    {
        $root = getenv('OPENING_FIXTURE_ROOT');
        if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0 || !$root || !is_dir($root)) {
            throw new \RuntimeException('Owned root history fixture is required.');
        }
        $dir = $root . '/capture-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        ProofFactory::$directories[] = $dir;
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $ref = static fn ($path) => ['path' => $path, 'sha256' => hash_file('sha256', $path)];
        $write = static function (string $name, array $value) use ($dir, $ref): array {
            $path = $dir . '/' . $name . '.json';
            ProofFactory::write($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $ref($path);
        };
        $sign = static function (string $name, array $value) use ($write, $key): array {
            $payload = $write($name, $value);
            openssl_sign(file_get_contents($payload['path']), $raw, $key, OPENSSL_ALGO_SHA256);
            $signature = $write($name . '-signature', ['schema_version' => 1, 'key_id' => 'fixture', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($raw)]);

            return ['payload' => $payload, 'signature' => $signature];
        };
        $base = self::records();
        $origin = strtotime($overrides['origin_at'] ?? $base['capture']['issued_at']);
        $shift = $origin - strtotime($base['capture']['issued_at']);
        foreach ($base as &$record) {
            foreach ($record as $field => &$value) {
                if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) {
                    $value = gmdate('Y-m-d\TH:i:s\Z', strtotime($value) + $shift);
                }
            }
            unset($value);
        }
        unset($record);
        $epoch = $write('epoch', array_replace($base['epoch'], $overrides['epoch'] ?? []));
        $collector = $write('collector', array_replace($base['collector'], ['control_epoch_sha256' => $epoch['sha256']], $overrides['collector'] ?? []));
        $leases = [];
        for ($sequence = 1; $sequence <= 2; $sequence++) {
            $offset = ($sequence - 1) * 10;
            $value = array_replace($base['capture'], ['nonce' => 'fixture-capture-' . $sequence, 'control_epoch_sha256' => $epoch['sha256'], 'sequence' => $sequence,
                'not_before' => gmdate('Y-m-d\TH:i:s\Z', strtotime($base['capture']['issued_at']) + $offset),
                'issued_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($base['capture']['issued_at']) + $offset),
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($base['capture']['issued_at']) + $offset + 60),
                'issued_monotonic_ns' => (string) (1000000000 + $offset * 1000000000), 'expires_monotonic_ns' => (string) (61000000000 + $offset * 1000000000),
                'previous_lease_sha256' => $sequence === 1 ? null : $leases[$sequence - 2]['payload']['sha256']], $overrides['lease_changes'][$sequence] ?? []);
            $leases[] = $sign('capture-' . $sequence, $value);
        }
        $historyValue = ['schema_version' => 1, 'purpose' => 'account-opening-lease-history', 'leases' => $leases];
        $captureHistory = $write('capture-history', $historyValue);
        $preparationHistory = $write('preparation-history', [...$historyValue, 'leases' => array_slice($leases, 0, $overrides['preparation_prefix_length'] ?? 2)]);
        $acceptance = $sign('acceptance', array_replace($base['acceptance'], ['issued_at' => gmdate('Y-m-d\TH:i:s\Z', $origin + 15), 'not_before' => gmdate('Y-m-d\TH:i:s\Z', $origin + 15), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $origin + 21615),
            'control_epoch_sha256' => $epoch['sha256'], 'capture_fence_sha256' => $leases[0]['payload']['sha256'], 'collector_receipt_sha256' => $collector['sha256'],
            'capture_history_sha256' => $captureHistory['sha256']], $overrides['acceptance'] ?? []));
        $ready = $sign('ready', array_replace($base['ready'], ['nonce' => 'fixture-ready-3', 'sequence' => 3, 'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $origin + 20), 'not_before' => gmdate('Y-m-d\TH:i:s\Z', $origin + 20), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $origin + 80),
            'control_epoch_sha256' => $epoch['sha256'], 'capture_fence_sha256' => $leases[0]['payload']['sha256'], 'capture_acceptance_sha256' => $acceptance['payload']['sha256'],
            'preparation_history_sha256' => $preparationHistory['sha256'], 'previous_lease_sha256' => $leases[1]['payload']['sha256'],
            'issued_monotonic_ns' => '21000000000', 'expires_monotonic_ns' => '81000000000'], $overrides['lease_changes'][3] ?? []));
        $leases[] = $ready;
        $history = $write('current-history', [...$historyValue, 'leases' => $leases]);
        $trust = $write('trust', ['schema_version' => 1, 'purpose' => 'opening-trust', 'keys' => ['fixture' => ['issuer' => 'fixture-custodian',
            'public_key' => openssl_pkey_get_details($key)['key'], 'purposes' => ['account-opening-capture-fence', 'account-opening-capture-acceptance', 'account-opening-fence', 'billmanager-inactive-opening-approval', 'account-funding-release', 'opening-backup-acceptance']]], 'revoked_keys' => [], 'revoked_nonces' => []]);

        return ['history_ref' => $history, 'trust_path' => $trust['path'], 'records' => ['directory' => $dir, 'epoch_ref' => $epoch, 'collector_ref' => $collector,
            'capture_history_ref' => $captureHistory, 'preparation_history_ref' => $preparationHistory, 'capture_acceptance_ref' => $acceptance['payload'], 'capture_acceptance_signature_ref' => $acceptance['signature'],
            'first_ready_ref' => $ready['payload'], 'first_ready_signature_ref' => $ready['signature'], 'leases' => $leases, 'sign' => $sign, 'write' => $write]];
    }

    public static function runtimeIdentity(): array
    {
        return ['machine_id_sha256' => str_repeat('a', 64), 'runtime_realpath' => '/fixture/runtime', 'db_database' => 'fixture_database',
            'db_socket_realpath' => '/fixture/mysql.sock', 'db_server_uuid_or_fingerprint' => str_repeat('b', 64)];
    }

    public static function records(): array
    {
        $hash = str_repeat('c', 64);
        $common = ['schema_version' => 1, 'issuer' => 'fixture-custodian', 'not_before' => '2026-01-01T00:00:00Z', 'expires_at' => '2026-01-01T00:01:00Z', 'nonce' => 'fixture-nonce'];
        $captureFacts = ['freeze_id' => 'fixture-freeze', 'source_capture_id' => 'fixture-capture', 'source_identity' => 'source.example.invalid',
            'runtime_target_identity' => self::runtimeIdentity(), 'control_epoch_sha256' => $hash];
        $lineage = ['source_capture_id' => 'fixture-capture', 'control_epoch_sha256' => $hash, 'capture_fence_sha256' => $hash,
            'capture_acceptance_sha256' => $hash, 'preparation_history_sha256' => $hash];
        $target = [...self::runtimeIdentity(), 'network_namespace_inode' => 'net:[123]', 'operator_uid' => 0];
        $capture = [...$common, 'purpose' => 'account-opening-capture-fence', ...$captureFacts, 'issued_at' => $common['not_before'],
            'sequence' => 1, 'status' => 'fenced', 'controlled_writers_fingerprint' => $hash, 'incoming_payment_boundary_fingerprint' => $hash,
            'previous_lease_sha256' => null, 'issued_monotonic_ns' => '1000000000', 'expires_monotonic_ns' => '61000000000'];
        $ready = [...$common, 'schema_version' => 2, 'purpose' => 'account-opening-fence', 'issued_at' => $common['not_before'],
            'freeze_id' => 'fixture-freeze', 'sequence' => 2, 'status' => 'fenced', 'source_identity' => 'source.example.invalid',
            'source_population_fingerprint' => $hash, 'target_identity' => $target, 'target_baseline_sql_sha256' => $hash,
            'controlled_writers_fingerprint' => $hash, 'incoming_payment_boundary_fingerprint' => $hash, ...$lineage,
            'previous_lease_sha256' => $hash, 'issued_monotonic_ns' => '1100000000', 'expires_monotonic_ns' => '61100000000'];

        return [
            'epoch' => ['schema_version' => 1, 'purpose' => 'account-opening-control-epoch', 'issuer' => 'fixture-custodian',
                'freeze_id' => 'fixture-freeze', 'source_capture_id' => 'fixture-capture',
                'observer_identity' => ['machine_id_sha256' => $hash, 'boot_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'pid' => 1, 'start_ticks' => '0'],
                'source_identity' => 'source.example.invalid', 'source_boot_id' => '11111111-2222-3333-4444-555555555555',
                'runtime_target_identity' => self::runtimeIdentity(), 'control_profile_sha256' => $hash,
                'controlled_writers_fingerprint' => $hash, 'incoming_payment_boundary_fingerprint' => $hash],
            'collector' => ['schema_version' => 1, 'purpose' => 'account-opening-collector-receipt',
                'freeze_id' => 'fixture-freeze', 'source_capture_id' => 'fixture-capture', 'control_epoch_sha256' => $hash,
                'source_identity' => 'source.example.invalid', 'source_boot_id' => '11111111-2222-3333-4444-555555555555',
                'collector_identity_sha256' => $hash, 'collection_started_at' => '2026-01-01T00:00:01Z', 'collection_completed_at' => '2026-01-01T00:00:03Z',
                'collection_started_monotonic_ns' => '2000000000', 'collection_completed_monotonic_ns' => '4000000000',
                'source_started_at' => '2026-01-01T00:00:01Z', 'source_completed_at' => '2026-01-01T00:00:02Z',
                'source_started_monotonic_ns' => '900000000000', 'source_completed_monotonic_ns' => '901000000000',
                'snapshot_captured_at' => '2026-01-01T00:00:02Z', 'snapshot_sha256' => $hash, 'source_clock_skew_upper_bound_ms' => 2000],
            'capture' => $capture,
            'acceptance' => [...$common, 'purpose' => 'account-opening-capture-acceptance', ...$captureFacts, 'issued_at' => $common['not_before'],
                'status' => 'captured', 'capture_fence_sha256' => $hash, 'collector_receipt_sha256' => $hash, 'capture_history_sha256' => $hash,
                'snapshot_sha256' => $hash, 'source_population_fingerprint' => $hash, 'login_cutoff' => '2024-01-01 00:00:00',
                'activation_cutoff' => '2024-01-01 00:00:00', 'source_timezone' => 'UTC'],
            'ready' => $ready,
            'approval' => [...$common, 'schema_version' => 2, 'purpose' => 'billmanager-inactive-opening-approval', 'bundle_sha256' => $hash,
                'release_sha256' => $hash, 'target_identity' => $target, 'baseline_sql_sha256' => $hash, 'freeze_id' => 'fixture-freeze',
                'freeze_receipt_sha256' => $hash, 'login_cutoff' => '2024-01-01 00:00:00', 'activation_cutoff' => '2024-01-01 00:00:00',
                'policy_sha256' => $hash, 'scope_fingerprint' => $hash, 'accounts' => [], 'capabilities' => ['inactive_opening'],
                'status' => 'approved', 'human_decision_sha256' => $hash, ...$lineage],
        ];
    }
}
