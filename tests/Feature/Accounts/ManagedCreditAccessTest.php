<?php

namespace Tests\Feature\Accounts;

use App\Admin\Resources\UserResource\Pages\ShowCredits;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use App\Policies\CurrencyPolicy;
use App\Policies\UserPolicy;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\UsesAccountWallet;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

class ManagedCreditAccessTest extends TestCase
{
    use UsesAccountWallet,UsesCommittedDatabase;

    private function fixture(): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $role = Role::create(['name' => 'Synthetic cash editor', 'permissions' => ['*']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        ApiKey::create(['name' => 'Synthetic native cash API', 'token' => hash('sha256', 'synthetic-managed-cash-key'), 'type' => 'admin', 'enabled' => true, 'permissions' => ['admin.credits.create', 'admin.credits.update', 'admin.credits.delete']]);
        $this->withToken('synthetic-managed-cash-key');

        return [$actor, $owner, $wallet, Credit::sole()];
    }

    public function test_native_credit_api_update_denies_managed_projection_with_clear_response(): void
    {
        [,$owner,$wallet,$credit] = $this->fixture();
        $this->putJson('/api/v1/admin/credits/' . $credit->id, ['amount' => '25.00'])->assertStatus(409);
        self::assertSame('10.00', $credit->fresh()->amount);
        self::assertSame('10.0000', $wallet->fresh()->balance);
    }

    public function test_native_credit_api_delete_denies_managed_projection_with_clear_response(): void
    {
        [,, $wallet,$credit] = $this->fixture();
        $this->deleteJson('/api/v1/admin/credits/' . $credit->id)->assertStatus(409);
        self::assertSame('10.00', $credit->fresh()->amount);
        self::assertSame('10.0000', $wallet->fresh()->balance);
    }

    public function test_native_credit_api_create_cannot_repair_missing_managed_cash_by_free_amount(): void
    {
        [,$owner,$wallet,$credit] = $this->fixture();
        DB::table('credits')->where('id', $credit->id)->delete();
        $this->postJson('/api/v1/admin/credits', ['user_id' => $owner->id, 'currency_code' => 'USD', 'amount' => '25.00'])->assertStatus(409);
        self::assertSame(0, Credit::count());
        self::assertSame('10.0000', $wallet->fresh()->balance);
    }

    public function test_unmanaged_cash_retains_native_api_create_update_and_delete(): void
    {
        $this->fixture();
        $owner = User::factory()->createQuietly();
        $this->postJson('/api/v1/admin/credits', ['user_id' => $owner->id, 'currency_code' => 'USD', 'amount' => '3.00'])->assertSuccessful();
        $credit = $owner->credits()->sole();
        $this->putJson('/api/v1/admin/credits/' . $credit->id, ['amount' => '4.00'])->assertSuccessful();
        self::assertSame('4.00', $credit->fresh()->amount);
        $this->deleteJson('/api/v1/admin/credits/' . $credit->id)->assertNoContent();
    }

    public function test_native_inline_cash_edit_is_disabled_and_direct_component_call_has_no_effect(): void
    {
        [,$owner,$wallet,$credit] = $this->fixture();
        $view = Livewire::test(ShowCredits::class, ['record' => (string) $owner->id]);
        $view->assertTableColumnExists('amount', fn ($column) => $column->isDisabled(), $credit)
            ->call('updateTableColumnState', 'amount', (string) $credit->id, '25.00')->assertTableActionHidden('delete', $credit);
        self::assertSame('10.00', $credit->fresh()->amount);
        self::assertSame('10.0000', $wallet->fresh()->balance);
    }

    public function test_native_create_action_cannot_replace_missing_managed_cash(): void
    {
        [,$owner,$wallet,$credit] = $this->fixture();
        DB::table('credits')->where('id', $credit->id)->delete();
        $view = Livewire::test(ShowCredits::class, ['record' => (string) $owner->id]);
        $view->assertTableActionDisabled('create')->callTableAction('create', data: ['currency_code' => 'USD', 'amount' => '25.00']);
        self::assertSame(0, Credit::count());
        self::assertSame('10.0000', $wallet->fresh()->balance);
    }

    public function test_direct_native_parent_deletion_retains_opening_and_cash_even_without_invoice(): void
    {
        [,$owner,$wallet,$credit] = $this->fixture();
        foreach ([$owner, Currency::findOrFail('USD')] as $record) {
            $denied = false;
            try {
                $record->deleteQuietly();
            } catch (\RuntimeException|QueryException) {
                $denied = true;
            }
            self::assertTrue($denied, 'Native parent deletion removed managed opening evidence');
        }
        self::assertNotNull(User::find($owner->id));
        self::assertNotNull(Currency::find('USD'));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame('10.00', $credit->fresh()->amount);
    }

    public function test_currency_and_owner_policies_deny_removing_managed_opening_evidence(): void
    {
        [$actor,$owner,,$credit] = $this->fixture();
        self::assertFalse((new CurrencyPolicy)->delete($actor, Currency::findOrFail('USD')));
        self::assertFalse((new UserPolicy)->delete($actor, $owner));
        self::assertSame('10.00', $credit->fresh()->amount);
    }

    public function test_statement_action_opens_managed_owner_currency_with_dedicated_permission(): void
    {
        [,$owner,,$credit] = $this->fixture();
        $view = Livewire::test(ShowCredits::class, ['record' => (string) $owner->id]);
        self::assertArrayHasKey('statement', $view->instance()->getTable()->getFlatRecordActions());
        $view->assertTableActionVisible('statement', $credit)
            ->assertSee(route('account.funding', ['owner' => $owner->id, 'currency' => 'USD']), false);
    }

    public function test_statement_action_is_hidden_without_dedicated_read_permission(): void
    {
        [$actor,$owner,,$credit] = $this->fixture();
        $actor->role->update(['permissions' => ['admin.users.viewAny', 'admin.users.view', 'admin.users.update']]);
        $view = Livewire::actingAs($actor->fresh())->test(ShowCredits::class, ['record' => (string) $owner->id]);
        self::assertArrayHasKey('statement', $view->instance()->getTable()->getFlatRecordActions());
        $view->assertTableActionHidden('statement', $credit);
    }
}
