<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\BillmanagerServiceDetail;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\BillmanagerMigration\RegistrarAttacher;
use App\Services\BillmanagerMigration\ServiceImporter;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RegistrarAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function file(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'registrar-test-');
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->temporary[] = $path;

        return $path;
    }

    private function fixture(?callable $change = null): array
    {
        $tables = [
            'accounts' => [['id' => '10']],
            'users' => [['id' => '11', 'account' => '10', 'enabled' => 'on', 'level' => '16', 'email' => 'client@example.test', 'realname' => 'Client', 'last_login' => '2025-01-01 00:00:00']],
            'currencies' => [['id' => '1', 'iso' => 'USD']],
            'pricelists' => [['id' => '20', 'name' => 'Domains', 'itemtype' => '30']],
            'items' => [], 'itemparams' => [],
        ];
        foreach (['40' => 'example.test', '41' => 'second.test'] as $id => $domain) {
            $tables['items'][] = ['id' => $id, 'account' => '10', 'pricelist' => '20', 'processingmodule' => '2', 'status' => '2', 'name' => 'Description, not the domain', 'period' => '12', 'costperiod' => '12', 'cost' => '10.0000', 'currency' => '1', 'createdate' => '2020-01-01', 'expiredate' => '2026-10-01'];
            foreach (['domain' => $domain, 'ns0' => 'NS1.EXAMPLE.TEST.', 'ns1' => 'ns2.example.test'] as $key => $value) {
                $tables['itemparams'][] = ['id' => (string) (50 + count($tables['itemparams'])), 'item' => $id, 'intname' => $key, 'value' => $value];
            }
        }
        if ($change) {
            $tables = $change($tables);
        }
        $path = $this->file(['schema_version' => 1, 'source_host' => '192.0.2.10', 'login_cutoff' => '2024-01-01 00:00:00', 'captured_at_utc' => '2026-01-01T00:00:00Z', 'source_timezone' => 'UTC', 'tables' => $tables]);
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        $this->temporary[] = $path . '.sha256';
        $snapshot = Snapshot::load($path, '192.0.2.10', '2024-01-01 00:00:00');
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => $snapshot->checksum(), 'status' => 'running']);
        $context = new ImportContext($id, '192.0.2.10');
        (new CustomerImporter)->import($snapshot, $context);
        (new ServiceImporter)->import($snapshot, $context);
        $server = Server::create(['name' => 'Synthetic registrar', 'extension' => 'ResellerClub', 'type' => 'server', 'enabled' => false]);
        foreach (['api_key' => 'synthetic-api-secret', 'reseller_id' => '123', 'environment' => 'test'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $context->recordMapping('provider_servers', '2', 'extensions', $server->id);
        Http::preventStrayRequests();

        return [$snapshot, $context, ['2' => $server->id], $path];
    }

    private function provider(array $changes = [], bool $failSecond = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($changes, $failSecond) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $second = ($query['domain-name'] ?? '') === 'second.test';
            if ($failSecond && $second) {
                return Http::response(['status' => 'ERROR', 'message' => 'synthetic provider error'], 200);
            }

            return Http::response(array_replace([
                'orderid' => $second ? '457' : '456', 'domainname' => $second ? 'second.test' : 'example.test',
                'customerid' => '789', 'currentstatus' => 'Active', 'endtime' => '1790812800',
                'creationtime' => '1600000000', 'ns1' => 'ns1.example.test', 'ns2' => 'ns2.example.test',
                'orderstatus' => ['transferlock'], 'recurring' => 'false', 'domsecret' => 'synthetic-epp-secret',
                'registrantcontact' => ['emailaddr' => 'private-contact@example.test'],
            ], $changes));
        });
    }

    private function unchanged(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['users', 'services', 'plans', 'prices', 'orders', 'invoices', 'billmanager_service_details', 'billmanager_holds']);
    }

    private function reject(callable $action): void
    {
        $error = null;
        try {
            $action();
        } catch (RuntimeException $e) {
            $error = $e;
        }
        $this->assertInstanceOf(RuntimeException::class, $error, 'Unsafe registrar attachment must be rejected');
        $this->assertStringNotContainsString('synthetic-api-secret', $error->getMessage());
    }

    public function test_native_binding_replays_without_changing_billing_and_archives_only_safe_encrypted_identity(): void
    {
        [$snapshot, $context, $map] = $this->fixture(function ($tables) {
            $tables['itemparams'][0]['value'] = ' EXAMPLE.TEST. ';

            return $tables;
        });
        $this->provider();
        $before = $this->unchanged();
        foreach ([1, 2] as $run) {
            $report = (new RegistrarAttacher)->attach($snapshot, $context, $map);
            $this->assertSame(2, $report->counts['attached']);
            $this->assertSame(0, $report->counts['review_required']);
            $this->assertSame(6, DB::table('properties')->count());
            $this->assertSame($before, $this->unchanged());
        }
        $service = Service::findOrFail($context->mappedId('items', '40'));
        $this->assertSame('example.test', $service->properties()->where('key', 'resellerclub_domain')->value('value'));
        $this->assertSame('456', $service->properties()->where('key', 'resellerclub_order_id')->value('value'));
        $this->assertSame('789', $service->properties()->where('key', 'resellerclub_customer_id')->value('value'));
        $this->assertTrue(MigrationHold::isHeld($service));
        $this->assertFalse((bool) $service->product->server->enabled);
        $records = DB::table('billmanager_records')->whereIn('source_table', ['provider_bindings', 'registrar_observations'])->get();
        $this->assertCount(4, $records);
        foreach ($records as $record) {
            $this->assertStringNotContainsString('example.test', $record->payload);
            $safe = Crypt::decryptString($record->payload);
            $this->assertStringNotContainsString('synthetic-epp-secret', $safe);
            $this->assertStringNotContainsString('private-contact', $safe);
            $this->assertStringNotContainsString('synthetic-api-secret', $safe);
        }
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' || !str_contains($r->url(), '/domains/details-by-name.json'));
    }

    public function test_one_failed_lookup_keeps_that_service_unbound_and_reports_review(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $this->provider(failSecond: true);
        $report = (new RegistrarAttacher)->attach($snapshot, $context, $map);
        $this->assertSame(1, $report->counts['attached']);
        $this->assertSame(1, $report->counts['review_required']);
        $this->assertSame('registrar_accounts_review_required', $report->status);
        $this->assertSame('41', (string) $report->conflicts[0]['source_id']);
        $service = Service::findOrFail($context->mappedId('items', '41'));
        $this->assertSame(0, $service->properties()->count());
        $this->assertTrue(MigrationHold::isHeld($service));
    }

    public function test_one_independent_signal_can_corroborate_drift_without_rewriting_source_dates_or_dns(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $this->provider(['endtime' => '1822348800']);
        $before = $this->unchanged();
        $report = (new RegistrarAttacher)->attach($snapshot, $context, $map);
        $this->assertSame(2, $report->counts['attached']);
        $this->assertSame(2, $report->counts['expiry_drift']);
        $this->assertSame(0, $report->counts['nameserver_drift']);
        $this->assertSame($before, $this->unchanged());
    }

    public function test_no_matching_independent_signal_is_never_bound(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $this->provider(['endtime' => '1822348800', 'ns1' => 'other1.example.test', 'ns2' => 'other2.example.test']);
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map));
        $this->assertSame(0, DB::table('properties')->count());
        $this->assertSame(0, DB::table('products')->whereNotNull('server_id')->count());
    }

    public function test_provider_status_mismatch_is_reviewed_without_releasing_or_rewriting_a_service(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        // One successful binding keeps a mixed report actionable.
        Http::fake(function ($request) {
            $second = str_contains($request->url(), 'domain-name=second.test');

            return Http::response(['orderid' => $second ? '457' : '456', 'domainname' => $second ? 'second.test' : 'example.test', 'customerid' => '789', 'currentstatus' => $second ? 'Active' : 'Suspended', 'endtime' => '1790812800', 'ns1' => 'ns1.example.test', 'ns2' => 'ns2.example.test']);
        });
        $report = (new RegistrarAttacher)->attach($snapshot, $context, $map);
        $this->assertSame(1, $report->counts['attached']);
        $this->assertSame('status_mismatch', $report->conflicts[0]['reason']);
        $service = Service::findOrFail($context->mappedId('items', '40'));
        $this->assertSame('active', $service->status);
        $this->assertSame(0, $service->properties()->count());
    }

    #[DataProvider('unsafeNativeChanges')]
    public function test_source_bound_native_preconditions_are_checked_before_provider_reads(string $change): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $service = Service::findOrFail($context->mappedId('items', '41'));
        match ($change) {
            'owner' => DB::table('services')->where('id', $service->id)->update(['user_id' => User::factory()->create()->id]),
            'status' => DB::table('services')->where('id', $service->id)->update(['status' => 'cancelled']),
            'hold' => DB::table('billmanager_holds')->update(['released_at' => now()]),
            'hidden' => DB::table('products')->update(['hidden' => false]),
            'enabled' => DB::table('extensions')->where('id', $map['2'])->update(['enabled' => true]),
            'mapping' => DB::table('billmanager_mappings')->where('source_table', 'provider_servers')->update(['target_table' => 'users']),
            'metadata' => BillmanagerServiceDetail::where('service_id', $service->id)->update(['source_id' => 'wrong']),
            'import' => DB::table('billmanager_imports')->where('id', $context->importId)->update(['snapshot_sha256' => str_repeat('0', 64)]),
        };
        Http::fake();
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map));
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('properties')->count());
    }

    public static function unsafeNativeChanges(): array
    {
        return array_map(fn ($s) => [$s], ['owner', 'status', 'hold', 'hidden', 'enabled', 'mapping', 'metadata', 'import']);
    }

    public function test_conflict_on_later_service_rolls_back_all_new_bindings(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $service = Service::findOrFail($context->mappedId('items', '41'));
        $service->properties()->create(['key' => 'resellerclub_customer_id', 'value' => '999']);
        $this->provider();
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map));
        $this->assertSame(1, DB::table('properties')->count());
        $this->assertSame(0, DB::table('products')->whereNotNull('server_id')->count());
        $this->assertSame(0, DB::table('billmanager_records')->where('source_table', 'provider_bindings')->count());
    }

    public function test_changed_provider_customer_identity_cannot_overwrite_a_binding(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $this->provider();
        (new RegistrarAttacher)->attach($snapshot, $context, $map);
        $before = DB::table('properties')->orderBy('id')->get()->toJson();
        $this->provider(['customerid' => '999']);
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map));
        $this->assertSame($before, DB::table('properties')->orderBy('id')->get()->toJson());
    }

    public function test_duplicate_resource_on_another_service_cannot_be_bound(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $other = Service::findOrFail($context->mappedId('items', '41'));
        $other->properties()->create(['key' => 'resellerclub_domain', 'value' => 'EXAMPLE.TEST']);
        $this->provider();
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map));
        $this->assertSame(1, DB::table('properties')->count());
    }

    public function test_invalid_customer_identity_and_total_provider_failure_never_report_success(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $this->provider(['customerid' => '0']);
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map));
        $this->assertSame(0, DB::table('properties')->count());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['status' => 'ERROR'], 200)]);
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map));
        $this->assertSame(0, DB::table('properties')->count());
    }

    public function test_duplicate_source_domain_parameters_are_rejected_without_reads(): void
    {
        [$snapshot, $context, $map] = $this->fixture(function ($tables) {
            $tables['itemparams'][] = ['id' => '99', 'item' => '40', 'intname' => 'domain', 'value' => 'different.test'];

            return $tables;
        });
        Http::fake();
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map));
        Http::assertNothingSent();
    }

    public function test_duplicate_provider_order_is_rejected_even_in_dry_run(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $this->provider(['orderid' => '456']);
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map, true));
        $this->assertSame(0, DB::table('properties')->count());
    }

    public function test_registrar_credentials_changed_during_reads_cannot_be_bound(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        Http::fake(function ($request) use ($map) {
            DB::table('settings')->where(['settingable_type' => Server::class, 'settingable_id' => $map['2'], 'key' => 'reseller_id'])->update(['value' => '999']);
            $second = str_contains($request->url(), 'domain-name=second.test');

            return Http::response(['orderid' => $second ? '457' : '456', 'domainname' => $second ? 'second.test' : 'example.test', 'customerid' => '789', 'currentstatus' => 'Active', 'endtime' => '1790812800', 'ns1' => 'ns1.example.test', 'ns2' => 'ns2.example.test']);
        });
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map));
        $this->assertSame(0, DB::table('properties')->count());
    }

    public function test_native_dry_run_verifies_current_provider_without_any_binding_or_import_write(): void
    {
        [$snapshot, $context, $map, $path] = $this->fixture();
        $this->provider();
        $mapping = $this->file(['source_host' => '192.0.2.10', 'servers_by_module' => $map]);
        $report = $this->file([]);
        $before = $this->unchanged();
        $imports = DB::table('billmanager_imports')->get()->toJson();
        $records = DB::table('billmanager_records')->count();
        $this->artisan('billmanager:import', ['snapshot' => $path, '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00', '--stage' => 'registrar-accounts', '--dry-run' => true, '--provider-servers' => $mapping, '--report' => $report])->assertSuccessful();
        $result = json_decode(file_get_contents($report), true);
        $this->assertSame(2, $result['counts']['attached'] ?? null);
        $this->assertSame('registrar_accounts_verified_dry_run', $result['status']);
        $this->assertSame($before, $this->unchanged());
        $this->assertSame($imports, DB::table('billmanager_imports')->get()->toJson());
        $this->assertSame($records, DB::table('billmanager_records')->count());
        $this->assertSame(0, DB::table('properties')->count());
        $this->assertSame(0, DB::table('products')->whereNotNull('server_id')->count());
        Http::assertSentCount(2);
        config(['billmanager-migration.allowed_databases' => [DB::connection()->getDatabaseName()]]);
        $this->artisan('billmanager:import', ['snapshot' => $path, '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00', '--stage' => 'registrar-accounts', '--apply' => true, '--provider-servers' => $mapping])->assertSuccessful();
        $this->assertSame(6, DB::table('properties')->count());
    }

    public function test_conflicting_encrypted_binding_is_rejected_in_dry_run_before_properties_exist(): void
    {
        [$snapshot, $context, $map] = $this->fixture();
        $context->archive('provider_bindings', '40', ['server_id' => $map['2'], 'extension' => 'ResellerClub', 'domain' => 'example.test', 'order_id' => '999', 'customer_id' => '789'], 10);
        $this->provider();
        $this->reject(fn () => (new RegistrarAttacher)->attach($snapshot, $context, $map, true));
        $this->assertSame(0, DB::table('properties')->count());
    }

    #[DataProvider('concurrentNativeChanges')]
    public function test_command_rechecks_concurrent_native_changes_with_current_mysql_reads(string $change): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Exercises MySQL repeatable-read concurrency');
        }
        // Commit only synthetic fixtures so a second connection can change state during GETs.
        // Restore the initial test database in finally; production databases are never used.
        $baseline = [];
        foreach (DB::select('SHOW TABLES') as $table) {
            $name = array_values((array) $table)[0];
            $baseline[$name] = DB::table($name)->get()->map(fn ($r) => (array) $r)->all();
        }
        [$snapshot, $context, $map, $path] = $this->fixture();
        $otherOwner = User::factory()->create()->id;
        $mapping = $this->file(['source_host' => '192.0.2.10', 'servers_by_module' => $map]);
        $default = config('database.default');
        config(['database.connections.registrar_concurrent_test' => config('database.connections.' . $default), 'billmanager-migration.allowed_databases' => [DB::connection()->getDatabaseName()]]);
        DB::connection()->commit();
        try {
            Http::fake(function ($request) use ($map, $change, $otherOwner) {
                $concurrent = DB::connection('registrar_concurrent_test');
                match ($change) {
                    'enabled' => $concurrent->table('extensions')->where('id', $map['2'])->update(['enabled' => true]),
                    'hold' => $concurrent->table('billmanager_holds')->update(['released_at' => now()]),
                    'metadata' => $concurrent->table('billmanager_service_details')->update(['source_id' => 'wrong']),
                    'account' => $concurrent->table('billmanager_accounts')->update(['owner_user_id' => $otherOwner]),
                };
                $second = str_contains($request->url(), 'domain-name=second.test');

                return Http::response(['orderid' => $second ? '457' : '456', 'domainname' => $second ? 'second.test' : 'example.test', 'customerid' => '789', 'currentstatus' => 'Active', 'endtime' => '1790812800', 'ns1' => 'ns1.example.test', 'ns2' => 'ns2.example.test']);
            });
            $this->artisan('billmanager:import', ['snapshot' => $path, '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00', '--stage' => 'registrar-accounts', '--apply' => true, '--provider-servers' => $mapping])->assertFailed();
            $this->assertSame(0, DB::table('properties')->count());
        } finally {
            DB::purge('registrar_concurrent_test');
            while (DB::connection()->transactionLevel() > 0) {
                DB::connection()->rollBack();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($baseline as $name => $rows) {
                    DB::table($name)->truncate();
                    foreach (array_chunk($rows, 100) as $chunk) {
                        DB::table($name)->insert($chunk);
                    }
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
                DB::connection()->beginTransaction();
            }
        }
    }

    public static function concurrentNativeChanges(): array
    {
        return array_map(fn ($s) => [$s], ['enabled', 'hold', 'metadata', 'account']);
    }
}
