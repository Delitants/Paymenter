<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\Gateway;
use App\Services\BillmanagerMigration\GatewayConfigurer;
use App\Services\BillmanagerMigration\GatewayFeeConfigurer;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $path = tempnam(sys_get_temp_dir(), 'gateway-snapshot-');
        $methods = [['id' => '5', 'module' => 'pmwebmoney', 'active' => 'on', 'currency' => '153'], ['id' => '38', 'module' => 'pmauthorizenet', 'active' => 'on', 'currency' => '153'], ['id' => '39', 'module' => 'pmklarna', 'active' => 'on', 'currency' => '153'], ['id' => '40', 'module' => 'pmwave', 'active' => 'on', 'currency' => '153']];
        $data = ['schema_version' => 1, 'source_host' => '192.0.2.10', 'login_cutoff' => '2024-01-01 00:00:00', 'captured_at_utc' => '2026-01-01T00:00:00Z', 'tables' => ['accounts' => [['id' => '1']], 'users' => [['id' => '1', 'account' => '1', 'enabled' => 'on', 'last_login' => '2026-01-01 00:00:00']], 'paymethods' => $methods, 'currencies' => [['id' => '153', 'iso' => 'USD']]]];
        file_put_contents($path, json_encode($data));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        try {
            $snapshot = Snapshot::load($path, '192.0.2.10', '2024-01-01 00:00:00');
        } finally {
            unlink($path);
            unlink($path . '.sha256');
        }
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => $snapshot->checksum(), 'status' => 'running']);
        $settings = [['purse' => 'Z123456789012', 'secret' => 'synthetic-secret', 'currency' => '153'], ['api_login_id' => 'synthetic-login', 'transaction_key' => 'synthetic-key', 'signature_key' => str_repeat('AB', 64), 'api_url' => 'https://api.authorize.net/xml/v1/request.api', 'form_url' => 'https://accept.authorize.net/payment/payment'], ['merchant_id' => 'synthetic-klarna', 'secret' => 'synthetic-secret', 'environment' => 'production', 'currency' => '153'], ['wave_access_token' => 'synthetic-token', 'wave_business_id' => 'synthetic-business', 'wave_product_id' => 'synthetic-product', 'wave_customer_id' => 'do-not-use-for-every-client', 'currency' => '153']];
        $records = [];
        foreach ($methods as $n => $m) {
            $records[] = $m + ['settings' => $settings[$n]];
        }

        return [$snapshot, new ImportContext($id, '192.0.2.10'), ['schema_version' => 1, 'source_host' => '192.0.2.10', 'kind' => 'gateway_settings', 'gateways' => $records]];
    }

    private function apply($snapshot, $context, $bundle): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gateway-settings-');
        file_put_contents($path, Crypt::encryptString(json_encode($bundle)));
        try {
            return (new GatewayConfigurer)->configure($snapshot, $context, $path);
        } finally {
            unlink($path);
        }
    }

    public function test_configuration_replays_with_encryption_and_no_collection_or_callbacks(): void
    {
        [$s,$c,$b] = $this->fixture();
        $first = $this->apply($s, $c, $b);
        $this->assertSame($first, $this->apply($s, $c, $b));
        $this->assertCount(4, $first);
        foreach ($first as $id) {
            $g = Gateway::findOrFail($id);
            $this->assertFalse((bool) $g->enabled);
            $this->assertSame('0', $g->settings()->where('key', 'collection_enabled')->first()->value);
            $this->assertFalse($g->settings->contains(fn ($setting) => !$setting->encrypted));
            $this->assertSame('USD', $g->settings()->where('key', 'currency')->first()->value);
        }
        $wave = Gateway::find($first['40']);
        $this->assertFalse($wave->settings()->where('key', 'customer_id')->exists());
        $this->assertSame(4, DB::table('billmanager_records')->where('source_table', 'gateway_source_settings')->count());
        Http::assertNothingSent();
    }

    public function test_changed_secret_or_enabled_destination_requires_reconciliation(): void
    {
        [$s,$c,$b] = $this->fixture();
        $this->apply($s, $c, $b);
        $b['gateways'][0]['settings']['secret'] = 'changed';
        $this->expectException(\RuntimeException::class);
        $this->apply($s, $c, $b);
    }

    public function test_wrong_source_currency_or_module_cannot_install_partial_configuration(): void
    {
        [$s,$c,$b] = $this->fixture();
        $b['gateways'][3]['module'] = 'unknown';
        try {
            $this->apply($s, $c, $b);
            $this->fail('Unsupported source accepted');
        } catch (\RuntimeException) {
            $this->assertSame(0, Gateway::count());
        }
        $b['gateways'][3]['module'] = 'pmwave';
        $b['gateways'][0]['settings']['currency'] = '999';
        try {
            $this->apply($s, $c, $b);
            $this->fail('Conflicting currency accepted');
        } catch (\RuntimeException) {
            $this->assertSame(0, Gateway::count());
        }
    }

    private function applyFees($snapshot, $context, $bundle): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gateway-fees-');
        file_put_contents($path, Crypt::encryptString(json_encode($bundle)));
        try {
            return (new GatewayFeeConfigurer)->configure($snapshot, $context, $path);
        } finally {
            unlink($path);
        }
    }

    private function withFees(array $bundle): array
    {
        $bundle['fees'] = array_map(fn ($record) => [
            'id' => $record['id'], 'module' => $record['module'], 'active' => 'on',
            'currency' => '153', 'commissionpercent' => '2.5', 'commissionamount' => '0.2500',
        ], $bundle['gateways']);

        return $bundle;
    }

    public function test_mapped_source_fees_import_and_replay_without_enabling_collection(): void
    {
        [$s, $c, $b] = $this->fixture();
        $targets = $this->apply($s, $c, $b);
        $fees = $this->withFees($b);
        $this->assertSame($targets, $this->applyFees($s, $c, $fees));
        $this->assertSame($targets, $this->applyFees($s, $c, $fees));
        $this->assertSame($targets, $this->apply($s, $c, $b)); // credential replay stays exact
        foreach ($targets as $target) {
            $g = Gateway::findOrFail($target);
            $this->assertFalse((bool) $g->enabled);
            $this->assertSame('0', $g->settings()->where('key', 'collection_enabled')->first()->value);
            $this->assertSame('2.5000', $g->settings()->where('key', 'customer_fee_percent')->first()->value);
            $this->assertSame('0.25', $g->settings()->where('key', 'customer_fee_fixed')->first()->value);
        }
        $this->assertSame(4, DB::table('billmanager_records')->where('source_table', 'gateway_source_fees')->count());
        Http::assertNothingSent();
    }

    public function test_fee_import_rejects_changed_target_and_source_identity(): void
    {
        [$s, $c, $b] = $this->fixture();
        $targets = $this->apply($s, $c, $b);
        $fees = $this->withFees($b);
        $this->applyFees($s, $c, $fees);
        $g = Gateway::findOrFail(reset($targets));
        $g->settings()->where('key', 'customer_fee_percent')->first()->update(['value' => '3.0000']);
        try {
            $this->applyFees($s, $c, $fees);
            $this->fail('Changed target fee accepted');
        } catch (\RuntimeException) {
            $this->assertSame('3.0000', $g->settings()->where('key', 'customer_fee_percent')->first()->value);
        }
        $fees['fees'][0]['module'] = 'unknown';
        $this->expectException(\RuntimeException::class);
        $this->applyFees($s, $c, $fees);
    }

    public function test_fee_source_currency_drift_rolls_back_partial_preparation(): void
    {
        [$s, $c, $b] = $this->fixture();
        $this->apply($s, $c, $b);
        $fees = $this->withFees($b);
        $fees['fees'][3]['currency'] = '999';
        try {
            $this->applyFees($s, $c, $fees);
            $this->fail('Source currency drift accepted');
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::table('settings')->where('key', 'customer_fee_enabled')->count());
            $this->assertSame(0, DB::table('billmanager_records')->where('source_table', 'gateway_source_fees')->count());
        }
    }
}
