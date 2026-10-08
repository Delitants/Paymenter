<?php

namespace Tests\Feature\Accounts;

use App\Livewire\Client\AccountFundingStatement;
use App\Models\AccountMovement;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\AccountFundingReversals;
use App\Services\Accounts\AccountStatement;
use App\Services\Accounts\InvoiceFunding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\UsesAccountWallet;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

class AccountStatementTest extends TestCase
{
    use UsesAccountWallet,UsesCommittedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        self::assertTrue(class_exists(AccountStatement::class), 'Missing native read-only account statement contract');
    }

    private function fixture(string $opening = '-40.0050'): array
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, $opening, '100.00');
        $this->actingAs($owner);

        return [$owner, $wallet];
    }

    private function shared(User $owner): User
    {
        $member = User::factory()->createQuietly();
        $id = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 51001, 'owner_user_id' => $owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $id, 'user_id' => $member->id, 'source_user_id' => 51002]);

        return $member;
    }

    private function statement(User $actor, User $owner): array
    {
        $this->actingAs($actor);
        try {
            return (new AccountStatement)->forReader($actor, $owner, 'USD');
        } catch (\RuntimeException $e) {
            self::fail('Native account statement failed: ' . $e->getMessage());
        }
    }

    public function test_owner_statement_separates_exact_debt_limit_allowance_and_cent_availability_without_private_opening(): void
    {
        [$owner] = $this->fixture();
        $data = $this->statement($owner, $owner);
        self::assertSame('40.0050', $data['quote']->debt);
        self::assertSame('100.0000', $data['quote']->borrowingLimit);
        self::assertSame('59.9950', $data['quote']->remainingAllowance);
        self::assertSame('59.99', $data['quote']->fundingAvailable);
        self::assertSame('0.00', $data['cash']);
        self::assertFalse($data['administrative']);
        self::assertNull($data['opening']);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('wallet-fixture-', $json);
        self::assertStringNotContainsString(str_repeat('c', 64), $json);
        self::assertSame(0, AccountMovement::count());
        self::assertSame('0.00', Credit::sole()->amount);
        Http::assertNothingSent();
    }

    public function test_shared_history_reader_sees_statement_but_never_payment_controls(): void
    {
        [$owner] = $this->fixture();
        $member = $this->shared($owner);
        $data = $this->statement($member, $owner);
        self::assertSame('40.0050', $data['quote']->debt);
        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_unrelated_reader_and_revoked_membership_cannot_read_private_statement(): void
    {
        [$owner] = $this->fixture();
        $member = $this->shared($owner);
        $this->statement($member, $owner);
        DB::table('billmanager_members')->where('user_id', $member->id)->delete();
        foreach ([$member, User::factory()->createQuietly()] as $reader) {
            $this->actingAs($reader);
            $denied = false;
            try {
                (new AccountStatement)->forReader($reader, $owner, 'USD');
            } catch (AuthorizationException) {
                $denied = true;
            }self::assertTrue($denied);
        }
        self::assertSame(0, AccountMovement::count());
    }

    public function test_disabled_facility_retains_readable_cash_and_history_while_available_funding_is_zero(): void
    {
        [$owner] = $this->fixture('10.00');
        config(['account-funding.enabled' => false]);
        $data = $this->statement($owner, $owner);
        self::assertSame('10.00', $data['cash']);
        self::assertTrue($data['quote']->blocked);
        self::assertSame('0.00', $data['quote']->fundingAvailable);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_statement_retains_original_internal_payment_and_linked_reversal_as_account_receipts(): void
    {
        [$owner,$wallet] = $this->fixture('10.00');
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $invoice->items()->create(['description' => 'Synthetic statement product', 'price' => '15.00', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', (string) Str::uuid());
        $role = Role::create(['name' => 'Synthetic statement reverser', 'permissions' => ['admin.invoice_transactions.account_reverse']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);
        (new AccountFundingReversals)->reverse($actor, $allocation, '5.00', 'Synthetic private correction', (string) Str::uuid());
        $data = $this->statement($owner, $owner);
        self::assertSame('0.0000', $data['quote']->balance);
        self::assertSame(2, $data['movements']->total());
        $json = json_encode($data['movements']->items(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Account payment', $json);
        self::assertStringContainsString('Account payment reversal', $json);
        self::assertStringNotContainsString('Synthetic private correction', $json);
        self::assertStringNotContainsString('movement_id', $json);
        self::assertStringNotContainsString('invoice_transaction_id', $json);
        self::assertSame(2, AccountMovement::count());
        self::assertSame(1, $invoice->transactions()->count());
        Http::assertNothingSent();
    }

    public function test_statement_paginates_immutable_receipts_without_changing_signed_history(): void
    {
        [$owner,$wallet] = $this->fixture('100.00');
        for ($i = 0; $i < 26; $i++) {
            $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
            $invoice->items()->create(['description' => 'Synthetic page product', 'price' => '1.00', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);
            (new InvoiceFunding)->fund($owner, $invoice, '1.00', (string) Str::uuid());
        }
        $data = $this->statement($owner, $owner);
        self::assertSame(26, $data['movements']->total());
        self::assertCount(25, $data['movements']->items());
        self::assertSame(2, $data['movements']->lastPage());
        self::assertSame('74.0000', $data['quote']->balance);
        self::assertSame('74.0000', $wallet->fresh()->balance);
        self::assertSame(26, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_admin_lineage_requires_current_dedicated_statement_permission(): void
    {
        [$owner] = $this->fixture();
        $role = Role::create(['name' => 'Synthetic statement reader', 'permissions' => ['admin.account_funding.view']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $data = $this->statement($actor, $owner);
        self::assertTrue($data['administrative']);
        self::assertSame(str_repeat('c', 64), $data['opening']['source']['grantHash']);
        $role->update(['permissions' => ['admin.users.update']]);
        $this->actingAs($actor);
        $denied = false;
        try {
            (new AccountStatement)->forReader($actor, $owner, 'USD');
        } catch (AuthorizationException) {
            $denied = true;
        }self::assertTrue($denied);
    }

    public function test_native_statement_rejects_an_unrelated_reader(): void
    {
        [$owner] = $this->fixture();
        $stranger = User::factory()->createQuietly();
        self::assertTrue(class_exists(AccountFundingStatement::class), 'Missing native account statement component');

        Livewire::actingAs($stranger)->test(AccountFundingStatement::class, ['owner' => $owner->id])
            ->assertForbidden();

        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_native_statement_rechecks_shared_membership_after_initial_render(): void
    {
        [$owner] = $this->fixture();
        $member = $this->shared($owner);
        self::assertTrue(class_exists(AccountFundingStatement::class), 'Missing native account statement component');
        $component = Livewire::actingAs($member)->test(AccountFundingStatement::class, ['owner' => $owner->id])
            ->assertSee('40.0050');

        DB::table('billmanager_members')->where('user_id', $member->id)->delete();
        $component->call('$refresh')->assertForbidden();

        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_native_statement_owner_identity_cannot_be_changed_by_a_livewire_payload(): void
    {
        [$owner] = $this->fixture();
        $stranger = User::factory()->createQuietly();
        self::assertTrue(class_exists(AccountFundingStatement::class), 'Missing native account statement component');
        $component = Livewire::actingAs($owner)->test(AccountFundingStatement::class, ['owner' => $owner->id]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('owner', $stranger->id);
    }

    public function test_native_statement_rechecks_dedicated_admin_permission_after_initial_render(): void
    {
        [$owner] = $this->fixture();
        $role = Role::create(['name' => 'Synthetic native statement reader', 'permissions' => ['admin.account_funding.view']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        self::assertTrue(class_exists(AccountFundingStatement::class), 'Missing native account statement component');
        $component = Livewire::actingAs($actor)->test(AccountFundingStatement::class, ['owner' => $owner->id])
            ->assertSee('Opening lineage')->assertSee(str_repeat('c', 64));

        $role->update(['permissions' => ['admin.users.update']]);
        $component->call('$refresh')->assertForbidden();

        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_native_shared_statement_renders_separate_labels_without_spending_or_private_source_controls(): void
    {
        [$owner] = $this->fixture();
        $member = $this->shared($owner);
        self::assertTrue(class_exists(AccountFundingStatement::class), 'Missing native account statement component');
        Livewire::actingAs($member)->test(AccountFundingStatement::class, ['owner' => $owner->id])
            ->assertSee('Outstanding debt')->assertSee('40.0050')->assertSee('Borrowing limit')->assertSee('Reserved refunds')->assertSee('Fractional-cent remainder')
            ->assertDontSee('Pay with account funds')->assertDontSee('wallet-fixture-')->assertDontSee(str_repeat('c', 64));
        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_native_disabled_statement_is_readable_without_new_funding_controls(): void
    {
        [$owner] = $this->fixture('10.00');
        config(['account-funding.enabled' => false]);
        self::assertTrue(class_exists(AccountFundingStatement::class), 'Missing native account statement component');
        Livewire::actingAs($owner)->test(AccountFundingStatement::class, ['owner' => $owner->id])->assertSee('10.00')->assertSee('Account funding is unavailable')->assertDontSee('Pay with account funds');
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }
}
