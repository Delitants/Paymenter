<?php

namespace Tests\Feature\Bind;

use App\Models\Extension;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\Bind\Models\ZoneBinding;
use Paymenter\Extensions\Others\Bind\SshTransport;
use Tests\TestCase;

abstract class BindTestCase extends TestCase
{
    use RefreshDatabase { migrateFreshUsing as private originalMigrateFreshUsing; }

    protected User $owner;

    protected Service $service;

    protected ZoneBinding $binding;

    protected Extension $extension;

    protected array $calls = [];

    protected function beforeRefreshingDatabase(): void
    {
        // Core tests may set the shared flag before the extension schema exists.
        if (RefreshDatabaseState::$migrated &&
            (!Schema::hasTable('ext_bind_zones') || !Schema::hasTable('ext_bind_operations'))) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    protected function migrateFreshUsing(): array
    {
        return array_merge($this->originalMigrateFreshUsing(), ['--path' => ['database/migrations', 'extensions/Others/Bind/database/migrations']]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(class_exists(ZoneBinding::class), 'native DNS binding model missing');
        Bus::fake();
        Mail::fake();
        $this->owner = User::factory()->create();
        $p = $this->createProduct();
        $this->service = Service::create(['user_id' => $this->owner->id, 'product_id' => $p->product->id, 'plan_id' => $p->plan->id, 'status' => 'active', 'price' => '10.00', 'currency_code' => 'USD', 'expires_at' => now()->addMonth()]);
        $this->extension = Extension::create(['name' => 'BIND', 'extension' => 'Bind', 'type' => 'other', 'enabled' => true]);
        $this->extension->settings()->create(['key' => 'operations_enabled', 'value' => '1']);
        $this->binding = ZoneBinding::forceCreate(['id' => (string) Str::uuid(), 'zone' => 'example.test.', 'service_id' => $this->service->id, 'enabled' => true, 'approved_at' => now()]);
    }

    protected function transport(string $endpoint = 'primary', ?callable $response = null): SshTransport
    {
        $config = ['host' => 'dns.example.test', 'port' => 22, 'username' => 'paymenter-dns', 'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n" . base64_encode('fixture bytes') . "\n-----END OPENSSH PRIVATE KEY-----\n", 'host_key' => 'ssh-ed25519 ' . base64_encode('fixture host')];

        return new SshTransport($config, function ($argv, $stdin) use ($endpoint, $response) {
            $request = json_decode($stdin, true);
            $this->calls[] = ['endpoint' => $endpoint, 'request' => $request];
            $result = $response ? $response($request) : ['state' => 'observed', 'code' => 'OK', 'data' => ['serial' => 2, 'revision' => str_repeat('b', 64), 'records' => []]];

            return json_encode(['protocol' => 'paymenter-bind-v1', 'request_id' => $request['request_id'], 'binding_id' => $request['binding_id']] + $result);
        });
    }

    protected function input(): array
    {
        return ['request_id' => (string) Str::uuid(), 'expected_serial' => 1, 'expected_revision' => str_repeat('a', 64), 'changes' => [['owner' => 'www', 'type' => 'A', 'ttl' => 300, 'records' => ['192.0.2.10']]]];
    }

    protected function denied(callable $call): void
    {
        $denied = false;
        try {
            $call();
        } catch (AuthorizationException $e) {
            $denied = true;
        }$this->assertTrue($denied, 'unauthorized DNS action accepted');
        $this->assertSame([], $this->calls);
    }
}
