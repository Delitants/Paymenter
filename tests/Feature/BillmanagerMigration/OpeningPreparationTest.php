<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Services\BillmanagerMigration\Opening\OpeningBackupVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningPreparation;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\MigrationFixture;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningPreparationTest extends TestCase
{
    use UsesCommittedDatabase { tearDown as private committedTearDown; }

    protected function tearDown(): void
    {
        ProofFactory::cleanup();
        $this->committedTearDown();
    }

    private function denied(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());

            return;
        }
        self::fail('Changed preparation was accepted.');
    }

    public function test_preparation_is_read_only_and_requires_complete_scope(): void
    {
        self::assertTrue(class_exists(OpeningPreparation::class), 'Read-only opening preparation is missing.');
        $p = MigrationFixture::input();
        $before = (new OpeningTargetState)->capture();
        $report = (new OpeningPreparation)->prepare(OpeningBundle::load($p['path']));
        self::assertFalse($report['application_authorized']);
        self::assertSame(1, $report['counts']['candidate']);
        self::assertSame('12.3500', $report['accounts'][0]['effective_opening']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        $changed = $p['input'];
        $changed['scope'] = [];
        MigrationFixture::writeInput($p['path'], $changed);
        $this->denied(fn () => (new OpeningPreparation)->prepare(OpeningBundle::load($p['path'])));
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_original_archive_cutoff_is_distinct_from_activation_cutoff(): void
    {
        self::assertTrue(class_exists(OpeningBundle::class), 'Opening input validation is missing.');
        $p = MigrationFixture::input([], '2026-06-01T00:00:00Z', '2024-06-01 00:00:00');
        $report = (new OpeningPreparation)->prepare(OpeningBundle::load($p['path']));
        self::assertSame('2024-01-01 00:00:00', $report['login_cutoff']);
        self::assertSame('2024-06-01 00:00:00', $report['activation_cutoff']);
        $input = $p['input'];
        $input['activation_cutoff'] = '2023-01-01 00:00:00';
        MigrationFixture::writeInput($p['path'], $input);
        $this->denied(fn () => (new OpeningPreparation)->prepare(OpeningBundle::load($p['path'])));
    }

    public function test_all_target_rows_and_only_receipted_deltas_are_verified(): void
    {
        self::assertTrue(class_exists(OpeningTargetState::class), 'Exact target state comparison is missing.');
        $p = MigrationFixture::input();
        $state = new OpeningTargetState;
        $before = $state->capture();
        $state->assertExpected($before, []);
        DB::table('users')->where('id', $p['owner_id'])->update(['email' => 'changed@example.invalid']);
        $changed = $state->capture();
        $this->denied(fn () => $state->assertExpected($before, []));
        self::assertSame($changed, $state->capture());
        self::assertArrayHasKey('user_sessions', $before['tables']);
        self::assertArrayHasKey('billmanager_holds', $before['tables']);
    }

    public function test_identity_hold_archive_import_and_population_drift_deny_without_repairs(): void
    {
        self::assertTrue(class_exists(OpeningPreparation::class), 'Read-only opening preparation is missing.');
        $p = MigrationFixture::input();
        $populationBytes = file_get_contents($p['dir'] . '/population.json');
        $population = json_decode($populationBytes, true);
        $population['eligible_account_ids'][] = '99';
        ProofFactory::write($p['dir'] . '/population.json', json_encode($population));
        $input = $p['input'];
        $input['references']['population_review']['sha256'] = hash_file('sha256', $p['dir'] . '/population.json');
        MigrationFixture::writeInput($p['path'], $input);
        $before = (new OpeningTargetState)->capture();
        $this->denied(fn () => (new OpeningPreparation)->prepare(OpeningBundle::load($p['path'])));
        self::assertSame($before, (new OpeningTargetState)->capture());
        ProofFactory::write($p['dir'] . '/population.json', $populationBytes);
        MigrationFixture::writeInput($p['path'], $p['input']);
        foreach ([['users', 'email', 'other@example.invalid'], ['billmanager_holds', 'released_at', '2026-01-01 00:00:00'], ['billmanager_records', 'payload_sha256', str_repeat('f', 64)]] as [$table, $field, $value]) {
            $row = (array) DB::table($table)->first();
            DB::table($table)->where('id', $row['id'])->update([$field => $value]);
            $bad = (new OpeningTargetState)->capture();
            $this->denied(fn () => (new OpeningPreparation)->prepare(OpeningBundle::load($p['path'])));
            self::assertSame($bad, (new OpeningTargetState)->capture());
            DB::table($table)->where('id', $row['id'])->update([$field => $row[$field]]);
        }
    }

    public function test_backup_cannot_accept_planning_input_or_unverified_restore(): void
    {
        self::assertTrue(class_exists(OpeningBackupVerifier::class), 'Backup acceptance verifier is missing.');
        $p = MigrationFixture::input();
        $before = (new OpeningTargetState)->capture();
        $this->denied(fn () => (new OpeningBackupVerifier)->assertReady(OpeningBundle::load($p['path'])));
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_prepare_command_requires_isolated_console_and_never_overwrites_report(): void
    {
        self::assertArrayHasKey('billmanager:openings:prepare', Artisan::all());
        $p = MigrationFixture::input();
        ProofFactory::write($p['dir'] . '/report.json', 'preserved evidence');
        $before = (new OpeningTargetState)->capture();
        $this->artisan('billmanager:openings:prepare', ['bundle' => $p['path'], '--report' => $p['dir'] . '/report.json'])->assertFailed();
        self::assertSame('preserved evidence', file_get_contents($p['dir'] . '/report.json'));
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_genuine_isolated_console_writes_only_a_private_preparation_report(): void
    {
        $p = MigrationFixture::input();
        $before = (new OpeningTargetState)->capture();
        $pipes = [];
        $child = proc_open([PHP_BINARY, 'artisan', 'billmanager:openings:prepare', $p['path'], '--report=' . $p['dir'] . '/new-report.json'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($child), $stderr . $stdout);
        $public = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['status' => 'prepared', 'application_authorized' => false, 'counts' => ['candidate' => 1, 'blocked' => 0, 'excluded' => 0]], $public);
        self::assertSame('', $stderr);
        self::assertSame(0600, fileperms($p['dir'] . '/new-report.json') & 0777);
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_provisional_postpaid_inactive_and_old_owner_are_blocked_without_financial_writes(): void
    {
        foreach ([['allowpostpaid' => 'on'], ['active' => 'off']] as $flags) {
            $p = MigrationFixture::input(['subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => '12.3450', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on', ...$flags]]]);
            $before = (new OpeningTargetState)->capture();
            $report = (new OpeningPreparation)->prepare(OpeningBundle::load($p['path']));
            self::assertSame(0, $report['counts']['candidate']);
            self::assertSame(1, $report['counts']['blocked']);
            self::assertSame($before, (new OpeningTargetState)->capture());
            $this->truncateDatabaseTables();
        }
        $p = MigrationFixture::input(['payments' => [['id' => '41', 'subaccount' => '31', 'status' => '3', 'subaccountamount' => '5.0000', 'usedamount' => '1.0000']]]);
        $before = (new OpeningTargetState)->capture();
        $report = (new OpeningPreparation)->prepare(OpeningBundle::load($p['path']));
        self::assertSame(0, $report['counts']['candidate']);
        self::assertContains('provisional_funds', $report['accounts'][0]['blocked_reasons']);
        self::assertSame($before, (new OpeningTargetState)->capture());
        $this->truncateDatabaseTables();
        $p = MigrationFixture::input(['users' => [['id' => '11', 'account' => '10', 'enabled' => 'on', 'level' => '16', 'email' => 'old@example.invalid', 'realname' => 'Old Owner', 'last_login' => '2024-02-01 00:00:00'],
            ['id' => '12', 'account' => '10', 'enabled' => 'on', 'level' => '16', 'email' => 'reader@example.invalid', 'realname' => 'Recent Reader', 'last_login' => '2025-01-01 00:00:00']]], '2026-06-01T00:00:00Z', '2024-06-01 00:00:00');
        $before = (new OpeningTargetState)->capture();
        $report = (new OpeningPreparation)->prepare(OpeningBundle::load($p['path']));
        self::assertSame(0, $report['counts']['candidate']);
        self::assertContains('owner_outside_activation_window', $report['accounts'][0]['blocked_reasons']);
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_changed_bundle_bytes_invalidate_an_already_loaded_manifest(): void
    {
        self::assertTrue(method_exists(OpeningBundle::class, 'assertCurrent'), 'Loaded opening bundle identity checks are missing.');
        $p = MigrationFixture::input();
        $bundle = OpeningBundle::load($p['path']);
        ProofFactory::write($p['path'], file_get_contents($p['path']) . ' ');
        $before = (new OpeningTargetState)->capture();
        $this->denied(fn () => $bundle->assertCurrent());
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_report_directory_must_be_owned_by_operator(): void
    {
        $p = MigrationFixture::input();
        $foreign = $p['dir'] . '/foreign';
        mkdir($foreign, 0700);
        chown($foreign, 65534);
        try {
            $pipes = [];
            $child = proc_open([PHP_BINARY, 'artisan', 'billmanager:openings:prepare', $p['path'], '--report=' . $foreign . '/report.json'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(1, proc_close($child), 'Preparation exported private evidence to a foreign-owned directory.');
            self::assertFileDoesNotExist($foreign . '/report.json');
        } finally {
            chown($foreign, 0);
        }
    }
}
