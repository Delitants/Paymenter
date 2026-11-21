<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\ViewCredentials;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminServiceCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(array $permissions = ['admin.services.viewAny', 'admin.services.view', 'admin.services.update']): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $product = $this->createProduct(['server_id' => null]);
        $owner = User::factory()->createQuietly();
        $service = Service::factory()->createQuietly(['user_id' => $owner->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id]);
        foreach (['cloud_init_password_encrypted' => Crypt::encryptString('SyntheticEncryptedGuest9!'), 'cloud_init_password' => 'ObsoletePlaintext9!', 'cloud_init_username' => 'root', 'assigned_ipv4' => '192.0.2.123', 'assigned_ipv6' => '2001:db8::123'] as $key => $value) {
            $service->properties()->create(['key' => $key, 'value' => $value]);
        }
        $role = Role::create(['name' => 'Synthetic credential operator', 'permissions' => $permissions]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor)->withSession($this->loginUser($actor));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$service->fresh(), $actor, $role, $owner];
    }

    public function test_authorized_non_owner_admin_can_read_credentials_without_changing_the_service(): void
    {
        [$service] = $this->fixture();
        $before = $service->getRawOriginal();
        $properties = $service->properties()->get()->toArray();
        $this->get('/admin/services/services/' . $service->id . '/credentials')->assertOk()
            ->assertSee('SyntheticEncryptedGuest9!')->assertDontSee('ObsoletePlaintext9!')
            ->assertSee('root')->assertSee('192.0.2.123')->assertSee('2001:db8::123')
            ->assertSee('A password changed inside the VM may differ from this saved password.');
        $this->assertSame($before, $service->fresh()->getRawOriginal());
        $this->assertSame($properties, $service->properties()->get()->toArray());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('gateway_payment_attempts', 0);
    }

    public static function insufficientPermissions(): array
    {
        return [
            'view only' => [['admin.services.viewAny', 'admin.services.view']],
            'update only' => [['admin.services.viewAny', 'admin.services.update']],
            'unprivileged role' => [[]],
        ];
    }

    #[DataProvider('insufficientPermissions')]
    public function test_http_and_direct_livewire_deny_insufficient_permissions(array $permissions): void
    {
        [$service] = $this->fixture($permissions);
        $this->get('/admin/services/services/' . $service->id . '/credentials')->assertForbidden()->assertDontSee('SyntheticEncryptedGuest9!');
        Livewire::test(ViewCredentials::class, ['record' => $service->id])->assertForbidden();
    }

    public function test_credentials_are_reauthorized_after_role_revocation_on_livewire_update(): void
    {
        [$service, , $role] = $this->fixture();
        $page = Livewire::test(ViewCredentials::class, ['record' => $service->id])->assertSee('SyntheticEncryptedGuest9!');
        $role->update(['permissions' => ['admin.services.viewAny', 'admin.services.view']]);
        $page->call('$refresh')->assertForbidden()->assertDontSee('SyntheticEncryptedGuest9!');
    }

    public function test_service_edit_links_authorized_admin_to_the_read_only_page(): void
    {
        [$service] = $this->fixture();
        $this->get('/admin/services/services/' . $service->id . '/edit')->assertOk()->assertSee('/admin/services/services/' . $service->id . '/credentials', false);
    }

    public function test_client_owners_and_shared_members_do_not_gain_admin_access(): void
    {
        [$service, , , $owner] = $this->fixture();
        $reader = User::factory()->createQuietly();
        $account = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 91, 'owner_user_id' => $owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $account, 'user_id' => $reader->id, 'source_user_id' => 92]);
        foreach ([$owner, $reader] as $client) {
            $this->actingAs($client)->withSession($this->loginUser($client));
            $this->get('/admin/services/services/' . $service->id . '/credentials')->assertForbidden()->assertDontSee('SyntheticEncryptedGuest9!');
            Livewire::test(ViewCredentials::class, ['record' => $service->id])->assertForbidden();
        }
        $this->actingAs($owner)->withSession($this->loginUser($owner));
        $this->get('/services/' . $service->id . '/credentials')->assertOk()->assertSee('SyntheticEncryptedGuest9!');
        $this->actingAs($reader)->withSession($this->loginUser($reader));
        $this->get('/services/' . $service->id . '/credentials')->assertNotFound()->assertDontSee('SyntheticEncryptedGuest9!');
    }

    public function test_legacy_plain_password_and_empty_credentials_are_read_only(): void
    {
        [$service] = $this->fixture();
        $service->properties()->where('key', 'cloud_init_password_encrypted')->delete();
        $this->get('/admin/services/services/' . $service->id . '/credentials')->assertOk()->assertSee('ObsoletePlaintext9!');
        $service->properties()->delete();
        $this->get('/admin/services/services/' . $service->id . '/credentials')->assertOk()->assertSee('No VM credentials have been saved for this service.');
    }
}
