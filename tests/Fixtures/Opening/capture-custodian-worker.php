<?php

// Fixture-only independent custodian. Never loaded by the product or operator.
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\PrivateProofFile;
use App\Services\BillmanagerMigration\Opening\ProofSchema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || posix_geteuid() !== 0 || getenv('DB_DATABASE') !== 'paymenter_test' || count($argv) !== 2) {
    throw new RuntimeException('Explicit owned test custodian required.');
}
$cfg = json_decode(PrivateProofFile::read($argv[1], 0, 65536)['bytes'], true, flags: JSON_THROW_ON_ERROR);
$dir = realpath(dirname($argv[1]));
$root = realpath(getenv('OPENING_FIXTURE_ROOT') ?: '');
if (!$root || !$dir || !str_starts_with($dir, $root . '/') || getenv('DB_SOCKET') !== $cfg['socket'] || readlink('/proc/self/ns/net') !== $cfg['controller_namespace'] || readlink('/proc/self/ns/net') === readlink('/proc/1/ns/net') || realpath(dirname(__DIR__, 3)) !== $cfg['runtime_target_identity']['runtime_realpath']) {
    throw new RuntimeException('Owned custodian target changed.');
}
$stamp = static fn () => gmdate('Y-m-d\TH:i:s\Z');
$ref = static fn (string $path) => ['path' => $path, 'sha256' => hash_file('sha256', $path)];
$syncDirectory = static function () use ($dir): void {
    $pipes = [];
    $p = proc_open(['/usr/bin/python3', '-c', 'import os,stat,sys; p=sys.argv[1]; f=os.open(p,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW); s=os.fstat(f); assert s.st_uid==0 and stat.S_IMODE(s.st_mode)==0o700; os.fsync(f); os.close(f)', $dir],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, ['PATH' => '/usr/bin:/bin']);
    if (!is_resource($p)) {
        throw new RuntimeException('Directory sync helper unavailable.');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0) {
        throw new RuntimeException('Durable directory synchronization failed: ' . $error);
    }
};
$write = static function (string $name, array|string $value) use ($dir, $ref): array {
    if (!preg_match('/^[a-z0-9-]+\.json(?:\.sha256)?$/D', $name)) {
        throw new RuntimeException('Owned filename required.');
    }
    $path = $dir . '/' . $name;
    $f = fopen($path, 'x');
    if (!$f) {
        throw new RuntimeException('Original fixture file already exists.');
    }
    try {
        chmod($path, 0600);
        $bytes = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $value;
        if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) {
            throw new RuntimeException('Incomplete durable fixture file.');
        }
    } finally {
        fclose($f);
    }

    return $ref($path);
};
$replace = static function (string $name, array $value) use ($dir, $write, $syncDirectory): void {
    $tmp = $write('staged-' . bin2hex(random_bytes(8)) . '.json', $value);
    if (!rename($tmp['path'], $dir . '/' . $name)) {
        throw new RuntimeException('Atomic fixture replacement failed.');
    }
    $syncDirectory();
};
$event = static function (string $phase, array $fields = []) use ($dir, $stamp): void {
    $path = $dir . '/events.json';
    $f = fopen($path, 'ab');
    if (!$f) {
        throw new RuntimeException('Event custody unavailable.');
    }
    chmod($path, 0600);
    try {
        if (!flock($f, LOCK_EX)) {
            throw new RuntimeException('Event custody lock unavailable.');
        }
        $bytes = json_encode(['phase' => $phase, 'utc' => $stamp(), 'monotonic_ns' => (string) hrtime(true), 'pid' => getmypid(), 'namespace' => readlink('/proc/self/ns/net'), ...$fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) {
            throw new RuntimeException('Incomplete event custody.');
        }
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
};
$key = openssl_pkey_get_private($cfg['private_key']);
if (!$key || openssl_pkey_get_details($key)['bits'] !== 3072) {
    throw new RuntimeException('Owned fixture key unavailable.');
}
$sign = static function (string $name, array $value) use ($write, $key): array {
    ProofSchema::attestation($value, $value['purpose']);
    $payload = $write($name . '.json', $value);
    if (!openssl_sign(file_get_contents($payload['path']), $raw, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Fixture signature failed.');
    }
    $signature = $write($name . '-signature.json', ['schema_version' => 1, 'key_id' => 'fixture-custodian', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($raw)]);

    return ['payload' => $payload, 'signature' => $signature];
};
$fields = preg_split('/\s+/', trim(substr(file_get_contents('/proc/self/stat'), strrpos(file_get_contents('/proc/self/stat'), ')') + 2)));
$epoch = ['schema_version' => 1, 'purpose' => 'account-opening-control-epoch', 'issuer' => 'fixture-custodian', 'freeze_id' => $cfg['freeze_id'], 'source_capture_id' => $cfg['source_capture_id'],
    'observer_identity' => ['machine_id_sha256' => hash_file('sha256', '/etc/machine-id'), 'boot_id' => trim(file_get_contents('/proc/sys/kernel/random/boot_id')), 'pid' => getmypid(), 'start_ticks' => $fields[19]],
    'source_identity' => 'source.example.invalid', 'source_boot_id' => trim(file_get_contents('/proc/sys/kernel/random/boot_id')), 'runtime_target_identity' => $cfg['runtime_target_identity'],
    'control_profile_sha256' => hash('sha256', CanonicalPolicy::bytes(['worker_sha256' => hash_file('sha256', __FILE__), 'source_gate' => 'root-private ordinary artifact', 'preparation_gate' => 'owned MariaDB DML role', 'controller_namespace' => $cfg['controller_namespace']])),
    'controlled_writers_fingerprint' => $cfg['controlled_writers_fingerprint'], 'incoming_payment_boundary_fingerprint' => $cfg['incoming_payment_boundary_fingerprint']];
ProofSchema::controlEpoch($epoch);
$epochRef = $write('epoch.json', $epoch);
$leases = [];
$pointer = null;
$readyFacts = null;
$state = ['epoch_ref' => $epochRef];
$lastPublished = 0;
$fault = '';
$pause = static function (string $phase) use (&$fault, $write, $syncDirectory): void {
    if ($fault !== $phase) {
        return;
    }
    $write('publication-barrier.json', ['pid' => getmypid(), 'phase' => $phase]);
    $syncDirectory();
    posix_kill(getmypid(), SIGSTOP);
    throw new RuntimeException('Interrupted publication cannot be acknowledged.');
};
$live = static function () use (&$leases): void {
    if (!$leases) {
        return;
    }
    $last = json_decode(file_get_contents($leases[array_key_last($leases)]['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
    if (time() >= strtotime($last['expires_at']) || hrtime(true) > (int) $last['expires_monotonic_ns']) {
        throw new RuntimeException('Custody lapse is latched; new timestamps cannot repair it.');
    }
};
$publish = static function () use (&$leases, &$pointer, &$readyFacts, &$state, &$lastPublished, $cfg, $epochRef, $write, $replace, $pause, $key, $event, $live): void {
    $live();
    $issuedUnix = time();
    $issued = gmdate('Y-m-d\TH:i:s\Z', $issuedUnix);
    $ns = hrtime(true);
    $seq = count($leases) + 1;
    $common = ['schema_version' => $readyFacts === null ? 1 : 2, 'purpose' => $readyFacts === null ? 'account-opening-capture-fence' : 'account-opening-fence', 'issuer' => 'fixture-custodian',
        'not_before' => $issued, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $issuedUnix + 60), 'nonce' => 'fixture-' . bin2hex(random_bytes(16)), 'issued_at' => $issued, 'freeze_id' => $cfg['freeze_id'], 'source_capture_id' => $cfg['source_capture_id'],
        'sequence' => $seq, 'status' => 'fenced', 'source_identity' => 'source.example.invalid', 'control_epoch_sha256' => $epochRef['sha256'], 'controlled_writers_fingerprint' => $cfg['controlled_writers_fingerprint'],
        'incoming_payment_boundary_fingerprint' => $cfg['incoming_payment_boundary_fingerprint'], 'previous_lease_sha256' => $leases ? $leases[array_key_last($leases)]['payload']['sha256'] : null,
        'issued_monotonic_ns' => (string) $ns, 'expires_monotonic_ns' => (string) ($ns + 60000000000)];
    $payload = $readyFacts === null ? [...$common, 'runtime_target_identity' => $cfg['runtime_target_identity']] : [...$common, ...$readyFacts];
    ProofSchema::attestation($payload, $payload['purpose']);
    $name = 'lease-' . $seq . '-' . bin2hex(random_bytes(4));
    $p = $write($name . '.json', $payload);
    $pause('payload');
    if (!openssl_sign(file_get_contents($p['path']), $raw, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Fixture lease signature failed.');
    }
    $s = $write($name . '-signature.json', ['schema_version' => 1, 'key_id' => 'fixture-custodian', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($raw)]);
    $pause('signature');
    $next = [...$leases, ['payload' => $p, 'signature' => $s]];
    $history = $write($name . '-history.json', ['schema_version' => 1, 'purpose' => 'account-opening-lease-history', 'leases' => $next]);
    $pause('index');
    $selection = ['payload' => $p, 'signature' => $s, 'history' => $history];
    $tmp = $write($name . '-pointer-staging.json', ['schema_version' => 2, 'purpose' => 'account-opening-fence-reference', ...$selection]);
    $pause('pointer-staging');
    if (!rename($tmp['path'], dirname($p['path']) . '/current-pointer.json')) {
        throw new RuntimeException('Fixture pointer rename failed.');
    }
    // replace() performs the mandatory directory sync before any state/ack.
    $leases = $next;
    $pointer = $selection;
    $lastPublished = $ns;
    $state['current_pointer'] = $pointer;
    $state['last_lease_expires_at'] = $payload['expires_at'];
    $replace('state.json', $state);
    $event($readyFacts === null ? 'capture-lease' : 'ready-renewal', ['sequence' => $seq, 'payload_sha256' => $p['sha256']]);
};
// first-ready phase is recorded explicitly at the ready transition below.
try {
    $publish();
    $legacyUnix = time();
    $legacyStamp = gmdate('Y-m-d\TH:i:s\Z', $legacyUnix);
    $state['legacy_pre_capture'] = $sign('legacy-pre-capture', ['schema_version' => 1, 'purpose' => 'account-opening-fence', 'issuer' => 'fixture-custodian', 'not_before' => $legacyStamp, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $legacyUnix + 60), 'nonce' => 'legacy-before-results',
        'issued_at' => $legacyStamp, 'freeze_id' => $cfg['freeze_id'], 'sequence' => 1, 'status' => 'fenced', 'source_identity' => 'source.example.invalid', 'source_population_fingerprint' => str_repeat('0', 64),
        'target_identity' => [...$cfg['runtime_target_identity'], 'network_namespace_inode' => $cfg['controller_namespace'], 'operator_uid' => 0], 'target_baseline_sql_sha256' => str_repeat('0', 64),
        'controlled_writers_fingerprint' => $cfg['controlled_writers_fingerprint'], 'incoming_payment_boundary_fingerprint' => $cfg['incoming_payment_boundary_fingerprint']]);
    $event('legacy-pre-capture');
    $replace('state.json', $state);
    while (true) {
        $live();
        if (hrtime(true) - $lastPublished >= 10000000000) {
            $publish();
        }
        $commandPath = $dir . '/command.json';
        clearstatcache(true, $commandPath);
        if (!is_file($commandPath)) {
            usleep(100000);

            continue;
        }
        $cmd = json_decode(PrivateProofFile::read($commandPath, 0, 65536)['bytes'], true, flags: JSON_THROW_ON_ERROR);
        if (!preg_match('/^[a-f0-9]{16}$/D', $cmd['id'] ?? '') || !in_array($cmd['action'] ?? '', ['collect', 'accept', 'bridge', 'ready', 'crash'], true)) {
            throw new RuntimeException('Owned stage command invalid.');
        }
        rename($commandPath, $dir . '/command-' . $cmd['id'] . '.json');
        $answer = [];
        if ($cmd['action'] === 'collect') {
            if (isset($state['collector_ref']) || $readyFacts !== null) {
                throw new RuntimeException('Collection stage already closed.');
            }
            $observerStarted = $stamp();
            $observerNs = hrtime(true);
            $event('collector-start');
            $sourceStarted = $stamp();
            $sourceNs = hrtime(true);
            $artifact = json_decode(PrivateProofFile::read($cfg['source_artifact'], 0)['bytes'], true, flags: JSON_THROW_ON_ERROR);
            $captured = $stamp();
            $snapshot = $write('snapshot.json', ['schema_version' => 1, 'source_host' => 'source.example.invalid', 'login_cutoff' => '2024-01-01 00:00:00', 'captured_at_utc' => $captured, 'source_timezone' => 'UTC', 'tables' => $artifact['tables']]);
            $checksum = $write('snapshot.json.sha256', $snapshot['sha256']);
            $event('snapshot-complete', ['sha256' => $snapshot['sha256']]);
            $sourceCompleted = $stamp();
            $sourceEndNs = hrtime(true);
            $observerCompleted = $stamp();
            $observerEndNs = hrtime(true);
            $cutoff = (new DateTimeImmutable($captured))->modify('-2 years')->format('Y-m-d H:i:s');
            $population = $write('population.json', ['schema_version' => 1, 'purpose' => 'opening-population-review', 'source_identity' => 'source.example.invalid', 'snapshot_sha256' => $snapshot['sha256'], 'activation_cutoff' => $cutoff,
                'selected_account_ids' => array_column($artifact['tables']['accounts'], 'id'), 'eligible_account_ids' => array_column($artifact['tables']['accounts'], 'id'), 'eligible_source_user_ids' => array_column($artifact['tables']['users'], 'id')]);
            $event('population-complete', ['sha256' => $population['sha256']]);
            $collector = ['schema_version' => 1, 'purpose' => 'account-opening-collector-receipt', 'freeze_id' => $cfg['freeze_id'], 'source_capture_id' => $cfg['source_capture_id'], 'control_epoch_sha256' => $epochRef['sha256'],
                'source_identity' => 'source.example.invalid', 'source_boot_id' => $epoch['source_boot_id'], 'collector_identity_sha256' => hash('sha256', CanonicalPolicy::bytes(['code_sha256' => hash_file('sha256', __FILE__), 'transport' => 'owned local fictional file collector'])),
                'collection_started_at' => $observerStarted, 'collection_completed_at' => $observerCompleted, 'collection_started_monotonic_ns' => (string) $observerNs, 'collection_completed_monotonic_ns' => (string) $observerEndNs,
                'source_started_at' => $sourceStarted, 'source_completed_at' => $sourceCompleted, 'source_started_monotonic_ns' => (string) $sourceNs, 'source_completed_monotonic_ns' => (string) $sourceEndNs,
                'snapshot_captured_at' => $captured, 'snapshot_sha256' => $snapshot['sha256'], 'source_clock_skew_upper_bound_ms' => 0];
            ProofSchema::collectorReceipt($collector);
            $state = [...$state, 'snapshot_ref' => $snapshot, 'snapshot_checksum_ref' => $checksum, 'population_ref' => $population, 'collector_ref' => $write('collector.json', $collector), 'activation_cutoff' => $cutoff];
            $answer = $state;
        } elseif ($cmd['action'] === 'accept') {
            if (!isset($state['collector_ref']) || isset($state['capture_acceptance'])) {
                throw new RuntimeException('Completed verified collection required.');
            }
            foreach (['snapshot_ref', 'population_ref'] as $field) {
                if ($cmd[$field] !== $state[$field]) {
                    throw new RuntimeException('Verified completed bytes changed.');
                }
            }
            $issuedUnix = time();
            $issued = gmdate('Y-m-d\TH:i:s\Z', $issuedUnix);
            $state['capture_history'] = $pointer['history'];
            $state['capture_acceptance'] = $sign('capture-acceptance', ['schema_version' => 1, 'purpose' => 'account-opening-capture-acceptance', 'issuer' => 'fixture-custodian', 'not_before' => $issued, 'issued_at' => $issued,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $issuedUnix + 21600), 'nonce' => 'capture-completed-' . bin2hex(random_bytes(8)), 'freeze_id' => $cfg['freeze_id'], 'source_capture_id' => $cfg['source_capture_id'], 'status' => 'captured',
                'source_identity' => 'source.example.invalid', 'runtime_target_identity' => $cfg['runtime_target_identity'], 'control_epoch_sha256' => $epochRef['sha256'], 'capture_fence_sha256' => $leases[0]['payload']['sha256'],
                'collector_receipt_sha256' => $state['collector_ref']['sha256'], 'capture_history_sha256' => $pointer['history']['sha256'], 'snapshot_sha256' => $state['snapshot_ref']['sha256'], 'source_population_fingerprint' => $state['population_ref']['sha256'],
                'login_cutoff' => '2024-01-01 00:00:00', 'activation_cutoff' => $state['activation_cutoff'], 'source_timezone' => 'UTC']);
            $event('capture-acceptance');
            $answer = $state;
        } elseif ($cmd['action'] === 'bridge') {
            if (!isset($state['capture_acceptance']) || $readyFacts !== null) {
                throw new RuntimeException('Capture completion required before bridge.');
            }
            $target = $cmd['release']['target_identity'];
            if (readlink('/proc/' . $cmd['operator_pid'] . '/ns/net') !== $target['network_namespace_inode'] || $target['operator_uid'] !== 0 || array_intersect_key($target, $cfg['runtime_target_identity']) !== $cfg['runtime_target_identity']) {
                throw new RuntimeException('Actual later operator target changed.');
            }
            $issuedUnix = time();
            $issued = gmdate('Y-m-d\TH:i:s\Z', $issuedUnix);
            $common = ['schema_version' => 1, 'issuer' => 'fixture-custodian', 'not_before' => $issued, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $issuedUnix + 3600)];
            $state['release'] = $sign('release', [...$common, 'purpose' => 'account-funding-release', 'nonce' => 'release-' . bin2hex(random_bytes(8)), ...$cmd['release']]);
            $event('release');
            $state['backup'] = $sign('backup', [...$common, 'purpose' => 'opening-backup-acceptance', 'nonce' => 'backup-' . bin2hex(random_bytes(8)), ...$cmd['backup'], 'release_sha256' => $state['release']['payload']['sha256']]);
            $event('backup-acceptance');
            $answer = $state;
        } elseif ($cmd['action'] === 'ready') {
            if (!isset($state['backup']) || $readyFacts !== null) {
                throw new RuntimeException('Completed bridge required before ready.');
            }
            $state['preparation_history'] = $pointer['history'];
            $backup = json_decode(file_get_contents($state['backup']['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
            $readyFacts = ['target_identity' => $backup['target_identity'], 'source_population_fingerprint' => $state['population_ref']['sha256'], 'target_baseline_sql_sha256' => $backup['baseline_sql_sha256'],
                'capture_fence_sha256' => $leases[0]['payload']['sha256'], 'capture_acceptance_sha256' => $state['capture_acceptance']['payload']['sha256'], 'preparation_history_sha256' => $pointer['history']['sha256']];
            $publish();
            $state['first_ready'] = ['payload' => $pointer['payload'], 'signature' => $pointer['signature']];
            $event('first-ready');
            $answer = $state;
        } else {
            $fault = $cmd['phase'];
            if (!in_array($fault, ['payload', 'signature', 'index', 'pointer-staging'], true)) {
                throw new RuntimeException('Unknown publication fault.');
            }
            $publish();
            throw new RuntimeException('Crash publication unexpectedly acknowledged.');
        }
        $live();
        $replace('state.json', $state);
        $write('response-' . $cmd['id'] . '.json', $answer);
        $syncDirectory();
    }
} catch (Throwable $error) {
    $replace('latched-failure.json', ['status' => 'latched', 'utc' => $stamp(), 'monotonic_ns' => (string) hrtime(true), 'reason' => $error->getMessage()]);
    exit(1);
}
