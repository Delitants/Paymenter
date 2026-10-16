<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHeldException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\SSLStore\SSLStore;
use Tests\TestCase;

class ProviderTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name = 'ISPmanager', bool $held = false): array
    {
        $config = ['url' => 'https://panel.example.test/manager', 'username' => 'reseller', 'password' => 'synthetic-secret'];
        $p = $this->createProduct();
        $u = User::factory()->create();
        $server = Server::create(['name' => 'Synthetic provider', 'extension' => $name, 'type' => 'server']);
        foreach ($config as $k => $v) {
            $server->settings()->create(['key' => $k, 'value' => $v]);
        }
        $p->product->update(['server_id' => $server->id]);
        $s = Service::factory()->create(['product_id' => $p->product->id, 'plan_id' => $p->plan->id, 'user_id' => $u->id, 'status' => 'active']);
        foreach (['provider_username' => 'client', 'provider_owner' => 'reseller'] as $k => $v) {
            $s->properties()->create(['key' => $k, 'value' => $v]);
        }
        if ($held) {
            DB::table('billmanager_holds')->insert(['model_type' => Service::class, 'model_id' => $s->id, 'reason' => 'Synthetic hold']);
        }
        $this->actingAs($u);
        Http::preventStrayRequests();
        $class = 'Paymenter\\Extensions\\Servers\\' . $name . '\\' . $name;

        return [$s->fresh(), new $class($config)];
    }

    public function test_isp_and_dns_status_validate_account_and_use_posted_auth_with_verified_tls(): void
    {
        foreach (['ISPmanager', 'DNSmanager'] as $name) {
            [$s,$p] = $this->fixture($name);
            Http::fake(function ($request, $options) {
                $this->assertTrue($options['verify']);
                $this->assertFalse($options['allow_redirects']);

                return Http::response('<doc><elem><name>client</name><owner>reseller</owner><active/><preset>standard</preset></elem></doc>');
            });
            $this->assertSame('active', $p->getServerInfo($s)['status']);
            $this->assertTrue($p->testConfig());
            foreach (ExtensionHelper::getActions($s) as $a) {
                $this->assertSame('text', $a['type']);
            }
            Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['func'] === 'user' && $r['authinfo'] === 'reseller:synthetic-secret' && !str_contains($r->url(), 'secret'));
        }
    }

    public function test_admin_login_can_read_a_bound_account_owned_by_a_different_reseller(): void
    {
        [$s,$p] = $this->fixture();
        $s->properties()->where('key', 'provider_owner')->update(['value' => 'actual-reseller']);
        Http::fake(['*' => Http::response('<doc><elem><name>client</name><owner>actual-reseller</owner><active/></elem></doc>')]);
        $this->assertSame('active', $p->getServerInfo($s)['status']);
    }

    public function test_missing_duplicate_or_moved_owner_fails_closed(): void
    {
        [$s,$p] = $this->fixture();
        Http::fake(['*' => Http::sequence()->push('<doc/>')->push('<doc><elem><name>client</name><owner>other</owner></elem></doc>')->push('<doc><elem><name>client</name><owner>reseller</owner></elem><elem><name>client</name><owner>reseller</owner></elem></doc>')]);
        for ($i = 0; $i < 3; $i++) {
            try {
                $p->getServerInfo($s);
                $this->fail('Resource identity accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('identity', $e->getMessage());
            }
        }
    }

    public function test_xml_provider_error_and_external_entities_are_rejected_without_details(): void
    {
        [$s,$p] = $this->fixture();
        Http::fake(['*' => Http::sequence()->push('<doc><error><msg>synthetic-secret</msg></error></doc>')->push('<!DOCTYPE doc [<!ENTITY x SYSTEM "file:///etc/passwd">]><doc>&x;</doc>')->push('not xml')]);
        for ($i = 0; $i < 3; $i++) {
            try {
                $p->getServerInfo($s);
                $this->fail('Invalid provider response accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString('synthetic-secret', $e->getMessage());
            }
        }
    }

    public function test_every_provider_blocks_held_lifecycle_calls_before_network(): void
    {
        foreach (['ISPmanager', 'DNSmanager', 'SSLStore', 'Manual'] as $name) {
            [$s,$p] = $this->fixture($name, true);
            Http::fake();
            foreach (['createServer', 'suspendServer', 'unsuspendServer', 'terminateServer', 'upgradeServer'] as $method) {
                try {
                    $p->$method($s, [], []);
                    $this->fail('Held action accepted');
                } catch (MigrationHeldException $e) {
                    $this->assertNotEmpty($e->getMessage());
                }
            }
            Http::assertNothingSent();
        }
    }

    public function test_ssl_authentication_checks_error_flag_and_never_orders_certificate(): void
    {
        $p = new SSLStore(['url' => 'https://ssl.example.test', 'partner_code' => 'synthetic-partner', 'auth_token' => 'synthetic-secret']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()->push('<AuthResponse><isError>false</isError></AuthResponse>')->push('<AuthResponse><isError>true</isError></AuthResponse>')->push('<AuthResponse/>')]);
        $this->assertTrue($p->testConfig());
        $this->assertIsString($p->testConfig());
        $this->assertIsString($p->testConfig());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/rest/health/validate') && $r->method() === 'POST' && str_contains($r->body(), '<PartnerCode>synthetic-partner</PartnerCode>'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/order/'));
    }

    public function test_manual_operations_are_explicitly_pending_never_successful_noops(): void
    {
        [$s,$p] = $this->fixture('Manual');
        Http::fake();
        $this->assertSame('manual', $p->getServerInfo($s)['management']);
        foreach (['createServer', 'suspendServer', 'unsuspendServer', 'terminateServer', 'upgradeServer'] as $method) {
            try {
                $p->$method($s, [], []);
                $this->fail('Manual operation pretended success');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('operator', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }
}
