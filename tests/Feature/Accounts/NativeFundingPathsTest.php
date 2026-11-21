<?php

namespace Tests\Feature\Accounts;

use App\Livewire\Cart as CheckoutCart;
use App\Livewire\Client\Credits;
use App\Livewire\Invoices\Show;
use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\Cart;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounts\InvoiceFunding;
use App\Services\Billing\InvoicePricing;
use App\Services\Gateways\GatewayFeePolicy;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\Operations\GatewayOperations;
use App\Services\Gateways\Operations\Refunds;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\UsesAccountWallet;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesVerifiedDeposit;
use Tests\Fixtures\Accounts\RaceRefundAdapter;
use Tests\Fixtures\FeeGateway;
use Tests\TestCase;

class NativeFundingPathsTest extends TestCase
{
    use UsesAccountWallet, UsesCommittedDatabase, UsesVerifiedDeposit;

    private function fixture(string $opening = '10.00', string $limit = '100.00', bool $active = true): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['app.version' => 'development', 'settings' => collect(config('settings'))->all()]);
        foreach (['tax_enabled' => false, 'tax_type' => 'exclusive', 'tax_scope' => 'all', 'default_currency' => 'USD',
            'credits_enabled' => true, 'credits_auto_use' => true, 'mail_must_verify' => false, 'tos' => null] as $key => $value) {
            Setting::updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value]);
            config(['settings.' . $key => $value]);
        }
        $owner = User::factory()->create();
        $this->actingAs($owner);

        return [$owner, $this->wallet($owner, $opening, $limit, $active)];
    }

    private function invoice(User $owner, string $amount = '15.00'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '0.0000']);
        $invoice->items()->create(['description' => 'Synthetic product', 'price' => $amount, 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);

        return $invoice;
    }

    private function nativePay(Invoice $invoice): void
    {
        try {
            Livewire::test(Show::class, ['invoice' => $invoice])->set('selectedMethod', 'credit')->call('processPayment');
        } catch (RuntimeException $exception) {
            self::fail('Native invoice funding rejected an eligible account: ' . $exception->getMessage());
        }
    }

    public function test_native_invoice_payment_uses_cash_and_borrowing(): void
    {
        [$owner, $wallet] = $this->fixture();
        $invoice = $this->invoice($owner);
        $this->nativePay($invoice);
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountFundingAllocation::count());
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame(0, $invoice->items()->where('kind', 'gateway_fee')->count());
        Http::assertNothingSent();
    }

    public function test_native_invoice_partial_funding_keeps_product_tax_and_fees_only_on_external_remainder(): void
    {
        [$owner, $wallet] = $this->fixture('10.00', '20.00');
        $invoice = $this->invoice($owner, '105.00');
        $invoice->items()->sole()->update(['tax_amount' => '5.00']);
        $this->nativePay($invoice);
        self::assertSame('-20.0000', $wallet->fresh()->balance);
        self::assertSame('30.00', AccountFundingAllocation::sole()->amount);
        self::assertSame('pending', $invoice->fresh()->status);
        self::assertSame('75.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        self::assertSame('5.00', $invoice->items()->sole()->tax_amount);
        class_exists(FeeGateway::class);
        $gateway = Gateway::create(['name' => 'Synthetic remainder', 'extension' => 'FeeGateway', 'type' => 'gateway', 'enabled' => true]);
        foreach (['collection_enabled' => '1', 'customer_fee_enabled' => '1', 'customer_fee_percent' => '2.5',
            'customer_fee_fixed' => '0.25', 'customer_fee_currency' => 'USD'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        $summary = (new GatewayFeePolicy)->quote((new InvoicePricing)->summary($invoice->fresh()), $gateway);
        // Remaining net: 75 * 100 / 105 = 71.43; 2.5% + 0.25 = 2.04.
        self::assertSame('71.43', $summary->unpaidNet);
        self::assertSame('3.57', $summary->unpaidTax);
        self::assertSame('2.04', $summary->gatewayFee);
        self::assertSame('77.04', $summary->payable);
        self::assertSame('5.00', $summary->productTax);
        Http::assertNothingSent();
    }

    public function test_maximum_funding_retry_returns_original_receipt_after_capacity_changes(): void
    {
        [$owner, $wallet] = $this->fixture('10.00', '20.00');
        $invoice = $this->invoice($owner, '50.00');
        self::assertTrue(method_exists(InvoiceFunding::class, 'fundAvailable'), 'Native maximum-funding contract is missing');
        $funding = new InvoiceFunding;
        $first = $funding->fundAvailable($owner, $invoice, 'synthetic-native-maximum');
        self::assertInstanceOf(AccountFundingAllocation::class, $first);
        $second = $funding->fundAvailable($owner, $invoice, 'synthetic-native-maximum');
        self::assertSame($first->id, $second->id);
        self::assertSame('30.00', $first->amount);
        self::assertSame('-20.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
        self::assertSame('20.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
    }

    public function test_native_livewire_retry_keeps_the_same_funding_request_identity(): void
    {
        [$owner, $wallet] = $this->fixture('10.00', '20.00');
        $invoice = $this->invoice($owner, '50.00');
        $component = Livewire::test(Show::class, ['invoice' => $invoice]);
        self::assertTrue(property_exists($component->instance(), 'fundingRequestKey'), 'Native action has no stable request identity');
        self::assertNotEmpty($component->get('fundingRequestKey'), 'Native action has no stable request identity');
        $key = $component->get('fundingRequestKey');
        $component->set('selectedMethod', 'credit')->call('processPayment')->call('processPayment');
        self::assertSame($key, $component->get('fundingRequestKey'));
        self::assertSame(1, AccountMovement::count());
        self::assertSame('-20.0000', $wallet->fresh()->balance);
    }

    public function test_native_cart_forecast_includes_confirmed_borrowing_capacity(): void
    {
        [$owner] = $this->fixture();
        $this->cart($owner);
        $component = Livewire::test(CheckoutCart::class);
        self::assertSame('15.00', $component->instance()->baseSummary()->paid);
        self::assertSame('0.00', $component->instance()->baseSummary()->payable);
        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_native_invoice_offers_borrowing_even_with_no_cash_balance(): void
    {
        [$owner] = $this->fixture('0.00', '100.00');
        $invoice = $this->invoice($owner);
        Livewire::test(Show::class, ['invoice' => $invoice])->set('showPayModal', true)->assertSee('Account funding');
    }

    private function cart(User $owner): Cart
    {
        $product = $this->createProduct(['server_id' => null, 'stock' => null]);
        $product->plan->prices()->first()->update(['price' => '15.00']);
        $cart = Cart::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
        Livewire::withCookies(['cart' => $cart->ulid]);

        return $cart;
    }

    public function test_native_cart_checkout_creates_one_funded_invoice_and_service_receipt(): void
    {
        [$owner, $wallet] = $this->fixture();
        $this->cart($owner);
        Livewire::test(CheckoutCart::class)->call('checkout')->assertHasNoErrors();
        self::assertSame(1, Invoice::count());
        self::assertSame('paid', Invoice::sole()->status);
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame('active', Service::sole()->status);
        self::assertSame(1, AccountFundingAllocation::count());
        self::assertSame(1, InvoicePaidProcessing::count());
        Http::assertNothingSent();
    }

    private function renewal(User $owner, string $price): Service
    {
        $product = $this->createProduct(['server_id' => null, 'stock' => null]);

        return Service::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'price' => $price,
            'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'expires_at' => now()->addDays(2)]);
    }

    public function test_native_cron_funds_a_whole_renewal_once(): void
    {
        [$owner, $wallet] = $this->fixture();
        $service = $this->renewal($owner, '15.00');
        $due = $service->expires_at->toDateTimeString();
        try {
            $this->artisan('app:cron-job')->assertExitCode(0);
        } catch (RuntimeException $exception) {
            self::fail('Native renewal funding rejected an eligible account: ' . $exception->getMessage());
        }
        self::assertSame('paid', Invoice::sole()->status);
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertNotSame($due, $service->fresh()->expires_at->toDateTimeString());
        $after = $service->fresh()->expires_at->toDateTimeString();
        $this->artisan('app:cron-job')->assertExitCode(0);
        self::assertSame($after, $service->fresh()->expires_at->toDateTimeString());
        self::assertSame(1, AccountMovement::count());
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame('scheduler', AccountMovement::sole()->origin);
        Http::assertNothingSent();
    }

    public function test_native_cron_insufficient_capacity_does_not_partially_borrow(): void
    {
        [$owner, $wallet] = $this->fixture('10.00', '20.00');
        $this->renewal($owner, '30.01');
        $this->artisan('app:cron-job')->assertExitCode(0);
        self::assertSame('pending', Invoice::sole()->status);
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, InvoicePaidProcessing::count());
    }

    public function test_held_native_cron_owner_has_no_invoice_or_account_movement(): void
    {
        [$owner, $wallet] = $this->fixture();
        $service = $this->renewal($owner, '15.00');
        $due = $service->expires_at->toDateTimeString();
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'synthetic excluded owner']);
        $this->artisan('app:cron-job')->assertExitCode(0);
        self::assertSame(0, Invoice::count());
        self::assertSame(0, AccountMovement::count());
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame($due, $service->fresh()->expires_at->toDateTimeString());
    }

    public function test_inactive_native_cron_owner_keeps_a_pending_invoice_without_account_payment(): void
    {
        [$owner, $wallet] = $this->fixture('10.00', '100.00', false);
        $this->renewal($owner, '15.00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        self::assertSame('pending', Invoice::sole()->status);
        self::assertSame(0, AccountMovement::count());
        self::assertSame('10.0000', $wallet->fresh()->balance);
    }

    public function test_inactive_native_invoice_cannot_use_managed_cash(): void
    {
        [$owner, $wallet] = $this->fixture('10.00', '100.00', false);
        $invoice = $this->invoice($owner);
        try {
            Livewire::test(Show::class, ['invoice' => $invoice])->set('selectedMethod', 'credit')->call('processPayment');
        } catch (RuntimeException) {
            // A domain denial is allowed; the wallet must remain unchanged.
        }
        self::assertSame('pending', $invoice->fresh()->status);
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_presentation_cart_separates_cash_applied_and_borrowing_applied(): void
    {
        [$owner] = $this->fixture('10.00', '20.00');
        $this->cart($owner);
        $view = Livewire::test(CheckoutCart::class)
            ->assertSee('Cash available')->assertSee('Outstanding debt')
            ->assertSee('Remaining borrowing allowance')->assertSee('Cash applied')->assertSee('Borrowing applied');
        self::assertSame('15.00', $view->instance()->baseSummary()->paid);
        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_presentation_invoice_uses_original_allocation_split_and_current_debt(): void
    {
        [$owner, $wallet] = $this->fixture('10.00', '20.00');
        $invoice = $this->invoice($owner, '50.00');
        (new InvoiceFunding)->fundAvailable($owner, $invoice, 'synthetic-presentation-partial');
        Livewire::test(Show::class, ['invoice' => $invoice->fresh()])
            ->assertSee('Account funding receipt')->assertSee('Cash applied')->assertSee('10.0000')
            ->assertSee('Borrowing applied')->assertSee('20.0000')->assertSee('Outstanding debt')
            ->assertSee('Internal account allocation');
        self::assertSame('-20.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_presentation_shared_invoice_reader_has_no_payment_controls(): void
    {
        [$owner] = $this->fixture();
        $invoice = $this->invoice($owner);
        $member = User::factory()->createQuietly();
        $id = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 52001, 'owner_user_id' => $owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $id, 'user_id' => $member->id, 'source_user_id' => 52002]);
        Livewire::actingAs($member)->test(Show::class, ['invoice' => $invoice])
            ->assertDontSee('showPayModal', false)->assertDontSee('wire:click="processPayment"', false);
        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_presentation_deposit_invoice_explains_principal_only_debt_first(): void
    {
        [$owner] = $this->fixture('-40.0050');
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $invoice->items()->create(['description' => 'Synthetic deposit', 'price' => '50.00', 'quantity' => 1,
            'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        Livewire::test(Show::class, ['invoice' => $invoice])->set('showPayModal', true)
            ->assertSee('Deposits repay debt first')->assertSee('Gateway fees do not increase your account balance')
            ->assertDontSee('Cash and borrowing allowance');
        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_presentation_credit_page_links_statement_even_when_cash_is_zero(): void
    {
        [$owner] = $this->fixture('-40.0050');
        Livewire::test(Credits::class)
            ->assertSee(route('account.funding', ['owner' => $owner->id, 'currency' => 'USD']), false)
            ->assertSee('Deposits repay debt first');
        self::assertSame('0.00', Credit::sole()->amount);
        Http::assertNothingSent();
    }

    public function test_presentation_disabled_account_preserves_invoice_history_without_offer(): void
    {
        [$owner] = $this->fixture('10.00', '20.00');
        $invoice = $this->invoice($owner, '50.00');
        config(['account-funding.enabled' => false]);
        Livewire::test(Show::class, ['invoice' => $invoice])->set('showPayModal', true)
            ->assertSee('Account funding is unavailable')->assertSee('Outstanding debt')
            ->assertDontSee('Cash and borrowing allowance');
        self::assertSame(0, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_presentation_credit_page_disabled_funding_keeps_statement_without_deposit_control(): void
    {
        [$owner] = $this->fixture('10.00');
        config(['account-funding.enabled' => false]);
        Livewire::test(Credits::class)->assertSee('Your account statement')
            ->assertSee('Account funding is unavailable')->assertDontSee('wire:submit.prevent="addCredit"', false);
        self::assertSame('10.00', Credit::sole()->amount);
        Http::assertNothingSent();
    }

    public function test_presentation_credit_page_inactive_account_keeps_statement_without_deposit_control(): void
    {
        [$owner] = $this->fixture('10.00', '100.00', false);
        Livewire::test(Credits::class)->assertSee('Your account statement')
            ->assertSee('Account funding is unavailable')->assertDontSee('wire:submit.prevent="addCredit"', false);
        self::assertSame('10.00', Credit::sole()->amount);
        Http::assertNothingSent();
    }

    private function reservedCart(User $owner, string $depositAmount, string $reservationAmount, string $price): void
    {
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '0.0000']);
        $invoice->items()->create(['description' => 'Synthetic pending-refund deposit', 'price' => $depositAmount, 'quantity' => 1,
            'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        $transaction = $this->verifiedDeposit($invoice, $this->depositGateway(), 'synthetic-cart-refundable-deposit');
        $role = Role::create(['name' => 'Synthetic cart refund staff', 'permissions' => ['admin.invoice_transactions.refund']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $adapter = new RaceRefundAdapter;
        app()->instance(GatewayOperations::class, new class($adapter) extends GatewayOperations
        {
            public function __construct(private Adapter $adapter) {}

            public function for(Gateway $gateway): Adapter
            {
                return $this->adapter;
            }
        });
        $this->actingAs($actor);
        self::assertSame('pending', (new Refunds)->submit($actor, $transaction, $reservationAmount, false,
            'Synthetic checkout reservation', (string) Str::uuid())->state);
        $this->actingAs($owner);
        $cart = $this->cart($owner);
        $cart->items()->sole()->plan->prices()->first()->update(['price' => $price]);
    }

    private function renderedSplit(string $html, string $label): string
    {
        self::assertSame(1, preg_match('~<dt[^>]*>\s*' . preg_quote($label, '~') . '\s*</dt>\s*<dd[^>]*>([^<]+)</dd>~', $html, $matches));

        return trim(html_entity_decode($matches[1]));
    }

    public function test_final_review_pending_reservation_preview_matches_committed_cash_and_borrowing_split(): void
    {
        [$owner] = $this->fixture('0.00', '100.00');
        $this->reservedCart($owner, '100.00', '80.00', '100.00');
        $view = Livewire::test(CheckoutCart::class);
        self::assertSame('100.00', $view->instance()->baseSummary()->paid);
        self::assertSame('100.0000 USD', $this->renderedSplit($view->html(), 'Cash applied'));
        self::assertSame('0.0000 USD', $this->renderedSplit($view->html(), 'Borrowing applied'));
        $invoice = $this->invoice($owner, '100.00');
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '100.00', (string) Str::uuid());
        self::assertSame('100.0000', $allocation->cash_amount);
        self::assertSame('0.0000', $allocation->debt_amount);
        Http::assertNothingSent();
    }

    public function test_final_review_fractional_cash_preview_preserves_exact_split_without_independent_cent_rounding(): void
    {
        [$owner] = $this->fixture('-40.0050', '100.00');
        $this->reservedCart($owner, '50.00', '2.00', '20.00');
        $view = Livewire::test(CheckoutCart::class);
        self::assertSame('20.00', $view->instance()->baseSummary()->paid);
        self::assertSame('9.9950 USD', $this->renderedSplit($view->html(), 'Cash applied'));
        self::assertSame('10.0050 USD', $this->renderedSplit($view->html(), 'Borrowing applied'));
        $invoice = $this->invoice($owner, '20.00');
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '20.00', (string) Str::uuid());
        self::assertSame('9.9950', $allocation->cash_amount);
        self::assertSame('10.0050', $allocation->debt_amount);
        Http::assertNothingSent();
    }
}
