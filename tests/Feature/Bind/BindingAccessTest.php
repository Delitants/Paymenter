<?php

namespace Tests\Feature\Bind;

use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Others\Bind\BindAccess;

final class BindingAccessTest extends BindTestCase
{
    public function test_owner_and_shared_read_preserve_native_update_permissions(): void
    {
        BindAccess::assertAllowed($this->owner, $this->binding, 'inspect');
        BindAccess::assertAllowed($this->owner, $this->binding, 'replace_records');
        $shared = User::factory()->create();
        $account = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 100, 'owner_user_id' => $this->owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $account, 'user_id' => $shared->id, 'source_user_id' => 101]);
        BindAccess::assertAllowed($shared, $this->binding, 'inspect');
        $this->denied(fn () => BindAccess::assertAllowed($shared, $this->binding, 'replace_records'));
        $this->denied(fn () => BindAccess::assertAllowed(User::factory()->create(), $this->binding, 'inspect'));
    }

    public function test_service_owner_and_actor_holds_deny_before_transport(): void
    {
        foreach ([[Service::class, $this->service->id], [User::class, $this->owner->id]] as [$type,$id]) {
            DB::table('billmanager_holds')->insert(['model_type' => $type, 'model_id' => $id, 'reason' => 'fixture']);
            foreach (['inspect', 'replace_records', 'inspect_request'] as $op) {
                $this->denied(fn () => BindAccess::assertAllowed($this->owner, $this->binding, $op));
            }DB::table('billmanager_holds')->where('model_type', $type)->where('model_id', $id)->delete();
        }
    }

    public function test_disabled_unapproved_or_inactive_binding_denies(): void
    {
        $this->binding->forceFill(['enabled' => false])->save();
        $this->denied(fn () => BindAccess::assertAllowed($this->owner, $this->binding, 'inspect'));
        $this->binding->forceFill(['enabled' => true, 'approved_at' => null])->save();
        $this->denied(fn () => BindAccess::assertAllowed($this->owner, $this->binding, 'inspect'));
        $this->binding->forceFill(['approved_at' => now()])->save();
        $this->service->status = 'suspended';
        $this->service->save();
        $this->denied(fn () => BindAccess::assertAllowed($this->owner, $this->binding, 'inspect'));
    }

    public function test_held_shared_actor_and_disabled_write_switch_deny(): void
    {
        $shared = User::factory()->create();
        $a = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 200, 'owner_user_id' => $this->owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $a, 'user_id' => $shared->id, 'source_user_id' => 201]);
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $shared->id, 'reason' => 'fixture']);
        $this->denied(fn () => BindAccess::assertAllowed($shared, $this->binding, 'inspect'));
        $this->extension->settings()->where('key', 'operations_enabled')->first()->update(['value' => '0']);
        $this->denied(fn () => BindAccess::assertAllowed($this->owner, $this->binding, 'replace_records'));
        BindAccess::assertAllowed($this->owner, $this->binding, 'inspect');
    }

    public function test_binding_identity_cannot_be_reassigned(): void
    {
        $this->expectException(\LogicException::class);
        $this->binding->forceFill(['zone' => 'other.test.'])->save();
    }

    public function test_missing_migration_schema_fails_closed(): void
    {
        Schema::partialMock()->shouldReceive('hasTable')->with('billmanager_holds')->andReturn(false);
        $this->denied(fn () => BindAccess::assertAllowed($this->owner, $this->binding, 'inspect'));
    }

    public function test_admin_inspection_of_held_binding_does_not_allow_mutation(): void
    {
        $role = Role::create(['name' => 'DNS inspector', 'permissions' => ['admin.bind.view', 'admin.bind.inspect']]);
        $admin = User::factory()->create(['role_id' => $role->id]);
        app()->instance('request', Request::create('/admin/bind'));
        DB::table('billmanager_holds')->insert(['model_type' => Service::class, 'model_id' => $this->service->id, 'reason' => 'fixture']);
        $this->binding->forceFill(['enabled' => false])->save();
        BindAccess::assertAllowed($admin, $this->binding, 'inspect');
        $this->denied(fn () => BindAccess::assertAllowed($admin, $this->binding, 'replace_records'));
    }
}
