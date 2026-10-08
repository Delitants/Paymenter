<?php

namespace Tests\Feature\Accounts;

use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\AccountReversalReservation;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\User;
use App\Services\Accounts\AccountWriteContext;
use App\Services\Accounts\AccountWriteGuard;
use App\Services\Accounts\OpeningAuthority;
use App\Services\Accounts\OpeningEvidence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Accounts\SyntheticOpeningAuthority;
use Tests\TestCase;

class AccountRecordsTest extends TestCase
{
    use UsesCommittedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(AccountWallet::class), 'Account wallet records are missing');
        self::assertTrue(class_exists(AccountMovement::class));
        self::assertTrue(class_exists(AccountFundingAllocation::class));
        self::assertTrue(class_exists(AccountReversalReservation::class));
        self::assertTrue(class_exists(AccountWriteContext::class));
        self::assertTrue(class_exists(AccountWriteGuard::class));
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
    }

    private function opening(): array
    {
        $owner = User::factory()->createQuietly();
        $evidence = new OpeningEvidence('synthetic', 'schema-fixture-' . $owner->id, $owner->id, 'USD', '-40.0050', '100.00', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), true);
        $authority = new SyntheticOpeningAuthority([$evidence]);
        config(['account-funding.enabled' => true]);
        app()->instance(OpeningAuthority::class, $authority);
        $context = AccountWriteContext::opening($evidence, $authority);

        return [$owner, $context];
    }

    private function denied(callable $write): void
    {
        $blocked = false;
        try {
            $write();
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(QueryException::class, $exception, 'SQL constraints do not prove write-context authorization');
            $blocked = true;
        }
        self::assertTrue($blocked, 'An unsupported account write was accepted');
    }

    private function rawWallet(User $owner): AccountWallet
    {
        // Only the immutable-record tests use a raw synthetic fixture. Opening
        // application itself is exercised through the verified context below.
        $id = DB::table('account_wallets')->insertGetId([
            'user_id' => $owner->id, 'currency_code' => 'USD',
            'opening_identity' => hash('sha256', 'synthetic-record-' . $owner->id),
            'opening_balance' => '-40.0050', 'balance' => '-40.0050',
            'borrowing_limit' => '100.0000', 'active' => true,
            'reconciliation_required' => false, 'opening_evidence' => '{}',
        ]);

        return AccountWallet::findOrFail($id);
    }

    private function rawMovement(AccountWallet $wallet): AccountMovement
    {
        $id = DB::table('account_movements')->insertGetId([
            'wallet_id' => $wallet->id, 'user_id' => $wallet->user_id, 'currency_code' => 'USD',
            'kind' => 'deposit', 'delta' => '50.0000', 'balance_before' => '-40.0050', 'balance_after' => '9.9950',
            'origin' => 'gateway', 'request_key' => 'synthetic-record-request', 'request_fingerprint' => str_repeat('d', 64),
            'reference_type' => 'invoice_paid_processing', 'reference_id' => 1, 'payload' => '{}',
        ]);

        return AccountMovement::findOrFail($id);
    }

    public function test_installation_creates_no_customer_finances_or_jobs(): void
    {
        foreach (['account_wallets', 'account_movements', 'account_funding_allocations', 'account_reversal_reservations'] as $table) {
            self::assertTrue(Schema::hasTable($table));
            self::assertSame(0, DB::table($table)->count());
        }
        self::assertSame(0, Credit::count());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_ordinary_and_quiet_wallet_creation_cannot_bypass_verified_writes(): void
    {
        [$owner, $context] = $this->opening();
        foreach ([false, true] as $quiet) {
            $this->denied(fn () => $quiet ? AccountWallet::withoutEvents(fn () => AccountWallet::create($context->openingAttributes())) : AccountWallet::create($context->openingAttributes()));
        }
        self::assertSame(0, $owner->accountWallets()->count());
        self::assertSame(0, Credit::count());
    }

    public function test_verified_opening_context_persists_only_the_approved_identity_and_exact_values(): void
    {
        [$owner, $context] = $this->opening();
        $wallet = DB::transaction(fn () => (new AccountWriteGuard)->duringVerifiedWrite($context, fn () => AccountWallet::create($context->openingAttributes())));
        self::assertSame($owner->id, $wallet->user->id);
        self::assertSame('USD', $wallet->currency->code);
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame('100.0000', $wallet->fresh()->borrowing_limit);
        self::assertSame(0, Credit::count());
        self::assertSame(0, AccountMovement::count());
    }

    public function test_an_opening_context_cannot_substitute_a_larger_balance(): void
    {
        [, $context] = $this->opening();
        try {
            $attributes = $context->openingAttributes();
        } catch (RuntimeException $e) {
            self::fail('Read-only opening attributes unexpectedly require a write transaction: ' . $e->getMessage());
        }
        $attributes['balance'] = '500.0000';
        $this->denied(fn () => DB::transaction(fn () => (new AccountWriteGuard)->duringVerifiedWrite($context, fn () => AccountWallet::create($attributes))));
        self::assertSame(0, AccountWallet::count());
        self::assertSame(0, Credit::count());
    }

    public function test_verified_context_requires_an_outer_transaction(): void
    {
        [, $context] = $this->opening();
        self::assertSame(0, DB::transactionLevel());
        $entered = false;
        $this->denied(function () use ($context, &$entered) {
            (new AccountWriteGuard)->duringVerifiedWrite($context, function () use (&$entered) {
                $entered = true;
            });
        });
        self::assertFalse($entered);
        self::assertSame(0, AccountWallet::count());
    }

    public function test_a_true_flag_and_caller_authority_cannot_replace_the_bound_release_authority(): void
    {
        [, $context] = $this->opening();
        app()->forgetInstance(OpeningAuthority::class);
        $entered = false;
        $this->denied(fn () => DB::transaction(function () use ($context, &$entered) {
            (new AccountWriteGuard)->duringVerifiedWrite($context, function () use (&$entered) {
                $entered = true;
            });
        }));
        self::assertFalse($entered);
        self::assertSame(0, AccountWallet::count());
    }

    public function test_a_hold_added_after_context_entry_is_checked_at_the_record_write(): void
    {
        [$owner, $context] = $this->opening();
        DB::transaction(function () use ($owner, $context) {
            $this->denied(fn () => (new AccountWriteGuard)->duringVerifiedWrite($context, function () use ($owner, $context) {
                DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'synthetic hold']);
                AccountWallet::create($context->openingAttributes());
            }));
            self::assertSame(0, AccountWallet::count());
            self::assertSame(1, DB::table('billmanager_holds')->count());
        });
    }

    public function test_opening_context_cannot_create_a_movement_or_funding_receipt(): void
    {
        [$owner, $context] = $this->opening();
        $wallet = $this->rawWallet($owner);
        foreach ([AccountMovement::class, AccountFundingAllocation::class, AccountReversalReservation::class] as $class) {
            $this->denied(fn () => DB::transaction(fn () => (new AccountWriteGuard)->duringVerifiedWrite($context, fn () => $class::create(['wallet_id' => $wallet->id, 'user_id' => $owner->id, 'currency_code' => 'USD']))));
            self::assertSame(0, $class::count());
        }
    }

    public function test_journal_update_and_delete_are_rejected_even_without_model_events(): void
    {
        [$owner] = $this->opening();
        $movement = $this->rawMovement($this->rawWallet($owner));
        $this->denied(fn () => AccountMovement::withoutEvents(fn () => $movement->update(['delta' => '60.0000'])));
        $this->denied(fn () => AccountMovement::withoutEvents(fn () => $movement->fresh()->delete()));
        self::assertSame('50.0000', $movement->fresh()->delta);
        self::assertSame(1, AccountMovement::count());
    }

    public function test_wallet_reassignment_recurrencying_balance_and_limit_edits_are_rejected(): void
    {
        [$owner] = $this->opening();
        $wallet = $this->rawWallet($owner);
        $other = User::factory()->createQuietly();
        foreach ([['user_id' => $other->id], ['currency_code' => 'EUR'], ['balance' => '500.0000'], ['borrowing_limit' => '200.0000'], ['active' => false]] as $attributes) {
            $this->denied(fn () => $wallet->fresh()->update($attributes));
        }
        self::assertSame($owner->id, $wallet->fresh()->user_id);
        self::assertSame('USD', $wallet->fresh()->currency_code);
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame('100.0000', $wallet->fresh()->borrowing_limit);
        self::assertTrue($wallet->fresh()->active);
    }

    public function test_wallet_owner_and_currency_foreign_keys_prevent_destructive_deletion(): void
    {
        [$owner] = $this->opening();
        $wallet = $this->rawWallet($owner);
        foreach ([fn () => DB::table('users')->where('id', $owner->id)->delete(), fn () => DB::table('currencies')->where('code', 'USD')->delete()] as $delete) {
            try {
                $delete();
                self::fail('A wallet parent was destructively deleted');
            } catch (QueryException) {
                self::assertSame(1, AccountWallet::count());
            }
        }
        self::assertNotNull($wallet->fresh()->user);
        self::assertNotNull(Currency::find('USD'));
    }

    public function test_journal_foreign_key_prevents_deleting_its_wallet(): void
    {
        [$owner] = $this->opening();
        $wallet = $this->rawWallet($owner);
        $movement = $this->rawMovement($wallet);
        try {
            DB::table('account_wallets')->where('id', $wallet->id)->delete();
            self::fail('The journal lost its wallet identity');
        } catch (QueryException) {
            self::assertSame($wallet->id, $movement->fresh()->wallet_id);
            self::assertSame(1, AccountWallet::count());
        }
    }
}
