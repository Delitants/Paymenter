<?php

namespace Tests\Feature\Accounts\Legacy;

use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Models\LegacyReceiptBudget;
use App\Models\LegacyReceiptConflict;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\LegacyReceipts\GoldenVectors;
use Tests\Fixtures\LegacyReceipts\LegacyReceiptFixture as Fixture;
use Tests\Fixtures\Opening\RuntimeFixture;
use Tests\TestCase;

#[Group('opening-native')]
final class LegacyReceiptRaceTest extends TestCase
{
    use UsesCommittedDatabase;

    private array $workers = [];

    private ?string $jobRoot = null;

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($this->workers as $worker) {
                $this->terminate($worker);
            }
            if ($this->jobRoot !== null) {
                foreach (glob($this->jobRoot . '/*.json') as $job) {
                    unlink($job);
                }
                rmdir($this->jobRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('races')]
    public function test_concurrent_retention_after_observed_native_lock_waits(string $case): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) use ($case) {
            if ($case !== 'first-identical') {
                $inbox->retain($payload, $signature);
            }
            $original = LegacyEffect::first()?->getAttributes();
            $a = $case === 'first-identical' ? [$payload, $signature] : Fixture::delivery(['nonce' => 'racing-a']);
            $overrides = ['nonce' => 'racing-b'];
            if ($case === 'conflicting') {
                $overrides['economic'] = [...GoldenVectors::economic(), 'allocation_evidence_sha256' => str_repeat('d', 64)];
            }
            $b = $case === 'first-identical' ? $a : Fixture::delivery($overrides);
            if ($case === 'last-quota') {
                Fixture::replaceTrust(['retained_observation_limit' => 2]);
            }
            $workers = [$this->worker(...$a), $this->worker(...$b)];
            try {
                DB::beginTransaction();
                DB::table('legacy_receipt_budgets')->where('store_key', 'legacy-receipt-store-v1')->lockForUpdate()->get();
                foreach ($workers as &$worker) {
                    $this->start($worker);
                    $this->release($worker);
                }
                unset($worker);
                $this->observeWaits($workers, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
                DB::commit();
                $results = array_map(fn ($worker) => $this->finish($worker), $workers);
                foreach ($results as $index => $result) {
                    if ($result['kind'] === 'retryable') {
                        // Explicit fresh caller attempt of exactly the same bytes.
                        $retry = $this->worker(...($index === 0 ? $a : $b));
                        $this->start($retry);
                        $this->release($retry);
                        $results[$index] = $this->finish($retry);
                    }
                }
                if ($case === 'last-quota') {
                    self::assertCount(1, array_filter($results, fn ($r) => $r['kind'] === 'retained'));
                    self::assertCount(1, array_filter($results, fn ($r) => $r['kind'] === 'denied'));
                    self::assertSame(2, LegacyObservation::count());
                } else {
                    self::assertSame(['retained', 'retained'], array_column($results, 'kind'));
                    self::assertSame($case === 'first-identical' ? 1 : 3, LegacyObservation::count());
                }
                self::assertSame(1, LegacyEffect::count());
                if ($original) {
                    self::assertSame($original, LegacyEffect::firstOrFail()->getAttributes());
                }
                if ($case === 'first-identical') {
                    self::assertSame($results[0]['observation_id'], $results[1]['observation_id']);
                    self::assertCount(1, array_filter($results, fn ($r) => $r['replay']));
                }
                self::assertSame($case === 'conflicting' ? 1 : 0, LegacyReceiptConflict::count());
                $this->assertAccountingAndNoMoney();
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                foreach ($workers as $worker) {
                    $this->terminate($worker);
                }
            }
        });
    }

    public static function races(): array
    {
        return array_map(fn ($case) => [$case], ['first-identical', 'two-observations', 'conflicting', 'last-quota']);
    }

    #[DataProvider('phases')]
    public function test_process_interruption_rolls_back_or_replays_original_committed_receipt(string $phase): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) use ($phase) {
            $worker = $this->worker($payload, $signature, $phase);
            $this->start($worker);
            $barrier = $this->await($worker, 'barrier');
            self::assertSame($phase, $barrier['phase']);
            if ($phase === 'after_commit') {
                self::assertSame(1, LegacyObservation::count());
                $originalId = (int) LegacyObservation::firstOrFail()->id;
                $usage = LegacyReceiptBudget::firstOrFail()->getAttributes();
            } else {
                self::assertSame(0, LegacyEffect::count()); // uncommitted child rows are invisible
                self::assertSame(0, LegacyObservation::count());
            }
            $this->terminate($worker);
            $this->awaitConnectionRetired($worker['connection']);
            if ($phase !== 'after_commit') {
                self::assertSame(0, LegacyEffect::count());
                self::assertSame(0, LegacyObservation::count());
                self::assertSame(0, LegacyReceiptBudget::count());
                self::assertSame(0, LegacyReceiptConflict::count());
            }
            $fresh = $this->worker($payload, $signature);
            $this->start($fresh);
            $this->release($fresh);
            $result = $this->finish($fresh);
            self::assertSame('retained', $result['kind']);
            self::assertSame($phase === 'after_commit', $result['replay']);
            if ($phase === 'after_commit') {
                self::assertSame($originalId, $result['observation_id']);
                self::assertSame($usage, LegacyReceiptBudget::firstOrFail()->getAttributes());
            }
            $this->assertAccountingAndNoMoney();
        });
    }

    public static function phases(): array
    {
        return array_map(fn ($phase) => [$phase], ['before_write', 'after_effect_insert', 'after_observation_insert', 'after_commit']);
    }

    public function test_policy_revocation_during_worker_transaction_leaves_no_retention(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $worker = $this->worker($payload, $signature, 'after_observation_insert');
            $this->start($worker);
            $this->await($worker, 'barrier');
            Fixture::replaceTrust(['revoked_nonces' => ['delivery-one']]);
            $this->release($worker);
            self::assertSame('denied', $this->finish($worker)['kind']);
            self::assertSame(0, LegacyEffect::count());
            self::assertSame(0, LegacyObservation::count());
            self::assertSame(0, LegacyReceiptBudget::count());
        });
    }

    public function test_policy_rotation_during_wait_and_reused_service_never_resets_budget(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $inbox->retain($payload, $signature);
            $original = LegacyReceiptBudget::firstOrFail()->getAttributes();
            [$other, $sig] = Fixture::delivery(['nonce' => 'after-policy-rotation']);
            $worker = $this->worker($other, $sig);
            DB::beginTransaction();
            try {
                DB::table('legacy_receipt_budgets')->lockForUpdate()->first();
                $this->start($worker);
                $this->release($worker);
                $this->observeWaits([$worker], (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
                Fixture::replaceTrust(['retained_byte_limit' => 2000000]);
                DB::commit();
                self::assertSame('denied', $this->finish($worker)['kind']);
                self::assertSame($original, LegacyReceiptBudget::firstOrFail()->getAttributes());
                $result = $inbox->retain($other, $sig); // same long-lived service, fresh accepted policy
                self::assertFalse($result->replay);
                self::assertSame($original['id'], LegacyReceiptBudget::firstOrFail()->id);
                self::assertSame(1, LegacyReceiptBudget::count());
                Fixture::replaceTrust(['revoked_keys' => ['fixture-key']]);
                $denied = false;
                try {
                    $inbox->retain($payload, $signature);
                } catch (\DomainException) {
                    $denied = true;
                }
                self::assertTrue($denied);
                self::assertSame(2, LegacyObservation::count());
                $this->assertAccountingAndNoMoney();
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                $this->terminate($worker);
            }
        });
    }

    private function worker(string $payload, string $signature, string $phase = 'before_write'): array
    {
        if ($this->jobRoot === null) {
            $this->jobRoot = RuntimeFixture::root() . '/legacy-jobs-' . bin2hex(random_bytes(8));
            mkdir($this->jobRoot, 0700);
        }
        $job = $this->jobRoot . '/' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($job, json_encode(['payload' => $payload, 'signature' => $signature, 'phase' => $phase,
            'trust_path' => config('legacy-payments.trust_path'), 'gid' => config('legacy-payments.trust_reader_gid')], JSON_THROW_ON_ERROR));
        chmod($job, 0600);
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/LegacyReceipts/inbox-worker.php'), $job], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'TELEMETRY_ENABLED' => 'false',
        ], $input);
        $process->setTimeout(40);
        $worker = ['process' => $process, 'input' => $input, 'phase' => $phase, 'connection' => null, 'start' => null];
        $this->workers[] = $worker;

        return $worker;
    }

    private function start(array &$worker): void
    {
        $worker['process']->start();
        $worker['start'] = $this->processIdentity($worker['process']->getPid());
        foreach ($this->workers as &$owned) {
            if ($owned['process'] === $worker['process']) {
                $owned = $worker;
            }
        }
        unset($owned);
        $ready = $this->await($worker, 'ready');
        self::assertSame($worker['process']->getPid(), $ready['pid']);
        $worker['connection'] = $ready['connection'];
        if ($worker['phase'] === 'before_write') {
            $this->await($worker, 'barrier');
        }
        // Keep the live identity for cleanup if any assertion fails later.
        foreach ($this->workers as &$owned) {
            if ($owned['process'] === $worker['process']) {
                $owned = $worker;
            }
        }
    }

    private function release(array $worker): void
    {
        $worker['input']->write("continue\n");
        $worker['input']->close();
        $worker['process']->isRunning(); // pump the queued continuation into the actual child pipe
    }

    private function await(array $worker, string $type): array
    {
        $deadline = microtime(true) + 15;
        do {
            foreach ($this->messages($worker) as $message) {
                if ($message['type'] === $type) {
                    return $message;
                }
            }
            usleep(10000);
        } while ($worker['process']->isRunning() && microtime(true) < $deadline);
        self::fail('Missing owned worker ' . $type . ': ' . $worker['process']->getOutput() . ' | ' . $worker['process']->getErrorOutput());
    }

    private function messages(array $worker): array
    {
        $output = $worker['process']->getOutput();
        self::assertLessThanOrEqual(8192, strlen($output));
        $lines = explode("\n", $output);
        array_pop($lines); // only complete protocol lines

        return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    }

    private function finish(array $worker): array
    {
        $worker['process']->wait();
        self::assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        $results = array_values(array_filter($this->messages($worker), fn ($m) => $m['type'] === 'result'));
        self::assertCount(1, $results);

        return $results[0];
    }

    private function observeWaits(array $workers, int $blocker): void
    {
        $ids = array_column($workers, 'connection');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT COUNT(DISTINCT r.trx_mysql_thread_id) AS n FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id WHERE b.trx_mysql_thread_id=? AND r.trx_mysql_thread_id IN (' . $marks . ') AND l.lock_table=?';
        $deadline = microtime(true) + 12;
        do {
            foreach ($workers as $worker) {
                $worker['process']->isRunning(); // Symfony must pump asynchronous stdin while we poll SQL.
            }
            $count = (int) DB::selectOne($sql, [$blocker, ...$ids, '`paymenter_test`.`legacy_receipt_budgets`'])->n;
            if ($count === count($ids)) {
                break;
            }
            usleep(150000);
        } while (microtime(true) < $deadline);
        self::assertSame(count($ids), $count, 'Independent InnoDB waits on the exact owned budget were not observed: ' . json_encode(['ids' => $ids, 'blocker' => $blocker, 'workers' => array_map(fn ($w) => ['out' => $w['process']->getOutput(), 'err' => $w['process']->getErrorOutput()], $workers), 'locks' => DB::select('SELECT r.trx_mysql_thread_id AS waiting,b.trx_mysql_thread_id AS blocking,l.lock_table AS target FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id')], JSON_THROW_ON_ERROR));
    }

    private function processIdentity(?int $pid): string
    {
        self::assertNotNull($pid);
        $stat = file_get_contents('/proc/' . $pid . '/stat');
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));

        return $fields[19]; // Linux starttime, stable across exec, protects PID reuse
    }

    private function terminate(array $worker): void
    {
        if (!$worker['process']->isRunning()) {
            return;
        }
        $pid = $worker['process']->getPid();
        self::assertSame($worker['start'], $this->processIdentity($pid), 'Refuse a reused process identity.');
        $worker['process']->signal(SIGKILL);
        $worker['process']->wait();
    }

    private function awaitConnectionRetired(int $connection): void
    {
        $deadline = microtime(true) + 5;
        do {
            $count = (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE ID=?', [$connection])->n;
            if ($count === 0) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::assertSame(0, $count, 'Interrupted fixture connection has not retired.');
    }

    private function assertAccountingAndNoMoney(): void
    {
        self::assertSame(1, LegacyReceiptBudget::count());
        $bytes = 0;
        foreach (LegacyEffect::all() as $effect) {
            $bytes += strlen($effect->identity_json) + strlen($effect->economic_json);
        }
        foreach (LegacyObservation::all() as $observation) {
            $bytes += strlen($observation->payload_bytes) + strlen($observation->signature_bytes);
        }
        self::assertSame($bytes, LegacyReceiptBudget::firstOrFail()->retained_bytes);
        self::assertSame(LegacyObservation::count(), LegacyReceiptBudget::firstOrFail()->retained_observations);
        foreach (['account_wallets', 'account_movements', 'credits', 'account_opening_receipts', 'invoice_paid_processings', 'billmanager_holds', 'invoices', 'payment_operations', 'jobs', 'services', 'email_logs'] as $table) {
            self::assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
