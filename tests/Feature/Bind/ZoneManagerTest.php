<?php

namespace Tests\Feature\Bind;

use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\Bind\Models\DnsOperation;
use Paymenter\Extensions\Others\Bind\ZoneManager;

final class ZoneManagerTest extends BindTestCase
{
    public function test_primary_write_and_secondary_read_reuse_original_reference(): void
    {
        $primary = $this->transport('primary', fn ($r) => ['state' => 'committed', 'code' => 'SAVED', 'data' => ['serial' => 2, 'revision' => str_repeat('b', 64)]]);
        $manager = new ZoneManager($primary, $this->transport('secondary'));
        $input = $this->input();
        $result = $manager->replace($this->owner, $this->binding, $input);
        $this->assertSame('committed', $result['state']);
        $this->assertCount(2, $this->calls);
        $this->assertSame('replace_records', $this->calls[0]['request']['operation']);
        $this->assertSame('inspect', $this->calls[1]['request']['operation']);
        $this->assertSame($input['request_id'], $this->calls[0]['request']['request_id']);
        $this->assertSame($result, $manager->replace($this->owner, $this->binding, $input));
        $this->assertCount(2, $this->calls);
    }

    public function test_revoked_authority_immediately_before_dispatch_stops_ssh(): void
    {
        $manager = new ZoneManager($this->transport(), $this->transport(), function () {
            DB::table('billmanager_holds')->insert(['model_type' => Service::class, 'model_id' => $this->service->id, 'reason' => 'fixture']);
        });
        $this->denied(fn () => $manager->replace($this->owner, $this->binding, $this->input()));
    }

    public function test_uncertain_request_blocks_new_mutations_and_reconciles_query_only(): void
    {
        $primary = $this->transport('primary', fn ($r) => $r['operation'] === 'replace_records' ? ['state' => 'uncertain', 'code' => 'OUTCOME_UNCONFIRMED', 'data' => (object) []] : ['state' => $r['operation'] === 'inspect_request' ? 'committed' : 'observed', 'code' => 'SAVED', 'data' => ['serial' => 2, 'revision' => str_repeat('b', 64), 'records' => []]]);
        $manager = new ZoneManager($primary, $this->transport('secondary'));
        $input = $this->input();
        $result = $manager->replace($this->owner, $this->binding, $input);
        $this->assertSame('uncertain', $result['state']);
        $count = count($this->calls);
        $blocked = false;
        try {
            $manager->replace($this->owner, $this->binding, $this->input());
        } catch (\RuntimeException $e) {
            $blocked = true;
        }$this->assertTrue($blocked);
        $this->assertCount($count, $this->calls);
        $manager->reconcile($this->owner, $this->binding, ['request_id' => $input['request_id']]);
        $this->assertSame('inspect_request', $this->calls[$count]['request']['operation']);
        $this->assertSame('committed', DnsOperation::findOrFail($input['request_id'])->state);
    }

    public function test_shared_membership_revoked_before_read_dispatch_is_denied(): void
    {
        $shared = User::factory()->create();
        $a = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 300, 'owner_user_id' => $this->owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $a, 'user_id' => $shared->id, 'source_user_id' => 301]);
        $manager = new ZoneManager($this->transport(), $this->transport(), fn () => DB::table('billmanager_members')->where('user_id', $shared->id)->delete());
        $this->denied(fn () => $manager->inspect($shared, $this->binding));
    }

    public function test_prepare_and_reconcile_observe_both_nodes_without_replaying_creation(): void
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create(['role_id' => $role->id]);
        app()->instance('request', Request::create('/admin/bind'));
        $primary = $this->transport('primary', fn ($r) => ['state' => in_array($r['operation'], ['prepare_zone', 'inspect_request'], true) ? 'committed' : 'observed', 'code' => 'OK', 'data' => ['serial' => 1, 'revision' => str_repeat('b', 64), 'records' => []]]);
        $secondary = $this->transport('secondary', fn ($r) => $r['operation'] === 'prepare_secondary' ? ['state' => 'pending_replication', 'code' => 'ZONE_PREPARED', 'data' => (object) []] : ['state' => 'observed', 'code' => 'OK', 'data' => ['serial' => 1, 'revision' => str_repeat('b', 64), 'records' => []]]);
        $manager = new ZoneManager($primary, $secondary);
        $this->assertSame('committed', $manager->prepare($admin, $this->binding)['state']);
        $op = DnsOperation::where('kind', 'prepare')->firstOrFail();
        $op->forceFill(['state' => 'uncertain'])->save();
        $this->calls = [];
        $this->assertSame('committed', $manager->reconcile($admin, $this->binding, ['request_id' => $op->id])['state']);
        $this->assertSame(['inspect_request', 'inspect', 'inspect'], array_column(array_column($this->calls, 'request'), 'operation'));
        $this->assertSame(['primary', 'primary', 'secondary'], array_column($this->calls, 'endpoint'));
    }

    public function test_record_reconciliation_requires_current_primary_even_if_secondary_matches_receipt(): void
    {
        foreach (['diverged', 'unavailable'] as $condition) {
            $id = (string) Str::uuid();
            DnsOperation::forceCreate(['id' => $id, 'binding_id' => $this->binding->id, 'actor_user_id' => $this->owner->id, 'kind' => 'replace_records', 'request_hash' => str_repeat('a', 64), 'state' => 'uncertain']);
            $primary = $this->transport('primary', function ($r) use ($condition) {
                if ($r['operation'] === 'inspect_request') {
                    return ['state' => 'committed', 'code' => 'SAVED', 'data' => ['serial' => 2, 'revision' => str_repeat('b', 64)]];
                }
                if ($condition === 'unavailable') {
                    throw new \RuntimeException('unavailable primary');
                }

                return ['state' => 'observed', 'code' => 'OK', 'data' => ['serial' => 3, 'revision' => str_repeat('c', 64), 'records' => []]];
            });
            $this->calls = [];
            $manager = new ZoneManager($primary, $this->transport('secondary'));
            $this->assertSame('uncertain', $manager->reconcile($this->owner, $this->binding, ['request_id' => $id])['state'], $condition);
            $this->assertSame(['inspect_request', 'inspect'], array_column(array_column($this->calls, 'request'), 'operation'));
            $this->assertSame(['primary', 'primary'], array_column($this->calls, 'endpoint'));
            $this->assertSame('uncertain', DnsOperation::findOrFail($id)->state);
        }
    }

    public function test_reusing_id_for_changed_payload_is_rejected(): void
    {
        $manager = new ZoneManager($this->transport('primary', fn () => ['state' => 'committed', 'code' => 'SAVED', 'data' => ['serial' => 2, 'revision' => str_repeat('b', 64)]]), $this->transport('secondary'));
        $input = $this->input();
        $manager->replace($this->owner, $this->binding, $input);
        $input['changes'][0]['records'] = ['192.0.2.20'];
        $this->expectException(\InvalidArgumentException::class);
        $manager->replace($this->owner, $this->binding, $input);
    }
}
