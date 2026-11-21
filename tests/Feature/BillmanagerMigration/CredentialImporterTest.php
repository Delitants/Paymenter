<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\User;
use App\Services\BillmanagerMigration\CredentialImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CredentialImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_encrypted_bundle_preserves_identity_and_mfa_and_can_be_replayed(): void
    {
        $this->exerciseBundle('JBSWY3DPEHPK3PXP', false, 'JBSWY3DPEHPK3PXP', 0);
    }

    public function test_unavailable_otp_can_be_disabled_only_with_an_explicit_option(): void
    {
        $this->exerciseBundle(null, true, null, 1);
    }

    private function exerciseBundle(?string $secret, bool $disable, ?string $expectedSecret, int $disabled): void
    {
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'credential-import-');
        file_put_contents($path, json_encode(['schema_version' => 1, 'source_host' => '192.0.2.10', 'login_cutoff' => '2024-01-01 00:00:00', 'captured_at_utc' => '2026-01-01T00:00:00Z',
            'tables' => ['accounts' => [['id' => '10']], 'users' => [['id' => '11', 'account' => '10', 'name' => 'legacy-client', 'email' => $user->email, 'enabled' => 'on', 'last_login' => '2025-01-01 00:00:00']]]]));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        file_put_contents($path . '.enc', Crypt::encryptString(json_encode(['schema_version' => 1, 'source_host' => '192.0.2.10', 'credentials' => [[
            'source_user_id' => '11', 'hash' => crypt('synthetic-password', '$5$testsalt$'), 'requires_mfa' => true, 'totp_secret' => $secret,
        ]]])));
        try {
            $snapshot = Snapshot::load($path, '192.0.2.10', '2024-01-01 00:00:00');
            $batch = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => $snapshot->checksum(), 'status' => 'running']);
            $context = new ImportContext($batch, '192.0.2.10');
            $context->recordMapping('users', '11', 'users', $user->id);
            if (!$secret) {
                $held = (new CredentialImporter)->import($snapshot, $context, $path . '.enc');
                $this->assertSame(1, $held->counts['blocked']);
            }
            for ($i = 0; $i < 2; $i++) {
                $report = (new CredentialImporter)->import($snapshot, $context, $path . '.enc', $disable);
                $this->assertSame(1, $report->counts['installed']);
                $this->assertSame($disabled, $report->counts['otp_disabled']);
            }
            $this->assertSame($expectedSecret, $user->fresh()->tfa_secret);
            $this->assertSame(1, DB::table('billmanager_login_aliases')->count());
            $this->assertSame(1, DB::table('billmanager_legacy_credentials')->count());
            $this->assertStringNotContainsString('$5$', DB::table('billmanager_legacy_credentials')->value('credential'));
        } finally {
            foreach ([$path, $path . '.sha256', $path . '.enc'] as $file) {
                unlink($file);
            }
        }
    }
}
