<?php

namespace Tests\Feature\Accounts;

use App\Enums\InvoiceTransactionStatus;
use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Services\Accounts\AccountPaymentLocks;
use App\Services\Accounts\AccountWriteContext;
use App\Services\Accounts\AccountWriteGuard;
use App\Services\Accounts\InvoiceFunding;
use App\Services\Accounts\WalletLedger;
use App\Services\Billing\InvoicePricing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesAccountWallet;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesVerifiedDeposit;
use Tests\TestCase;

class InvoiceFundingTest extends TestCase
{
    use UsesAccountWallet, UsesCommittedDatabase, UsesVerifiedDeposit;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(InvoiceFunding::class) && class_exists(AccountPaymentLocks::class), 'Internal invoice funding contract is missing');
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
    }

    private function invoice(User $owner, string $amount = '15.00'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '0.0000']);
        $invoice->items()->create(['description' => 'Synthetic product', 'price' => $amount, 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);

        return $invoice;
    }

    private function denied(callable $operation): void
    {
        $denied = false;
        try {
            $operation();
        } catch (AssertionFailedError|QueryException $exception) {
            throw $exception;
        } catch (RuntimeException|AuthorizationException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Invalid internal account funding was accepted');
    }

    public function test_cash_and_borrowing_fund_a_native_invoice_with_one_complete_receipt(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner);
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-complete-funding');
        self::assertSame('10.0000', $allocation->cash_amount);
        self::assertSame('5.0000', $allocation->debt_amount);
        self::assertSame('15.00', $allocation->amount);
        self::assertSame('0.00', $allocation->reversed_amount);
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame(1, AccountFundingAllocation::count());
        self::assertSame(1, AccountMovement::count());
        self::assertSame(1, InvoicePaidProcessing::count());
        $transaction = $invoice->transactions()->sole();
        self::assertSame($transaction->id, $allocation->invoice_transaction_id);
        self::assertNull($transaction->gateway_id);
        self::assertNull($transaction->transaction_id);
        self::assertTrue($transaction->is_credit_transaction);
        self::assertSame('account_funding', $transaction->settlement_origin);
        self::assertSame(InvoiceTransactionStatus::Succeeded, $transaction->status);
        self::assertTrue($transaction->isManaged());
        Http::assertNothingSent();
    }

    public function test_partial_explicit_funding_leaves_only_the_unfunded_invoice_amount_collectible(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner, '25.00');
        (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-partial-funding');
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame('pending', $invoice->fresh()->status);
        self::assertSame('10.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        self::assertSame(0, InvoicePaidProcessing::count());
        self::assertSame(1, $invoice->transactions()->count());
    }

    public function test_exact_available_capacity_can_be_used_and_over_capacity_cannot(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '-40.0050', '100.00');
        $invoice = $this->invoice($owner, '60.00');
        $this->denied(fn () => (new InvoiceFunding)->fund($owner, $invoice, '60.00', 'synthetic-over-capacity'));
        self::assertSame(0, AccountMovement::count());
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '59.99', 'synthetic-exact-capacity');
        self::assertSame('0.0000', $allocation->cash_amount);
        self::assertSame('59.9900', $allocation->debt_amount);
        self::assertSame('-99.9950', $wallet->fresh()->balance);
        self::assertSame('0.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
        self::assertSame('0.01', (new InvoicePricing)->summary($invoice->fresh())->payable);
    }

    public function test_automatic_funding_is_whole_invoice_only_and_does_not_partially_borrow(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '20.00');
        $invoice = $this->invoice($owner, '30.01');
        self::assertNull((new InvoiceFunding)->fundAutomatic($invoice, 'synthetic-insufficient-renewal'));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, AccountFundingAllocation::count());
        self::assertSame(0, InvoiceTransaction::count());
        self::assertSame('pending', $invoice->fresh()->status);
    }

    public function test_automatic_funding_records_system_origin_and_no_client_actor(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '20.00');
        $invoice = $this->invoice($owner, '30.00');
        $allocation = (new InvoiceFunding)->fundAutomatic($invoice, 'synthetic-whole-renewal');
        self::assertNotNull($allocation);
        self::assertSame('-20.0000', $wallet->fresh()->balance);
        self::assertNull($allocation->movement->actor_id);
        self::assertSame('scheduler', $allocation->movement->origin);
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame(1, InvoicePaidProcessing::count());
    }

    public function test_identical_funding_replay_returns_one_allocation_without_a_second_fulfillment(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner);
        $first = (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-repeat-funding');
        $again = (new InvoiceFunding)->fund($owner, $invoice->fresh(), '15.00', 'synthetic-repeat-funding');
        self::assertSame($first->id, $again->id);
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountFundingAllocation::count());
        self::assertSame(1, AccountMovement::count());
        self::assertSame(1, InvoiceTransaction::count());
        self::assertSame(1, InvoicePaidProcessing::count());
    }

    #[DataProvider('changedRequest')]
    public function test_changed_amount_or_actor_cannot_reuse_a_funding_request(bool $changedActor): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner, '25.00');
        (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-changed-funding');
        $actor = $changedActor ? User::factory()->createQuietly() : $owner;
        $amount = $changedActor ? '15.00' : '10.00';
        $this->denied(fn () => (new InvoiceFunding)->fund($actor, $invoice->fresh(), $amount, 'synthetic-changed-funding'));
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
        self::assertSame(1, AccountFundingAllocation::count());
        self::assertSame(1, InvoiceTransaction::count());
    }

    public static function changedRequest(): array
    {
        return [[false], [true]];
    }

    public function test_a_deposit_invoice_cannot_credit_itself_with_account_funding(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '0.0000']);
        $invoice->items()->create(['description' => 'Synthetic deposit', 'price' => '15.00', 'quantity' => 1, 'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        $this->denied(fn () => (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-self-deposit'));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoiceTransaction::count());
    }

    public function test_a_shared_history_reader_cannot_spend_the_owners_account(): void
    {
        $owner = User::factory()->createQuietly();
        $reader = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $account = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 71001, 'owner_user_id' => $owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $account, 'user_id' => $reader->id, 'source_user_id' => 71002]);
        $invoice = $this->invoice($owner);
        self::assertTrue((new InvoicePolicy)->view($reader, $invoice));
        $this->denied(fn () => (new InvoiceFunding)->fund($reader, $invoice, '15.00', 'synthetic-shared-read-only'));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoiceTransaction::count());
    }

    #[DataProvider('frozenStates')]
    public function test_each_external_claim_state_prevents_switching_to_account_funding(string $state): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner);
        $gateway = Gateway::create(['name' => 'Synthetic frozen claim', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
        GatewayPaymentAttempt::create(['invoice_id' => $invoice->id, 'user_id' => $owner->id, 'gateway_id' => $gateway->id, 'currency_code' => 'USD', 'amount' => '15.00', 'state' => $state,
            'reference' => 'synthetic-funding-claim-' . $state, 'merchant_fingerprint' => hash('sha256', 'synthetic merchant'), 'pricing_fingerprint' => hash('sha256', 'synthetic invoice')]);
        $this->denied(fn () => (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-frozen-' . $state));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoiceTransaction::count());
        Http::assertNothingSent();
    }

    public static function frozenStates(): array
    {
        return [['open'], ['initializing'], ['paid']];
    }

    #[DataProvider('blockedWallets')]
    public function test_disabled_inactive_and_held_accounts_cannot_be_funded(string $block): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00', $block !== 'inactive');
        $invoice = $this->invoice($owner);
        if ($block === 'disabled') {
            config(['account-funding.enabled' => false]);
        } elseif ($block === 'hold') {
            DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'synthetic funding hold']);
        }
        $this->denied(fn () => (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-blocked-' . $block));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoiceTransaction::count());
    }

    public static function blockedWallets(): array
    {
        return [['disabled'], ['inactive'], ['hold']];
    }

    public function test_funding_cannot_exceed_the_remaining_product_principal(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner);
        $this->denied(fn () => (new InvoiceFunding)->fund($owner, $invoice, '15.01', 'synthetic-over-invoice'));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoiceTransaction::count());
    }

    public function test_changed_pricing_cannot_reuse_an_original_funding_request(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner, '25.00');
        (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-changed-pricing');
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['price' => '30.00']);
        $this->denied(fn () => (new InvoiceFunding)->fund($owner, $invoice->fresh(), '15.00', 'synthetic-changed-pricing'));
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountFundingAllocation::count());
        self::assertSame(1, AccountMovement::count());
        self::assertSame(1, InvoiceTransaction::count());
    }

    public function test_native_internal_allocation_cannot_be_edited_deleted_or_reassigned_quietly(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner);
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-protected-allocation');
        $transaction = $allocation->transaction;
        self::assertNotNull($transaction);
        $other = $this->invoice($owner, '20.00');
        foreach ([
            fn () => InvoiceTransaction::withoutEvents(fn () => $transaction->fresh()->update(['amount' => '20.00'])),
            fn () => InvoiceTransaction::withoutEvents(fn () => $transaction->fresh()->update(['invoice_id' => $other->id])),
            fn () => InvoiceTransaction::withoutEvents(fn () => $transaction->fresh()->delete()),
            fn () => AccountFundingAllocation::withoutEvents(fn () => $allocation->fresh()->update(['reversed_amount' => '5.00'])),
            fn () => AccountFundingAllocation::withoutEvents(fn () => $allocation->fresh()->delete()),
        ] as $write) {
            $this->denied($write);
        }
        self::assertSame('15.00', $transaction->fresh()->amount);
        self::assertSame($invoice->id, $transaction->fresh()->invoice_id);
        self::assertSame('0.00', $allocation->fresh()->reversed_amount);
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
    }

    public function test_ordinary_transaction_creation_cannot_forge_internal_account_funding_origin(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner);
        $this->denied(fn () => InvoiceTransaction::withoutEvents(fn () => $invoice->transactions()->create([
            'amount' => '15.00', 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => true, 'settlement_origin' => 'account_funding', 'settlement_state' => 'settled',
        ])));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoiceTransaction::count());
    }

    public function test_a_legacy_credit_marker_cannot_pay_a_managed_invoice_without_an_allocation(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner);
        $this->denied(fn () => InvoiceTransaction::withoutEvents(fn () => $invoice->transactions()->create([
            'amount' => '15.00', 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => true,
        ])));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame('pending', $invoice->fresh()->status);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoiceTransaction::count());
    }

    public function test_an_unlinked_allocation_cannot_escape_its_construction_transaction(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner);
        $context = AccountWriteContext::invoiceFunding($invoice, $owner, '15.00');
        $key = 'synthetic-incomplete-allocation';
        DB::transaction(fn () => (new AccountPaymentLocks)->during([$invoice->id], function () use ($context, $wallet, $key) {
            $this->denied(fn () => (new AccountWriteGuard)->duringVerifiedWrite($context, function () use ($context, $wallet, $key) {
                $movement = AccountMovement::create($context->movementAttributes($wallet, $key));
                $wallet->balance = $movement->balance_after;
                $wallet->save();
                (new WalletLedger)->refreshProjection($context);
                AccountFundingAllocation::create($context->allocationAttributes($movement));
                self::assertSame(1, AccountFundingAllocation::count());
                self::assertNull(AccountFundingAllocation::sole()->invoice_transaction_id);
            }, $key));
            self::assertSame(0, AccountMovement::count());
            self::assertSame(0, AccountFundingAllocation::count());
            self::assertSame(0, InvoiceTransaction::count());
            self::assertSame('10.0000', $wallet->fresh()->balance);
            self::assertSame('10.00', Credit::sole()->amount);
        }));
        self::assertSame(0, AccountFundingAllocation::count());
    }

    public function test_funding_rollback_restores_the_journal_projection_invoice_and_complete_native_link(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = $this->invoice($owner);
        $rolledBack = false;
        try {
            DB::transaction(function () use ($owner, $invoice) {
                $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-funding-rollback');
                self::assertNotNull($allocation->invoice_transaction_id);
                self::assertSame(1, InvoicePaidProcessing::count());
                throw new RuntimeException('synthetic funding rollback');
            });
        } catch (AssertionFailedError|QueryException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic funding rollback', $exception->getMessage());
            $rolledBack = true;
        }
        self::assertTrue($rolledBack);
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame('pending', $invoice->fresh()->status);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, AccountFundingAllocation::count());
        self::assertSame(0, InvoiceTransaction::count());
        self::assertSame(0, InvoicePaidProcessing::count());
    }

    public function test_pure_funding_removes_unclaimed_gateway_fee_and_preserves_product_tax(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = Invoice::factory()->createQuietly(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '5.0000', 'pricing_tax_inclusive' => true]);
        $invoice->items()->create(['description' => 'Synthetic taxed product', 'price' => '21.00', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '1.00']);
        $gateway = Gateway::create(['name' => 'Synthetic unclaimed fee', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
        $invoice->items()->create(['description' => 'Synthetic gateway fee', 'price' => '2.00', 'quantity' => 1, 'kind' => 'gateway_fee', 'tax_amount' => '0.00', 'gateway_id' => $gateway->id]);
        (new InvoiceFunding)->fund($owner, $invoice, '21.00', 'synthetic-taxed-funding');
        self::assertSame('-11.0000', $wallet->fresh()->balance);
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame(0, $invoice->items()->where('kind', 'gateway_fee')->count());
        self::assertSame('1.00', $invoice->items()->sole()->tax_amount);
        self::assertSame('5.0000', $invoice->fresh()->pricing_tax_rate);
        $summary = (new InvoicePricing)->summary($invoice->fresh());
        self::assertSame('21.00', $summary->total);
        self::assertSame('1.00', $summary->productTax);
        self::assertSame('0.00', $summary->gatewayFee);
        self::assertSame('0.00', $summary->payable);
        Http::assertNothingSent();
    }

    public function test_fractional_cash_and_debt_components_conserve_the_cent_invoice_amount(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '-40.0050', '100.00');
        $deposit = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $gateway = $this->depositGateway('0.00');
        $deposit->items()->create(['description' => 'Synthetic native principal', 'price' => '50.00', 'quantity' => 1, 'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        $this->verifiedDeposit($deposit, $gateway, 'synthetic-fractional-deposit', false);
        $receipt = InvoicePaidProcessing::create(['invoice_id' => $deposit->id, 'origin' => 'native', 'processed_at' => now()]);
        $context = AccountWriteContext::paidDeposit($receipt);
        (new WalletLedger)->post($context, '50.00', 'deposit', 'synthetic-fractional-principal', $context->fingerprint('synthetic-fractional-principal'));
        self::assertSame('9.9950', $wallet->fresh()->balance);
        self::assertSame('9.99', Credit::sole()->amount);
        $invoice = $this->invoice($owner);
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-fractional-funding');
        self::assertSame('9.9950', $allocation->cash_amount);
        self::assertSame('5.0050', $allocation->debt_amount);
        self::assertSame('-5.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame('15.00', $allocation->transaction->amount);
        self::assertSame('paid', $invoice->fresh()->status);
    }

    public function test_dependency_graph_expansion_is_detected_before_any_wallet_change(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $product = $this->createProduct(['server_id' => null, 'stock' => null]);
        $service = Service::factory()->create(['user_id' => $owner->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'expires_at' => now()->addMonth()]);
        $invoice = $this->invoice($owner);
        $invoice->items()->sole()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        $other = $this->invoice($owner, '20.00');
        $expanded = false;
        DB::listen(function ($query) use (&$expanded, $other, $service) {
            if (!$expanded && str_contains($query->sql, '`invoices`') && str_contains($query->sql, 'for update')) {
                $expanded = true;
                DB::table('invoice_items')->where('invoice_id', $other->id)->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
            }
        });
        $this->denied(fn () => DB::transaction(fn () => (new AccountPaymentLocks)->lock([$invoice->id])));
        self::assertTrue($expanded, 'The graph did not change after initial discovery');
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoiceTransaction::count());
    }

    public function test_a_foreign_service_reference_cannot_redirect_account_funding_or_fulfillment(): void
    {
        $owner = User::factory()->createQuietly();
        $foreign = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $product = $this->createProduct(['server_id' => null, 'stock' => null]);
        $service = Service::factory()->create(['user_id' => $foreign->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => 'active', 'expires_at' => now()->addMonth()]);
        $expiration = $service->expires_at->format('Y-m-d H:i:s');
        $invoice = $this->invoice($owner);
        $invoice->items()->sole()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        $this->denied(fn () => (new InvoiceFunding)->fund($owner, $invoice, '15.00', 'synthetic-foreign-service'));
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame('pending', $invoice->fresh()->status);
        self::assertSame($expiration, $service->fresh()->expires_at->format('Y-m-d H:i:s'));
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoiceTransaction::count());
        self::assertSame(0, InvoicePaidProcessing::count());
    }
}
