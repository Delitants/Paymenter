<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Livewire\Services\Credentials;
use App\Livewire\Services\Show;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\BillmanagerMigration\PriceReconciler;
use App\Services\BillmanagerMigration\ProxmoxAttacher;
use App\Services\BillmanagerMigration\ProxmoxMatcher;
use App\Services\BillmanagerMigration\ServiceImporter;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Proxmox\Proxmox;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(array $overrides = []): array
    {
        $tables = array_replace([
            'accounts' => [['id' => '10']],
            'users' => [['id' => '11', 'account' => '10', 'enabled' => 'on', 'level' => '16', 'email' => 'client@example.test', 'realname' => 'Client', 'last_login' => '2025-01-01 00:00:00']],
            'currencies' => [['id' => '1', 'iso' => 'USD']],
            'pricelists' => [['id' => '20', 'name' => 'Synthetic Hosting', 'itemtype' => '30']],
            'items' => [['id' => '40', 'account' => '10', 'pricelist' => '20', 'processingmodule' => '5', 'status' => '2', 'name' => 'host.example.test', 'period' => '3', 'costperiod' => '3', 'cost' => '11.2345', 'costdate' => '2026-01-01', 'currency' => '1', 'createdate' => '2020-01-01 00:00:00', 'expiredate' => '2026-04-01']],
            'addons' => [['id' => '41', 'parent' => '40', 'pricelist' => '21', 'intvalue' => '2', 'cost' => null]],
            'expenses' => [
                ['id' => '50', 'item' => '40', 'operation' => 'prolong', 'period' => '3', 'amount' => '9.2345', 'discountamount' => '1.0000', 'taxamount' => '0.0000', 'cdate' => '2025-10-01'],
                ['id' => '51', 'item' => '41', 'operation' => 'prolong', 'period' => '3', 'amount' => '2.0000', 'discountamount' => '0.0000', 'taxamount' => '0.0000', 'cdate' => '2025-10-01'],
            ],
        ], $overrides);
        $path = tempnam(sys_get_temp_dir(), 'service-snapshot-');
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

        return [$snapshot, $context];
    }

    public function test_source_total_includes_addons_and_discount_without_a_second_charge(): void
    {
        [$snapshot] = $this->fixture();
        $result = (new PriceReconciler)->resolve($snapshot->rows('items')[0], $snapshot);
        $this->assertSame('11.2345', $result['source_amount']);
        $this->assertSame('11.23', $result['native_amount']);
        $this->assertSame('-0.0045', $result['rounding_delta']);
        $this->assertSame('11.2345', $result['last_billed_total']);
        $this->assertSame('matched', $result['expense_comparison']);
        $this->assertContains('fractional_cent', $result['review_reasons']);
        $this->assertSame('1.0000', $result['last_discount_total']);
    }

    public function test_periods_and_lifetime_are_preserved_and_changed_cost_period_requires_review(): void
    {
        [$snapshot] = $this->fixture();
        $base = $snapshot->rows('items')[0];
        foreach ([1, 3, 6, 12, 24, 60] as $months) {
            $result = (new PriceReconciler)->resolve(array_replace($base, ['period' => (string) $months, 'costperiod' => (string) $months]), $snapshot);
            $this->assertSame($months, $result['billing_period']);
            $this->assertSame('month', $result['billing_unit']);
        }
        $result = (new PriceReconciler)->resolve(array_replace($base, ['period' => '-100', 'costperiod' => '-100', 'cost' => '0.0000']), $snapshot);
        $this->assertSame('one-time', $result['type']);
        $this->assertNull($result['billing_period']);
        $result = (new PriceReconciler)->resolve(array_replace($base, ['costperiod' => '12']), $snapshot);
        $this->assertContains('cost_period_changed', $result['review_reasons']);
    }

    public function test_import_is_held_hidden_exact_replayable_and_visible_to_its_customer(): void
    {
        [$snapshot, $context] = $this->fixture();
        for ($i = 0; $i < 2; $i++) {
            (new ServiceImporter)->import($snapshot, $context);
        }
        $service = Service::sole();
        $this->assertSame('11.23', $service->price);
        $this->assertTrue(MigrationHold::isHeld($service));
        $this->assertTrue((bool) $service->product->hidden);
        $this->assertSame(0, $service->product->stock);
        $this->assertNull($service->product->server_id);
        $this->assertSame(3, $service->plan->billing_period);
        $this->assertSame('11.2345', $service->billmanagerDetails->details['price']['source_amount']);
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('invoices')->count());
        Livewire::actingAs(User::first())->test(Show::class, ['service' => $service])->assertSee('11.2345')->assertSee('BILLmanager');
        $this->actingAs(User::factory()->create());
        $component = new Show;
        $component->service = $service;
        $this->expectException(AuthorizationException::class);
        $component->mount();
    }

    public function test_ordered_processing_and_suspended_services_remain_distinct(): void
    {
        $items = [];
        foreach (['1', '5', '3'] as $index => $status) {
            $items[] = ['id' => (string) (60 + $index), 'account' => '10', 'pricelist' => '20', 'processingmodule' => '5', 'status' => $status, 'period' => '1', 'costperiod' => '1', 'cost' => '5.0000', 'currency' => '1', 'createdate' => '2020-01-01', 'expiredate' => null];
        }
        [$snapshot, $context] = $this->fixture(['items' => $items, 'addons' => [], 'expenses' => []]);
        (new ServiceImporter)->import($snapshot, $context);
        $this->assertSame(['pending', 'pending', 'suspended'], Service::orderBy('id')->pluck('status')->all());
        $this->assertSame(['ordered', 'processing', 'suspended'], Service::orderBy('id')->get()->map(fn ($s) => $s->billmanagerDetails->source_status)->all());
    }

    public function test_replay_rejects_native_price_drift_without_overwriting_it(): void
    {
        [$snapshot, $context] = $this->fixture();
        (new ServiceImporter)->import($snapshot, $context);
        DB::table('services')->update(['price' => '99.00']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mapped service changed');
        (new ServiceImporter)->import($snapshot, $context);
    }

    public function test_resource_matching_requires_both_identifier_and_hostname_and_uses_current_node(): void
    {
        $service = ['id' => '40', 'panelid' => '500', 'domain' => 'host.example.test', 'node' => 'old-node'];
        $resources = [['vmid' => 500, 'name' => 'host.example.test', 'node' => 'new-node', 'type' => 'qemu']];
        $matcher = new ProxmoxMatcher;
        $this->assertSame('new-node', $matcher->match($service, $resources)['node']);
        foreach ([array_merge($resources, $resources), [array_replace($resources[0], ['name' => 'another.example.test'])]] as $bad) {
            try {
                $matcher->match($service, $bad);
                $this->fail('Ambiguous or contradictory identity was accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('resource identity', $e->getMessage());
            }
        }
    }

    public function test_attachment_preserves_existing_ip_reservations_and_never_provisions_a_resource(): void
    {
        if (!class_exists(Proxmox::class)) {
            $this->markTestSkipped('Install the Proxmox extension to verify resource attachment');
        }
        [$snapshot, $context] = $this->fixture(['itemparams' => [
            ['item' => '40', 'intname' => 'domain', 'value' => 'host.example.test'],
            ['item' => '40', 'intname' => 'panelid', 'value' => '500'],
            ['item' => '40', 'intname' => 'ip', 'value' => '192.0.2.55'],
        ]]);
        (new ServiceImporter)->import($snapshot, $context);
        $server = Server::create(['type' => 'server', 'extension' => 'Proxmox', 'name' => 'Synthetic cluster']);
        $server->settings()->create(['key' => 'host', 'value' => 'https://proxmox.example.test']);
        $pool = DB::table('ip_pools')->insertGetId(['name' => 'Synthetic pool', 'server_id' => $server->id]);
        $ip = ['ip_pool_id' => $pool, 'ip_address' => '192.0.2.55', 'hostname' => 'other.example.test', 'is_assigned' => true, 'assigned_to_type' => User::class, 'assigned_to_id' => 999];
        DB::table('ip_addresses')->insert($ip);
        Http::preventStrayRequests();
        Http::fake([
            '*/cluster/resources*' => Http::response(['data' => [['vmid' => 500, 'name' => 'host.example.test', 'node' => 'new-node', 'type' => 'qemu']]]),
            '*/status/current' => Http::response(['data' => ['status' => 'running']]),
        ]);
        for ($i = 0; $i < 2; $i++) {
            $report = (new ProxmoxAttacher)->attach($snapshot, $context, ['5' => $server->id]);
        }
        $service = Service::sole();
        $this->assertSame($server->id, $service->product->server_id);
        $this->assertSame('500', $service->properties()->where('key', 'proxmox_vm_id')->value('value'));
        $this->assertSame('new-node', $service->properties()->where('key', 'proxmox_node')->value('value'));
        $this->assertDatabaseHas('ip_addresses', $ip);
        $this->assertSame(1, $report->counts['ip_ownership_review']);
        $this->assertSame(1, $report->counts['ip_hostname_conflicts']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_replay_detects_plan_drift(): void
    {
        [$snapshot, $context] = $this->fixture();
        (new ServiceImporter)->import($snapshot, $context);
        DB::table('plans')->where('id', Service::sole()->plan_id)->update(['billing_period' => 12]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mapped service plan changed');
        (new ServiceImporter)->import($snapshot, $context);
    }

    public function test_native_credentials_page_renders_without_inventing_guest_access_or_password_changes(): void
    {
        [$snapshot, $context] = $this->fixture();
        (new ServiceImporter)->import($snapshot, $context);
        $service = Service::sole();
        DB::table('properties')->insert(['model_type' => Service::class, 'model_id' => $service->id, 'key' => 'proxmox_vm_id', 'value' => '500']);
        Livewire::actingAs(User::first())->test(Credentials::class, ['service' => $service])
            ->assertSee('500')->assertDontSee('Regenerate Password')->assertDontSee('Username:');
    }
}
