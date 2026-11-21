<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Admin\Resources\BillmanagerRecordResource;
use App\Models\BillmanagerRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\BillmanagerMigration\ImportContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ArchiveAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_archive_never_exposes_internal_notes_to_customers_or_unrelated_staff_roles(): void
    {
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('a', 64), 'status' => 'running']);
        $context = new ImportContext($id, '192.0.2.10');
        $context->archive('ticket_notes', '1', ['note' => '<script>private synthetic note</script>'], 10);
        $context->archive('payments', '2', ['amount' => '1.2345'], 10);
        $note = BillmanagerRecord::where('source_table', 'ticket_notes')->firstOrFail();
        $payment = BillmanagerRecord::where('source_table', 'payments')->firstOrFail();
        $client = User::factory()->create();
        $role = Role::create(['name' => 'Support archive test', 'permissions' => ['admin.tickets.view']]);
        $support = User::factory()->create(['role_id' => $role->id]);
        $this->assertFalse(Gate::forUser($client)->allows('view', $note));
        $this->assertTrue(Gate::forUser($support)->allows('view', $note));
        $this->assertFalse(Gate::forUser($support)->allows('view', $payment));
        $this->assertSame('<script>private synthetic note</script>', $note->payload['note']);
        $this->assertArrayNotHasKey('payload', $note->toArray());
        $this->actingAs($support);
        $this->assertSame(['ticket_notes'], BillmanagerRecordResource::getEloquentQuery()->pluck('source_table')->all());
        $this->assertFalse(BillmanagerRecordResource::canCreate());
    }
}
