<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Services\BillmanagerMigration\Opening\CaptureLineageVerifier;
use App\Services\BillmanagerMigration\Opening\ControlProofGraph;
use App\Services\BillmanagerMigration\Opening\InactiveOpeningAuthority;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\CaptureProofFixture;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class CaptureLineageTest extends TestCase
{
    use UsesCommittedDatabase { tearDown as private committedTearDown; }

    private array $argv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->argv = $_SERVER['argv'];
        $_SERVER['argv'] = [base_path('artisan'), 'billmanager:openings:apply'];
        self::assertTrue(class_exists(CaptureLineageVerifier::class), 'Completed capture lineage verification is missing.');
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;
        ProofFactory::cleanup();
        $this->committedTearDown();
    }

    private function fixture(array $overrides = []): array
    {
        // Each denial compares one complete independent synthetic baseline.
        ProofFactory::cleanup();
        $this->truncateDatabaseTables();

        return CaptureProofFixture::executable($overrides);
    }

    private function verify(array $p): array
    {
        return CaptureLineageVerifier::verify($p['bundle'], new ControlProofGraph, $p['trust_path']);
    }

    private function denied(array $p, callable $call): void
    {
        $before = (new OpeningTargetState)->capture();
        try {
            $call();
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
            self::assertSame($before, (new OpeningTargetState)->capture());

            return;
        }
        self::fail('Unbound capture lineage was accepted: ' . ($p['scenario'] ?? 'unspecified'));
    }

    public function test_completed_capture_binds_snapshot_population_import_and_preparation(): void
    {
        $p = $this->fixture();
        $lineage = $this->verify($p);
        self::assertSame($p['bundle']->snapshot()->checksum(), $lineage['acceptance']['snapshot_sha256']);
        self::assertSame(hash_file('sha256', $p['bundle']->reference('population_review')), $lineage['acceptance']['source_population_fingerprint']);
        self::assertSame($p['data']['preparation_history_sha256'], $lineage['preparation_history']['reference']['sha256']);
        self::assertSame($p['data']['freeze_receipt_sha256'], $lineage['first_ready']['payload_ref']['sha256']);
        self::assertSame($p['bundle']->snapshot()->capturedAt(), $lineage['collector']['snapshot_captured_at']);
        self::assertSame($p['data']['activation_cutoff'], $lineage['acceptance']['activation_cutoff']);
        self::assertSame(0, DB::table('account_wallets')->count());
    }

    public function test_collector_uses_each_hosts_own_clock_bracket(): void
    {
        $p = $this->fixture();
        $lineage = $this->verify($p);
        self::assertNotSame($lineage['collector']['collection_started_monotonic_ns'], $lineage['collector']['source_started_monotonic_ns']);
        foreach ([['snapshot_captured_at' => '2020-01-01T00:00:00Z'], ['source_clock_skew_upper_bound_ms' => 2001], ['collection_completed_monotonic_ns' => '2000000000'], ['collection_completed_monotonic_ns' => '6000000001']] as $bad) {
            $q = $this->fixture(['collector' => $bad]);
            $this->denied($q, fn () => $this->verify($q));
        }
        $q = $this->fixture(['collector' => ['collection_started_monotonic_ns' => '0', 'collection_completed_monotonic_ns' => '1000000000']]);
        $this->denied($q, fn () => $this->verify($q));
    }

    public function test_capture_acceptance_and_first_ready_are_actually_ordered(): void
    {
        foreach (['acceptance_before_collector', 'ready_before_acceptance', 'ready_before_backup', 'ready_at_acceptance_expiry', 'missing_preparation_coverage', 'missing_preparation_utc', 'missing_preparation_monotonic'] as $scenario) {
            $p = $this->fixture(['scenario' => $scenario]);
            $this->denied($p, fn () => $this->verify($p));
        }
    }

    public function test_first_ready_may_meet_its_capture_predecessor_boundary(): void
    {
        $p = $this->fixture(['scenario' => 'preparation_meets_ready']);
        $lineage = $this->verify($p);
        $last = $lineage['preparation_history']['entries'][array_key_last($lineage['preparation_history']['entries'])]['payload'];
        self::assertSame($last['expires_at'], $lineage['first_ready']['payload']['issued_at']);
        self::assertSame($last['expires_monotonic_ns'], $lineage['first_ready']['payload']['issued_monotonic_ns']);
    }

    public function test_completed_backup_can_be_signed_after_acceptance(): void
    {
        $p = $this->fixture();
        $backup = json_decode(file_get_contents($p['bundle']->reference('backup_acceptance')), true, flags: JSON_THROW_ON_ERROR);
        $backup['not_before'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($backup['accepted_at']) + 1);
        $backupPair = ($p['sign'])('backup-later-signature', $backup);
        $ready = json_decode(file_get_contents($p['bundle']->reference('freeze_receipt')), true, flags: JSON_THROW_ON_ERROR);
        $readyAt = max(strtotime($ready['issued_at']), strtotime($backup['not_before'])) + 1;
        $shiftNs = ($readyAt - strtotime($ready['issued_at'])) * 1000000000;
        $ready['issued_at'] = $ready['not_before'] = gmdate('Y-m-d\TH:i:s\Z', $readyAt);
        $ready['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', $readyAt + 60);
        $ready['issued_monotonic_ns'] = (string) ((int) $ready['issued_monotonic_ns'] + $shiftNs);
        $ready['expires_monotonic_ns'] = (string) ((int) $ready['expires_monotonic_ns'] + $shiftNs);
        $readyPair = ($p['sign'])('ready-after-backup-signature', $ready);
        $data = $p['data'];
        $data['references']['backup_acceptance'] = $backupPair['payload'];
        $data['references']['backup_signature'] = $backupPair['signature'];
        $data['references']['freeze_receipt'] = $readyPair['payload'];
        $data['references']['freeze_signature'] = $readyPair['signature'];
        $data['freeze_receipt_sha256'] = $readyPair['payload']['sha256'];
        $path = $p['dir'] . '/later-backup-bundle.json';
        ProofFactory::write($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $p['bundle'] = OpeningBundle::load($path);
        self::assertSame($readyPair['payload']['sha256'], $this->verify($p)['first_ready']['payload_ref']['sha256']);
    }

    public function test_changed_capture_epoch_or_import_facts_deny(): void
    {
        foreach (['source_boot_id', 'observer_boot_id', 'control_profile', 'runtime_database', 'runtime_socket', 'capture_id', 'snapshot_digest', 'population_digest', 'preparation_prefix', 'source_identity', 'login_cutoff', 'activation_cutoff'] as $scenario) {
            $p = $this->fixture(['scenario' => $scenario]);
            $this->denied($p, fn () => $this->verify($p));
        }
        $p = $this->fixture();
        $path = $p['dir'] . '/wrong-import.json';
        $data = $p['data'];
        $data['import_id']++;
        ProofFactory::write($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->denied($p, fn () => OpeningBundle::load($path));
    }

    public function test_static_v2_bundle_has_no_legacy_execution_authority(): void
    {
        $p = $this->fixture();
        $authority = new InactiveOpeningAuthority($p['bundle'], $p['dir'] . '/approval.json', $p['dir'] . '/approval-signature.json', $p['process']);
        $this->denied($p, fn () => $authority->assertFenceCurrent());
    }
}
