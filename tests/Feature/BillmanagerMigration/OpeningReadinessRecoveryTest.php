<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Models\AccountWallet;
use App\Services\BillmanagerMigration\Opening\ControlProofGraph;
use App\Services\BillmanagerMigration\Opening\InactiveOpeningAuthority;
use App\Services\BillmanagerMigration\Opening\OpeningBackupVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningBatchOperator;
use App\Services\BillmanagerMigration\Opening\OpeningBatchStore;
use App\Services\BillmanagerMigration\Opening\OpeningFenceHistoryVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningReceiptVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\AcceptedFixture;
use Tests\Fixtures\Opening\CaptureProofFixture;
use Tests\Fixtures\Opening\ConsoleChild;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningReadinessRecoveryTest extends TestCase
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

    private function fixture(array $options = []): array
    {
        $stamp = (new \DateTimeImmutable)->modify('-1 year')->format('Y-m-d H:i:s');

        return CaptureProofFixture::ready([...$options, 'heartbeat' => true, 'tables' => [
            'accounts' => [['id' => '10'], ['id' => '20']],
            'users' => [['id' => '11', 'account' => '10', 'enabled' => 'on', 'level' => '16', 'email' => 'first@example.invalid', 'realname' => 'Synthetic First', 'last_login' => $stamp],
                ['id' => '21', 'account' => '20', 'enabled' => 'on', 'level' => '16', 'email' => 'second@example.invalid', 'realname' => 'Synthetic Second', 'last_login' => $stamp]],
            'subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => '12.3400', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on'],
                ['id' => '41', 'account' => '20', 'currency' => '1', 'balance' => '1.0050', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on']],
        ]]);
    }

    private function child(array $p, string $fault = '', string $name = 'result.json', string $command = 'apply'): ConsoleChild
    {
        return $this->children[] = new ConsoleChild($p, $fault, $name, $command);
    }

    private function partial(array $p): void
    {
        $result = $this->child($p, 'after-first-commit', 'partial.json')->finish(900);
        self::assertSame(86, $result['exit_code'], $result['output']);
        self::assertSame(1, AccountOpeningReceipt::count());
        self::assertSame('opening', AccountOpeningBatch::sole()->state);
    }

    private function verify(array $p): array
    {
        return (new OpeningReceiptVerifier)->verify($p['bundle'], AccountOpeningBatch::sole());
    }

    private function denied(callable $call): void
    {
        $before = (new OpeningTargetState)->capture();
        try {
            $call();
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
            self::assertSame($before, (new OpeningTargetState)->capture());

            return;
        }
        self::fail('Unproven recovery authority was accepted.');
    }

    private function stopCustody(array $p): void
    {
        self::assertTrue(posix_kill($p['custodian_pid'], SIGSTOP));
        $deadline = microtime(true) + 5;
        while (preg_match('/^State:\s+[Tt]\b/m', file_get_contents('/proc/' . $p['custodian_pid'] . '/status')) !== 1 && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertMatchesRegularExpression('/^State:\s+[Tt]\b/m', file_get_contents('/proc/' . $p['custodian_pid'] . '/status'));
    }

    private function current(array $p): array
    {
        $p['pointer_record'] = json_decode(file_get_contents($p['pointer_path']), true, flags: JSON_THROW_ON_ERROR);
        $p['history']['history_ref'] = $p['pointer_record']['history'];
        $p['history']['records']['leases'] = json_decode(file_get_contents($p['pointer_record']['history']['path']), true, flags: JSON_THROW_ON_ERROR)['leases'];

        return $p;
    }

    public function test_recorded_head_requires_exact_original_bytes_and_signature(): void
    {
        $p = CaptureProofFixture::history();
        $history = OpeningFenceHistoryVerifier::verify($p['history_ref'], new ControlProofGraph, $p['trust_path']);
        $head = $history['entries'][array_key_last($history['entries'])];
        self::assertTrue(method_exists(OpeningFenceHistoryVerifier::class, 'assertRecordedHead'), 'Original recorded-head check is missing.');
        OpeningFenceHistoryVerifier::assertRecordedHead($history, $head['payload_bytes'], $head['signature']);
        foreach ([[$head['payload_bytes'] . ' ', $head['signature']], [$history['entries'][0]['payload_bytes'], $history['entries'][0]['signature']],
            [$head['payload_bytes'], [...$head['signature'], 'signature_b64' => base64_encode('changed')]]] as [$bytes, $signature]) {
            $this->denied(fn () => OpeningFenceHistoryVerifier::assertRecordedHead($history, $bytes, $signature));
        }
    }

    public function test_legacy_receipt_cannot_borrow_a_v2_ready_payload(): void
    {
        $p = AcceptedFixture::make(heartbeat: false);
        $fence = json_decode(file_get_contents($p['dir'] . '/fence.json'), true, flags: JSON_THROW_ON_ERROR);
        $fence = [...$fence, 'schema_version' => 2, 'sequence' => 2, 'nonce' => 'mixed-version-receipt', 'source_capture_id' => 'fixture-capture',
            'control_epoch_sha256' => str_repeat('a', 64), 'capture_fence_sha256' => str_repeat('b', 64), 'capture_acceptance_sha256' => str_repeat('c', 64),
            'preparation_history_sha256' => str_repeat('d', 64), 'previous_lease_sha256' => str_repeat('e', 64), 'issued_monotonic_ns' => '1000000000', 'expires_monotonic_ns' => '61000000000'];
        ($p['sign'])('mixed-version', 'account-opening-fence', $fence);
        $proof = ['payload_b64' => base64_encode(file_get_contents($p['dir'] . '/mixed-version.json')),
            'signature' => json_decode(file_get_contents($p['dir'] . '/mixed-version-signature.json'), true, flags: JSON_THROW_ON_ERROR)];
        $method = new \ReflectionMethod(OpeningReceiptVerifier::class, 'historicalFence');
        $this->denied(fn () => $method->invoke(null, $p['bundle'], $proof));
    }

    public function test_v2_receipt_retains_exact_head_and_history_reference(): void
    {
        $p = $this->fixture();
        $holds = DB::table('billmanager_holds')->orderBy('id')->get()->all();
        self::assertSame('sealed', (new OpeningBatchOperator)->apply($p['bundle'], $p['approval_path'], $p['signature_path'])['status']);
        $rows = OpeningReceiptVerifier::committedRows($p['bundle'], AccountOpeningBatch::sole());
        self::assertCount(3, $rows);
        foreach (array_slice($rows, 1) as $row) {
            $raw = $row['receipt_row'];
            $proof = json_decode($raw['delta'], true, flags: JSON_THROW_ON_ERROR)['fence_proof'];
            self::assertSame(['schema_version', 'payload_b64', 'signature', 'history'], array_keys($proof));
            self::assertSame($proof['history'], $row['fence_history']);
            self::assertSame(hash_file('sha256', $proof['history']['path']), $proof['history']['sha256']);
            $history = OpeningFenceHistoryVerifier::verify($proof['history'], new ControlProofGraph, $p['trust_path']);
            $head = $history['entries'][array_key_last($history['entries'])];
            self::assertSame($head['payload_bytes'], base64_decode($proof['payload_b64'], true));
            self::assertSame($head['signature'], $proof['signature']);
            self::assertSame($raw['receipt_sha256'], OpeningBatchStore::receiptHash($raw));
            self::assertSame($raw['wallet_id'], $row['wallet_row']['id']);
        }
        $before = (new OpeningTargetState)->capture();
        self::assertSame('sealed', $this->verify($p)['status']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        self::assertSame(2, AccountWallet::where('active', false)->count());
        self::assertEquals($holds, DB::table('billmanager_holds')->orderBy('id')->get()->all());
        $again = $this->child($p, '', 'sealed-again.json')->finish(900);
        self::assertSame(1, $again['exit_code']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        $this->denied(fn () => (new OpeningBackupVerifier)->assertReady($p['bundle']));
    }

    public function test_read_only_verification_needs_historical_lineage_not_live_approval(): void
    {
        $p = $this->fixture(['approval_seconds' => 300]);
        $this->partial($p);
        $this->stopCustody($p);
        $pointer = $this->current($p)['pointer_record'];
        $head = json_decode(file_get_contents($pointer['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        $expiry = max(strtotime($head['expires_at']), strtotime($p['approval']['expires_at']));
        while (time() <= $expiry) {
            usleep(100000);
        }
        self::assertGreaterThanOrEqual(strtotime($p['approval']['expires_at']), time());
        $before = (new OpeningTargetState)->capture();
        self::assertSame('partial_inactive', $this->verify($p)['status']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        $trust = json_decode(file_get_contents($p['trust_path']), true, flags: JSON_THROW_ON_ERROR);
        $row = OpeningReceiptVerifier::committedRows($p['bundle'], AccountOpeningBatch::sole())[1];
        foreach (['key', 'nonce'] as $kind) {
            $changed = $trust;
            $changed[$kind === 'key' ? 'revoked_keys' : 'revoked_nonces'][] = $kind === 'key' ? 'fixture' : $row['fence']['nonce'];
            ProofFactory::write($p['trust_path'], json_encode($changed));
            $this->denied(fn () => $this->verify($p));
        }
        ProofFactory::write($p['trust_path'], json_encode($trust));
        config(['account-opening.fence_index_path' => '/missing-live-pointer', 'account-opening.trust_path' => $p['trust_path']]);
        unlink($p['approval_path']);
        self::assertSame('partial_inactive', $this->verify($p)['status']);
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_partial_resume_requires_original_bundle_and_continuous_extension(): void
    {
        $p = $this->fixture();
        $this->partial($p);
        $original = (array) DB::table('account_opening_receipts')->sole();
        $batch = AccountOpeningBatch::sole()->getRawOriginal();
        $journal = file_get_contents($batch['journal_path']);
        $result = $this->child($p, '', 'resumed.json')->finish(900);
        self::assertSame(0, $result['exit_code'], $result['output']);
        $report = json_decode(file_get_contents($p['dir'] . '/resumed.json'), true, flags: JSON_THROW_ON_ERROR)['result'];
        self::assertSame(['status' => 'sealed', 'expected' => 2, 'committed' => 2, 'replayed' => 1, 'sealed' => true], $report);
        self::assertSame($original, (array) DB::table('account_opening_receipts')->where('id', $original['id'])->sole());
        self::assertSame($batch['id'], AccountOpeningBatch::sole()->id);
        self::assertStringStartsWith($journal, file_get_contents($batch['journal_path']));
    }

    public function test_missing_history_after_commit_denies_without_repair(): void
    {
        $p = $this->fixture();
        $this->partial($p);
        $proof = json_decode(DB::table('account_opening_receipts')->value('delta'), true, flags: JSON_THROW_ON_ERROR)['fence_proof'];
        $history = json_decode(file_get_contents($proof['history']['path']), true, flags: JSON_THROW_ON_ERROR);
        foreach ([$proof['history']['path'], $history['leases'][0]['signature']['path'], $history['leases'][0]['payload']['path']] as $path) {
            rename($path, $path . '.saved');
            try {
                $this->denied(fn () => $this->verify($p));
                $before = (new OpeningTargetState)->capture();
                $result = $this->child($p, '', 'missing-' . bin2hex(random_bytes(3)) . '.json')->finish(900);
                self::assertSame(1, $result['exit_code']);
                self::assertSame($before, (new OpeningTargetState)->capture());
            } finally {
                rename($path . '.saved', $path);
            }
        }
    }

    public function test_fresh_process_rejects_older_live_pointer(): void
    {
        $p = $this->fixture();
        $first = $this->child($p, 'before-first-receipt-then-exit', 'live-regression-first.json');
        self::assertSame('projection', $first->barrier($p['dir'] . '/live-regression-first.json.barrier', 900)['phase']);
        $this->stopCustody($p);
        $p = $this->current($p);
        $old = file_get_contents($p['pointer_path']);
        $oldHead = json_decode(file_get_contents($p['pointer_record']['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        $p = CaptureProofFixture::publish($p);
        $first->signal(SIGCONT);
        $result = $first->finish(900);
        self::assertSame(86, $result['exit_code'], $result['output']);
        self::assertGreaterThan(time(), strtotime($oldHead['expires_at']), 'Regression fixture did not retain a live older head.');
        ProofFactory::write($p['pointer_path'], $old);
        $before = (new OpeningTargetState)->capture();
        $result = $this->child($p, '', 'regressed.json')->finish(900);
        self::assertSame(1, $result['exit_code'], $result['output']);
        $stillLive = (new InactiveOpeningAuthority($p['bundle'], $p['approval_path'], $p['signature_path'], $p['process']))->fenceFacts();
        $recorded = json_decode(base64_decode(json_decode(DB::table('account_opening_receipts')->value('delta'), true, flags: JSON_THROW_ON_ERROR)['fence_proof']['payload_b64'], true), true, flags: JSON_THROW_ON_ERROR);
        self::assertLessThan($recorded['sequence'], $stillLive['sequence']);
        self::assertGreaterThan(time(), strtotime($stillLive['expires_at']));
        self::assertSame($before, (new OpeningTargetState)->capture());
        self::assertSame('partial_inactive', $this->verify($p)['status']);
    }

    public function test_gap_new_epoch_or_new_baseline_cannot_resume_original_batch(): void
    {
        $p = $this->fixture();
        $this->partial($p);
        $this->stopCustody($p);
        $p = $this->current($p);
        $pointer = file_get_contents($p['pointer_path']);
        foreach (['control_epoch_sha256', 'source_capture_id', 'target_baseline_sql_sha256', 'previous_lease_sha256', 'gap'] as $field) {
            $changes = $field === 'gap' ? ['issued_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 61), 'not_before' => gmdate('Y-m-d\TH:i:s\Z', time() + 61), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 121)] : [$field => $field === 'source_capture_id' ? 'different-capture' : str_repeat('f', 64)];
            CaptureProofFixture::publish($p, $changes);
            $this->denied(fn () => (new InactiveOpeningAuthority($p['bundle'], $p['approval_path'], $p['signature_path'], $p['process']))->assertFenceCurrent());
            ProofFactory::write($p['pointer_path'], $pointer);
        }
        $before = (new OpeningTargetState)->capture();
        $data = $p['data'];
        $data['source_capture_id'] = 'replacement-bundle';
        ProofFactory::write($p['bundle_path'], json_encode($data));
        $result = $this->child($p, '', 'replacement.json')->finish(900);
        self::assertSame(1, $result['exit_code']);
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_new_live_head_cannot_commit_an_expired_recorded_receipt_proof(): void
    {
        $p = $this->fixture();
        $holds = DB::table('billmanager_holds')->orderBy('id')->get()->all();
        $child = $this->child($p, 'final-first-account', 'receipt-expiry.json');
        self::assertSame(1, $child->barrier($p['dir'] . '/receipt-expiry.json.barrier', 900)['receipts']);
        $this->stopCustody($p);
        $p = $this->current($p);
        $head = json_decode(file_get_contents($p['pointer_record']['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        $expiry = strtotime($head['expires_at']);
        while (time() < $expiry - 20) {
            usleep(100000);
        }
        $p = CaptureProofFixture::publish($p);
        while (time() <= $expiry) {
            usleep(100000);
        }
        $beforeResume = (new InactiveOpeningAuthority($p['bundle'], $p['approval_path'], $p['signature_path'], $p['process']))->fenceFacts();
        self::assertGreaterThan(time(), strtotime($beforeResume['expires_at']));
        $child->signal(SIGCONT);
        $result = $child->finish(900);
        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertSame(0, AccountOpeningReceipt::count(), 'A fresh successor let an expired recorded receipt commit.');
        $stillLive = (new InactiveOpeningAuthority($p['bundle'], $p['approval_path'], $p['signature_path'], $p['process']))->fenceFacts();
        self::assertGreaterThan(time(), strtotime($stillLive['expires_at']));
        self::assertSame(0, AccountWallet::count());
        self::assertSame(0, DB::table('credits')->count());
        self::assertSame('opening', AccountOpeningBatch::sole()->state);
        self::assertEquals($holds, DB::table('billmanager_holds')->orderBy('id')->get()->all());
    }

    public function test_receipt_proof_rejects_mixed_version_oversize_and_changed_recorded_head(): void
    {
        $p = $this->fixture();
        $this->partial($p);
        $row = (array) DB::table('account_opening_receipts')->sole();
        $delta = json_decode($row['delta'], true, flags: JSON_THROW_ON_ERROR);
        $original = $delta['fence_proof'];
        $bad = [];
        $legacy = $original;
        unset($legacy['schema_version'], $legacy['history']);
        $bad[] = $legacy;
        $bad[] = [...$original, 'schema_version' => 1];
        $bad[] = [...$original, 'payload_b64' => str_repeat('A', 87385)];
        $bad[] = [...$original, 'payload_b64' => base64_encode(str_repeat('x', 65537))];
        $bad[] = [...$original, 'payload_b64' => $original['payload_b64'] . "\n"];
        $bad[] = [...$original, 'history' => $p['history']['records']['capture_history_ref']];
        foreach ($bad as $proof) {
            $changed = $row;
            $changed['delta'] = json_encode([...$delta, 'fence_proof' => $proof], JSON_THROW_ON_ERROR);
            $changed['receipt_sha256'] = OpeningBatchStore::receiptHash($changed);
            DB::table('account_opening_receipts')->where('id', $row['id'])->update(['delta' => $changed['delta'], 'receipt_sha256' => $changed['receipt_sha256']]);
            $this->denied(fn () => $this->verify($p));
        }
        DB::table('account_opening_receipts')->where('id', $row['id'])->update(['delta' => $row['delta'], 'receipt_sha256' => $row['receipt_sha256']]);
        self::assertSame('partial_inactive', $this->verify($p)['status']);
    }
}
