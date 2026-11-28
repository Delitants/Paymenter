<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\User;
use App\Services\BillmanagerMigration\LegacyCredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_dry_run_validates_without_persisting_anything(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'migration-test-');
        $report = $path . '.report';
        file_put_contents($path, json_encode([
            'schema_version' => 1, 'source_host' => '192.0.2.10', 'login_cutoff' => '2024-01-01 00:00:00',
            'captured_at_utc' => '2026-01-01T00:00:00Z',
            'tables' => ['accounts' => [['id' => '1']], 'users' => [
                ['id' => '1', 'account' => '1', 'enabled' => 'on', 'last_login' => '2025-01-01 00:00:00'],
            ]],
        ]));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        try {
            $this->artisan('billmanager:import', [
                'snapshot' => $path, '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00', '--report' => $report,
            ])->assertSuccessful();
            $this->assertSame(0, DB::table('billmanager_imports')->count());
            $data = json_decode(file_get_contents($report), true);
            $this->assertSame('validated', $data['status']);
            $this->assertSame(['accounts' => 1, 'users' => 1], $data['counts']);
        } finally {
            foreach ([$path, $path . '.sha256', $report] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_apply_refuses_an_unapproved_database_before_reading_payload(): void
    {
        config(['billmanager-migration.allowed_databases' => []]);
        $this->artisan('billmanager:import', [
            'snapshot' => '/nonexistent', '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00', '--apply' => true,
        ])->expectsOutputToContain('Database is not explicitly allowed')->assertFailed();
    }

    public function test_explicit_customer_stage_imports_once_under_holds(): void
    {
        config(['billmanager-migration.allowed_databases' => [DB::connection()->getDatabaseName()]]);
        $path = tempnam(sys_get_temp_dir(), 'customer-stage-');
        file_put_contents($path, json_encode([
            'schema_version' => 1, 'source_host' => '192.0.2.10', 'login_cutoff' => '2024-01-01 00:00:00',
            'captured_at_utc' => '2026-01-01T00:00:00Z', 'tables' => [
                'accounts' => [['id' => '1']], 'users' => [[
                    'id' => '1', 'account' => '1', 'enabled' => 'on', 'level' => '16',
                    'email' => 'customer@example.test', 'realname' => 'Synthetic Customer',
                    'last_login' => '2025-01-01 00:00:00',
                ]],
            ],
        ]));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        try {
            for ($i = 0; $i < 2; $i++) {
                $this->artisan('billmanager:import', [
                    'snapshot' => $path, '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00',
                    '--apply' => true, '--stage' => 'customers',
                ])->assertSuccessful();
            }
            $this->assertSame(1, User::count());
            $this->assertSame(1, DB::table('billmanager_imports')->count());
            $this->assertSame(1, DB::table('billmanager_holds')->count());
            $this->assertTrue((bool) DB::table('billmanager_legacy_credentials')->value('login_blocked'));
        } finally {
            unlink($path);
            unlink($path . '.sha256');
        }
    }

    public function test_activity_customer_stage_preserves_disabled_logins_and_prefers_an_enabled_owner(): void
    {
        config(['billmanager-migration.allowed_databases' => [DB::connection()->getDatabaseName()]]);
        $path = tempnam(sys_get_temp_dir(), 'activity-stage-');
        file_put_contents($path, json_encode([
            'schema_version' => 2, 'source_host' => '192.0.2.10', 'activity_cutoff' => '2022-01-01 00:00:00',
            'captured_at_utc' => '2026-01-01T00:00:00Z', 'consistent_snapshot' => true,
            'tables' => [
                'accounts' => [['id' => '1', 'registration_date' => '2020-01-01']],
                'items' => [['id' => '31', 'account' => '1', 'status' => '2']],
                'users' => [
                    ['id' => '11', 'account' => '1', 'enabled' => 'off', 'level' => '16', 'email' => 'disabled@example.test'],
                    ['id' => '12', 'account' => '1', 'enabled' => 'on', 'level' => '16', 'email' => 'owner@example.test'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        try {
            $options = [
                'snapshot' => $path, '--source' => '192.0.2.10', '--activity-cutoff' => '2022-01-01 00:00:00',
                '--apply' => true, '--stage' => 'customers',
            ];
            $this->artisan('billmanager:import', $options + ['--login-cutoff' => '2024-01-01 00:00:00'])->assertFailed();
            $this->assertSame(0, User::count());
            $this->artisan('billmanager:import', $options)->assertSuccessful();
            $this->artisan('billmanager:import', $options)->assertSuccessful();
            $this->assertSame(2, DB::table('billmanager_members')->count());
            $this->assertEquals(User::where('email', 'owner@example.test')->value('id'), DB::table('billmanager_accounts')->value('owner_user_id'));
            $disabled = User::where('email', 'disabled@example.test')->sole();
            $verifier = new LegacyCredentialVerifier;
            $verifier->install($disabled, crypt('synthetic-password', '$5$testsalt$'), null, false);
            $this->assertFalse($verifier->canAuthenticate($disabled));
            $this->assertSame('Source login disabled', DB::table('billmanager_legacy_credentials')->where('user_id', $disabled->id)->value('block_reason'));
        } finally {
            unlink($path);
            unlink($path . '.sha256');
        }
    }
}
