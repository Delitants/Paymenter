<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\Server;
use App\Models\Service;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\BillmanagerMigration\ProviderAttacher;
use App\Services\BillmanagerMigration\ProviderConfigurer;
use App\Services\BillmanagerMigration\ServiceImporter;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $tables = [
            'accounts' => [['id' => '10']],
            'users' => [['id' => '11', 'account' => '10', 'enabled' => 'on', 'level' => '16', 'email' => 'client@example.test', 'realname' => 'Client', 'last_login' => '2025-01-01 00:00:00']],
            'currencies' => [['id' => '1', 'iso' => 'USD']],
            'pricelists' => [['id' => '20', 'name' => 'Hosting', 'itemtype' => '30']],
            'items' => [['id' => '40', 'account' => '10', 'pricelist' => '20', 'processingmodule' => '26', 'status' => '2', 'name' => 'site.example.test', 'period' => '1', 'costperiod' => '1', 'cost' => '10.0000', 'currency' => '1', 'createdate' => '2020-01-01', 'expiredate' => '2026-10-01']],
            'itemparams' => [['id' => '50', 'item' => '40', 'intname' => 'username', 'value' => 'client']],
        ];
        $path = tempnam(sys_get_temp_dir(), 'provider-test-');
        file_put_contents($path, json_encode(['schema_version' => 1, 'source_host' => '192.0.2.10', 'login_cutoff' => '2024-01-01 00:00:00', 'captured_at_utc' => '2026-01-01T00:00:00Z', 'source_timezone' => 'UTC', 'tables' => $tables]));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        try {
            $snapshot = Snapshot::load($path, '192.0.2.10', '2024-01-01 00:00:00');
        } finally {
            unlink($path);
            unlink($path . '.sha256');
        }
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => $snapshot->checksum(), 'status' => 'running']);
        $context = new ImportContext($id, '192.0.2.10');
        (new CustomerImporter)->import($snapshot, $context);
        (new ServiceImporter)->import($snapshot, $context);

        return [$snapshot, $context];
    }

    private function configure(ImportContext $context, array $overrides = []): array
    {
        $provider = array_replace(['id' => '26', 'module' => 'pmispmgr5', 'active' => 'on', 'url' => 'https://panel.example.test/manager', 'login' => 'admin', 'password' => 'synthetic-secret'], $overrides);
        $path = tempnam(sys_get_temp_dir(), 'provider-settings-test-');
        file_put_contents($path, Crypt::encryptString(json_encode(['schema_version' => 1, 'source_host' => '192.0.2.10', 'kind' => 'provider_settings', 'providers' => [$provider]])));
        try {
            return (new ProviderConfigurer)->configure($context, $path);
        } finally {
            unlink($path);
        }
    }

    public function test_configuration_and_attachment_replay_preserve_encrypted_settings_and_native_identity(): void
    {
        [$snapshot,$context] = $this->fixture();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('<doc><elem><name>client</name><owner>actual-owner</owner><active/><preset>standard</preset></elem></doc>')]);
        for ($i = 0; $i < 2; $i++) {
            $map = $this->configure($context);
            $report = (new ProviderAttacher)->attach($snapshot, $context, $map);
            $this->assertSame(1, $report->counts['attached']);
        }
        $service = Service::sole()->fresh();
        $this->assertTrue(MigrationHold::isHeld($service));
        $this->assertSame('ISPmanager', $service->product->server->extension);
        $this->assertSame('actual-owner', $service->properties()->where('key', 'provider_owner')->value('value'));
        $this->assertSame(1, Server::where('extension', 'ISPmanager')->count());
        $raw = DB::table('settings')->where('settingable_id', $service->product->server_id)->where('settingable_type', Server::class)->where('key', 'password')->sole();
        $this->assertTrue((bool) $raw->encrypted);
        $this->assertSame('synthetic-secret', Crypt::decryptString($raw->value));
        $this->assertStringNotContainsString('synthetic-secret', $raw->value);
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertNotSent(fn ($r) => $r['func'] !== 'user' || ($r->data()['sok'] ?? null) === 'ok');
    }

    public function test_changed_credentials_require_explicit_reconciliation(): void
    {
        [, $context] = $this->fixture();
        $this->configure($context);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('configuration changed');
        $this->configure($context, ['password' => 'different-secret']);
    }

    public function test_wrong_remote_account_rolls_back_product_and_properties(): void
    {
        [$snapshot,$context] = $this->fixture();
        $map = $this->configure($context);
        Http::fake(['*' => Http::response('<doc><elem><name>different</name><owner>owner</owner><active/></elem></doc>')]);
        try {
            (new ProviderAttacher)->attach($snapshot, $context, $map);
            $this->fail('Missing resource bound');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('identity', $e->getMessage());
        }
        $this->assertNull(Service::sole()->product->server_id);
        $this->assertSame(0,Service::sole()->properties()->count());
    }
}
