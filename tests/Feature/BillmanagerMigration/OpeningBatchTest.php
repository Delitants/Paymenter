<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Services\BillmanagerMigration\Opening\OpeningBatchOperator;
use App\Services\BillmanagerMigration\Opening\OpeningReceiptVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\AcceptedFixture;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningBatchTest extends TestCase
{
    use UsesCommittedDatabase { tearDown as private committedTearDown; }

    private array $argv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->argv = $_SERVER['argv'];
        $_SERVER['argv'] = [base_path('artisan'), 'billmanager:openings:apply'];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;
        ProofFactory::cleanup();
        $this->committedTearDown();
    }

    private function fixture(): array
    {
        self::assertTrue(class_exists(OpeningBatchOperator::class), 'Atomic native opening batch operator is missing.');
        $p = AcceptedFixture::make(realBackup: true);
        config(['account-opening.journal_directory' => $p['dir']]);

        return $p;
    }

    private function apply(array $p): array
    {
        return (new OpeningBatchOperator)->apply($p['bundle'], $p['dir'] . '/approval.json', $p['dir'] . '/approval-signature.json');
    }

    public function test_sealed_batch_is_inactive_and_cannot_initialize_again(): void
    {
        $p = $this->fixture();
        self::assertSame(['status' => 'sealed', 'expected' => 1, 'committed' => 1, 'replayed' => 0, 'sealed' => true], $this->apply($p));
        self::assertFalse(AccountWallet::sole()->active);
        self::assertSame('12.34', Credit::sole()->amount);
        self::assertSame(1, AccountOpeningReceipt::count());
        $before = (new OpeningTargetState)->capture();
        try {
            $this->apply($p);
            self::fail('Sealed apply was accepted.');
        } catch (RuntimeException $e) {
            self::assertSame('Sealed opening batches cannot initialize.', $e->getMessage());
        }
        self::assertSame($before, (new OpeningTargetState)->capture());
        self::assertSame('sealed', (new OpeningReceiptVerifier)->verify($p['bundle'], AccountOpeningBatch::sole())['status']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        $approval = json_decode(file_get_contents($p['dir'] . '/approval.json'), true, flags: JSON_THROW_ON_ERROR);
        $trust = json_decode(file_get_contents($p['dir'] . '/trust.json'), true, flags: JSON_THROW_ON_ERROR);
        $trust['revoked_nonces'][] = $approval['nonce'];
        ProofFactory::write($p['dir'] . '/trust.json', json_encode($trust, JSON_THROW_ON_ERROR));
        self::assertSame('sealed', (new OpeningReceiptVerifier)->verify($p['bundle'], AccountOpeningBatch::sole())['status']);
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_unrelated_target_delta_and_direct_receipt_write_are_denied(): void
    {
        $p = $this->fixture();
        $this->apply($p);
        $before = (new OpeningTargetState)->capture();
        try {
            AccountOpeningReceipt::sole()->delete();
            self::fail('Durable receipt deletion was accepted.');
        } catch (RuntimeException $e) {
            self::assertSame('Opening history is writable only by the live batch store.', $e->getMessage());
        }
        self::assertSame($before, (new OpeningTargetState)->capture());
        DB::table('settings')->insert(['key' => 'synthetic-unrelated-opening-delta', 'value' => 'denied']);
        $changed = (new OpeningTargetState)->capture();
        try {
            (new OpeningReceiptVerifier)->verify($p['bundle'], AccountOpeningBatch::sole());
            self::fail('Unrelated target state was hidden by receipt deltas.');
        } catch (RuntimeException $e) {
            self::assertSame('Unreceipted target state changed.', $e->getMessage());
        }
        self::assertSame($changed, (new OpeningTargetState)->capture());
    }
}
