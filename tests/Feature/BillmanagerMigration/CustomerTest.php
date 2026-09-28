<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\Show;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Services\BillmanagerMigration\AccountAccess;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(array $overrides = []): Snapshot
    {
        $tables = array_replace([
            'accounts' => [['id' => '10', 'registration_date' => '2020-01-01 00:00:00']],
            'users' => array_map(fn ($id) => [
                'id' => (string) $id, 'account' => '10', 'enabled' => 'on', 'level' => '16',
                'email' => 'client' . $id . '@example.test', 'realname' => 'Test Client',
                'last_login' => '2025-01-01 00:00:00', 'emailverified' => 'on',
            ], [11, 12, 13]),
            'ticket_authors' => [['id' => '99', 'level' => '29', 'realname' => 'Historical Operator']],
        ], $overrides);
        $path = tempnam(sys_get_temp_dir(), 'customer-snapshot-');
        file_put_contents($path, json_encode(['schema_version' => 1, 'source_host' => '192.0.2.10',
            'login_cutoff' => '2024-01-01 00:00:00', 'captured_at_utc' => '2026-01-01T00:00:00Z', 'tables' => $tables]));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        try {
            return Snapshot::load($path, '192.0.2.10', '2024-01-01 00:00:00');
        } finally {
            unlink($path);
            unlink($path . '.sha256');
        }
    }

    private function context(): ImportContext
    {
        $id = DB::table('billmanager_imports')->insertGetId([
            'source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('a', 64), 'status' => 'running',
        ]);

        return new ImportContext($id, '192.0.2.10');
    }

    public function test_three_logins_share_one_account_without_creating_a_staff_login(): void
    {
        Bus::fake();
        $snapshot = $this->snapshot();
        $context = $this->context();
        $importer = new CustomerImporter;
        $importer->import($snapshot, $context);
        $importer->import($snapshot, $context);
        $this->assertSame(3, User::count());
        $this->assertSame(1, DB::table('billmanager_accounts')->count());
        $this->assertSame(3, DB::table('billmanager_members')->count());
        $this->assertSame(3, DB::table('billmanager_holds')->count());
        $this->assertNull($context->mappedId('users', '99'));
        $this->assertSame(0, User::whereNotNull('role_id')->count());
        Bus::assertNothingDispatched();
        $ownerId = DB::table('billmanager_accounts')->value('owner_user_id');
        $invoice = new Invoice(['user_id' => $ownerId]);
        foreach (User::all() as $member) {
            $this->assertTrue(AccountAccess::canRead($member, $invoice));
            $this->assertContains((int) $ownerId, AccountAccess::visibleOwnerIds($member));
        }
        $outsider = User::factory()->create();
        $this->assertFalse(AccountAccess::canRead($outsider, $invoice));
        $this->assertFalse(in_array((int) $ownerId, AccountAccess::visibleOwnerIds($outsider), true));
    }

    public function test_existing_identity_collision_aborts_without_overwriting_or_partial_import(): void
    {
        $existing = User::factory()->create(['email' => 'client12@example.test']);
        $password = $existing->password;
        try {
            (new CustomerImporter)->import($this->snapshot(), $this->context());
            $this->fail('Identity collision was silently merged');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('email collision', $e->getMessage());
            $this->assertSame($password, $existing->fresh()->password);
            $this->assertSame(1, User::count());
            $this->assertSame(0, DB::table('billmanager_members')->count());
        }
    }

    public function test_customer_read_policy_does_not_grant_shared_write_permissions(): void
    {
        (new CustomerImporter)->import($this->snapshot(), $this->context());
        $ownerId = DB::table('billmanager_accounts')->value('owner_user_id');
        $member = User::where('id', '!=', $ownerId)->first();
        $invoice = new Invoice(['user_id' => $ownerId]);
        $policy = new InvoicePolicy;
        $this->assertTrue($policy->view($member, $invoice));
        $this->assertFalse($policy->update($member, $invoice));
    }

    public function test_shared_member_cannot_invoke_customer_payment_action(): void
    {
        (new CustomerImporter)->import($this->snapshot(), $this->context());
        $ownerId = DB::table('billmanager_accounts')->value('owner_user_id');
        $this->actingAs(User::where('id', '!=', $ownerId)->first());
        $component = new Show;
        $component->invoice = new Invoice(['user_id' => $ownerId]);
        $this->expectException(AuthorizationException::class);
        $component->processPayment();
    }

    public function test_native_customer_list_includes_shared_account_records_only(): void
    {
        (new CustomerImporter)->import($this->snapshot(), $this->context());
        $ownerId = DB::table('billmanager_accounts')->value('owner_user_id');
        $shared = Invoice::withoutEvents(fn () => Invoice::create(['user_id' => $ownerId, 'currency_code' => 'USD', 'status' => 'paid']));
        $outsider = User::factory()->create();
        Invoice::withoutEvents(fn () => Invoice::create(['user_id' => $outsider->id, 'currency_code' => 'USD', 'status' => 'paid']));
        $this->actingAs(User::whereIn('id', DB::table('billmanager_members')->pluck('user_id'))->where('id', '!=', $ownerId)->first());
        $rows = (new Index)->render()->getData()['invoices'];
        $this->assertSame([$shared->id], $rows->pluck('id')->all());
    }
}
