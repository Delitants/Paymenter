<?php

namespace Tests\Feature\Accounts;

use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\AccountReversalReservation;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\PaymentOperation;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Accounts\AccountStatement;
use App\Services\Accounts\InsufficientAccountFunding;
use App\Services\Accounts\InvoiceFunding;
use App\Services\Accounts\WalletLedger;
use App\Services\Billing\InvoicePricing;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\Operations\GatewayOperations;
use App\Services\Gateways\Operations\Refunds;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAccountWallet;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesVerifiedDeposit;
use Tests\Fixtures\Accounts\RaceRefundAdapter;
use Tests\Fixtures\FeeGateway;
use Tests\TestCase;

class AccountFundingRaceTest extends TestCase
{
    use UsesAccountWallet,UsesCommittedDatabase,UsesVerifiedDeposit;

    private function fixture(string $limit = '100.00'): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.tax_enabled' => false]);
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '0.00', $limit);
        $this->actingAs($owner);

        return [$owner, $wallet];
    }

    private function invoice(User $owner, string $price = '60.00'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $invoice->items()->create(['description' => 'Synthetic race product', 'price' => $price, 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);

        return $invoice;
    }

    private function worker(User $actor, string $action, int $record, string $amount, array $extra = []): Process
    {
        self::assertSame(0, DB::transactionLevel(), 'Create race workers before the control transaction');
        $c = config('database.connections.' . config('database.default'));
        $request = ['actor' => $actor->id, 'action' => $action, 'record' => $record, 'amount' => $amount, 'key' => (string) Str::uuid()] + $extra;
        $p = new Process([PHP_BINARY, base_path('tests/Fixtures/account-funding-worker.php'), DB::connection()->getDatabaseName(), json_encode($request, JSON_THROW_ON_ERROR)], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => $c['database'], 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_SOCKET' => $c['unix_socket'],
            'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'], 'ACCOUNT_FUNDING_TEST_SOCKET' => $c['unix_socket']]);
        $p->setTimeout(30);

        return $p;
    }

    private function waiting(array $workers, string $table): void
    {
        $deadline = microtime(true) + 10;
        $ids = [];
        foreach ($workers as $worker) {
            while (!preg_match('/ready:([0-9]+)/', $worker->getOutput(), $m) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertSame(1, preg_match('/ready:([0-9]+)/', $worker->getOutput(), $m), $worker->getErrorOutput());
            $ids[] = (int) $m[1];
        }
        $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $observed = 0;
        do {
            $query = 'SELECT COUNT(DISTINCT r.trx_mysql_thread_id) AS n FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id WHERE b.trx_mysql_thread_id=? AND r.trx_mysql_thread_id IN (' . $marks . ') AND l.lock_table=?';
            $observed = (int) DB::selectOne($query, [$blocker, ...$ids, '`' . DB::connection()->getDatabaseName() . '`.`' . $table . '`'])->n;
            if ($observed === count($ids)) {
                break;
            }usleep(150000);
        } while (microtime(true) < $deadline);
        self::assertSame(count($ids), $observed, 'The actual independent database lock waits were not observed: ' . json_encode([
            'workers' => array_map(fn ($w) => ['output' => $w->getOutput(), 'errors' => $w->getErrorOutput(), 'running' => $w->isRunning()], $workers),
            'locks' => DB::select('SELECT r.trx_mysql_thread_id AS waiting, b.trx_mysql_thread_id AS blocking,l.lock_table AS target FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id')], JSON_THROW_ON_ERROR));
    }

    private function finish(Process $worker): array
    {
        $worker->wait();
        self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        $lines = array_values(array_filter(explode("\n", $worker->getOutput()), fn ($line) => str_starts_with($line, '{')));
        self::assertCount(1, $lines, 'Independent worker outcome: ' . $worker->getOutput() . ' | ' . $worker->getErrorOutput());

        return json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    }

    private function compete(array $workers, string $table, int $id, ?callable $duringWait = null): array
    {
        try {
            DB::beginTransaction();
            self::assertNotNull(DB::table($table)->where('id', $id)->lockForUpdate()->first());
            foreach ($workers as $worker) {
                $worker->start();
            }$this->waiting($workers, $table);
            if ($duringWait) {
                $duringWait();
            }DB::commit();

            return array_map(fn ($worker) => $this->finish($worker), $workers);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }

    public function test_two_independent_spends_use_current_shared_capacity_after_observed_owner_wait(): void
    {
        [$owner,$wallet] = $this->fixture();
        $a = $this->invoice($owner);
        $b = $this->invoice($owner);
        $results = $this->compete([$this->worker($owner, 'fund', $a->id, '60.00'), $this->worker($owner, 'fund', $b->id, '60.00')], 'users', $owner->id);
        self::assertSame(1, count(array_filter($results, fn ($r) => $r['result'] === 'written')));
        self::assertSame(1, count(array_filter($results, fn ($r) => ($r['kind'] ?? null) === 'capacity')));
        self::assertSame(1, AccountFundingAllocation::count());
        self::assertSame(1, AccountMovement::count());
        self::assertSame('-60.0000', $wallet->fresh()->balance);
        self::assertSame('40.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(1, InvoicePaidProcessing::count());
        Http::assertNothingSent();
    }

    public function test_overlapping_partial_reversals_cannot_exceed_original_allocation_after_observed_invoice_wait(): void
    {
        [$owner,$wallet] = $this->fixture();
        $invoice = $this->invoice($owner, '15.00');
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', (string) Str::uuid());
        $role = Role::create(['name' => 'Synthetic concurrent reversal staff', 'permissions' => ['admin.invoice_transactions.account_reverse']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);
        $results = $this->compete([$this->worker($actor, 'reverse', $allocation->id, '10.00'), $this->worker($actor, 'reverse', $allocation->id, '10.00')], 'invoices', $invoice->id);
        self::assertSame(1, count(array_filter($results, fn ($r) => $r['result'] === 'written')));
        self::assertSame(1, count(array_filter($results, fn ($r) => ($r['kind'] ?? null) === 'capacity')));
        self::assertSame('10.00', $allocation->fresh()->reversed_amount);
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame(2, AccountMovement::count());
        self::assertSame('10.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        self::assertSame(1, InvoicePaidProcessing::count());
    }

    public function test_new_owner_hold_during_observed_lock_wait_prevents_spend_without_receipt(): void
    {
        [$owner,$wallet] = $this->fixture();
        $invoice = $this->invoice($owner);
        try {
            [$result] = $this->compete([$this->worker($owner, 'fund', $invoice->id, '60.00')], 'users', $owner->id, fn () => DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic after-wait hold']));
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1213) {
                throw $e;
            } self::fail('Owner hold insertion deadlocked against a pre-owner hold gap lock');
        }
        self::assertSame(['result' => 'blocked', 'kind' => 'hold'], $result);
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, AccountFundingAllocation::count());
        self::assertSame('pending', $invoice->fresh()->status);
    }

    public function test_dependency_expansion_during_observed_invoice_wait_is_rejected_before_wallet_write(): void
    {
        [$owner,$wallet] = $this->fixture();
        $invoice = $this->invoice($owner);
        $item = $invoice->items()->sole();
        $product = $this->createProduct(['server_id' => null, 'stock' => null]);
        $service = Service::factory()->create(['user_id' => $owner->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'currency_code' => 'USD', 'status' => 'active']);
        [$result] = $this->compete([$this->worker($owner, 'fund', $invoice->id, '60.00')], 'invoices', $invoice->id, fn () => $item->update(['reference_type' => Service::class, 'reference_id' => $service->id]));
        self::assertSame(['result' => 'blocked', 'kind' => 'graph'], $result);
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, AccountFundingAllocation::count());
        self::assertSame('pending', $invoice->fresh()->status);
    }

    public function test_current_reversal_permission_revocation_during_observed_invoice_wait_denies_money(): void
    {
        [$owner,$wallet] = $this->fixture();
        $invoice = $this->invoice($owner, '15.00');
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', (string) Str::uuid());
        $role = Role::create(['name' => 'Synthetic after-wait reversal staff', 'permissions' => ['admin.invoice_transactions.account_reverse']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);
        [$result] = $this->compete([$this->worker($actor, 'reverse', $allocation->id, '10.00')], 'invoices', $invoice->id, fn () => $role->update(['permissions' => []]));
        self::assertSame(['result' => 'blocked', 'kind' => 'permission'], $result);
        self::assertSame('0.00', $allocation->fresh()->reversed_amount);
        self::assertSame('-15.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
        self::assertSame('paid', $invoice->fresh()->status);
    }

    private function deposit(User $owner): array
    {
        $gateway = $this->depositGateway('0.00');
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $invoice->items()->create(['description' => 'Synthetic concurrent deposit', 'price' => '50.00', 'quantity' => 1, 'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);

        return [$invoice, $gateway];
    }

    private function refundStaff(): User
    {
        $role = Role::create(['name' => 'Synthetic worker refund staff', 'permissions' => ['admin.invoice_transactions.refund', 'admin.invoice_transactions.reconcile']]);

        return User::factory()->createQuietly(['role_id' => $role->id]);
    }

    private function existingPendingReservation(User $owner): array
    {
        [$invoice, $gateway] = $this->deposit($owner);
        $transaction = $this->verifiedDeposit($invoice, $gateway, 'synthetic-existing-reservation-deposit');
        $actor = $this->refundStaff();
        $adapter = new RaceRefundAdapter;
        app()->instance(GatewayOperations::class, new class($adapter) extends GatewayOperations
        {
            public function __construct(private Adapter $adapter) {}

            public function for(Gateway $gateway): Adapter
            {
                return $this->adapter;
            }
        });
        $this->actingAs($actor);
        $operation = (new Refunds)->submit($actor, $transaction, '20.00', false, 'Synthetic retained reservation', (string) Str::uuid());
        self::assertSame('pending', $operation->state);
        self::assertSame('20.0000', AccountReversalReservation::sole()->principal);
        $this->actingAs($owner);

        return [$actor, $operation];
    }

    private function assertOriginalReceiptBusy(PaymentOperation $operation): void
    {
        $busy = false;
        try {
            DB::table('invoice_transactions')->where('id', $operation->original_transaction_id)->lock('for update nowait')->first();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1205) {
                throw $exception;
            }
            $busy = true;
        }
        self::assertTrue($busy, 'The waiting reconciliation must already hold the original source receipt');
    }

    public function test_final_review_statement_does_not_wait_on_busy_original_receipt_while_reconciliation_waits_on_owner(): void
    {
        [$owner, $wallet] = $this->fixture();
        [$actor, $operation] = $this->existingPendingReservation($owner);
        $worker = $this->worker($actor, 'reconcile', $operation->id, '20.00', ['mode' => 'succeeded']);
        $quote = null;
        $deadlock = false;
        try {
            DB::beginTransaction();
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $worker->start();
            $this->waiting([$worker], 'users');
            $this->assertOriginalReceiptBusy($operation);
            try {
                $quote = (new AccountStatement)->forReader($owner, $owner, 'USD')['quote'];
            } catch (QueryException|DeadlockException $exception) {
                if (!str_contains($exception->getMessage(), 'Deadlock')) {
                    throw $exception;
                }
                $deadlock = true;
            }
            while (DB::transactionLevel() > 0) {
                DB::commit();
            }
            $result = $this->finish($worker);
            self::assertSame('written', $result['result'], 'Reconciliation aborted: ' . json_encode($result));
            self::assertFalse($deadlock, 'Statement acquired a waiting source lock while holding the owner');
            self::assertTrue($quote?->blocked ?? false, 'A busy original receipt must give a readable temporarily blocked quote');
            self::assertSame('20.0000', $quote->reservedPrincipal);
            self::assertSame('0.00', $quote->fundingAvailable);
            self::assertSame('written', $result['result']);
            self::assertSame(0, $result['writes']);
            self::assertSame(1, $result['reads']);
            self::assertSame('30.0000', $wallet->fresh()->balance);
            self::assertFalse((new WalletLedger)->quote($owner, 'USD')->blocked);
            self::assertSame('consumed', AccountReversalReservation::sole()->state);
            Http::assertNothingSent();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    public function test_final_review_unrelated_funding_retries_after_busy_original_receipt_without_deadlock_or_money(): void
    {
        [$owner, $wallet] = $this->fixture();
        [$actor, $operation] = $this->existingPendingReservation($owner);
        $invoice = $this->invoice($owner, '40.00');
        $worker = $this->worker($actor, 'reconcile', $operation->id, '20.00', ['mode' => 'succeeded']);
        $key = (string) Str::uuid();
        $blocked = false;
        $deadlock = false;
        try {
            DB::beginTransaction();
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $worker->start();
            $this->waiting([$worker], 'users');
            $this->assertOriginalReceiptBusy($operation);
            try {
                (new InvoiceFunding)->fund($owner, $invoice, '40.00', $key);
            } catch (InsufficientAccountFunding) {
                $blocked = true;
            } catch (\RuntimeException $exception) {
                if ($exception->getMessage() !== 'Original receipt posting requires active reconciled account history.') {
                    throw $exception;
                }
                $blocked = true;
            } catch (QueryException|DeadlockException $exception) {
                if (!str_contains($exception->getMessage(), 'Deadlock')) {
                    throw $exception;
                }
                $deadlock = true;
            }
            while (DB::transactionLevel() > 0) {
                DB::commit();
            }
            $result = $this->finish($worker);
            self::assertSame('written', $result['result'], 'Reconciliation aborted: ' . json_encode($result));
            self::assertFalse($deadlock, 'Funding acquired a waiting source lock while holding the owner');
            self::assertTrue($blocked, 'A busy original receipt must deny funding before any journal write');
            self::assertSame(0, AccountFundingAllocation::count());
            self::assertSame('30.0000', $wallet->fresh()->balance);
            self::assertSame('written', $result['result']);
            self::assertSame(0, $result['writes']);
            (new InvoiceFunding)->fund($owner, $invoice->fresh(), '40.00', $key);
            self::assertSame('-10.0000', $wallet->fresh()->balance);
            self::assertSame(1, AccountFundingAllocation::count());
            self::assertSame('paid', $invoice->fresh()->status);
            self::assertSame('consumed', AccountReversalReservation::sole()->state);
            Http::assertNothingSent();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    private function boundary(Process $worker, string $kind): int
    {
        $deadline = microtime(true) + 10;
        $pattern = '/boundary:' . preg_quote($kind, '/') . ':([0-9]+)/';
        while (!preg_match($pattern, $worker->getOutput(), $m) && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertSame(1, preg_match($pattern, $worker->getOutput(), $m), $worker->getErrorOutput());

        return (int) $m[1];
    }

    public function test_duplicate_provider_callbacks_wait_on_real_gateway_lock_and_credit_one_deposit(): void
    {
        [$owner,$wallet] = $this->fixture();
        [$deposit,$gateway] = $this->deposit($owner);
        class_exists(FeeGateway::class);
        $attempt = (new PaymentAttempts)->begin($gateway, $deposit, hash('sha256', 'synthetic duplicate merchant'), 'USD');
        $results = $this->compete([$this->worker($owner, 'settle', $attempt->id, '50.00'), $this->worker($owner, 'settle', $attempt->id, '50.00')], $gateway->getTable(), $gateway->id);
        self::assertSame($results[0]['id'], $results[1]['id']);
        self::assertSame(1, AccountMovement::count());
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame('50.00', Credit::sole()->amount);
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame(1, $deposit->transactions()->count());
        self::assertSame('paid', $deposit->fresh()->status);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_spend_and_refund_claim_share_current_effective_capacity_after_observed_owner_wait(): void
    {
        [$owner,$wallet] = $this->fixture('10.00');
        [$deposit,$gateway] = $this->deposit($owner);
        $tx = $this->verifiedDeposit($deposit, $gateway, 'synthetic-race-refundable');
        $bill = $this->invoice($owner, '40.00');
        $actor = $this->refundStaff();
        $results = $this->compete([$this->worker($owner, 'fund', $bill->id, '40.00'), $this->worker($actor, 'refund', $tx->id, '20.00')], 'users', $owner->id);
        self::assertSame(['written', 'written'], array_column($results, 'result'));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        $quote = (new WalletLedger)->quote($owner, 'USD');
        self::assertSame('20.0000', $quote->reservedPrincipal);
        self::assertSame('0.00', $quote->fundingAvailable);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(1, AccountFundingAllocation::count());
        self::assertSame(2, AccountMovement::count());
        self::assertSame('reserved', AccountReversalReservation::sole()->state);
        self::assertSame('pending', PaymentOperation::sole()->state);
        self::assertSame(2, InvoicePaidProcessing::count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_worker_crash_after_durable_claim_preserves_reservation_and_resumes_one_execution(): void
    {
        [$owner,$wallet] = $this->fixture();
        [$deposit,$gateway] = $this->deposit($owner);
        $tx = $this->verifiedDeposit($deposit, $gateway, 'synthetic-crash-queued-deposit');
        $actor = $this->refundStaff();
        $worker = $this->worker($actor, 'refund', $tx->id, '20.00', ['crash' => 'queued', 'mode' => 'succeeded']);
        try {
            $worker->start();
            $id = $this->boundary($worker, 'queued');
            $op = PaymentOperation::findOrFail($id);
            self::assertSame('queued', $op->state);
            self::assertSame('reserved', AccountReversalReservation::sole()->state);
            self::assertSame('30.00', Credit::sole()->amount);
            $worker->stop(0, 9);
            self::assertNotSame(0, $worker->getExitCode());
            self::assertSame('50.0000', $wallet->fresh()->balance);
            $resume = $this->worker($actor, 'resume', $id, '20.00', ['mode' => 'succeeded']);
            $resume->start();
            $result = $this->finish($resume);
            self::assertSame(1, $result['writes']);
            self::assertSame(1, $result['reads']);
            self::assertSame('succeeded', $op->fresh()->state);
            self::assertSame('consumed', AccountReversalReservation::sole()->state);
            self::assertSame('30.0000', $wallet->fresh()->balance);
            self::assertSame(2, AccountMovement::count());
            self::assertSame(1, InvoicePaidProcessing::count());
        } finally {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }if (isset($resume) && $resume->isRunning()) {
                $resume->stop(1);
            }
        }
    }

    public function test_worker_crash_after_durable_verified_result_never_repeats_provider_or_account_movement(): void
    {
        [$owner,$wallet] = $this->fixture();
        [$deposit,$gateway] = $this->deposit($owner);
        $tx = $this->verifiedDeposit($deposit, $gateway, 'synthetic-crash-verified-deposit');
        $actor = $this->refundStaff();
        $worker = $this->worker($actor, 'refund', $tx->id, '20.00', ['crash' => 'verified', 'mode' => 'succeeded']);
        try {
            $worker->start();
            $id = $this->boundary($worker, 'verified');
            $op = PaymentOperation::findOrFail($id);
            self::assertSame('succeeded', $op->state);
            self::assertSame('consumed', AccountReversalReservation::sole()->state);
            self::assertSame('30.0000', $wallet->fresh()->balance);
            $worker->stop(0, 9);
            self::assertNotSame(0, $worker->getExitCode());
            $resume = $this->worker($actor, 'resume', $id, '20.00', ['mode' => 'succeeded']);
            $resume->start();
            $result = $this->finish($resume);
            self::assertSame(0, $result['writes']);
            self::assertSame(0, $result['reads']);
            self::assertSame('succeeded', $op->fresh()->state);
            self::assertSame('30.0000', $wallet->fresh()->balance);
            self::assertSame(2, AccountMovement::count());
            self::assertSame(1, InvoicePaidProcessing::count());
            self::assertSame(1, $deposit->transactions()->count());
        } finally {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }if (isset($resume) && $resume->isRunning()) {
                $resume->stop(1);
            }
        }
    }

    public function test_related_distinct_gateway_callback_and_spend_do_not_invert_observed_gateway_waits(): void
    {
        [$owner,$wallet] = $this->fixture();
        $product = $this->createProduct(['server_id' => null, 'stock' => null]);
        $product->plan->update(['type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
        $service = Service::factory()->create(['user_id' => $owner->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'currency_code' => 'USD', 'expires_at' => '2026-12-01']);
        $lower = $this->depositGateway('0.00');
        $higher = $this->depositGateway('0.00');
        $bill = $this->invoice($owner, '50.00');
        $related = $this->invoice($owner, '60.00');
        foreach ([$bill, $related] as $invoice) {
            $invoice->items()->sole()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        }
        $related->items()->create(['description' => 'Synthetic uncharged gateway marker', 'price' => '0.00', 'quantity' => 1, 'kind' => 'gateway_fee', 'tax_amount' => '0.00', 'gateway_id' => $lower->id]);
        $allocation = (new InvoiceFunding)->fund($owner, $bill, '15.00', (string) Str::uuid());
        $attempt = (new PaymentAttempts)->begin($higher, $bill->fresh(), hash('sha256', 'synthetic distinct gateway merchant'), 'USD');
        $spend = $this->worker($owner, 'fund', $related->id, '60.00');
        $settle = $this->worker($owner, 'settle', $attempt->id, '35.00');
        try {
            DB::beginTransaction();
            Gateway::whereKey($lower->id)->lockForUpdate()->firstOrFail();
            $spend->start();
            $this->waiting([$spend], $lower->getTable());
            $settle->start();
            $this->waiting([$spend, $settle], $lower->getTable());
            DB::commit();
            $results = [$this->finish($spend), $this->finish($settle)];
            self::assertNotContains('deadlock', array_column($results, 'kind'), 'Related callback and spend inverted the complete sorted gateway frame');
            self::assertSame('written', $results[1]['result']);
            self::assertSame('paid', $bill->fresh()->status);
            self::assertSame('paid', $attempt->fresh()->state);
            self::assertSame(1, InvoicePaidProcessing::count());
            self::assertSame(1, AccountFundingAllocation::count());
            self::assertSame('pending', $related->fresh()->status);
            self::assertSame('-15.0000', $wallet->fresh()->balance);
            self::assertSame(1, AccountMovement::count());
            self::assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());
            Http::assertNothingSent();
            Mail::assertNothingSent();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }foreach ([$spend, $settle] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }
}
