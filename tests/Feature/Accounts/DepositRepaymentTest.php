<?php

namespace Tests\Feature\Accounts;

use App\Enums\InvoiceTransactionStatus;
use App\Livewire\Client\Credits;
use App\Livewire\Invoices\Show;
use App\Models\AccountMovement;
use App\Models\AccountPostingIssue;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounts\AccountWriteContext;
use App\Services\Accounts\DepositLifecycle;
use App\Services\Accounts\WalletLedger;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\Concerns\UsesAccountWallet;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesVerifiedDeposit;
use Tests\TestCase;

class DepositRepaymentTest extends TestCase
{
    use UsesAccountWallet, UsesCommittedDatabase, UsesVerifiedDeposit;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(DepositLifecycle::class), 'Native deposit lifecycle contract is missing');
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings' => collect(config('settings'))->all()]);
        config(['settings.tax_enabled' => false]);
    }

    private function invoice(User $owner): array
    {
        $gateway = $this->depositGateway();
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'status' => 'pending', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Synthetic principal', 'price' => '50.00', 'quantity' => 1,
            'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        $invoice->items()->create(['description' => 'Synthetic fee', 'price' => '2.00', 'quantity' => 1,
            'kind' => 'gateway_fee', 'tax_amount' => '0.00', 'gateway_id' => $gateway->id]);

        return [$invoice, $gateway];
    }

    private function nativePayment(Invoice $invoice, Gateway $gateway, string $amount, string $reference): void
    {
        try {
            if ($amount === '52.00') {
                $this->verifiedDeposit($invoice, $gateway, $reference);
            } else {
                $this->manualDeposit($invoice, $gateway, $amount, $reference);
            }
        } catch (RuntimeException $exception) {
            self::fail('Native deposit payment could not complete: ' . $exception->getMessage());
        }
    }

    public function test_native_paid_deposit_repays_exact_debt_before_exposing_cash(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '-40.0050', '100.00');
        [$invoice, $gateway] = $this->invoice($owner);
        $this->nativePayment($invoice, $gateway, '52.00', 'synthetic-full-deposit');
        $quote = (new WalletLedger)->quote($owner, 'USD');
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame('9.9950', $wallet->fresh()->balance);
        self::assertSame('9.99', Credit::sole()->amount);
        self::assertSame('0.0050', $quote->residual);
        self::assertSame(1, AccountMovement::count());
        self::assertSame('50.0000', AccountMovement::sole()->delta);
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame('1.00', $invoice->transactions()->sole()->fee);
        $replay = (new DepositLifecycle)->creditPaidInvoice($invoice->fresh());
        self::assertSame(AccountMovement::sole()->id, $replay->id);
        self::assertSame('9.9950', $wallet->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_multiple_original_receipts_partition_one_deposit_principal_once(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '-40.0050', '100.00');
        [$invoice, $gateway] = $this->invoice($owner);
        $this->nativePayment($invoice, $gateway, '20.00', 'synthetic-deposit-first');
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        $this->nativePayment($invoice, $gateway, '32.00', 'synthetic-deposit-second');
        $transactions = $invoice->transactions()->orderBy('id')->get();
        self::assertSame(['transaction_id' => $transactions[0]->id, 'principal' => '20.0000', 'fee' => '0.00'], AccountMovement::sole()->payload['deposit_slices'][0]);
        self::assertSame(['transaction_id' => $transactions[1]->id, 'principal' => '30.0000', 'fee' => '2.00'], AccountMovement::sole()->payload['deposit_slices'][1]);
        self::assertSame('9.9950', $wallet->fresh()->balance);
        (new DepositLifecycle)->creditPaidInvoice($invoice->fresh());
        self::assertSame(1, AccountMovement::count());
    }

    private function preparedReceipt(User $owner, string $origin = 'native'): Invoice
    {
        [$invoice, $gateway] = $this->invoice($owner);
        $this->verifiedDeposit($invoice, $gateway, 'synthetic-original-' . $invoice->id, false);
        InvoicePaidProcessing::create(['invoice_id' => $invoice->id, 'origin' => $origin, 'processed_at' => now()]);

        return $invoice;
    }

    private function denied(callable $operation): void
    {
        $denied = false;
        try {
            $operation();
        } catch (QueryException|AssertionFailedError $exception) {
            throw $exception;
        } catch (RuntimeException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Unproven deposit was accepted');
    }

    public function test_legacy_paid_history_cannot_become_a_new_native_deposit(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '0.00', '100.00');
        $invoice = $this->preparedReceipt($owner, 'legacy');
        $this->denied(fn () => (new DepositLifecycle)->creditPaidInvoice($invoice));
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_changed_original_allocation_does_not_credit_a_deposit(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '0.00', '100.00');
        $invoice = $this->preparedReceipt($owner);
        DB::table('invoice_transactions')->where('invoice_id', $invoice->id)->update(['amount' => '52.01']);
        $this->denied(fn () => (new DepositLifecycle)->creditPaidInvoice($invoice));
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_mixed_product_deposit_scope_cannot_credit_an_account(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '0.00', '100.00');
        $invoice = $this->preparedReceipt($owner);
        DB::table('invoice_items')->insert(['invoice_id' => $invoice->id, 'description' => 'Synthetic mixed product', 'price' => '1.00', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        $this->denied(fn () => (new DepositLifecycle)->creditPaidInvoice($invoice));
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_verified_incoming_payment_survives_failed_native_posting_and_blocks_spending(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '999999999999999.99', '0.00');
        [$invoice, $gateway] = $this->invoice($owner);
        $this->nativePayment($invoice, $gateway, '52.00', 'synthetic-overflow-deposit');
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame('52.00', $invoice->transactions()->sole()->amount);
        self::assertSame(['net' => '50.00', 'tax' => '0.00', 'fee' => '2.00'], $invoice->transactions()->sole()->original_allocation);
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame('999999999999999.9900', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertTrue((new WalletLedger)->quote($owner, 'USD')->blocked);
        self::assertSame('0.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
    }

    public function test_changed_native_financial_owner_cannot_redirect_received_principal(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '0.00', '100.00');
        $invoice = $this->preparedReceipt($owner);
        $other = User::factory()->createQuietly();
        $otherWallet = $this->wallet($other, '0.00', '100.00');
        DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => $other->id]);
        $this->denied(fn () => (new DepositLifecycle)->creditPaidInvoice($invoice->fresh()));
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame('0.0000', $otherWallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_changed_native_financial_currency_cannot_redirect_received_principal(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '0.00', '100.00');
        $invoice = $this->preparedReceipt($owner);
        Currency::create(['code' => 'EUR', 'name' => 'Synthetic euro', 'prefix' => 'EUR ', 'suffix' => '', 'format' => '1,000.00']);
        $otherWallet = $this->wallet($owner, '0.00', '100.00', currency: 'EUR');
        DB::table('invoices')->where('id', $invoice->id)->update(['currency_code' => 'EUR']);
        $this->denied(fn () => (new DepositLifecycle)->creditPaidInvoice($invoice->fresh()));
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame('0.0000', $otherWallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_native_deposit_checkout_caps_future_cash_after_repaying_debt(): void
    {
        foreach (['default_currency' => 'USD', 'credits_enabled' => true, 'credits_minimum_deposit' => '1.00',
            'credits_maximum_deposit' => '500.00', 'credits_maximum_credit' => '10.00', 'tax_enabled' => false,
            'mail_must_verify' => false] as $key => $value) {
            Setting::updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value]);
            config(['settings.' . $key => $value]);
        }
        config(['app.version' => 'development']);
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '-100.00', '100.00');
        $gateway = $this->depositGateway('0.00');
        $this->actingAs($owner);
        try {
            Livewire::test(Credits::class)->set('amount', '50.00')->set('gateway', $gateway->id)->call('addCredit')->assertHasNoErrors();
            self::assertSame(1, Invoice::count(), 'Debt repayment below the future cash cap must create an invoice');
            self::assertSame(0, DB::transactionLevel());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $invoice = Invoice::sole();
        self::assertSame('50.00', $invoice->items()->sole()->price);
        $attempt = GatewayPaymentAttempt::sole();
        (new PaymentAttempts)->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, '50.00', 'USD', 'synthetic-checkout-repayment');
        self::assertSame('-50.0000', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
    }

    public function test_pending_incoming_issue_blocks_spending_and_resolves_once_after_authorized_recovery(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '0.00', '100.00', false);
        [$invoice, $gateway] = $this->invoice($owner);
        $this->nativePayment($invoice, $gateway, '52.00', 'synthetic-inactive-incoming');
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame(1, AccountPostingIssue::count());
        self::assertTrue((new WalletLedger)->quote($owner, 'USD')->blocked);
        self::assertNull(AccountPostingIssue::sole()->resolved_movement_id);
        $this->denied(fn () => AccountPostingIssue::sole()->updateQuietly(['resolved_movement_id' => 999]));
        $this->denied(fn () => AccountPostingIssue::sole()->deleteQuietly());
        // Simulate the separately approved activation; no public applicator exists.
        DB::table('account_wallets')->where('id', $wallet->id)->update(['active' => true]);
        $movement = (new DepositLifecycle)->creditPaidInvoice($invoice->fresh());
        self::assertSame($movement->id, AccountPostingIssue::sole()->resolved_movement_id);
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertFalse((new WalletLedger)->quote($owner, 'USD')->blocked);
        (new DepositLifecycle)->creditPaidInvoice($invoice->fresh());
        self::assertSame(1, AccountMovement::count());
        self::assertSame(1, AccountPostingIssue::count());
        Http::assertNothingSent();
    }

    public function test_original_incoming_proof_round_trips_as_immutable_json_evidence(): void
    {
        $owner = User::factory()->createQuietly();
        $this->wallet($owner, '0.00', '100.00');
        $invoice = $this->preparedReceipt($owner);
        $proof = AccountWriteContext::paidDeposit(InvoicePaidProcessing::sole())->receiptProof;
        self::assertSame($proof, json_decode(json_encode($proof, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_managed_cash_rejects_native_increment_and_quiet_decrement(): void
    {
        $owner = User::factory()->createQuietly();
        $this->wallet($owner, '10.00', '100.00');
        $this->denied(fn () => Credit::sole()->increment('amount', 5));
        $this->denied(fn () => Credit::sole()->decrementQuietly('amount', 1));
        self::assertSame('10.00', Credit::sole()->amount);
    }

    public function test_managed_wallet_rejects_native_increment_without_events(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '-40.0050', '100.00');
        $this->denied(fn () => AccountWallet::withoutEvents(fn () => $wallet->increment('balance', 30)));
        self::assertSame('-40.0050', $wallet->fresh()->balance);
    }

    public function test_posted_journal_rejects_native_increment(): void
    {
        $owner = User::factory()->createQuietly();
        $this->wallet($owner, '0.00', '100.00');
        [$invoice, $gateway] = $this->invoice($owner);
        $this->nativePayment($invoice, $gateway, '52.00', 'synthetic-arithmetic-guard');
        $this->denied(fn () => AccountMovement::sole()->increment('delta', 1));
        self::assertSame('50.0000', AccountMovement::sole()->delta);
    }

    public function test_raw_gateway_success_marker_cannot_forge_a_managed_deposit_receipt(): void
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '0.00', '100.00');
        [$invoice, $gateway] = $this->invoice($owner);
        $this->denied(fn () => InvoiceTransaction::withoutEvents(fn () => $invoice->transactions()->create([
            'gateway_id' => $gateway->id, 'transaction_id' => 'synthetic-unverified-success',
            'amount' => '52.00', 'status' => InvoiceTransactionStatus::Succeeded,
        ])));
        self::assertSame(0, InvoiceTransaction::count());
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_unmanaged_native_deposit_keeps_ordinary_cash_behavior(): void
    {
        $owner = User::factory()->createQuietly();
        [$invoice, $gateway] = $this->invoice($owner);
        $this->nativePayment($invoice, $gateway, '52.00', 'synthetic-unmanaged-deposit');
        self::assertSame('50.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
        self::assertNull((new DepositLifecycle)->creditPaidInvoice($invoice));
    }

    public function test_consumed_native_deposit_from_before_same_second_opening_cannot_be_replayed_as_new_income(): void
    {
        $this->travelTo(now()->startOfSecond());
        $owner = User::factory()->createQuietly();
        [$deposit, $gateway] = $this->invoice($owner);
        $this->nativePayment($deposit, $gateway, '52.00', 'synthetic-prior-opening-deposit');
        self::assertSame('50.00', Credit::sole()->amount);
        foreach (['credits_enabled' => true, 'mail_must_verify' => false] as $key => $value) {
            Setting::updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value]);
            config(['settings.' . $key => $value]);
        }
        config(['app.version' => 'development']);
        $purchase = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '0.0000']);
        $purchase->items()->create(['description' => 'Synthetic prior opening usage', 'price' => '50.00', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);
        $this->actingAs($owner);
        Livewire::test(Show::class, ['invoice' => $purchase])->set('selectedMethod', 'credit')->call('processPayment')->assertHasNoErrors();
        self::assertSame('paid', $purchase->fresh()->status);
        self::assertSame('0.00', Credit::sole()->amount);
        $wallet = $this->wallet($owner, '0.00', '100.00');
        self::assertSame($wallet->created_at->format('Y-m-d H:i:s'), InvoicePaidProcessing::findOrFail($deposit->id)->processed_at->format('Y-m-d H:i:s'));
        try {
            (new DepositLifecycle)->creditPaidInvoice($deposit->fresh());
        } catch (RuntimeException) {
        }
        self::assertSame('0.0000', $wallet->fresh()->balance, 'Original pre-opening income was minted again after its cash had already been spent');
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, AccountPostingIssue::count());
        self::assertSame(2, InvoicePaidProcessing::count());
        Http::assertNothingSent();
        $this->travelBack();
    }

    public function test_older_pending_deposit_paid_after_same_second_opening_is_new_income_once(): void
    {
        $this->travelTo(now()->startOfSecond());
        $owner = User::factory()->createQuietly();
        [$deposit, $gateway] = $this->invoice($owner);
        $laterInvoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        self::assertLessThan($laterInvoice->id, $deposit->id);
        $wallet = $this->wallet($owner, '0.00', '100.00');
        $this->nativePayment($deposit, $gateway, '52.00', 'synthetic-pending-before-opening-paid-after');
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame('50.00', Credit::sole()->amount);
        self::assertSame(1, AccountMovement::count());
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame(AccountMovement::sole()->id, (new DepositLifecycle)->creditPaidInvoice($deposit->fresh())->id);
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
        self::assertSame(0, AccountPostingIssue::count());
        Http::assertNothingSent();
        $this->travelBack();
    }
}
