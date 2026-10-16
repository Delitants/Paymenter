<?php

namespace Tests\Feature;

use App\Livewire\Services\Show;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_service_action_refreshes_status_without_a_page_reload(): void
    {
        $product = $this->createProduct();
        $owner = User::factory()->create();
        $server = Server::create(['name' => 'Synthetic service controls', 'extension' => 'Proxmox', 'type' => 'server']);
        foreach (['host' => 'https://proxmox.example.test', 'verify_ssl' => '1'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['user_id' => $owner->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active']);
        foreach (['proxmox_vm_id' => '500', 'proxmox_node' => 'test', 'proxmox_vm_type' => 'qemu', 'proxmox_vm_name' => 'vm500'] as $key => $value) {
            $service->properties()->create(['key' => $key, 'value' => $value]);
        }
        $state = 'running';
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$state) {
            if (str_contains($request->url(), '/cluster/resources')) return Http::response(['data' => [['vmid' => 500, 'node' => 'test', 'type' => 'qemu', 'name' => 'vm500']]]);
            if (str_ends_with($request->url(), '/status/current')) return Http::response(['data' => ['status' => $state, 'maxmem' => 2147483648, 'mem' => 0]]);
            if (str_ends_with($request->url(), '/status/stop')) { $state = 'stopped'; return Http::response(['data' => 'UPID:test:1:2:3:qmstop:500:test@pve:']); }
            if (str_contains($request->url(), '/tasks/')) return Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]);
            throw new \RuntimeException('Unexpected provider request');
        });
        Livewire::actingAs($owner)->test(Show::class, ['service' => $service])->assertSee('Running')
            ->call('goto', 'stopServer')->assertSee('Stopped')->assertDontSee('Running');
    }
}
