<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\User;
use App\Services\Accounts\AccountFundingGate;
use App\Services\Accounts\WalletLedger;
use App\Services\BillmanagerMigration\Opening\OpeningAttemptJournal;
use App\Services\BillmanagerMigration\Opening\OpeningReceiptVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\AcceptedFixture;
use Tests\Fixtures\Opening\ConsoleChild;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningBatchRaceTest extends TestCase
{
    use UsesCommittedDatabase { tearDown as private committedTearDown; }

    private array $argv;

    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->argv = $_SERVER['argv'];
        $_SERVER['argv'] = [base_path('artisan'), 'billmanager:openings:apply'];
    }

    protected function tearDown(): void
    {
        $this->children = [];
        $_SERVER['argv'] = $this->argv;
        ProofFactory::cleanup();
        $this->committedTearDown();
    }

    private function fixture(): array
    {
        $stamp = (new \DateTimeImmutable)->modify('-1 year')->format('Y-m-d H:i:s');
        $p = AcceptedFixture::make(realBackup: true, tables: [
            'accounts' => [['id' => '10'], ['id' => '20']],
            'users' => [['id' => '11', 'account' => '10', 'enabled' => 'on', 'level' => '16', 'email' => 'first@example.invalid', 'realname' => 'Synthetic First', 'last_login' => $stamp],
                ['id' => '21', 'account' => '20', 'enabled' => 'on', 'level' => '16', 'email' => 'second@example.invalid', 'realname' => 'Synthetic Second', 'last_login' => $stamp]],
            'subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => '12.3400', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on'],
                ['id' => '41', 'account' => '20', 'currency' => '1', 'balance' => '1.0050', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on']],
        ]);
        config(['account-opening.journal_directory' => $p['dir']]);

        return $p;
    }

    private function child(array $p, string $fault, string $name): ConsoleChild
    {
        return $this->children[] = new ConsoleChild($p, $fault, $name);
    }

    public function test_half_batch_fresh_process_restart_replays_original_receipt_and_wallet_ids(): void
    {
        $p = $this->fixture();
        $holds = DB::table('billmanager_holds')->orderBy('id')->get()->all();
        $first = $this->child($p, 'after-first-commit', 'partial.json');
        self::assertSame(86, $first->finish()['exit_code']);
        $ids = AccountOpeningReceipt::orderBy('id')->get()->map(fn ($row) => [$row->id, $row->wallet_id])->all();
        self::assertCount(1, $ids);
        self::assertFileDoesNotExist($p['dir'] . '/partial.json');
        $resumed = $this->child($p, '', 'resumed.json')->finish();
        self::assertSame(0, $resumed['exit_code'], $resumed['output']);
        $report = json_decode(file_get_contents($p['dir'] . '/resumed.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['status' => 'sealed', 'expected' => 2, 'committed' => 2, 'replayed' => 1, 'sealed' => true], $report['result']);
        self::assertSame($ids[0], AccountOpeningReceipt::orderBy('id')->get()->map(fn ($row) => [$row->id, $row->wallet_id])->first());
        self::assertSame(2, AccountWallet::where('active', false)->count());
        self::assertEquals($holds, DB::table('billmanager_holds')->orderBy('id')->get()->all());
    }

    public function test_killed_precommit_worker_rolls_back_rows_and_resumes_only_bounded_journal_gaps(): void
    {
        $p = $this->fixture();
        $child = $this->child($p, 'before-receipt', 'killed.json');
        $barrier = $child->barrier($p['dir'] . '/killed.json.barrier');
        self::assertSame('projection', $barrier['phase']);
        self::assertSame(1, $barrier['wallets']);
        self::assertSame(0, $barrier['receipts']);
        $child->signal(SIGKILL);
        self::assertSame(137, $child->finish()['exit_code']);
        self::assertSame(0, AccountWallet::count());
        self::assertSame(0, Credit::count());
        self::assertSame(0, AccountOpeningReceipt::count());
        $journal = new OpeningAttemptJournal($p['evidence']->grantHash);
        self::assertCount(1, $journal->attempts($p['bundle']));
        $resumed = $this->child($p, '', 'after-kill.json')->finish();
        self::assertSame(0, $resumed['exit_code'], $resumed['output']);
        self::assertSame(2, AccountOpeningReceipt::count());
        self::assertCount(3, $journal->attempts($p['bundle']));
    }

    public function test_observed_parallel_operator_and_client_cannot_duplicate_or_spend(): void
    {
        $p = $this->fixture();
        $first = $this->child($p, 'before-receipt', 'locked.json');
        self::assertSame('projection', $first->barrier($p['dir'] . '/locked.json.barrier')['phase']);
        $second = $this->child($p, '', 'competing.json')->finish();
        self::assertSame(1, $second['exit_code'], $second['output']);
        self::assertFileDoesNotExist($p['dir'] . '/competing.json');
        try {
            (new AccountFundingGate)->assertEnabled();
            self::fail('Client funding opened during the suspended operator.');
        } catch (RuntimeException $e) {
            self::assertFalse(config('account-funding.enabled'));
        }
        self::assertSame(0, AccountOpeningReceipt::count());
        $first->signal(SIGCONT);
        $result = $first->finish();
        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertSame(2, AccountOpeningReceipt::count());
        self::assertTrue((new WalletLedger)->quote(User::findOrFail($p['owner_id']), 'USD')->blocked);
    }

    public function test_precommit_lease_revocation_retains_only_prior_inactive_commits(): void
    {
        $p = $this->fixture();
        $child = $this->child($p, 'before-second-receipt', 'revoked.json');
        self::assertSame(1, $child->barrier($p['dir'] . '/revoked.json.barrier')['receipts']);
        // Stop this test's independent custodian before changing its trust,
        // leaving its source fence in place until the console child exits.
        foreach (ProofFactory::$children as $pid) {
            self::assertTrue(posix_kill($pid, SIGSTOP));
            $deadline = microtime(true) + 5;
            while (preg_match('/^State:\s+[Tt]\b/m', (string) file_get_contents('/proc/' . $pid . '/status')) !== 1 && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertMatchesRegularExpression('/^State:\s+[Tt]\b/m', file_get_contents('/proc/' . $pid . '/status'));
        }
        $reference = json_decode(file_get_contents($p['dir'] . '/current-lease.json'), true, flags: JSON_THROW_ON_ERROR);
        $lease = json_decode(file_get_contents($reference['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        $trust = json_decode(file_get_contents($p['dir'] . '/trust.json'), true, flags: JSON_THROW_ON_ERROR);
        $trust['revoked_nonces'][] = $lease['nonce'];
        ProofFactory::write($p['dir'] . '/trust.json', json_encode($trust, JSON_THROW_ON_ERROR));
        $child->signal(SIGCONT);
        $result = $child->finish();
        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertSame(1, AccountOpeningReceipt::count());
        self::assertSame(1, AccountWallet::where('active', false)->count());
        self::assertSame(1, Credit::count());
        foreach (ProofFactory::$children as $pid) {
            posix_kill($pid, SIGCONT);
        }
    }

    public function test_postcommit_export_failure_recovers_original_database_receipts(): void
    {
        $p = $this->fixture();
        ProofFactory::write($p['dir'] . '/existing.json', 'original private evidence');
        $result = $this->child($p, '', 'existing.json')->finish();
        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertSame('original private evidence', file_get_contents($p['dir'] . '/existing.json'));
        self::assertSame('sealed', AccountOpeningBatch::sole()->state);
        $ids = AccountOpeningReceipt::orderBy('id')->get()->map(fn ($row) => [$row->id, $row->wallet_id])->all();
        self::assertCount(2, $ids);
        $before = (new OpeningTargetState)->capture();
        $verified = new ConsoleChild($p, '', 'recovered.json', 'verify');
        $this->children[] = $verified;
        $result = $verified->finish();
        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertSame('sealed', json_decode(file_get_contents($p['dir'] . '/recovered.json'), true, flags: JSON_THROW_ON_ERROR)['result']['status']);
        self::assertSame($ids, AccountOpeningReceipt::orderBy('id')->get()->map(fn ($row) => [$row->id, $row->wallet_id])->all());
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_partial_batch_refuses_changed_journal_excess_allocator_and_activated_wallet(): void
    {
        $p = $this->fixture();
        self::assertSame(86, $this->child($p, 'after-first-commit', 'partial-drift.json')->finish()['exit_code']);
        $batch = AccountOpeningBatch::sole();
        $bytes = file_get_contents($batch->journal_path);
        foreach ([$bytes . '{"incomplete":true}', str_replace('opening-attempt', 'tampered-attempt', $bytes)] as $changed) {
            self::assertNotSame($bytes, $changed);
            ProofFactory::write($batch->journal_path, $changed);
            $this->assertVerificationDeniesWithoutWrites($p, $batch);
        }
        ProofFactory::write($batch->journal_path, $bytes);
        rename($batch->journal_path, $batch->journal_path . '.saved');
        try {
            $this->assertVerificationDeniesWithoutWrites($p, $batch);
        } finally {
            rename($batch->journal_path . '.saved', $batch->journal_path);
        }
        $counter = (new OpeningTargetState)->capture()['tables']['account_wallets']['auto_increment'];
        DB::statement('ALTER TABLE account_wallets AUTO_INCREMENT = ' . ($counter + 5));
        $this->assertVerificationDeniesWithoutWrites($p, $batch);
        DB::statement('ALTER TABLE account_wallets AUTO_INCREMENT = ' . $counter);
        DB::table('account_wallets')->update(['active' => true]);
        $this->assertVerificationDeniesWithoutWrites($p, $batch);
        $before = (new OpeningTargetState)->capture();
        $result = $this->child($p, '', 'activated-denied.json')->finish();
        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        self::assertSame(1, AccountOpeningReceipt::count());
    }

    public function test_expiry_after_final_account_verification_rolls_back_only_current_account(): void
    {
        $p = $this->fixture();
        $child = $this->child($p, 'final-second-account', 'late-account.json');
        self::assertSame('final-account-verification', $child->barrier($p['dir'] . '/late-account.json.barrier')['phase']);
        $this->expireLease($p);
        $child->signal(SIGCONT);
        $result = $child->finish();
        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertSame(1, AccountOpeningReceipt::count(), 'Expired final account verification committed an unauthorized second opening.');
        self::assertSame(1, AccountWallet::where('active', false)->count());
        self::assertSame(1, Credit::count());
    }

    public function test_expiry_after_final_seal_verification_keeps_batch_unsealed(): void
    {
        $p = $this->fixture();
        $child = $this->child($p, 'final-seal', 'late-seal.json');
        self::assertSame('final-seal-verification', $child->barrier($p['dir'] . '/late-seal.json.barrier')['phase']);
        $this->expireLease($p);
        $child->signal(SIGCONT);
        $result = $child->finish();
        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertSame('opening', AccountOpeningBatch::sole()->state, 'Expired final seal verification committed a terminal seal.');
        self::assertSame(2, AccountOpeningReceipt::count());
        self::assertSame(2, AccountWallet::where('active', false)->count());
    }

    public function test_killed_initial_batch_resumes_original_bundle_with_only_journaled_batch_allocation(): void
    {
        $p = $this->fixture();
        $child = $this->child($p, 'initial-batch', 'initial-kill.json');
        self::assertSame('initial-batch', $child->barrier($p['dir'] . '/initial-kill.json.barrier')['phase']);
        $child->signal(SIGKILL);
        self::assertSame(137, $child->finish()['exit_code']);
        self::assertSame(0, AccountOpeningBatch::count());
        self::assertSame(0, AccountWallet::count());
        $counter = OpeningAttemptJournal::allocator('account_opening_batches');
        DB::statement('ALTER TABLE account_opening_batches AUTO_INCREMENT = ' . ($counter + 5));
        $unexplained = (new OpeningTargetState)->capture();
        self::assertSame(1, $this->child($p, '', 'initial-unexplained.json')->finish()['exit_code']);
        self::assertSame($unexplained, (new OpeningTargetState)->capture());
        DB::statement('ALTER TABLE account_opening_batches AUTO_INCREMENT = ' . $counter);
        $result = $this->child($p, '', 'initial-resumed.json')->finish();
        self::assertSame(0, $result['exit_code'], 'Original approved bundle could not recover its initial rolled-back batch allocation: ' . $result['output']);
        self::assertSame('sealed', AccountOpeningBatch::sole()->state);
        self::assertSame(2, AccountOpeningReceipt::count());
    }

    private function expireLease(array $p): void
    {
        foreach (ProofFactory::$children as $pid) {
            self::assertTrue(posix_kill($pid, SIGSTOP));
            $deadline = microtime(true) + 5;
            while (preg_match('/^State:\s+[Tt]\b/m', (string) file_get_contents('/proc/' . $pid . '/status')) !== 1 && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertMatchesRegularExpression('/^State:\s+[Tt]\b/m', file_get_contents('/proc/' . $pid . '/status'));
        }
        $reference = json_decode(file_get_contents($p['dir'] . '/current-lease.json'), true, flags: JSON_THROW_ON_ERROR);
        $lease = json_decode(file_get_contents($reference['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        $expires = strtotime($lease['expires_at']);
        self::assertLessThanOrEqual(time() + 60, $expires);
        while (time() <= $expires) {
            usleep(100000);
        }
    }

    private function assertVerificationDeniesWithoutWrites(array $p, AccountOpeningBatch $batch): void
    {
        $before = (new OpeningTargetState)->capture();
        try {
            (new OpeningReceiptVerifier)->verify($p['bundle'], $batch);
            self::fail('Changed partial opening evidence was accepted.');
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame($before, (new OpeningTargetState)->capture());
    }
}
