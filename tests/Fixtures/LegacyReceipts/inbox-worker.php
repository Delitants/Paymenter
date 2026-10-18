<?php

// No standalone ingress: this child refuses outside the owned native fixture.
require dirname(__DIR__) . '/Opening/native-bootstrap.php';

use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Services\Accounts\Legacy\LegacyClock;
use App\Services\Accounts\Legacy\LegacyReceiptInbox;
use App\Services\BillmanagerMigration\Opening\PrivateProofFile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Opening\RuntimeFixture;

$jobPath = $argv[1] ?? '';
if (count($argv) !== 2 || !str_starts_with($jobPath, RuntimeFixture::root() . '/legacy-jobs-')) {
    throw new RuntimeException('Owned fictional receipt job required.');
}
$job = json_decode(PrivateProofFile::read($jobPath, 0, 262144)['bytes'], true, flags: JSON_THROW_ON_ERROR);
$keys = array_keys($job);
sort($keys);
if ($keys !== ['gid', 'payload', 'phase', 'signature', 'trust_path'] || !is_string($job['payload']) || !is_string($job['signature']) ||
    !in_array($job['phase'], ['before_write', 'after_effect_insert', 'after_observation_insert', 'after_commit'], true) ||
    $job['gid'] !== RuntimeFixture::gid() || !is_string($job['trust_path']) || !str_starts_with($job['trust_path'], RuntimeFixture::root() . '/legacy-')) {
    throw new RuntimeException('Invalid fictional receipt job.');
}
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!app()->environment('testing') || DB::connection()->getDatabaseName() !== 'paymenter_test' || config('database.connections.' . config('database.default') . '.unix_socket') !== getenv('DB_SOCKET')) {
    throw new RuntimeException('Explicit owned socket/database identity required.');
}
config(['legacy-payments.receipt_ingress_enabled' => true, 'legacy-payments.trust_path' => $job['trust_path'], 'legacy-payments.trust_reader_gid' => $job['gid']]);
$clock = new class implements LegacyClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:01:00Z');
    }
};
app()->instance(LegacyClock::class, $clock);
Mail::fake();
Bus::fake();
Queue::fake();
Http::preventStrayRequests();
Http::fake();
$businessWrites = [];
DB::listen(function ($query) use (&$businessWrites) {
    if (preg_match('/^(insert|update|delete|replace)\b/i', $query->sql) && !str_contains($query->sql, 'legacy_')) {
        $businessWrites[] = true;
    }
});
$noEffects = static function () use (&$businessWrites): void {
    if ($businessWrites !== []) {
        throw new RuntimeException('Receipt worker changed business records.');
    }
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
};
$emit = static function (array $message): void {
    fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
    fflush(STDOUT);
};
$pause = static function (string $phase) use ($job, $emit, $noEffects): void {
    if ($job['phase'] !== $phase) {
        return;
    }
    $noEffects();
    $emit(['type' => 'barrier', 'phase' => $phase]);
    $read = [STDIN];
    $write = $except = [];
    if (stream_select($read, $write, $except, 25) !== 1 || fgets(STDIN, 32) !== "continue\n") {
        throw new RuntimeException('Owned worker continuation absent.');
    }
};
LegacyEffect::created(fn () => $pause('after_effect_insert'));
LegacyObservation::created(fn () => $pause('after_observation_insert'));
DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use ($pause): void {
    if (DB::transactionLevel() === 0) {
        $pause('after_commit');
    }
});
$emit(['type' => 'ready', 'pid' => getmypid(), 'connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
$pause('before_write');
try {
    $result = (new LegacyReceiptInbox($clock))->retain($job['payload'], $job['signature']);
    $noEffects();
    $emit(['type' => 'result', 'kind' => 'retained', 'effect_key' => $result->effectKey, 'observation_key' => $result->observationKey,
        'observation_id' => $result->observationId, 'disposition' => $result->disposition, 'replay' => $result->replay]);
} catch (DomainException) {
    $noEffects();
    $emit(['type' => 'result', 'kind' => 'denied']);
} catch (DeadlockException) {
    $noEffects();
    $emit(['type' => 'result', 'kind' => 'retryable']);
} catch (QueryException $exception) {
    if (($exception->errorInfo[1] ?? null) !== 1213 && (string) $exception->getCode() !== '40001') {
        throw $exception;
    }
    $noEffects();
    $emit(['type' => 'result', 'kind' => 'retryable']);
}
