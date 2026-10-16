<?php

namespace Tests\Feature;

use App\Admin\Resources\IpPoolResource\Pages\CreateIpPool;
use App\Admin\Resources\IpPoolResource\Pages\EditIpPool;
use App\Models\IpPool;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IpPoolFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $role = Role::create(['name' => 'Synthetic IP pool operator', 'permissions' => ['*']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public static function networks(): array
    {
        return [
            'compressed IPv6' => ['2001:db8:1::/64', 'ipv6', '/64', '2001:db8:1::1', null],
            'expanded IPv6' => ['2001:0db8:0001:0000:0000:0000:0000:0000/64', 'ipv6', '/64', '2001:db8:1::1', null],
            'IPv6 host bits' => ['2001:db8:1::abcd/64', 'ipv6', '/64', '2001:db8:1::1', null],
            'IPv6 byte boundary' => ['2001:db8::1ff/120', 'ipv6', '/120', '2001:db8::101', null],
            'IPv6 partial byte' => ['2001:db8::1ff/121', 'ipv6', '/121', '2001:db8::181', null],
            'IPv6 maximum two-address subnet' => ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff/127', 'ipv6', '/127', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', null],
            'IPv6 single address' => ['2001:db8::9/128', 'ipv6', '/128', null, null],
            'IPv4' => ['192.0.2.0/24', 'ipv4', '255.255.255.0', '192.0.2.1', '192.0.2.255'],
        ];
    }

    #[DataProvider('networks')]
    public function test_native_form_persists_usable_network_defaults(string $network, string $version, string $mask, ?string $gateway, ?string $broadcast): void
    {
        Livewire::test(CreateIpPool::class)
            ->set('data.network_address', $network)
            ->assertSet('data.ip_version', $version)
            ->assertSet('data.subnet_mask', $mask)
            ->assertSet('data.gateway', $gateway)
            ->assertSet('data.broadcast_address', $broadcast)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('ip_pools', ['network_address' => $network, 'ip_version' => $version,
            'subnet_mask' => $mask, 'gateway' => $gateway, 'broadcast_address' => $broadcast]);
        Http::assertNothingSent();
    }

    public static function invalidNetworks(): array
    {
        return array_map(fn ($network) => [$network], ['2001:db8::/129', '2001:db8::::/64', '192.0.2.0/33', '999.0.2.0/24', '2001:db8::/64/12']);
    }

    #[DataProvider('invalidNetworks')]
    public function test_invalid_network_never_saves_or_replaces_valid_defaults(string $network): void
    {
        Livewire::test(CreateIpPool::class)
            ->set('data.network_address', '2001:db8:1::/64')
            ->set('data.gateway', 'fe80::1')
            ->set('data.network_address', $network)
            ->assertSet('data.gateway', 'fe80::1')
            ->call('create')
            ->assertHasFormErrors(['network_address']);
        $this->assertSame(0, IpPool::count());
    }

    public function test_administrator_can_override_default_with_a_link_local_gateway(): void
    {
        Livewire::test(CreateIpPool::class)
            ->set('data.network_address', '2001:db8:1::/64')
            ->set('data.gateway', 'fe80::1')
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame('fe80::1', IpPool::sole()->gateway);
    }

    public static function invalidGateways(): array
    {
        return [['2001:db8:1::::1'], ['192.0.2.1']];
    }

    #[DataProvider('invalidGateways')]
    public function test_ipv6_pool_rejects_malformed_or_wrong_family_gateway(string $gateway): void
    {
        Livewire::test(CreateIpPool::class)
            ->set('data.network_address', '2001:db8:1::/64')
            ->set('data.gateway', $gateway)
            ->call('create')
            ->assertHasFormErrors(['gateway']);
        $this->assertSame(0, IpPool::count());
    }

    public static function editableNetworks(): array
    {
        return [
            ['2001:db8:1::/64', 'ipv6', '/64', 'fe80::123', 'fe80::456'],
            ['192.0.2.0/24', 'ipv4', '255.255.255.0', '192.0.2.1', '192.0.2.2'],
        ];
    }

    #[DataProvider('editableNetworks')]
    public function test_edit_preserves_existing_gateway_and_allows_operator_override(string $network, string $version, string $mask, string $oldGateway, string $newGateway): void
    {
        $pool = IpPool::create(['name' => 'Synthetic IP pool', 'network_address' => $network,
            'ip_version' => $version, 'subnet_mask' => $mask, 'gateway' => $oldGateway]);
        Livewire::test(EditIpPool::class, ['record' => $pool->id])
            ->assertSet('data.gateway', $oldGateway)
            ->set('data.gateway', $newGateway)
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($newGateway, $pool->fresh()->gateway);
    }

    public function test_edit_cannot_take_another_pools_network(): void
    {
        IpPool::create(['name' => 'Other IPv6 pool', 'network_address' => '2001:db8:1::/64',
            'ip_version' => 'ipv6', 'subnet_mask' => '/64', 'gateway' => 'fe80::1']);
        $pool = IpPool::create(['name' => 'Edited IPv6 pool', 'network_address' => '2001:db8:2::/64',
            'ip_version' => 'ipv6', 'subnet_mask' => '/64', 'gateway' => 'fe80::1']);
        Livewire::test(EditIpPool::class, ['record' => $pool->id])
            ->set('data.network_address', '2001:db8:1::/64')
            ->call('save')
            ->assertHasFormErrors(['network_address']);
        $this->assertSame('2001:db8:2::/64', $pool->fresh()->network_address);
    }

    public function test_ipv4_overlap_with_another_pool_still_rejected(): void
    {
        IpPool::create(['name' => 'Other IPv4 pool', 'network_address' => '192.0.2.0/24',
            'ip_version' => 'ipv4', 'subnet_mask' => '255.255.255.0', 'gateway' => '192.0.2.1']);
        Livewire::test(CreateIpPool::class)
            ->set('data.network_address', '192.0.2.128/25')
            ->call('create')
            ->assertHasFormErrors(['network_address']);
        $this->assertSame(1, IpPool::count());
    }

    public function test_another_pools_duplicate_network_still_rejected(): void
    {
        $pool = IpPool::create(['name' => 'Synthetic IPv6 pool', 'network_address' => '2001:db8:1::/64',
            'ip_version' => 'ipv6', 'subnet_mask' => '/64', 'gateway' => 'fe80::123']);
        Livewire::test(CreateIpPool::class)
            ->set('data.network_address', $pool->network_address)
            ->call('create')
            ->assertHasFormErrors(['network_address']);
        $this->assertSame(1, IpPool::count());
    }
}
