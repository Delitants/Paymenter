<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\AccountOpeningBatch;
use App\Services\BillmanagerMigration\Opening\InactiveOpeningAuthority;
use App\Services\BillmanagerMigration\Opening\OpeningBatchOperator;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningPreparation;
use App\Services\BillmanagerMigration\Opening\OpeningReceiptVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\AcceptedFixture;
use Tests\Fixtures\Opening\CaptureProofFixture;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningVersionCompatibilityTest extends TestCase
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
        self::fail('Mixed opening versions were accepted.');
    }

    public function test_schema1_input_and_historical_receipts_keep_original_contracts(): void
    {
        $p = AcceptedFixture::make(realBackup: true);
        config(['account-opening.journal_directory' => $p['dir']]);
        $inputBytes = file_get_contents($p['dir'] . '/input.json');
        $report = (new OpeningPreparation)->prepare(OpeningBundle::load($p['dir'] . '/input.json'));
        self::assertSame($inputBytes, file_get_contents($p['dir'] . '/input.json'));
        self::assertFalse(OpeningBundle::load($p['dir'] . '/input.json')->executable());
        self::assertSame(1, $p['data']['schema_version']);
        self::assertSame($p['bundle']->snapshot()->checksum(), $report['snapshot_sha256']);
        (new OpeningBatchOperator)->apply($p['bundle'], $p['dir'] . '/approval.json', $p['dir'] . '/approval-signature.json');
        $original = (array) DB::table('account_opening_receipts')->sole();
        $proof = json_decode($original['delta'], true, flags: JSON_THROW_ON_ERROR)['fence_proof'];
        self::assertSame(['payload_b64', 'signature'], array_keys($proof));
        $rows = OpeningReceiptVerifier::committedRows($p['bundle'], AccountOpeningBatch::sole());
        self::assertArrayNotHasKey('fence_history', $rows[1]);
        self::assertSame('sealed', (new OpeningReceiptVerifier)->verify($p['bundle'], AccountOpeningBatch::sole())['status']);
        self::assertSame($original, (array) DB::table('account_opening_receipts')->sole());
    }

    public function test_v2_cannot_borrow_any_v1_authority_component(): void
    {
        $p = CaptureProofFixture::ready();
        $v2Bytes = file_get_contents($p['bundle_path']);
        $data = $p['data'];
        $data['schema_version'] = 1;
        $path = $p['dir'] . '/v1-with-v2-fields.json';
        ProofFactory::write($path, json_encode($data));
        $this->denied(fn () => OpeningBundle::load($path));
        $input = json_decode(file_get_contents($p['dir'] . '/input.json'), true, flags: JSON_THROW_ON_ERROR);
        $input['schema_version'] = 2;
        $path = $p['dir'] . '/v2-input.json';
        ProofFactory::write($path, json_encode($input));
        $this->denied(fn () => OpeningBundle::load($path));
        $authority = fn () => new InactiveOpeningAuthority($p['bundle'], $p['approval_path'], $p['signature_path'], $p['process']);
        $pointer = $p['pointer_record'];
        unset($pointer['history']);
        $pointer['schema_version'] = 1;
        ProofFactory::write($p['pointer_path'], json_encode($pointer));
        $this->denied(fn () => $authority()->assertFenceCurrent());
        ProofFactory::write($p['pointer_path'], json_encode($p['pointer_record']));
        $this->denied(fn () => (new InactiveOpeningAuthority($p['bundle'], $p['dir'] . '/approval.json', $p['dir'] . '/approval-signature.json', $p['process']))->assertApproved($p['evidence']));
        self::assertSame($v2Bytes, file_get_contents($p['bundle_path']));
        self::assertSame(0,DB::table('account_opening_receipts')->count());
    }
}
