<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\AccountOpeningReceipt;
use App\Models\AccountWallet;
use App\Services\BillmanagerMigration\Opening\ControlProofGraph;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningFenceHistoryVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningFenceReference;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\ChronologicalOpeningFixture;
use Tests\Fixtures\Opening\ConsoleChild;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningChronologyTest extends TestCase
{
    use UsesCommittedDatabase { tearDown as private committedTearDown; }

    private array $argv;

    private array $fixtures = [];

    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->argv = $_SERVER['argv'];
        $_SERVER['argv'] = [base_path('artisan'), 'billmanager:openings:prepare'];
        self::assertTrue(class_exists(ChronologicalOpeningFixture::class), 'Actual ordered native capture lifecycle is missing.');
    }

    protected function tearDown(): void
    {
        $this->children = [];
        foreach (array_reverse($this->fixtures) as $fixture) {
            $fixture->retire();
        }
        $this->fixtures = [];
        $_SERVER['argv'] = $this->argv;
        $this->committedTearDown();
    }

    private function start(): ChronologicalOpeningFixture
    {
        return $this->fixtures[] = ChronologicalOpeningFixture::start();
    }

    private function child(ChronologicalOpeningFixture $fixture, string $fault = '', string $name = 'result.json', string $command = 'apply'): ConsoleChild
    {
        return $this->children[] = new ConsoleChild($fixture->evidence()['operator'], $fault, $name, $command);
    }

    private function assertOrder(array $events, array $phases): void
    {
        $prior = -1;
        $lastNs = -1;
        foreach ($phases as $phase) {
            $positions = array_keys(array_column($events, 'phase'), $phase, true);
            self::assertNotEmpty($positions, $phase);
            $position = $positions[0];
            self::assertGreaterThan($prior, $position, $phase);
            self::assertGreaterThanOrEqual($lastNs, (int) $events[$position]['monotonic_ns'], $phase);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $events[$position]['utc']);
            $prior = $position;
            $lastNs = (int) $events[$position]['monotonic_ns'];
        }
    }

    public function test_legacy_sign_before_capture_cannot_truthfully_bind_later_results(): void
    {
        $fixture = $this->start();
        $early = $fixture->evidence()['legacy_pre_capture'];
        $bytes = file_get_contents($early['payload']['path']);
        self::assertFileDoesNotExist($fixture->evidence()['snapshot_path']);
        $fixture->completeCapture();
        $fixture->prepareReady();
        self::assertSame($bytes, file_get_contents($early['payload']['path']));
        $p = $fixture->evidence()['operator'];
        $legacyValue = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $actual = OpeningBundle::load($p['bundle_path'])->data();
        self::assertNotSame($actual['baseline_sql_sha256'], $legacyValue['target_baseline_sql_sha256']);
        self::assertNotSame($actual['references']['population_review']['sha256'], $legacyValue['source_population_fingerprint']);
        $p = [...$p, ...$fixture->evidence()['legacy_operator']];
        $before = (new OpeningTargetState)->capture();
        $child = $this->children[] = new ConsoleChild($p, '', 'legacy-denied.json');
        $result = $child->finish(900);
        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        self::assertSame(0, AccountOpeningReceipt::count());
        $this->assertOrder($fixture->evidence()['events'], ['capture-lease', 'legacy-pre-capture', 'collector-start', 'snapshot-complete', 'capture-acceptance', 'import-complete', 'first-ready']);
    }

    public function test_actual_capture_import_backup_ready_and_inactive_opening_order(): void
    {
        $fixture = $this->start();
        self::assertTrue($fixture->assertGatesHeld());
        $input = $fixture->completeCapture();
        self::assertSame(hash_file('sha256', $input['path']), $input['sha256']);
        $paths = $fixture->prepareReady();
        self::assertSame(['bundle_path', 'approval_path', 'signature_path'], array_keys($paths));
        $beforeHolds = DB::table('billmanager_holds')->orderBy('id')->get()->all();
        $result = $this->child($fixture)->finish(900);
        self::assertSame(0, $result['exit_code'], $result['output']);
        $ids = DB::table('account_opening_receipts')->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->wallet_id, $r->receipt_sha256])->all();
        self::assertCount(2, $ids);
        $before = (new OpeningTargetState)->capture();
        $verified = $this->child($fixture, '', 'verified.json', 'verify')->finish(900);
        self::assertSame(0, $verified['exit_code'], $verified['output']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        self::assertSame(2, AccountWallet::where('active', false)->count());
        self::assertEquals($beforeHolds, DB::table('billmanager_holds')->orderBy('id')->get()->all());
        $e = $fixture->evidence();
        self::assertNotSame($e['controller_namespace'], $e['operator_namespace']);
        self::assertNotSame($e['cold_before']['server_start'], $e['cold_after']['server_start']);
        foreach (['sql_sha256', 'runtime_sha256', 'configuration_sha256', 'holds_sha256', 'target_identity', 'network_namespace'] as $field) {
            self::assertSame($e['cold_before'][$field], $e['cold_after'][$field], $field);
        }
        $this->assertOrder($e['events'], ['gates-held', 'capture-lease', 'collector-start', 'snapshot-complete', 'population-complete', 'capture-acceptance', 'import-complete', 'preparation-complete', 'cold-restore', 'operator-namespace', 'backup-acceptance', 'first-ready', 'bundle', 'human-approval', 'account-commit']);
        self::assertTrue($fixture->assertGatesHeld());
        self::assertFileExists($e['retention_manifest_path']);
    }

    public function test_actor_restart_with_continuous_custody_resumes_original_ids(): void
    {
        $fixture = $this->start();
        $fixture->completeCapture();
        $fixture->prepareReady();
        $first = $this->child($fixture, 'after-first-commit', 'partial.json')->finish(900);
        self::assertSame(86, $first['exit_code'], $first['output']);
        $original = (array) DB::table('account_opening_receipts')->sole();
        $fixture->interruptOperator();
        $result = $this->child($fixture, '', 'resumed.json')->finish(900);
        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertSame($original, (array) DB::table('account_opening_receipts')->where('id', $original['id'])->sole());
        self::assertSame(2, AccountOpeningReceipt::count());
        self::assertTrue($fixture->assertGatesHeld());
    }

    public function test_signer_or_observer_loss_keeps_gates_closed(): void
    {
        $fixture = $this->start();
        $fixture->completeCapture();
        $fixture->prepareReady();
        $first = $this->child($fixture, 'after-first-commit', 'before-loss.json')->finish(900);
        self::assertSame(86, $first['exit_code'], $first['output']);
        $fixture->stopCustody();
        $e = $fixture->evidence();
        while (time() <= strtotime($e['last_lease_expires_at'])) {
            usleep(100000);
        }
        $before = (new OpeningTargetState)->capture();
        $result = $this->child($fixture, '', 'after-loss.json')->finish(900);
        self::assertSame(1, $result['exit_code']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        self::assertTrue($fixture->lateRestartDenied());
        self::assertTrue($fixture->assertGatesHeld());
        self::assertSame(1, AccountOpeningReceipt::count());
    }

    public function test_crash_at_each_publication_boundary_never_acknowledges_partial_generation(): void
    {
        foreach (['payload', 'signature', 'index', 'pointer-staging'] as $phase) {
            $fixture = $this->start();
            $before = $fixture->evidence()['current_pointer'];
            $result = $fixture->crashPublisherAt($phase);
            self::assertFalse($result['acknowledged']);
            config(['account-opening.fence_index_path' => $fixture->evidence()['pointer_path']]);
            $graph = new ControlProofGraph;
            $reference = OpeningFenceReference::currentV2($graph);
            self::assertSame($before, $reference);
            $history = OpeningFenceHistoryVerifier::verify($reference['history'], $graph, $fixture->evidence()['trust_path']);
            self::assertSame($reference['payload'], $history['entries'][array_key_last($history['entries'])]['payload_ref']);
            self::assertTrue($fixture->assertGatesHeld());
            $fixture->retire();
        }
        self::assertSame(0, AccountOpeningReceipt::count());
    }
}
