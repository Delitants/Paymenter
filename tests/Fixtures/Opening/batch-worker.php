<?php

use App\Models\AccountOpeningReceipt;
use App\Models\AccountWallet;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Input\ArgvInput;
use Tests\Fixtures\Opening\ProofFactory;

// Test-only bootstrap observer for a genuine `php artisan` child. Production
// application code never loads this file or exposes these synchronization hooks.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Synthetic console observer requires isolated testing.');
}

require __DIR__ . '/../../../vendor/autoload.php';
$application = require __DIR__ . '/../../../bootstrap/app.php';
$application->booted(function () {
    Bus::fake();
    Mail::fake();
    Http::fake();
    $fault = getenv('OPENING_FIXTURE_FAULT') ?: '';
    $barrier = getenv('OPENING_FIXTURE_BARRIER') ?: '';
    $publish = static function (string $phase) use ($barrier) {
        if ($barrier === '') {
            throw new RuntimeException('Synthetic observation barrier is missing.');
        }
        ProofFactory::write($barrier, json_encode(['schema_version' => 1, 'purpose' => 'opening-test-barrier', 'pid' => getmypid(), 'phase' => $phase,
            'wallets' => AccountWallet::count(), 'receipts' => AccountOpeningReceipt::count()], JSON_THROW_ON_ERROR));
    };
    $latePaused = false;
    DB::listen(static function ($event) use ($fault, $publish, &$latePaused) {
        if ($latePaused || !in_array($fault, ['final-second-account', 'final-seal', 'initial-batch'], true)) {
            return;
        }
        $sql = strtolower($event->sql);
        $stack = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
        $initial = $fault === 'initial-batch' && str_starts_with($sql, 'insert into `account_opening_batches`');
        $account = $fault === 'final-second-account' && in_array('assertAccountCommitted', $stack, true)
            && str_contains($sql, '`account_posting_issues`') && AccountOpeningReceipt::count() === 2;
        $seal = $fault === 'final-seal' && in_array('seal', $stack, true) && in_array('assertApproved', $stack, true) && in_array('forOpening', $stack, true)
            && str_contains($sql, '`account_wallets`') && DB::table('account_opening_batches')->value('state') === 'sealed';
        if ($initial || $account || $seal) {
            $latePaused = true;
            $publish($initial ? 'initial-batch' : ($account ? 'final-account-verification' : 'final-seal-verification'));
            posix_kill(getmypid(), SIGSTOP);
        }
    });
    $paused = false;
    DB::listen(static function ($event) use ($fault, $publish, &$paused) {
        if ($paused || !str_starts_with(strtolower($event->sql), 'insert into `credits`') || !in_array($fault, ['before-receipt', 'before-second-receipt'], true)) {
            return;
        }
        if ($fault === 'before-second-receipt' && AccountOpeningReceipt::count() !== 1) {
            return;
        }
        $paused = true;
        $publish('projection');
        posix_kill(getmypid(), SIGSTOP);
    });
    Event::listen(TransactionCommitted::class, static function ($event) use ($fault, $publish) {
        if ($event->connection->transactionLevel() !== 0 || !in_array($fault, ['after-first-commit', 'after-69-commits'], true)) {
            return;
        }
        $count = AccountOpeningReceipt::count();
        if ($count === ($fault === 'after-first-commit' ? 1 : 69)) {
            $publish('committed');
            exit(86);
        }
    });
});
$status = $application->handleCommand(new ArgvInput);
Bus::assertNothingDispatched();
Mail::assertNothingSent();
Mail::assertNothingQueued();
Http::assertNothingSent();
exit($status);
