<?php

namespace Tests\Feature;

use App\Classes\PDF;
use App\Classes\Price;
use App\Classes\Synths\PriceSynth;
use App\Livewire\Cart as CheckoutCart;
use App\Livewire\Client\Credits;
use App\Livewire\Invoices\Show;
use App\Models\Cart;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\FeeGateway;
use Tests\TestCase;

class CheckoutGatewayFeeTest extends TestCase
{
    use UsesCommittedDatabase;

    private function fixture(): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        class_exists(FeeGateway::class);
        // The first HTTP termination reloads global settings from the database.
        foreach (['tax_enabled' => true, 'tax_type' => 'exclusive', 'tax_scope' => 'all',
            'default_currency' => 'USD', 'credits_enabled' => true, 'mail_must_verify' => false, 'tos' => null] as $key => $value) {
            Setting::updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value]);
        }
        // Laravel's dot-key test overrides require an array, not the provider's Collection.
        config(['settings' => collect(config('settings'))->all()]);
        TaxRate::create(['name' => 'Synthetic tax', 'rate' => '7.1250', 'country' => 'all']);
        $user = User::factory()->create();
        $this->actingAs($user);
        $product = $this->createProduct(['allow_quantity' => 'combined']);
        $product->plan->prices()->first()->update(['price' => '100.00']);
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
        $g = $this->gateway();
        Livewire::withCookies(['cart' => $cart->ulid]);

        return [$user, $product, $cart, $g];
    }

    private function gateway(string $percent = '2.5'): Gateway
    {
        $g = Gateway::create(['name' => 'Synthetic checkout gateway ' . Gateway::count(), 'extension' => 'FeeGateway', 'type' => 'gateway', 'enabled' => true]);
        foreach (['collection_enabled' => '1', 'customer_fee_enabled' => '1', 'customer_fee_percent' => $percent,
            'customer_fee_fixed' => '0.25', 'customer_fee_currency' => 'USD'] as $key => $value) {
            $g->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $g;
    }

    public function test_cart_coupon_tax_is_recomputed_before_quantity(): void
    {
        [,, $cart] = $this->fixture();
        $coupon = Coupon::forceCreate(['code' => 'SYNTHETICNET', 'type' => 'percentage', 'value' => '10.00', 'applies_to' => 'all', 'recurring' => 1]);
        $cart->update(['coupon_id' => $coupon->id]);
        $price = $cart->items()->sole()->price;
        $this->assertSame('96.41', $price->price_decimal);
        $this->assertSame('6.41', $price->total_tax);
    }

    public function test_setup_only_coupon_preserves_unchanged_inclusive_product_gross(): void
    {
        [, $p, $cart] = $this->fixture();
        config(['settings.tax_type' => 'inclusive']);
        TaxRate::where('country', 'all')->update(['rate' => '20.0000']);
        $p->plan->prices()->first()->update(['price' => '0.03', 'setup_fee' => '0.03']);
        $coupon = Coupon::forceCreate(['code' => 'SYNTHETICSETUPONLY', 'type' => 'fixed', 'value' => '1.00', 'applies_to' => 'setup_fee', 'recurring' => 1]);
        $cart->update(['coupon_id' => $coupon->id]);
        $price = $cart->items()->sole()->price;
        $this->assertSame('0.03', $price->price_decimal);
        $this->assertSame('0.00', $price->setup_fee_decimal);
        $this->assertSame('0.01', $price->total_tax);
    }

    public function test_native_price_snapshot_keeps_exact_amounts_and_issued_tax(): void
    {
        $this->fixture();
        $price = new Price(['price' => '999999999999999.99', 'currency' => Currency::find('USD'), 'tax_amount' => '6.41', 'setup_tax_amount' => '0.00']);
        [$payload] = PriceSynth::dehydrate($price);
        config(['settings.tax_enabled' => false]);
        $restored = PriceSynth::hydrate($payload);
        $this->assertSame('999999999999999.99', $restored->price_decimal);
        $this->assertSame('$6.41', $restored->formatted->tax);
    }

    public function test_large_native_price_keeps_cents(): void
    {
        [, $product, $cart] = $this->fixture();
        config(['settings.tax_enabled' => false]);
        $product->plan->prices()->first()->update(['price' => '999999999999999.99']);
        $this->assertSame('999999999999999.99', $cart->items()->sole()->price->price_decimal);
    }

    #[DataProvider('paidTampering')]
    public function test_native_paid_replay_requires_paid_invoice_and_matching_transaction(string $change): void
    {
        [$u,,, $g] = $this->fixture();
        $i = Invoice::factory()->create(['user_id' => $u->id, 'status' => 'pending']);
        $i->items()->create(['price' => '107.13', 'quantity' => 1, 'tax_amount' => '7.13', 'description' => 'Synthetic replay']);
        $ledger = new PaymentAttempts;
        $a = $ledger->begin($g, $i, hash('sha256', 'synthetic merchant'), 'USD');
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-paid-record');
        if ($change === 'status') {
            DB::table('invoices')->where('id', $i->id)->update(['status' => 'cancelled']);
        } else {
            DB::table('invoice_transactions')->where('invoice_id', $i->id)->update(['amount' => '109.87']);
        }
        $this->expectException(\RuntimeException::class);
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-paid-record');
    }

    public static function paidTampering(): array
    {
        return [['status'], ['amount']];
    }

    public function test_switching_gateway_only_changes_preview(): void
    {
        [,,, $g] = $this->fixture();
        $other = $this->gateway('5');
        $component = Livewire::test(CheckoutCart::class)->set('use_credits', false)->set('gateway', $g->id);
        $this->assertSame('2.75', $component->instance()->paymentSummary()->gatewayFee);
        $this->assertSame('109.88', $component->instance()->paymentSummary()->payable);
        $component->assertSee('$100.00')->assertSee('$7.13')->assertSee('$2.75')->assertSee('$109.88');
        $component->set('gateway', $other->id);
        $this->assertSame('5.25', $component->instance()->paymentSummary()->gatewayFee);
        $this->assertSame('112.38', $component->instance()->paymentSummary()->payable);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, GatewayPaymentAttempt::count());
        Http::assertNothingSent();
    }

    public function test_coupon_quantity_and_option_recalculate_tax_and_fee(): void
    {
        [, $product, $cart, $g] = $this->fixture();
        $product->plan->prices()->first()->update(['setup_fee' => '10.00']);
        $option = ConfigOption::create(['name' => 'Synthetic backup', 'type' => 'checkbox']);
        $child = $option->children()->create(['name' => 'Enabled', 'type' => 'select']);
        $option->products()->attach($product->product);
        $plan = $child->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month']);
        $plan->prices()->create(['price' => '3.00', 'setup_fee' => '7.00', 'currency_code' => 'USD']);
        $coupon = Coupon::forceCreate(['code' => 'SYNTHETIC10', 'type' => 'percentage', 'value' => '10.00', 'applies_to' => 'all', 'recurring' => 1]);
        $cart->update(['coupon_id' => $coupon->id]);
        $item = $cart->items()->first();
        $item->update(['quantity' => 2, 'config_options' => [['option_id' => $option->id, 'option_type' => 'checkbox', 'option_name' => $option->name, 'option_env_variable' => null, 'value_name' => $child->name, 'value' => $child->id]]]);
        $component = Livewire::test(CheckoutCart::class)->set('use_credits', false)->set('gateway', $g->id);
        $summary = $component->instance()->paymentSummary();
        $this->assertSame('216.00', $summary->productNet);
        $this->assertSame('15.38', $summary->productTax);
        $this->assertSame('5.65', $summary->gatewayFee);
        $this->assertSame('237.03', $summary->payable);
        $component->call('checkout')->assertHasNoErrors();
        $invoice = Invoice::sole();
        $this->assertSame('115.69', $invoice->items()->sole()->price);
        $this->assertSame('15.38', $invoice->items()->sole()->tax_amount);
        $this->assertSame('110.34', (string) $invoice->items()->sole()->reference->getRawOriginal('price'));
        $this->assertSame(0, GatewayPaymentAttempt::count());
    }

    public function test_small_quantity_keeps_native_unit_rounding(): void
    {
        [, $product, $cart, $g] = $this->fixture();
        $product->plan->prices()->first()->update(['price' => '0.10']);
        $cart->items()->first()->update(['quantity' => 3]);
        $component = Livewire::test(CheckoutCart::class)->set('use_credits', false)->set('gateway', $g->id);
        $this->assertSame('0.03', $component->instance()->paymentSummary()->productTax);
        $component->call('checkout');
        $this->assertSame('0.11', InvoiceItem::sole()->price);
        $this->assertSame('0.03', InvoiceItem::sole()->tax_amount);
    }

    public function test_credit_paid_checkout_has_no_fee_and_preserves_product_value(): void
    {
        [$u,,, $g] = $this->fixture();
        $credit = $u->credits()->create(['amount' => '200.00', 'currency_code' => 'USD']);
        $component = Livewire::test(CheckoutCart::class)->set('use_credits', true)->set('gateway', $g->id);
        $this->assertSame('0.00', $component->instance()->paymentSummary()->gatewayFee);
        $this->assertSame('0.00', $component->instance()->paymentSummary()->payable);
        $component->call('checkout');
        $i = Invoice::sole();
        $this->assertSame('paid', $i->status);
        $this->assertSame(0, $i->items()->where('kind', 'gateway_fee')->count());
        $this->assertSame('92.87', (string) $credit->fresh()->getRawOriginal('amount'));
        $this->assertSame('107.13', (string) $i->items()->sole()->reference->getRawOriginal('price'));
        $this->assertSame(0, GatewayPaymentAttempt::count());
    }

    public function test_pdf_invoice_and_checkout_share_totals(): void
    {
        [,,, $g] = $this->fixture();
        $component = Livewire::test(CheckoutCart::class)->set('use_credits', false)->set('gateway', $g->id);
        $component->call('checkout');
        $i = Invoice::sole();
        $show = Livewire::test(Show::class, ['invoice' => $i])->set('selectedMethod', 'gateway-' . $g->id);
        $this->assertSame('109.88', $show->instance()->paymentSummary()->payable);
        $this->assertSame(0, $i->items()->where('kind', 'gateway_fee')->count());
        $ledger = new PaymentAttempts;
        $a = $ledger->begin($g, $i, hash('sha256', 'synthetic merchant'), 'USD');
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-receipt');
        $html = view('pdf.invoice', ['invoice' => $i->fresh()])->render();
        foreach (['$100.00', '$7.13', '$2.75', '$109.88'] as $amount) {
            $this->assertStringContainsString($amount, $html);
        }
        $this->assertStringNotContainsString('$102.75', $html);
        $this->assertStringStartsWith('%PDF', PDF::generateInvoice($i->fresh())->output());
        $download = Livewire::test(Show::class, ['invoice' => $i->fresh()])->call('downloadPDF');
        $download->assertFileDownloaded('invoice-' . $i->fresh()->number . '.pdf');
        $this->assertSame('%PDF', substr(base64_decode($download->effects['download']['content'], true), 0, 4));
        $download->assertFileDownloaded(contentType: 'application/pdf');
    }

    public function test_checkout_rejects_forged_payment_values(): void
    {
        $this->fixture();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(CheckoutCart::class)->set('total.price', '0.01');
    }

    public function test_disabled_gateway_is_not_eligible_or_carried_to_invoice(): void
    {
        [,,, $g] = $this->fixture();
        $g->update(['enabled' => false]);
        $component = Livewire::test(CheckoutCart::class)->set('use_credits', false)->set('gateway', $g->id);
        $this->assertSame([], $component->instance()->gateways());
        $this->assertSame('0.00', $component->instance()->paymentSummary()->gatewayFee);
        $component->call('checkout');
        $this->assertSame(0, Invoice::count());
    }

    public function test_zero_checkout_has_no_invoice_or_gateway_fee(): void
    {
        [, $product,, $g] = $this->fixture();
        $product->plan->prices()->first()->update(['price' => '0.00']);
        $component = Livewire::test(CheckoutCart::class)->set('gateway', $g->id);
        $this->assertSame('0.00', $component->instance()->paymentSummary()->gatewayFee);
        $component->call('checkout');
        $this->assertSame(0, Invoice::count());
        $this->assertSame('active', Service::sole()->status);
        $this->assertSame('0.00', (string) Service::sole()->getRawOriginal('price'));
    }

    public function test_claimed_invoice_keeps_original_gateway_and_blocks_credits(): void
    {
        [$u,,, $g] = $this->fixture();
        $other = $this->gateway('5');
        $credit = $u->credits()->create(['currency_code' => 'USD', 'amount' => '200.00']);
        $i = Invoice::factory()->create(['user_id' => $u->id, 'status' => 'pending']);
        $i->items()->create(['price' => '107.13', 'quantity' => 1, 'tax_amount' => '7.13', 'description' => 'Synthetic claimed invoice']);
        $a = (new PaymentAttempts)->begin($g, $i, hash('sha256', 'synthetic merchant'), 'USD');
        $g->settings()->where('key', 'customer_fee_percent')->first()->update(['value' => '15']);
        $component = Livewire::test(Show::class, ['invoice' => $i])->set('showPayModal', true)->assertSet('selectedMethod', 'gateway-' . $g->id)->assertSee('requires reconciliation');
        $this->assertSame([$g->id], array_column($component->instance()->gateways(), 'id'));
        $this->assertSame('109.88', $component->instance()->paymentSummary()->payable);
        $component->set('selectedMethod', 'credit')->call('processPayment');
        $component->set('selectedMethod', 'gateway-' . $other->id)->call('processPayment');
        $this->assertSame('200.00', (string) $credit->fresh()->getRawOriginal('amount'));
        $this->assertSame(0, $i->transactions()->count());
        $this->assertSame('109.88', $a->fresh()->amount);
        $this->assertSame(1, GatewayPaymentAttempt::count());
        Http::assertNothingSent();
    }

    public function test_finite_recurring_coupon_expires_after_its_last_paid_cycle(): void
    {
        [$u, $product] = $this->fixture();
        $coupon = Coupon::forceCreate(['code' => 'SYNTHETIC2CYCLES', 'type' => 'percentage', 'value' => '10.00', 'applies_to' => 'all', 'recurring' => 2]);
        $service = Service::factory()->create(['user_id' => $u->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'coupon_id' => $coupon->id, 'price' => '96.41', 'quantity' => 1, 'status' => 'active', 'expires_at' => now()->addDay()]);
        for ($cycle = 0; $cycle < 2; $cycle++) {
            $invoice = Invoice::factory()->create(['user_id' => $u->id, 'status' => 'paid']);
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => '96.41', 'tax_amount' => '6.41', 'quantity' => 1, 'description' => 'Synthetic completed cycle']);
        }
        $this->assertSame('107.13', $service->fresh()->calculatePrice());
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame('107.13', (string) $service->fresh()->getRawOriginal('price'));
        $this->assertSame('107.13', $service->invoices()->where('status', 'pending')->sole()->items()->sole()->price);
    }

    public function test_fee_does_not_change_credit_deposit_value(): void
    {
        [,,, $g] = $this->fixture();
        config(['settings.credits_minimum_deposit' => 1, 'settings.credits_maximum_deposit' => 500, 'settings.credits_maximum_credit' => 1000]);
        Livewire::test(Credits::class)->assertStatus(200)->set('amount', '10.00')->set('gateway', $g->id)->call('addCredit')->assertStatus(200)->assertHasNoErrors();
        $i = Invoice::sole();
        $a = GatewayPaymentAttempt::sole();
        $this->assertSame('10.50', $a->amount);
        $this->assertSame('0.00', $i->items()->where('reference_type', Credit::class)->sole()->tax_amount);
        $ledger = new PaymentAttempts;
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-credit-deposit');
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-credit-deposit');
        $this->assertSame('10.00', (string) $i->user->credits()->sole()->getRawOriginal('amount'));
    }

    public function test_credit_settlement_preserves_large_wallet_cents(): void
    {
        [$user,,, $gateway] = $this->fixture();
        $wallet = $user->credits()->create(['currency_code' => 'USD', 'amount' => '99999999999999.90']);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        $invoice->items()->create(['description' => 'Synthetic wallet cents', 'price' => '0.01', 'quantity' => 1,
            'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        $ledger = new PaymentAttempts;
        $attempt = $ledger->begin($gateway, $invoice, hash('sha256', 'synthetic merchant'), 'USD');
        $ledger->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, '0.26', 'USD', 'synthetic-wallet-cents');
        $this->assertSame('99999999999999.91', (string) $wallet->fresh()->getRawOriginal('amount'));
    }
}
