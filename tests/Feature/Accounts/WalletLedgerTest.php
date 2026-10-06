<?php

namespace Tests\Feature\Accounts;

use App\Models\AccountMovement;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Accounts\AccountWriteContext;
use App\Services\Accounts\AccountWriteGuard;
use App\Services\Accounts\OpeningAuthority;
use App\Services\Accounts\OpeningEvidence;
use App\Services\Accounts\WalletLedger;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesVerifiedDeposit;
use Tests\Fixtures\Accounts\SyntheticOpeningAuthority;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
{
    use UsesCommittedDatabase, UsesVerifiedDeposit;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(WalletLedger::class), 'Wallet ledger contract is missing');
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
    }

    private function evidence(User $owner, string $opening = '-40.0050', string $limit = '100.00', bool $active = true, string $snapshot = 'a'): OpeningEvidence
    {
        return new OpeningEvidence('synthetic', 'ledger-fixture-' . $owner->id, $owner->id, 'USD', $opening, $limit, str_repeat($snapshot, 64), str_repeat('b', 64), str_repeat('c', 64), $active);
    }

    private function register(OpeningEvidence ...$evidence): SyntheticOpeningAuthority
    {
        $authority = new SyntheticOpeningAuthority($evidence);
        config(['account-funding.enabled' => true]);
        app()->instance(OpeningAuthority::class, $authority);

        return $authority;
    }

    private function rejected(callable $write, string $reason): void
    {
        $blocked = false;
        try {
            $write();
        } catch (AssertionFailedError|QueryException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $blocked = true;
            self::assertStringContainsString($reason, strtolower($exception->getMessage()));
        }
        self::assertTrue($blocked, 'An invalid wallet transition was accepted');
    }

    public function test_fractional_opening_debt_preserves_limit_without_creating_negative_cash(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $quote = (new WalletLedger)->quote($owner, 'USD');
        self::assertSame('-40.0050', $wallet->balance);
        self::assertSame('100.0000', $wallet->borrowing_limit);
        self::assertSame('40.0050', $quote->debt);
        self::assertSame('59.9950', $quote->remainingAllowance);
        self::assertSame('59.99', $quote->fundingAvailable);
        self::assertSame('0.00', $quote->cashAvailable);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertFalse($quote->blocked);
        self::assertSame(0, AccountMovement::count());
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_positive_opening_cash_uses_half_up_and_retains_exact_source_and_rounding_delta(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner, '1.0050', '0.00');
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        self::assertSame('1.0100', $wallet->opening_balance);
        self::assertSame('1.0100', $wallet->balance);
        self::assertSame('1.01', Credit::sole()->amount);
        self::assertSame('1.0050', $wallet->opening_evidence['source']['opening']);
        self::assertSame('0.0050', $wallet->opening_evidence['rounding_delta']);
        self::assertSame('1.01', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
    }

    public function test_exact_opening_replay_returns_the_same_wallet_and_projection(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $authority = $this->register($evidence);
        $first = (new WalletLedger)->initialize($evidence, $authority);
        $again = (new WalletLedger)->initialize($evidence, $authority);
        self::assertSame($first->id, $again->id);
        self::assertSame(1, AccountWallet::count());
        self::assertSame(1, Credit::count());
        self::assertSame(0, AccountMovement::count());
    }

    public function test_a_changed_capture_cannot_apply_a_second_opening(): void
    {
        $owner = User::factory()->createQuietly();
        $first = $this->evidence($owner);
        $changed = $this->evidence($owner, snapshot: 'd');
        $authority = $this->register($first, $changed);
        (new WalletLedger)->initialize($first, $authority);
        $this->rejected(fn () => (new WalletLedger)->initialize($changed, $authority), 'opening');
        self::assertSame(1, AccountWallet::count());
        self::assertSame('-40.0050', AccountWallet::sole()->balance);
        self::assertSame(1, Credit::count());
    }

    public function test_same_source_account_cannot_be_opened_for_another_native_owner(): void
    {
        $owner = User::factory()->createQuietly();
        $other = User::factory()->createQuietly();
        $first = $this->evidence($owner);
        $moved = new OpeningEvidence($first->sourceSystem, $first->sourceAccount, $other->id, 'USD', '-40.0050', '100.00', $first->snapshotHash, $first->policyHash, $first->grantHash, true);
        $authority = $this->register($first, $moved);
        (new WalletLedger)->initialize($first, $authority);
        $this->rejected(fn () => (new WalletLedger)->initialize($moved, $authority), 'identity');
        self::assertSame($owner->id, AccountWallet::sole()->user_id);
        self::assertSame(0, $other->credits()->count());
    }

    public function test_nonzero_unmatched_cash_is_retained_and_blocks_initialization(): void
    {
        $owner = User::factory()->createQuietly();
        Credit::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'amount' => '5.00']);
        $evidence = $this->evidence($owner);
        $this->rejected(fn () => (new WalletLedger)->initialize($evidence, $this->register($evidence)), 'cash');
        self::assertSame(0, AccountWallet::count());
        self::assertSame('5.00', Credit::sole()->amount);
    }

    public function test_duplicate_zero_credit_rows_are_not_silently_merged(): void
    {
        $owner = User::factory()->createQuietly();
        foreach ([1, 2] as $unused) {
            Credit::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'amount' => '0.00']);
        }
        $evidence = $this->evidence($owner);
        $this->rejected(fn () => (new WalletLedger)->initialize($evidence, $this->register($evidence)), 'duplicate');
        self::assertSame(0, AccountWallet::count());
        self::assertSame(2, Credit::count());
    }

    public function test_a_single_zero_credit_row_can_become_the_verified_projection(): void
    {
        $owner = User::factory()->createQuietly();
        $cash = Credit::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'amount' => '0.00']);
        $evidence = $this->evidence($owner, '1.0050');
        (new WalletLedger)->initialize($evidence, $this->register($evidence));
        self::assertSame(1, Credit::count());
        self::assertSame($cash->id, Credit::sole()->id);
        self::assertSame('1.01', $cash->fresh()->amount);
    }

    public function test_unmanaged_accounts_keep_cash_and_return_no_wallet_quote(): void
    {
        $owner = User::factory()->createQuietly();
        Credit::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'amount' => '5.00']);
        self::assertNull((new WalletLedger)->quote($owner, 'USD'));
        self::assertSame('5.00', Credit::sole()->amount);
        self::assertSame(0, AccountWallet::count());
    }

    public function test_feature_flag_alone_cannot_initialize_customer_finances(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        config(['account-funding.enabled' => true]);
        $this->rejected(fn () => (new WalletLedger)->initialize($evidence, new SyntheticOpeningAuthority([$evidence])), 'authority');
        self::assertSame(0, AccountWallet::count());
        self::assertSame(0, Credit::count());
    }

    public function test_held_owner_cannot_be_initialized(): void
    {
        $owner = User::factory()->createQuietly();
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'synthetic hold']);
        $evidence = $this->evidence($owner);
        $this->rejected(fn () => (new WalletLedger)->initialize($evidence, $this->register($evidence)), 'hold');
        self::assertSame(0, AccountWallet::count());
        self::assertSame(0, Credit::count());
    }

    public function test_inactive_wallet_has_no_spending_capacity(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner, active: false);
        (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $quote = (new WalletLedger)->quote($owner, 'USD');
        self::assertTrue($quote->blocked);
        self::assertSame('0.00', $quote->fundingAvailable);
        self::assertSame('40.0050', $quote->debt);
    }

    public function test_hold_or_disabled_feature_blocks_spending_without_rewriting_cash_or_history(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        (new WalletLedger)->initialize($evidence, $this->register($evidence));
        config(['account-funding.enabled' => false]);
        self::assertTrue((new WalletLedger)->quote($owner, 'USD')->blocked);
        config(['account-funding.enabled' => true]);
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'synthetic hold']);
        $quote = (new WalletLedger)->quote($owner, 'USD');
        self::assertTrue($quote->blocked);
        self::assertSame('0.00', $quote->fundingAvailable);
        self::assertSame('-40.0050', AccountWallet::sole()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_projection_drift_blocks_spending_and_is_not_silently_repaired(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner, '10.00');
        (new WalletLedger)->initialize($evidence, $this->register($evidence));
        DB::table('credits')->where('user_id', $owner->id)->update(['amount' => '9.00']);
        $quote = (new WalletLedger)->quote($owner, 'USD');
        self::assertTrue($quote->blocked);
        self::assertSame('0.00', $quote->fundingAvailable);
        self::assertSame('9.00', Credit::sole()->amount);
        self::assertSame('10.0000', AccountWallet::sole()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_opening_and_projection_roll_back_together(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner, '10.00');
        $authority = $this->register($evidence);
        $this->rejected(fn () => DB::transaction(function () use ($evidence, $authority) {
            (new WalletLedger)->initialize($evidence, $authority);
            self::assertSame(1, AccountWallet::count());
            self::assertSame(1, Credit::count());
            throw new RuntimeException('synthetic rollback');
        }), 'rollback');
        self::assertSame(0, AccountWallet::count());
        self::assertSame(0, Credit::count());
        self::assertSame(0, AccountMovement::count());
    }

    public function test_wallet_balance_drift_from_its_opening_blocks_funding_without_an_automatic_correction(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner, '10.00');
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        DB::table('account_wallets')->where('id', $wallet->id)->update(['balance' => '20.0000']);
        $quote = (new WalletLedger)->quote($owner, 'USD');
        self::assertTrue($quote->blocked);
        self::assertSame('0.00', $quote->fundingAvailable);
        self::assertSame('20.0000', $wallet->fresh()->balance);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_managed_cash_creation_edit_and_delete_cannot_bypass_projection_authority(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner, '10.00');
        (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $cash = Credit::sole();
        foreach ([
            fn () => Credit::withoutEvents(fn () => Credit::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'amount' => '20.00'])),
            fn () => Credit::withoutEvents(fn () => $cash->fresh()->update(['amount' => '20.00'])),
            fn () => Credit::withoutEvents(fn () => $cash->fresh()->delete()),
        ] as $write) {
            $this->rejected($write, 'projection');
        }
        self::assertSame(1, Credit::count());
        self::assertSame('10.00', $cash->fresh()->amount);
        self::assertSame('10.0000', AccountWallet::sole()->balance);
    }

    public function test_an_opening_context_cannot_be_used_to_post_an_unbacked_movement(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner, '10.00');
        $authority = $this->register($evidence);
        (new WalletLedger)->initialize($evidence, $authority);
        $context = AccountWriteContext::opening($evidence, $authority);
        $this->rejected(fn () => (new WalletLedger)->post($context, '50.00', 'deposit', 'synthetic-unbacked', str_repeat('d', 64)), 'receipt');
        self::assertSame('10.0000', AccountWallet::sole()->balance);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    private function paidDeposit(User $owner, string $origin = 'native'): InvoicePaidProcessing
    {
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'status' => 'pending', 'currency_code' => 'USD', 'pricing_tax_rate' => '0.0000']);
        $gateway = $this->depositGateway();
        $invoice->items()->create(['description' => 'Synthetic deposit principal', 'price' => '50.00', 'quantity' => 1, 'tax_amount' => '0.00', 'kind' => 'credit_allocation', 'reference_type' => Credit::class]);
        $invoice->items()->create(['description' => 'Synthetic gateway fee', 'price' => '2.00', 'quantity' => 1, 'tax_amount' => '0.00', 'kind' => 'gateway_fee', 'gateway_id' => $gateway->id]);
        // Suppress only paid lifecycle; retain actual verified settlement and native allocation.
        $this->verifiedDeposit($invoice, $gateway, 'synthetic-ledger-' . $invoice->id, false);
        self::assertSame(['net' => '50.00', 'tax' => '0.00', 'fee' => '2.00'], $invoice->transactions()->sole()->original_allocation);

        return InvoicePaidProcessing::create(['invoice_id' => $invoice->id, 'origin' => $origin, 'processed_at' => now()]);
    }

    private function postDeposit(InvoicePaidProcessing $receipt): AccountMovement
    {
        $context = AccountWriteContext::paidDeposit($receipt);
        $key = 'synthetic-deposit:' . $receipt->invoice_id;

        try {
            return (new WalletLedger)->post($context, '50.00', 'deposit', $key, $context->fingerprint($key));
        } catch (DomainException $exception) {
            self::fail('Verified native deposit principal must remain valid as an exact journal delta: ' . $exception->getMessage());
        }
    }

    public function test_proven_deposit_repays_fractional_debt_and_excludes_all_fees_from_cash(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner);
        $movement = $this->postDeposit($receipt);
        $quote = (new WalletLedger)->quote($owner, 'USD');
        self::assertSame('9.9950', $wallet->fresh()->balance);
        self::assertSame('9.99', Credit::sole()->amount);
        self::assertSame('0.0050', $quote->residual);
        self::assertSame('109.99', $quote->fundingAvailable);
        self::assertSame('50.0000', $movement->delta);
        self::assertSame('-40.0050', $movement->balance_before);
        self::assertSame('9.9950', $movement->balance_after);
        self::assertSame([['transaction_id' => InvoiceTransaction::sole()->id, 'principal' => '50.0000', 'fee' => '2.00']], $movement->payload['deposit_slices']);
        self::assertSame(1, AccountMovement::count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_same_deposit_receipt_and_request_replay_never_repay_debt_twice(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner);
        $first = $this->postDeposit($receipt);
        $again = $this->postDeposit($receipt);
        self::assertSame($first->id, $again->id);
        self::assertSame(1, AccountMovement::count());
        self::assertSame('9.9950', $wallet->fresh()->balance);
        self::assertSame('9.99', Credit::sole()->amount);
    }

    public function test_exact_deposit_replay_at_money_ceiling_does_not_add_principal_again(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner, '999999999999949.00', '0.00');
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner);
        $first = $this->postDeposit($receipt);
        $failure = null;
        $again = null;
        try {
            $again = $this->postDeposit($receipt);
        } catch (DomainException $exception) {
            $failure = $exception->getMessage();
        }
        self::assertNull($failure, 'An exact replay attempted to add the principal a second time: ' . $failure);
        self::assertSame($first->id, $again->id);
        self::assertSame('999999999999999.0000', $wallet->fresh()->balance);
        self::assertSame('999999999999999.00', Credit::sole()->amount);
        self::assertSame(1, AccountMovement::count());
    }

    public function test_exact_inactive_opening_replay_does_not_reactivate_or_change_cash(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner, '10.00', '100.00', false);
        $authority = $this->register($evidence);
        $wallet = (new WalletLedger)->initialize($evidence, $authority);
        $failure = null;
        $again = null;
        try {
            $again = (new WalletLedger)->initialize($evidence, $authority);
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }
        self::assertNull($failure, 'An exact opening receipt replay was refused: ' . $failure);
        self::assertSame($wallet->id, $again->id);
        self::assertFalse($again->active);
        self::assertTrue((new WalletLedger)->quote($owner, 'USD')->blocked);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_changed_deposit_amount_kind_or_request_fingerprint_is_rejected_without_writes(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner);
        $context = AccountWriteContext::paidDeposit($receipt);
        $key = 'synthetic-deposit:' . $receipt->invoice_id;
        foreach ([['40.00', 'deposit', $context->fingerprint($key)], ['50.00', 'downgrade', $context->fingerprint($key)], ['50.00', 'deposit', str_repeat('d', 64)]] as [$amount, $kind, $fingerprint]) {
            $this->rejected(fn () => (new WalletLedger)->post($context, $amount, $kind, $key, $fingerprint), 'receipt');
        }
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_legacy_paid_processing_cannot_be_presented_as_a_new_deposit(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner, 'legacy');
        $this->rejected(fn () => $this->postDeposit($receipt), 'receipt');
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_a_deposit_without_an_immutable_original_allocation_is_not_credited(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner);
        DB::table('invoice_transactions')->where('invoice_id', $receipt->invoice_id)->update(['original_allocation' => null]);
        $this->rejected(fn () => $this->postDeposit($receipt), 'receipt');
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_a_source_receipt_changed_after_context_capture_cannot_redirect_a_deposit(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner);
        $context = AccountWriteContext::paidDeposit($receipt);
        $key = 'synthetic-deposit:' . $receipt->invoice_id;
        DB::table('invoice_transactions')->where('invoice_id', $receipt->invoice_id)->update(['transaction_id' => 'changed-synthetic-original']);
        $this->rejected(fn () => (new WalletLedger)->post($context, '50.00', 'deposit', $key, $context->fingerprint($key)), 'receipt');
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_native_deposit_scope_rejects_mixed_service_items_without_any_cash_change(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner);
        DB::table('invoice_items')->insert(['invoice_id' => $receipt->invoice_id, 'description' => 'Synthetic mixed product', 'price' => '5.00', 'tax_amount' => '0.00', 'kind' => 'product', 'quantity' => 1]);
        $this->rejected(fn () => $this->postDeposit($receipt), 'receipt');
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_posted_movement_wallet_and_projection_roll_back_as_one_unit(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner);
        $this->rejected(fn () => DB::transaction(function () use ($receipt) {
            $this->postDeposit($receipt);
            self::assertSame(1, AccountMovement::count());
            self::assertSame('9.99', Credit::sole()->amount);
            throw new RuntimeException('synthetic movement rollback');
        }), 'rollback');
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_paid_processing_origin_and_time_cannot_be_relabelled_even_without_events(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner, 'legacy');
        $this->rejected(fn () => InvoicePaidProcessing::withoutEvents(fn () => $receipt->update(['origin' => 'native', 'processed_at' => now()->addDay()])), 'immutable');
        $this->rejected(fn () => InvoicePaidProcessing::withoutEvents(fn () => $receipt->fresh()->delete()), 'history');
        self::assertSame('legacy', $receipt->fresh()->origin);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_receipt_context_cannot_commit_a_movement_without_wallet_and_projection(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $context = AccountWriteContext::paidDeposit($this->paidDeposit($owner));
        $key = 'synthetic-incomplete-deposit';
        DB::transaction(function () use ($context, $key, $wallet) {
            // Catch the domain denial inside the caller's transaction. The bounded
            // context itself must roll back the incomplete receipt before returning.
            $this->rejected(fn () => (new AccountWriteGuard)->duringVerifiedWrite($context,
                fn () => AccountMovement::create($context->movementAttributes($wallet, $key)), $key), 'incomplete');
            self::assertSame(0, AccountMovement::count());
            self::assertSame('-40.0050', $wallet->fresh()->balance);
            self::assertSame('0.00', Credit::sole()->amount);
        });
        self::assertSame(0, AccountMovement::count());
    }

    public function test_an_old_native_processing_receipt_cannot_replay_as_new_cash_after_wallet_opening(): void
    {
        $owner = User::factory()->createQuietly();
        $evidence = $this->evidence($owner);
        $wallet = (new WalletLedger)->initialize($evidence, $this->register($evidence));
        $receipt = $this->paidDeposit($owner);
        DB::table('invoice_paid_processings')->where('invoice_id', $receipt->invoice_id)->update(['processed_at' => '2000-01-01 00:00:00']);
        $this->rejected(fn () => $this->postDeposit($receipt->fresh()), 'receipt');
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    #[DataProvider('invalidOpeningMoney')]
    public function test_opening_rejects_invalid_exact_money_without_any_financial_write(mixed $opening, mixed $limit): void
    {
        $owner = User::factory()->createQuietly();
        $rejected = false;
        try {
            $evidence = new OpeningEvidence('synthetic', 'invalid-fixture', $owner->id, 'USD', $opening, $limit, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), true);
            (new WalletLedger)->initialize($evidence, $this->register($evidence));
        } catch (DomainException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'Opening silently coerced or rounded invalid money');
        self::assertSame(0, AccountWallet::count());
        self::assertSame(0, Credit::count());
    }

    public static function invalidOpeningMoney(): array
    {
        return [['1e3', '100.00'], ['999999999999999.9999', '100.00'], ['0.00001', '100.00'], ['0.00', '-0.01'], [1.005, '100.00'], ['0.00', 100.00], [1, '100.00']];
    }
}
