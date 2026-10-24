<?php

namespace Tests\Feature\Bind;

use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Paymenter\Extensions\Others\Bind\Admin\Resources\ZoneBindingResource;
use Paymenter\Extensions\Others\Bind\Admin\Resources\ZoneBindingResource\Pages\ListZoneBindings;
use Paymenter\Extensions\Others\Bind\Bind;
use Paymenter\Extensions\Others\Bind\Livewire\Zones\Show;
use Paymenter\Extensions\Others\Bind\Models\DnsOperation;
use Paymenter\Extensions\Others\Bind\ZoneManager;

final class ZoneScreensTest extends BindTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(class_exists(Show::class), 'DNS screens missing');
        (new Bind)->boot();
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
        $this->withoutVite();
        app()->instance(ZoneManager::class, new ZoneManager($this->transport(), $this->transport('secondary')));
    }

    public function test_owner_routes_render_native_components_and_foreign_binding_is_denied(): void
    {
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner))->get(route('bind.zones.index'))->assertOk()->assertSee('example.test.');
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner))->get(route('bind.zones.show', $this->binding))->assertOk()->assertSee('DNS records');
        Livewire::actingAs(User::factory()->create())->test(Show::class, ['binding' => $this->binding])->assertForbidden();
    }

    public function test_shared_read_only_user_and_revoked_actor_cannot_save(): void
    {
        $shared = User::factory()->create();
        $a = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 400, 'owner_user_id' => $this->owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $a, 'user_id' => $shared->id, 'source_user_id' => 401]);
        $component = Livewire::actingAs($shared)->test(Show::class, ['binding' => $this->binding])->assertDontSee('Save DNS records');
        $before = count($this->calls);
        $component->call('save')->assertForbidden();
        $this->assertCount($before, $this->calls);
        $component = Livewire::actingAs($this->owner)->test(Show::class, ['binding' => $this->binding]);
        DB::table('billmanager_holds')->insert(['model_type' => Service::class, 'model_id' => $this->service->id, 'reason' => 'fixture']);
        $before = count($this->calls);
        $component->call('save')->assertForbidden();
        $this->assertCount($before, $this->calls);
    }

    public function test_replication_delay_and_uncertain_outcome_use_exact_friendly_messages(): void
    {
        foreach (['committed', 'uncertain'] as $state) {
            app()->instance(ZoneManager::class, new ZoneManager($this->transport('primary', fn ($r) => $r['operation'] === 'replace_records' ? ['state' => $state, 'code' => 'OK', 'data' => $state === 'committed' ? ['serial' => 3, 'revision' => str_repeat('c', 64)] : (object) []] : ['state' => 'observed', 'code' => 'OK', 'data' => ['serial' => 2, 'revision' => str_repeat('b', 64), 'records' => []]]), $this->transport('secondary')));
            $component = Livewire::actingAs($this->owner)->test(Show::class, ['binding' => $this->binding])->set('owner', 'www')->set('type', 'A')->set('ttl', 300)->set('recordsText', '192.0.2.30')->call('save');
            $component->assertSee($state === 'committed' ? 'Your DNS update was saved. The secondary nameserver is still updating.' : 'We could not confirm the update. Please do not submit it again; contact support with reference')->assertDontSee('Save DNS records');
            DB::table('ext_bind_operations')->delete();
        }
    }

    public function test_record_values_are_escaped_and_secrets_not_rendered(): void
    {
        app()->instance(ZoneManager::class, new ZoneManager($this->transport('primary', fn () => ['state' => 'observed', 'code' => 'OK', 'data' => ['serial' => 1, 'revision' => str_repeat('a', 64), 'records' => [['owner' => 'text.example.test.', 'ttl' => 300, 'type' => 'TXT', 'records' => ['"<script>alert(1)</script>"']]]]]), $this->transport()));
        Livewire::actingAs($this->owner)->test(Show::class, ['binding' => $this->binding])->assertSee('text.example.test.')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('BEGIN OPENSSH PRIVATE KEY');
    }

    public function test_reload_keeps_unresolved_reference_and_blocks_new_save(): void
    {
        $id = (string) Str::uuid();
        DnsOperation::forceCreate(['id' => $id, 'binding_id' => $this->binding->id, 'actor_user_id' => $this->owner->id, 'kind' => 'replace_records', 'request_hash' => str_repeat('a', 64), 'state' => 'uncertain', 'code' => 'OUTCOME_UNCONFIRMED', 'response' => ['state' => 'uncertain', 'code' => 'OUTCOME_UNCONFIRMED', 'data' => []]]);
        Livewire::actingAs($this->owner)->test(Show::class, ['binding' => $this->binding])->assertSee($id)->assertDontSee('Save DNS records');
    }

    public function test_inspection_failure_is_friendly_and_blocks_saving(): void
    {
        app()->instance(ZoneManager::class, new ZoneManager($this->transport('primary', fn () => throw new \RuntimeException('private/path/provider-stderr')), $this->transport('secondary')));
        Livewire::actingAs($this->owner)->test(Show::class, ['binding' => $this->binding])->assertSee('DNS records are temporarily unavailable')->assertDontSee('Save DNS records')->assertDontSee('private/path/provider-stderr');
    }

    public function test_conflict_explains_that_another_update_changed_the_zone(): void
    {
        app()->instance(ZoneManager::class, new ZoneManager($this->transport('primary', fn ($r) => $r['operation'] === 'replace_records' ? ['state' => 'rejected', 'code' => 'REVISION_CONFLICT', 'data' => (object) []] : ['state' => 'observed', 'code' => 'OK', 'data' => ['serial' => 2, 'revision' => str_repeat('b', 64), 'records' => []]]), $this->transport('secondary')));
        Livewire::actingAs($this->owner)->test(Show::class, ['binding' => $this->binding])->set('owner', 'www')->set('recordsText', '192.0.2.30')->call('save')->assertSee('The records changed while you were editing');
    }

    public function test_client_cannot_access_admin_binding_resource(): void
    {
        Auth::login($this->owner);
        $this->assertFalse(ZoneBindingResource::canAccess());
        $this->assertFalse(ZoneBindingResource::canCreate());
    }

    public function test_admin_table_renders_with_explicit_view_permission(): void
    {
        $role = Role::create(['name' => 'DNS viewer', 'permissions' => ['admin.bind.view']]);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($admin);
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Route::name('filament.admin.')->group(fn () => ZoneBindingResource::registerRoutes($panel));
        app('router')->getRoutes()->refreshNameLookups();
        Livewire::test(ListZoneBindings::class)->assertOk()->assertCanSeeTableRecords([$this->binding])->assertActionHidden('create');
    }

    public function test_invalid_types_and_disabled_binding_do_not_submit_mutation(): void
    {
        $component = Livewire::actingAs($this->owner)->test(Show::class, ['binding' => $this->binding]);
        $before = count($this->calls);
        $component->set('type', 'SOA')->call('save')->assertHasErrors('type');
        $this->assertCount($before, $this->calls);
        $this->binding->forceFill(['enabled' => false])->save();
        $component->call('refreshRecords')->assertForbidden();
        $this->assertCount($before, $this->calls);
    }
}
