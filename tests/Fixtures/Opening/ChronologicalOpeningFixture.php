<?php

namespace Tests\Fixtures\Opening;

use App\Services\BillmanagerMigration\BalancePreview;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\FinancialImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\ControlProofGraph;
use App\Services\BillmanagerMigration\Opening\OpeningBackupVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningFenceHistoryVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningPreparation;
use App\Services\BillmanagerMigration\Opening\OpeningProcessContext;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use App\Services\BillmanagerMigration\Opening\PrivateProofFile;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use App\Services\BillmanagerMigration\Opening\ReleaseVerifier;
use App\Services\BillmanagerMigration\Opening\SignedAttestation;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Owned actual-clock fictional lifecycle; no live host, date or hash injection API. */
final class ChronologicalOpeningFixture
{
    private string $dir;

    private string $writer;

    private string $writerPassword;

    private array $connection;

    private array $config;

    private $worker = null;

    private ?array $workerIdentity = null;

    private $holder = null;

    private ?array $holderIdentity = null;

    private ?array $input = null;

    private ?array $operator = null;

    private ?array $legacyOperator = null;

    private ?array $coldBefore = null;

    private ?array $coldAfter = null;

    private bool $retired = false;

    private bool $custodyStopped = false;

    private $financialKey;

    private function __construct() {}

    public static function start(): self
    {
        if (PHP_SAPI !== 'cli' || !app()->environment('testing') || posix_geteuid() !== 0 || DB::connection()->getDatabaseName() !== 'paymenter_test' || DB::transactionLevel() !== 0 || readlink('/proc/self/ns/net') === readlink('/proc/1/ns/net')) {
            throw new RuntimeException('Explicit owned native chronology required.');
        }
        $root = realpath(getenv('OPENING_FIXTURE_ROOT') ?: '');
        if (!$root || fileowner($root) !== 0 || (fileperms($root) & 0777) !== 0700) {
            throw new RuntimeException('Owned chronology root unavailable.');
        }
        $self = new self;
        $self->dir = $root . '/chronology-' . bin2hex(random_bytes(8));
        if (!mkdir($self->dir, 0700)) {
            throw new RuntimeException('Owned chronology directory unavailable.');
        }
        try {
            $self->connection = config('database.connections.' . DB::getDefaultConnection());
            $self->writer = 'capture_fixture_' . bin2hex(random_bytes(8));
            $self->writerPassword = bin2hex(random_bytes(24));
            $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $self->financialKey = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if (!$key || !$self->financialKey || !openssl_pkey_export($key, $private)) {
                throw new RuntimeException('Fixture signing keys unavailable.');
            }
            $stamp = (new \DateTimeImmutable)->modify('-1 year')->format('Y-m-d H:i:s');
            $tables = ['accounts' => [['id' => '10'], ['id' => '20']],
                'users' => [['id' => '11', 'account' => '10', 'enabled' => 'on', 'level' => '16', 'email' => 'first@example.invalid', 'realname' => 'Fictional First', 'last_login' => $stamp],
                    ['id' => '21', 'account' => '20', 'enabled' => 'on', 'level' => '16', 'email' => 'second@example.invalid', 'realname' => 'Fictional Second', 'last_login' => $stamp]],
                'currencies' => [['id' => '1', 'iso' => 'USD']],
                'subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => '12.3400', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on'],
                    ['id' => '41', 'account' => '20', 'currency' => '1', 'balance' => '1.0050', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on']],
                'payments' => [], 'expenses' => [], 'invoices' => [], 'invoice_items' => []];
            $self->write('source-artifact.json', ['tables' => $tables]);
            $runtime = OpeningProcessContext::runtimeTargetIdentity();
            $self->config = ['socket' => $runtime['db_socket_realpath'], 'runtime_target_identity' => $runtime, 'controller_namespace' => readlink('/proc/self/ns/net'),
                'source_artifact' => $self->dir . '/source-artifact.json', 'freeze_id' => 'fixture-freeze-' . bin2hex(random_bytes(8)), 'source_capture_id' => 'fixture-capture-' . bin2hex(random_bytes(8)),
                'controlled_writers_fingerprint' => hash('sha256', CanonicalPolicy::bytes(['source_sha256' => hash_file('sha256', $self->dir . '/source-artifact.json'), 'writer' => $self->writer, 'denied_dml' => ['INSERT', 'UPDATE', 'DELETE']])),
                'incoming_payment_boundary_fingerprint' => hash('sha256', CanonicalPolicy::bytes(['namespace' => readlink('/proc/self/ns/net'), 'provider_channels' => 'disabled fictional source'])), 'private_key' => $private];
            $self->write('control.json', $self->config);
            $self->write('trust.json', ['schema_version' => 1, 'purpose' => 'opening-trust', 'keys' => [
                'fixture-custodian' => ['issuer' => 'fixture-custodian', 'public_key' => openssl_pkey_get_details($key)['key'], 'purposes' => ['account-opening-capture-fence', 'account-opening-capture-acceptance', 'account-opening-fence', 'account-funding-release', 'opening-backup-acceptance']],
                'fixture-human' => ['issuer' => 'fixture-financial-reviewer', 'public_key' => openssl_pkey_get_details($self->financialKey)['key'], 'purposes' => ['billmanager-inactive-opening-approval']]], 'revoked_keys' => [], 'revoked_nonces' => []]);
            DB::unprepared("CREATE USER '" . $self->writer . "'@'localhost' IDENTIFIED BY '" . $self->writerPassword . "'");
            DB::unprepared("GRANT SELECT ON paymenter_test.* TO '" . $self->writer . "'@'localhost'");
            $self->assertGatesHeld();
            $pipes = [];
            $self->worker = proc_open([PHP_BINARY, base_path('tests/Fixtures/Opening/capture-custodian-worker.php'), $self->dir . '/control.json'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $self->dir . '/custodian.private.log', 'w'], 2 => ['file', $self->dir . '/custodian-error.private.log', 'w']], $pipes, base_path(), getenv());
            if (!is_resource($self->worker)) {
                throw new RuntimeException('Independent custodian could not start.');
            }
            $pid = proc_get_status($self->worker)['pid'];
            $self->workerIdentity = $self->processIdentity($pid);
            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline) {
                $self->running($self->worker, $self->workerIdentity);
                if (is_file($self->dir . '/state.json') && isset($self->state()['legacy_pre_capture'])) {
                    break;
                }
                usleep(100000);
            }
            if (!is_file($self->dir . '/state.json') || !isset($self->state()['legacy_pre_capture'])) {
                $self->retire();
                throw new RuntimeException('Independent capture lease not published.');
            }
            config(['account-opening.trust_path' => $self->dir . '/trust.json', 'account-opening.fence_index_path' => $self->dir . '/current-pointer.json', 'account-opening.journal_directory' => $self->dir]);
            $self->assertCustody();
            if (!$self->assertGatesHeld()) {
                $self->retire();
                throw new RuntimeException('Physical fictional gates not held.');
            }

            return $self;
        } catch (\Throwable $error) {
            try {
                $self->retire();
            } catch (\Throwable $retirement) {
                throw new RuntimeException('Owned setup failed; retirement diagnostics retained.', 0, $error);
            }
            throw $error;
        }
    }

    private function processIdentity(int $pid): array
    {
        $stat = file_get_contents('/proc/' . $pid . '/stat');
        $fields = preg_split('/\s+/', trim(substr($stat, strrpos($stat, ')') + 2)));

        return ['pid' => $pid, 'start_ticks' => $fields[19], 'namespace' => readlink('/proc/' . $pid . '/ns/net')];
    }

    private function running($process, array $identity): void
    {
        if (!is_resource($process) || !proc_get_status($process)['running'] || $this->processIdentity($identity['pid'])['start_ticks'] !== $identity['start_ticks']) {
            throw new RuntimeException('Owned fixture process identity lost.');
        }
    }

    private function write(string $name, array|string $value): array
    {
        if (basename($name) !== $name || $name === '.' || $name === '..') {
            throw new RuntimeException('Owned fixture filename required.');
        }
        $path = $this->dir . '/' . $name;
        $file = fopen($path, 'x');
        if (!$file) {
            throw new RuntimeException('Original chronology file already exists.');
        }
        try {
            chmod($path, 0600);
            $bytes = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $value;
            if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || !fsync($file)) {
                throw new RuntimeException('Incomplete original chronology file.');
            }
        } finally {
            fclose($file);
        }

        return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
    }

    private function state(): array
    {
        return json_decode(PrivateProofFile::read($this->dir . '/state.json', 0)['bytes'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function event(string $phase, array $fields = []): void
    {
        $path = $this->dir . '/events.json';
        $f = fopen($path, 'ab');
        chmod($path, 0600);
        try {
            if (!flock($f, LOCK_EX)) {
                throw new RuntimeException('Chronology event lock failed.');
            }
            $bytes = json_encode(['phase' => $phase, 'utc' => gmdate('Y-m-d\TH:i:s\Z'), 'monotonic_ns' => (string) hrtime(true), 'pid' => getmypid(), 'namespace' => readlink('/proc/self/ns/net'), ...$fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) {
                throw new RuntimeException('Incomplete chronology event.');
            }
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }

    private function command(string $action, array $fields = []): array
    {
        $this->assertCustody();
        $id = bin2hex(random_bytes(8));
        $ref = $this->write('pending-' . $id . '.json', ['id' => $id, 'action' => $action, ...$fields]);
        if (is_file($this->dir . '/command.json') || !rename($ref['path'], $this->dir . '/command.json')) {
            throw new RuntimeException('Owned stage publication failed.');
        }
        $deadline = microtime(true) + 120;
        $path = $this->dir . '/response-' . $id . '.json';
        while (!is_file($path) && microtime(true) < $deadline) {
            $this->running($this->worker, $this->workerIdentity);
            usleep(100000);
            clearstatcache(true, $path);
        }
        if (!is_file($path)) {
            throw new RuntimeException('Owned stage acknowledgment missing.');
        }

        return json_decode(PrivateProofFile::read($path, 0)['bytes'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertCustody(): void
    {
        if ($this->custodyStopped || is_file($this->dir . '/latched-failure.json')) {
            throw new RuntimeException('Owned custody failed closed.');
        }
        $this->running($this->worker, $this->workerIdentity);
        $s = $this->state();
        $graph = new ControlProofGraph;
        $history = OpeningFenceHistoryVerifier::verify($s['current_pointer']['history'], $graph, $this->dir . '/trust.json');
        $head = $history['entries'][array_key_last($history['entries'])];
        SignedAttestation::verifyBytes($head['payload_bytes'], $head['signature'], $head['payload']['purpose'], json_decode(PrivateProofFile::read($this->dir . '/trust.json', 0)['bytes'], true, flags: JSON_THROW_ON_ERROR), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    private function dml(bool $enabled): void
    {
        DB::unprepared(($enabled ? 'GRANT INSERT, UPDATE, DELETE ON paymenter_test.* TO ' : 'REVOKE INSERT, UPDATE, DELETE ON paymenter_test.* FROM ') . "'" . $this->writer . "'@'localhost'");
        $this->event($enabled ? 'preparation-admission' : 'preparation-closure');
    }

    public function assertGatesHeld(): bool
    {
        $before = hash_file('sha256', $this->dir . '/source-artifact.json');
        $pipes = [];
        $process = proc_open(['/usr/bin/setpriv', '--reuid=33', '--regid=33', '--clear-groups', PHP_BINARY, '-r',
            '$r=@file_put_contents($argv[1],"unauthorized",FILE_APPEND); echo json_encode(["uid"=>posix_geteuid(),"denied"=>$r===false]);', $this->dir . '/source-artifact.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), ['PATH' => '/usr/bin:/bin']);
        if (!is_resource($process)) {
            throw new RuntimeException('Physical source writer probe unavailable.');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Physical source writer probe failed: ' . $err);
        }
        $probe = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        $pdo = new \PDO('mysql:unix_socket=' . $this->config['socket'] . ';dbname=paymenter_test', $this->writer, $this->writerPassword, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $denied = false;
        try {
            $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('forbidden-fixture-writer','denied')");
        } catch (\PDOException $e) {
            $denied = (int) ($e->errorInfo[1] ?? 0) === 1142;
        }
        if ($probe !== ['uid' => 33, 'denied' => true] || $before !== hash_file('sha256', $this->dir . '/source-artifact.json') || !$denied) {
            throw new RuntimeException('Physical source/preparation writer gate failed.');
        }
        $this->event('gates-held', ['source_write_denied' => true, 'preparation_write_denied' => true]);

        return true;
    }

    public function completeCapture(): array
    {
        if ($this->input !== null) {
            throw new RuntimeException('Capture stage already complete.');
        }
        $s = $this->command('collect');
        $snapshot = Snapshot::load($s['snapshot_ref']['path'], 'source.example.invalid', '2024-01-01 00:00:00');
        if ($snapshot->checksum() !== $s['snapshot_ref']['sha256'] || hash_file('sha256', $s['population_ref']['path']) !== $s['population_ref']['sha256']) {
            throw new RuntimeException('Completed collection bytes changed.');
        }
        $this->command('accept', ['snapshot_ref' => $s['snapshot_ref'], 'population_ref' => $s['population_ref']]);
        $this->dml(true);
        $connectionName = DB::getDefaultConnection();
        try {
            config(['database.connections.' . $connectionName . '.username' => $this->writer, 'database.connections.' . $connectionName . '.password' => $this->writerPassword]);
            DB::purge();
            $preview = DB::transaction(function () use ($snapshot, $s) {
                $id = DB::table('billmanager_imports')->insertGetId(['source_host' => 'source.example.invalid', 'snapshot_sha256' => $snapshot->checksum(), 'status' => 'prepared_partial']);
                $context = new ImportContext($id, 'source.example.invalid');
                (new CustomerImporter)->import($snapshot, $context);
                (new FinancialImporter)->import($snapshot, $context);
                $result = (new BalancePreview)->prepare($snapshot, $context, $s['activation_cutoff'], 'preserve');
                $this->assertCustody();

                return ['id' => $id, 'preview' => $result];
            });
        } finally {
            config(['database.connections.' . $connectionName => $this->connection]);
            DB::purge();
            $this->dml(false);
        }
        $this->event('import-complete', ['import_id' => $preview['id'], 'snapshot_sha256' => $snapshot->checksum()]);
        $policy = $this->write('policy.json', ['schema_version' => 1, 'purpose' => 'inactive-opening-policy', 'inactive_only' => true, 'credit_limit_policy' => 'preserve', 'rounding' => 'positive_half_up_cents_debt_exact_four',
            'shared_access' => 'owner_spend_members_read_only', 'currencies' => ['USD'], 'reviewed_reasons' => ['debt', 'credit_limit_activation', 'shared_account', 'below_native_precision']]);
        $scope = [];
        foreach ($preview['preview']['entries'] as $row) {
            $scope[] = ['source_account' => $row['source_account_id'], 'owner_id' => $row['owner_user_id'], 'members' => $row['member_user_ids'], 'currency' => $row['currency'], 'opening' => $row['exact_amount'], 'limit' => $row['credit_limit'], 'reviewed_reasons' => $row['review_reasons']];
        }
        $input = ['schema_version' => 1, 'purpose' => 'billmanager-inactive-opening-input', 'source_identity' => 'source.example.invalid', 'login_cutoff' => '2024-01-01 00:00:00', 'activation_cutoff' => $s['activation_cutoff'], 'source_timezone' => 'UTC', 'import_id' => $preview['id'], 'scope' => $scope,
            'references' => ['snapshot' => $s['snapshot_ref'], 'snapshot_checksum' => $s['snapshot_checksum_ref'], 'population_review' => $s['population_ref'], 'policy' => $policy]];
        $this->input = $this->write('input.json', $input);

        return $this->input;
    }

    public function prepareReady(): array
    {
        if ($this->input === null || $this->operator !== null) {
            throw new RuntimeException('Completed capture must precede one preparation.');
        }
        $this->assertCustody();
        $input = OpeningBundle::load($this->input['path']);
        $report = (new OpeningPreparation)->prepare($input);
        $preparation = $this->write('preparation.json', $report);
        $this->event('preparation-complete');
        $this->coldBefore = ColdRestoreProbe::baseline();
        $this->event('cold-snapshot', ['sql_sha256' => $this->coldBefore['sql_sha256']]);
        $this->coldAfter = ColdRestoreProbe::restore($this->coldBefore);
        if ($this->coldAfter['status'] !== 'restored' || $this->coldBefore['server_start'] === $this->coldAfter['server_start']) {
            throw new RuntimeException('Actual owned cold restart/restore required.');
        }
        $acceptedAt = gmdate('Y-m-d\TH:i:s\Z');
        $this->event('cold-restore', ['server_start' => $this->coldAfter['server_start'], 'sql_sha256' => $this->coldAfter['sql_sha256']]);
        $this->assertCustody();
        $this->assertGatesHeld();
        $pipes = [];
        $this->holder = proc_open(['/usr/bin/unshare', '--net', '/usr/bin/sleep', '2147483647'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/namespace.private.log', 'w'], 2 => ['file', $this->dir . '/namespace-error.private.log', 'w']], $pipes, base_path(), ['PATH' => '/usr/bin:/bin']);
        if (!is_resource($this->holder)) {
            throw new RuntimeException('Later operator namespace unavailable.');
        }
        $pid = proc_get_status($this->holder)['pid'];
        $deadline = microtime(true) + 10;
        do {
            $this->holderIdentity = $this->processIdentity($pid);
            if ($this->holderIdentity['namespace'] !== $this->config['controller_namespace']) {
                break;
            } usleep(10000);
        } while (microtime(true) < $deadline);
        if ($this->holderIdentity['namespace'] === $this->config['controller_namespace']) {
            throw new RuntimeException('Later operator namespace was not isolated.');
        }
        $base = ['dir' => $this->dir, 'trust_path' => $this->dir . '/trust.json', 'pointer_path' => $this->dir . '/current-pointer.json', 'bundle_path' => $this->input['path'],
            'operator_namespace_path' => '/proc/' . $pid . '/ns/net', 'identity_path' => $this->dir . '/operator-identity.json', 'events_path' => $this->dir . '/events.json'];
        $child = new ConsoleChild($base, '', 'operator-preparation.json', 'prepare');
        $result = $child->finish(180);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Genuine later native preparation failed: ' . $result['output']);
        }
        $identity = json_decode(PrivateProofFile::read($base['identity_path'], 0)['bytes'], true, flags: JSON_THROW_ON_ERROR);
        $target = $identity['target_identity'];
        if ($target['network_namespace_inode'] !== $this->holderIdentity['namespace'] || $target['operator_uid'] !== 0 || array_intersect_key($target, $this->config['runtime_target_identity']) !== $this->config['runtime_target_identity']) {
            throw new RuntimeException('Observed genuine operator identity changed.');
        }
        unset($base['identity_path']);
        $this->event('operator-namespace', ['target_identity' => $target, 'observed_operator_pid' => $identity['pid']]);
        $sql = gzdecode(file_get_contents($this->coldBefore['directory'] . '/baseline.private.sql.gz'));
        if ($sql === false || hash('sha256', $sql) !== $this->coldAfter['sql_sha256']) {
            throw new RuntimeException('Actual restored baseline bytes changed.');
        }
        $baseline = $this->write('baseline.sql', $sql);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Owned release contains a symbolic file.');
            }
            $name = substr($file->getPathname(), strlen(base_path()) + 1);
            if (!str_starts_with($name, 'storage/logs/') && !str_starts_with($name, 'storage/framework/cache/data/') && !str_starts_with($name, 'storage/framework/sessions/')) {
                $files[$name] = hash_file('sha256', $file->getPathname());
            }
        }
        $manifest = $this->write('manifest.json', ['schema_version' => 1, 'purpose' => 'account-funding-file-manifest', 'files' => $files, 'generated_paths' => ['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions']]);
        $inventory = $this->write('inventory.json', (new OpeningTargetState)->capture());
        $this->event('inventory');
        $configuration = ReleaseVerifier::configurationHash();
        $s = $this->command('bridge', ['operator_pid' => $pid, 'operator_start_ticks' => $this->holderIdentity['start_ticks'],
            'release' => ['manifest_path' => $manifest['path'], 'manifest_sha256' => $manifest['sha256'], 'target_identity' => $target, 'configuration_sha256' => $configuration, 'decision_ref_sha256' => hash('sha256', 'fictional reviewed release ' . $manifest['sha256'])],
            'backup' => ['baseline_sql_sha256' => $baseline['sha256'], 'target_identity' => $target, 'configuration_sha256' => $configuration, 'restored_sql_sha256' => $this->coldAfter['sql_sha256'], 'accepted_at' => $acceptedAt, 'dump_binary_sha256' => hash_file('sha256', '/usr/bin/mysqldump'), 'dump_options' => OpeningBackupVerifier::OPTIONS]]);
        $s = $this->command('ready');
        $history = json_decode(PrivateProofFile::read($s['capture_history']['path'], 0)['bytes'], true, flags: JSON_THROW_ON_ERROR);
        $capture = $history['leases'][0];
        $refs = [...$input->data()['references'], 'preparation_input' => $this->input, 'preparation' => $preparation, 'target_inventory' => $inventory, 'target_sql' => $baseline,
            'backup_acceptance' => $s['backup']['payload'], 'backup_signature' => $s['backup']['signature'], 'release' => $s['release']['payload'], 'release_signature' => $s['release']['signature'],
            'freeze_receipt' => $s['first_ready']['payload'], 'freeze_signature' => $s['first_ready']['signature'], 'control_epoch' => $s['epoch_ref'], 'collector_receipt' => $s['collector_ref'],
            'capture_fence' => $capture['payload'], 'capture_fence_signature' => $capture['signature'], 'capture_history' => $s['capture_history'], 'preparation_history' => $s['preparation_history'],
            'capture_acceptance' => $s['capture_acceptance']['payload'], 'capture_acceptance_signature' => $s['capture_acceptance']['signature']];
        $scope = array_values(array_filter($report['accounts'], fn ($row) => $row['disposition'] === 'candidate'));
        $data = [...$input->data(), 'schema_version' => 2, 'purpose' => 'billmanager-inactive-opening-bundle', 'scope' => $scope, 'references' => $refs, 'target_identity' => $target,
            'baseline_sql_sha256' => $baseline['sha256'], 'release_sha256' => $s['release']['payload']['sha256'], 'freeze_id' => $this->config['freeze_id'], 'freeze_receipt_sha256' => $s['first_ready']['payload']['sha256'],
            'policy_sha256' => $report['policy_sha256'], 'scope_fingerprint' => hash('sha256', CanonicalPolicy::bytes($scope)),
            'source_capture_id' => $this->config['source_capture_id'], 'control_epoch_sha256' => $s['epoch_ref']['sha256'], 'capture_fence_sha256' => $capture['payload']['sha256'],
            'capture_acceptance_sha256' => $s['capture_acceptance']['payload']['sha256'], 'preparation_history_sha256' => $s['preparation_history']['sha256']];
        $bundle = $this->write('bundle.json', $data);
        OpeningBundle::load($bundle['path']);
        $this->event('bundle', ['sha256' => $bundle['sha256']]);
        $approval = $this->financial('approval', $data, $bundle['sha256']);
        $this->event('human-approval', ['sha256' => $approval['payload']['sha256']]);
        $this->operator = [...$base, 'bundle_path' => $bundle['path'], 'approval_path' => $approval['payload']['path'], 'signature_path' => $approval['signature']['path']];
        $legacy = $data;
        $legacy['schema_version'] = 1;
        foreach (['source_capture_id', 'control_epoch_sha256', 'capture_fence_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256'] as $field) {
            unset($legacy[$field]);
        }
        foreach (['control_epoch', 'collector_receipt', 'capture_fence', 'capture_fence_signature', 'capture_history', 'preparation_history', 'capture_acceptance', 'capture_acceptance_signature'] as $field) {
            unset($legacy['references'][$field]);
        }
        $legacy['references']['freeze_receipt'] = $s['legacy_pre_capture']['payload'];
        $legacy['references']['freeze_signature'] = $s['legacy_pre_capture']['signature'];
        $legacy['freeze_receipt_sha256'] = $s['legacy_pre_capture']['payload']['sha256'];
        $legacyBundle = $this->write('legacy-bundle.json', $legacy);
        $legacyApproval = $this->financial('legacy-approval', $legacy, $legacyBundle['sha256']);
        $legacyPointer = $this->write('legacy-current-pointer.json', ['schema_version' => 1, 'purpose' => 'account-opening-fence-reference', ...$s['legacy_pre_capture']]);
        $this->legacyOperator = ['bundle_path' => $legacyBundle['path'], 'approval_path' => $legacyApproval['payload']['path'], 'signature_path' => $legacyApproval['signature']['path'], 'pointer_path' => $legacyPointer['path']];
        $this->assertCustody();

        return ['bundle_path' => $bundle['path'], 'approval_path' => $approval['payload']['path'], 'signature_path' => $approval['signature']['path']];
    }

    private function financial(string $name, array $data, string $bundleHash): array
    {
        $decision = $this->write($name . '-fictional-human-decision.json', ['purpose' => 'fictional native inactive-only QA approval', 'bundle_sha256' => $bundleHash, 'approved_at' => gmdate('Y-m-d\TH:i:s\Z'), 'monotonic_ns' => (string) hrtime(true)]);
        $fields = [];
        foreach (['release_sha256', 'target_identity', 'baseline_sql_sha256', 'freeze_id', 'freeze_receipt_sha256', 'login_cutoff', 'activation_cutoff', 'policy_sha256', 'scope_fingerprint'] as $field) {
            $fields[$field] = $data[$field];
        }
        if ($data['schema_version'] === 2) {
            foreach (['source_capture_id', 'control_epoch_sha256', 'capture_fence_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256'] as $field) {
                $fields[$field] = $data[$field];
            }
        }
        $issuedUnix = time();
        $value = ['schema_version' => $data['schema_version'], 'purpose' => 'billmanager-inactive-opening-approval', 'issuer' => 'fixture-financial-reviewer', 'not_before' => gmdate('Y-m-d\TH:i:s\Z', $issuedUnix), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $issuedUnix + 3600), 'nonce' => $name . '-' . bin2hex(random_bytes(8)),
            'bundle_sha256' => $bundleHash, ...$fields, 'accounts' => $data['scope'], 'capabilities' => ['inactive_opening'], 'status' => 'approved', 'human_decision_sha256' => $decision['sha256']];
        ProofSchema::attestation($value, $value['purpose']);
        $payload = $this->write($name . '.json', $value);
        if (!openssl_sign(file_get_contents($payload['path']), $raw, $this->financialKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Fictional human signature failed.');
        }
        $signature = $this->write($name . '-signature.json', ['schema_version' => 1, 'key_id' => 'fixture-human', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($raw)]);

        return ['payload' => $payload, 'signature' => $signature];
    }

    public function interruptOperator(): void
    {
        $this->assertCustody();
        if (DB::table('account_opening_receipts')->count() !== 1 || DB::table('account_opening_batches')->value('state') !== 'opening') {
            throw new RuntimeException('Actual partial inactive interruption required.');
        }
        $this->event('actor-interruption-observed');
    }

    private function stopOwned(&$process, ?array $identity, int $signal = SIGTERM): void
    {
        if (!is_resource($process)) {
            $process = null;

            return;
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            if ($identity === null || $this->processIdentity($status['pid'])['start_ticks'] !== $identity['start_ticks']) {
                throw new RuntimeException('Owned process identity changed before retirement.');
            }
            posix_kill($identity['pid'], SIGCONT);
            proc_terminate($process, $signal);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(100000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }
        }
        proc_close($process);
        $process = null;
    }

    public function stopCustody(): void
    {
        if (!$this->custodyStopped) {
            $this->stopOwned($this->worker, $this->workerIdentity);
            $this->custodyStopped = true;
            $this->event('custody-stopped');
        }
    }

    public function lateRestartDenied(): bool
    {
        if (!$this->custodyStopped) {
            throw new RuntimeException('Actual stopped custody required.');
        }
        $s = $this->state();
        $index = json_decode(PrivateProofFile::read($s['current_pointer']['history']['path'], 0)['bytes'], true, flags: JSON_THROW_ON_ERROR);
        $last = $index['leases'][array_key_last($index['leases'])];
        $value = json_decode(PrivateProofFile::read($last['payload']['path'], 0)['bytes'], true, flags: JSON_THROW_ON_ERROR);
        if (time() <= strtotime($value['expires_at'])) {
            throw new RuntimeException('Actual elapsed expiry required.');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $ns = hrtime(true);
        $value = [...$value, 'sequence' => count($index['leases']) + 1, 'previous_lease_sha256' => $last['payload']['sha256'], 'nonce' => 'late-replacement-' . bin2hex(random_bytes(8)),
            'issued_at' => $now, 'not_before' => $now, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 60), 'issued_monotonic_ns' => (string) $ns, 'expires_monotonic_ns' => (string) ($ns + 60000000000)];
        $payload = $this->write('late-replacement.json', $value);
        openssl_sign(file_get_contents($payload['path']), $raw, $this->config['private_key'], OPENSSL_ALGO_SHA256);
        $signature = $this->write('late-replacement-signature.json', ['schema_version' => 1, 'key_id' => 'fixture-custodian', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($raw)]);
        $history = $this->write('late-replacement-history.json', [...$index, 'leases' => [...$index['leases'], ['payload' => $payload, 'signature' => $signature]]]);
        try {
            OpeningFenceHistoryVerifier::verify($history, new ControlProofGraph, $this->dir . '/trust.json');
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'uncovered interval')) {
                throw $e;
            }
            $this->event('late-replacement-denied');

            return true;
        }
        throw new RuntimeException('Late timestamp repaired a real custody gap.');
    }

    public function crashPublisherAt(string $phase): array
    {
        $this->assertCustody();
        $id = bin2hex(random_bytes(8));
        $pending = $this->write('pending-' . $id . '.json', ['id' => $id, 'action' => 'crash', 'phase' => $phase]);
        rename($pending['path'], $this->dir . '/command.json');
        $path = $this->dir . '/publication-barrier.json';
        $deadline = microtime(true) + 20;
        while (!is_file($path) && microtime(true) < $deadline) {
            $this->running($this->worker, $this->workerIdentity);
            usleep(100000);
            clearstatcache(true, $path);
        }
        if (!is_file($path)) {
            throw new RuntimeException('Actual publication crash barrier missing.');
        }
        $barrier = json_decode(PrivateProofFile::read($path, 0)['bytes'], true, flags: JSON_THROW_ON_ERROR);
        if ($barrier !== ['pid' => $this->workerIdentity['pid'], 'phase' => $phase]) {
            throw new RuntimeException('Publication barrier identity changed.');
        }
        while (preg_match('/^State:\s+[Tt]\b/m', file_get_contents('/proc/' . $this->workerIdentity['pid'] . '/status')) !== 1 && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (preg_match('/^State:\s+[Tt]\b/m', file_get_contents('/proc/' . $this->workerIdentity['pid'] . '/status')) !== 1) {
            throw new RuntimeException('Actual publisher did not stop.');
        }
        $this->stopOwned($this->worker, $this->workerIdentity, SIGKILL);
        $this->custodyStopped = true;
        $this->event('publisher-crash', ['boundary' => $phase]);

        return ['acknowledged' => is_file($this->dir . '/response-' . $id . '.json')];
    }

    public function evidence(): array
    {
        $s = $this->state();
        $events = [];
        $f = fopen($this->dir . '/events.json', 'rb');
        try {
            flock($f, LOCK_SH);
            foreach (explode("\n", trim(stream_get_contents($f))) as $line) {
                if ($line !== '') {
                    $events[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                }
            }
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
        $manifest = $this->retain();

        return [...$s, 'events' => $events, 'operator' => $this->operator, 'legacy_operator' => $this->legacyOperator, 'snapshot_path' => $this->dir . '/snapshot.json', 'pointer_path' => $this->dir . '/current-pointer.json', 'trust_path' => $this->dir . '/trust.json',
            'controller_namespace' => $this->config['controller_namespace'], 'operator_namespace' => $this->holderIdentity['namespace'] ?? null, 'cold_before' => $this->coldBefore, 'cold_after' => $this->coldAfter, 'retention_manifest_path' => $manifest];
    }

    private function retain(): string
    {
        $parent = dirname(getenv('OPENING_FIXTURE_ROOT')) . '/chronology-retention';
        if (!is_dir($parent) && !mkdir($parent, 0700)) {
            throw new RuntimeException('Independent chronology retention unavailable.');
        }
        $dest = $parent . '/' . basename($this->dir);
        if (!is_dir($dest)) {
            mkdir($dest, 0700);
        }
        if (realpath($dest) !== $dest || fileowner($dest) !== 0 || (fileperms($dest) & 0777) !== 0700) {
            throw new RuntimeException('Chronology retention identity changed.');
        }
        $files = [];
        foreach (new \DirectoryIterator($this->dir) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if (!$file->isFile() || $file->isLink() || $file->getOwner() !== 0) {
                throw new RuntimeException('Unexpected original chronology file.');
            }
            $f = fopen($file->getPathname(), 'rb');
            if (!$f) {
                throw new RuntimeException('Original chronology evidence unavailable.');
            }
            try {
                flock($f, LOCK_SH);
                $before = fstat($f);
                $bytes = stream_get_contents($f);
                $after = fstat($f);
            } finally {
                flock($f, LOCK_UN);
                fclose($f);
            }
            if ($before['size'] !== $after['size'] || strlen($bytes) !== $after['size']) {
                continue;
            }
            $hash = hash('sha256', $bytes);
            $name = $file->getFilename() . '.' . $hash;
            if (!is_file($dest . '/' . $name)) {
                $out = fopen($dest . '/' . $name, 'x');
                chmod($dest . '/' . $name, 0600);
                try {
                    if (fwrite($out, $bytes) !== strlen($bytes) || !fflush($out) || !fsync($out)) {
                        throw new RuntimeException('Incomplete independently retained evidence.');
                    }
                } finally {
                    fclose($out);
                }
            }
            if (hash_file('sha256', $dest . '/' . $name) !== $hash) {
                throw new RuntimeException('Retained chronology bytes changed.');
            }
            $files[$file->getFilename()] = ['original_path' => $file->getPathname(), 'sha256' => $hash, 'size' => strlen($bytes), 'retained_path' => $dest . '/' . $name];
        }
        $manifest = $dest . '/manifest-' . bin2hex(random_bytes(8)) . '.json';
        $out = fopen($manifest, 'x');
        chmod($manifest, 0600);
        try {
            $bytes = json_encode(['schema_version' => 1, 'fixture_directory' => $this->dir, 'files' => $files], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($out, $bytes) !== strlen($bytes) || !fflush($out) || !fsync($out)) {
                throw new RuntimeException('Incomplete retained manifest.');
            }
        } finally {
            fclose($out);
        }

        return $manifest;
    }

    public function retire(): void
    {
        if ($this->retired) {
            return;
        }
        if (isset($this->dir) && is_dir($this->dir)) {
            $this->stopOwned($this->worker, $this->workerIdentity);
            $this->custodyStopped = true;
            $this->stopOwned($this->holder, $this->holderIdentity);
            if (isset($this->writer, $this->config)) {
                if (DB::connection()->getDatabaseName() !== 'paymenter_test' || DB::connection()->getConfig('unix_socket') !== $this->config['socket']) {
                    throw new RuntimeException('Owned permission-retirement target changed.');
                }
                DB::unprepared("DROP USER IF EXISTS '" . $this->writer . "'@'localhost'");
            }
            $this->event('fixture-retired');
            $this->retain();
        }
        $this->retired = true;
    }
}
