<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Service\RenewServiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\ISPmanager\ISPmanager;
use Paymenter\Extensions\Servers\SSLStore\SSLStore;
use Tests\TestCase;

class ProviderTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name = 'ISPmanager', bool $held = false, bool $lifecycle = false, string $username = 'client'): array
    {
        $config = ['url' => 'https://panel.example.test/manager', 'username' => 'reseller', 'password' => 'synthetic-secret'];
        if ($lifecycle) {
            $config['lifecycle_enabled'] = '1';
            $config['lifecycle_endpoint'] = $config['url'];
        }
        $p = $this->createProduct();
        $u = User::factory()->create();
        $server = Server::create(['name' => 'Synthetic provider', 'extension' => $name, 'type' => 'server', 'enabled' => true]);
        foreach ($config as $k => $v) {
            $server->settings()->create(['key' => $k, 'value' => $v]);
        }
        $p->product->update(['server_id' => $server->id]);
        $s = Service::factory()->create(['product_id' => $p->product->id, 'plan_id' => $p->plan->id, 'user_id' => $u->id, 'status' => 'active']);
        foreach (['provider_username' => $username, 'provider_owner' => 'reseller'] as $k => $v) {
            $s->properties()->create(['key' => $k, 'value' => $v]);
        }
        if ($lifecycle && $name === 'ISPmanager') {
            $importId = DB::table('billmanager_imports')->insertGetId([
                'source_host' => '192.0.2.1',
                'snapshot_sha256' => hash('sha256', (string) $s->id),
                'status' => 'complete',
            ]);
            $sourceId = (string) $s->id;
            $context = new ImportContext($importId, '192.0.2.1');
            $context->recordMapping('items', $sourceId, 'services', $s->id);
            DB::table('billmanager_accounts')->insert(['source_account_id' => $s->id, 'owner_user_id' => $u->id]);
            $context->archive('provider_bindings', $sourceId, [
                'server_id' => $server->id, 'extension' => $name, 'username' => $username, 'owner' => 'reseller',
            ], $s->id);
        }
        if ($held) {
            DB::table('billmanager_holds')->insert(['model_type' => Service::class, 'model_id' => $s->id, 'reason' => 'Synthetic hold']);
        }
        $this->actingAs($u);
        Http::preventStrayRequests();
        $class = 'Paymenter\\Extensions\\Servers\\' . $name . '\\' . $name;

        return [$s->fresh(), new $class($config)];
    }

    public function test_ispmanager_suspends_and_reactivates_a_bound_account_through_the_extension_helper(): void
    {
        [$service] = $this->fixture(lifecycle: true);
        $functions = [];
        $active = true;
        Http::fake(function ($request, $options) use (&$functions, &$active) {
            $this->assertSame('https://panel.example.test/manager', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('reseller:synthetic-secret', $request['authinfo']);
            $function = $functions[] = $request['func'];
            if ($function === 'user') {
                return Http::response('<doc><elem><name>client</name><owner>reseller</owner><active>' . ($active ? 'on' : 'off') . '</active></elem></doc>');
            }
            $this->assertContains($function, ['user.suspend', 'user.resume']);
            $this->assertSame('client', $request['elid']);
            $active = $function === 'user.resume';

            return Http::response('<doc><ok/></doc>');
        });

        ExtensionHelper::suspendServer($service);
        ExtensionHelper::suspendServer($service);
        ExtensionHelper::unsuspendServer($service);

        $this->assertSame(['user', 'user.suspend', 'user', 'user', 'user', 'user.resume', 'user'], $functions);
        $this->assertTrue($active);
    }

    public function test_ispmanager_recovers_after_a_lost_acknowledgement_using_the_saved_server_and_account(): void
    {
        [$service] = $this->fixture(lifecycle: true);
        $adapter = new ISPmanager(['url' => 'https://other.example.test/manager']);
        $functions = [];
        $suspended = false;
        Http::fake(function ($request) use (&$functions, &$suspended) {
            $this->assertSame('https://panel.example.test/manager', $request->url());
            $function = $functions[] = $request['func'];
            if ($function === 'user.suspend') {
                $this->assertSame('client', $request['elid']);
                $suspended = true;
                throw new ConnectionException('Synthetic response loss');
            }

            return Http::response('<doc><elem><name>client</name><owner>reseller</owner>' . ($suspended ? '' : '<active/>') . '</elem></doc>');
        });

        $adapter->suspendServer($service, [], ['provider_username' => 'other']);

        $this->assertSame(['user', 'user.suspend', 'user'], $functions);
    }

    public function test_ispmanager_client_view_recognizes_an_explicitly_inactive_account(): void
    {
        [$service, $adapter] = $this->fixture();
        Http::fake(['*' => Http::response(
            '<doc><elem><name>client</name><owner>reseller</owner><active>off</active></elem></doc>'
        )]);

        $this->assertSame('suspended', $adapter->getServerInfo($service)['status']);
        $this->assertSame('suspended', $adapter->getResourceInventory()[0]['status']);
    }

    public function test_ispmanager_keeps_legacy_statuses_readable_without_accepting_them_for_writes(): void
    {
        [$service, $adapter] = $this->fixture(lifecycle: true);
        Http::fake(['*' => Http::response(
            '<doc><elem><name>client</name><owner>reseller</owner><active>off</active></elem>'
            . '<elem><name>legacy</name><owner>reseller</owner><active>enabled</active></elem></doc>'
        )]);

        $this->assertSame('suspended', $adapter->getServerInfo($service)['status']);
        $this->assertSame(['suspended', 'active'], array_column($adapter->getResourceInventory(), 'status'));

        try {
            $adapter->unsuspendServer($service);
            $this->fail('Provider change accepted an unconfirmed account status');
        } catch (\RuntimeException $e) {
            $this->assertSame('Provider account status is invalid', $e->getMessage());
            Http::assertNotSent(fn ($request) => $request['func'] !== 'user');
        }
    }

    public function test_ispmanager_refuses_an_unconfirmed_provider_change_without_repeating_it(): void
    {
        [$service, $adapter] = $this->fixture(lifecycle: true);
        $functions = [];
        Http::fake(function ($request) use (&$functions) {
            $function = $functions[] = $request['func'];

            return Http::response($function === 'user'
                ? '<doc><elem><name>client</name><owner>reseller</owner><active/></elem></doc>'
                : '<doc><ok/></doc>');
        });

        try {
            $adapter->suspendServer($service);
            $this->fail('Unconfirmed provider change succeeded');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be confirmed', $e->getMessage());
        }
        $this->assertSame(['user', 'user.suspend', 'user'], $functions);
    }

    public function test_ispmanager_rechecks_the_service_hold_after_account_lookup(): void
    {
        [$service, $adapter] = $this->fixture(lifecycle: true);
        Http::fake(function ($request) use ($service) {
            $this->assertSame('user', $request['func']);
            DB::table('billmanager_holds')->insert(['model_type' => Service::class, 'model_id' => $service->id, 'reason' => 'Synthetic hold']);

            return Http::response('<doc><elem><name>client</name><owner>reseller</owner><active/></elem></doc>');
        });

        try {
            $adapter->suspendServer($service);
            $this->fail('Held provider operation succeeded');
        } catch (MigrationHeldException) {
            Http::assertSentCount(1);
        }
    }

    public function test_ispmanager_rejects_an_account_name_that_could_address_multiple_users(): void
    {
        [$service, $adapter] = $this->fixture(lifecycle: true);
        $service->properties()->where('key', 'provider_username')->update(['value' => 'client, other']);
        Http::fake();

        try {
            $adapter->suspendServer($service);
            $this->fail('Multiple provider accounts were accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('identity', $e->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_ispmanager_write_enable_is_opt_in_and_dnsmanager_stays_read_only(): void
    {
        foreach ([['ISPmanager', false], ['DNSmanager', true]] as [$name, $enabled]) {
            [$service, $adapter] = $this->fixture($name, lifecycle: $enabled);
            Http::fake();
            try {
                $adapter->suspendServer($service);
                $this->fail('Unconfigured lifecycle operation succeeded');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('operator', $e->getMessage());
                Http::assertNothingSent();
            }
        }
    }

    public function test_ispmanager_stale_jobs_never_change_the_provider_account(): void
    {
        [$service] = $this->fixture(lifecycle: true);
        $active = true;
        Http::fake(function ($request) use (&$active) {
            if ($request['func'] === 'user') {
                return Http::response('<doc><elem><name>client</name><owner>reseller</owner>' . ($active ? '<active/>' : '') . '</elem></doc>');
            }
            $active = $request['func'] === 'user.resume';

            return Http::response('<doc><ok/></doc>');
        });

        (new SuspendJob($service, false))->handle();
        $service->update(['status' => Service::STATUS_SUSPENDED]);
        (new UnsuspendJob($service))->handle();

        Http::assertNothingSent();
    }

    public function test_ispmanager_skips_a_suspension_if_renewal_commits_during_the_provider_read(): void
    {
        [$service] = $this->fixture(lifecycle: true);
        $service->update(['status' => Service::STATUS_SUSPENDED]);
        Http::fake(function ($request) use ($service) {
            $this->assertSame('user', $request['func']);
            $service->update(['status' => Service::STATUS_ACTIVE]);

            return Http::response('<doc><elem><name>client</name><owner>reseller</owner><active/></elem></doc>');
        });

        (new SuspendJob($service, false))->handle();

        Http::assertSentCount(1);
    }

    public function test_ispmanager_cron_and_renewal_dispatch_after_the_billing_status_is_saved(): void
    {
        [$service] = $this->fixture(lifecycle: true);
        config([
            'settings.cronjob_order_suspend' => 2,
            'settings.cronjob_order_terminate' => 14,
            'app.version' => 'development',
        ]);
        $service->update(['price' => 10, 'expires_at' => now()->subDays(4)]);
        $active = true;
        $functions = [];
        Http::fake(function ($request) use ($service, &$active, &$functions) {
            $function = $functions[] = $request['func'];
            if ($function === 'user') {
                return Http::response('<doc><elem><name>client</name><owner>reseller</owner>' . ($active ? '<active/>' : '') . '</elem></doc>');
            }
            $this->assertContains($function, ['user.suspend', 'user.resume']);
            $this->assertSame($function === 'user.suspend' ? Service::STATUS_SUSPENDED : Service::STATUS_ACTIVE, $service->fresh()->status);
            $active = $function === 'user.resume';

            return Http::response('<doc><ok/></doc>');
        });

        $this->artisan('app:cron-job')->assertSuccessful();
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertFalse($active);

        DB::transaction(fn () => (new RenewServiceService)->handle($service->fresh()));
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertTrue($active);
        $this->assertSame(['user', 'user.suspend', 'user', 'user', 'user.resume', 'user'], $functions);
    }

    public function test_ispmanager_rejects_reassigned_server_and_duplicate_panel_binding_before_network(): void
    {
        [$service, $adapter] = $this->fixture(lifecycle: true);
        $originalServer = $service->product->server_id;
        [$other] = $this->fixture(lifecycle: true, held: true);
        $other->product->server->settings()->whereIn('key', ['url', 'lifecycle_endpoint'])
            ->update(['value' => 'https://PANEL.example.test:443/manager/']);
        Http::fake();
        foreach ([$other->product->server_id, $originalServer] as $serverId) {
            $service->product->update(['server_id' => $serverId]);
            try {
                $adapter->suspendServer($service);
                $this->fail('Unsafe provider account binding accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('binding', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_ispmanager_service_owner_with_limited_admin_rights_cannot_edit_the_service(): void
    {
        [$service] = $this->fixture(lifecycle: true);
        $actor = $service->user;
        $actor->role()->associate(Role::create(['name' => 'View only', 'permissions' => ['admin.services.view', 'admin.services.viewAny']]));
        $actor->save();
        $this->actingAs($actor);
        $this->assertTrue($actor->can('update', $service));

        Livewire::test(EditService::class, ['record' => $service->id])->assertForbidden();
    }

    public function test_ispmanager_endpoint_edit_requires_a_new_lifecycle_confirmation(): void
    {
        [$service, $adapter] = $this->fixture(lifecycle: true);
        $service->product->server->settings()->where('key', 'url')->update(['value' => 'https://different.example.test/manager']);
        Http::fake(['*' => Http::response('<doc><elem><name>client</name><owner>reseller</owner><active/></elem></doc>')]);

        try {
            $adapter->suspendServer($service);
            $this->fail('Provider call used the reassigned endpoint');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('endpoint', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_ispmanager_unconfirmed_operation_fences_later_jobs_until_provider_state_confirms_it(): void
    {
        [$service, $adapter] = $this->fixture(lifecycle: true);
        $active = true;
        $writes = [];
        Http::fake(function ($request) use (&$active, &$writes) {
            $function = $request['func'];
            if ($function === 'user') {
                return Http::response('<doc><elem><name>client</name><owner>reseller</owner>' . ($active ? '<active/>' : '') . '</elem></doc>');
            }
            $writes[] = $function;
            if ($function === 'user.suspend') {
                throw new ConnectionException('Synthetic delayed provider operation');
            }
            $active = true;

            return Http::response('<doc><ok/></doc>');
        });

        try {
            $adapter->suspendServer($service);
            $this->fail('Unconfirmed suspension succeeded');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be confirmed', $e->getMessage());
        }
        try {
            (new UnsuspendJob($service))->handle();
            $this->fail('Renewal accepted the state while the suspension was unresolved');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('earlier ISPmanager operation', $e->getMessage());
        }
        $this->assertSame(['user.suspend'], $writes);

        $active = false;
        (new UnsuspendJob($service))->handle();
        $this->assertTrue($active);
        $this->assertSame(['user.suspend', 'user.resume'], $writes);
    }

    public function test_ispmanager_one_failed_dispatched_account_still_allows_other_due_accounts_to_suspend(): void
    {
        [$first] = $this->fixture(lifecycle: true);
        [$second] = $this->fixture(lifecycle: true, username: 'other');
        foreach ([$first, $second] as $service) {
            $service->update(['price' => 10, 'expires_at' => now()->subDays(4)]);
        }
        config(['app.version' => 'development', 'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14]);
        $failed = [];
        Event::listen(JobFailed::class, static function ($event) use (&$failed) {
            $failed[] = $event->exception->getMessage();
        });
        $otherActive = true;
        $writes = [];
        Http::fake(function ($request) use (&$otherActive, &$writes) {
            if ($request['func'] === 'user') {
                return Http::response('<doc><elem><name>client</name><owner>reseller</owner><active/></elem>'
                    . '<elem><name>other</name><owner>reseller</owner>' . ($otherActive ? '<active/>' : '') . '</elem></doc>');
            }
            $this->assertSame('user.suspend', $request['func']);
            $writes[] = $request['elid'];
            if ($request['elid'] === 'other') {
                $otherActive = false;
            }

            return Http::response('<doc><ok/></doc>');
        });

        $this->artisan('app:cron-job')->assertSuccessful();
        $this->assertSame(['The ISPmanager account status could not be confirmed'], $failed);
        $this->assertSame(Service::STATUS_SUSPENDED, $first->fresh()->status);
        $this->assertSame(Service::STATUS_SUSPENDED, $second->fresh()->status);
        $this->assertFalse($otherActive);
        $this->artisan('app:cron-job')->assertSuccessful();
        $this->assertSame(['client', 'other'], $writes);
    }

    public function test_ispmanager_overdue_cancellation_suspends_the_only_bound_account(): void
    {
        [$service] = $this->fixture(lifecycle: true);
        $service->update(['price' => 10, 'expires_at' => now()->subDays(20)]);
        config(['app.version' => 'development', 'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14]);
        $functions = [];
        Http::fake(function ($request) use ($service, &$functions) {
            $function = $functions[] = $request['func'];
            if ($function === 'user.suspend') {
                $this->assertSame('client', $request['elid']);
                $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);

                return Http::response('<doc><ok/></doc>');
            }
            $this->assertSame('user', $function);

            return Http::response('<doc><elem><name>client</name><owner>reseller</owner>'
                . (count($functions) === 1 ? '<active/>' : '') . '</elem></doc>');
        });

        $this->artisan('app:cron-job')->assertSuccessful();
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(['user', 'user.suspend', 'user'], $functions);
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
