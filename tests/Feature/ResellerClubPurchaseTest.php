<?php

namespace Tests\Feature;

use App\Jobs\Server\PaidInvoiceJob;
use App\Livewire\Cart as CartComponent;
use App\Livewire\Products\Checkout;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\ResellerClub\CatalogSync;
use Tests\Fixtures\FeeGateway;
use Tests\TestCase;

class ResellerClubPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $p = $this->createProduct();
        $server = Server::create(['name' => 'Synthetic registrar', 'extension' => 'ResellerClub', 'type' => 'server', 'enabled' => true]);
        foreach (['api_key' => 'synthetic-key', 'reseller_id' => '123', 'environment' => 'test', 'lifecycle_enabled' => '1', 'default_ns1' => 'ns1.example.test', 'default_ns2' => 'ns2.example.test'] as $key => $value) {
            $server->settings()->create(compact('key', 'value'));
        }
        $p->product->update(['server_id' => $server->id, 'hidden' => false, 'stock' => null, 'allow_quantity' => 'separated']);
        $p->plan->update(['billing_unit' => 'year', 'type' => 'recurring']);
        $p->plan->prices()->update(['setup_fee' => '0.00']);
        $p->product->settings()->create(['key' => CatalogSync::KEY, 'value' => json_encode(['server_id' => $server->id, 'tld' => '.com', 'currency' => 'USD', 'active' => true,
            'plans' => [1 => $p->plan->id], 'prices' => ['register' => [1 => '10.00'], 'renew' => [1 => '14.00']], 'provider_identity' => hash('sha256', 'test:123')])]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->withSession($this->loginUser($user));
        Http::preventStrayRequests();
        Queue::fake();
        $values = ['domain' => 'synthetic-domain.com', 'registrant_name' => 'Synthetic Buyer', 'registrant_company' => 'N/A',
            'registrant_address' => '123 Example Street', 'registrant_city' => 'Example City', 'registrant_state' => 'California',
            'registrant_country' => 'US', 'registrant_postcode' => '90210', 'registrant_phone_cc' => '1', 'registrant_phone' => '5550101234', 'registry_consent' => true,
            'nameserver_1' => 'ns1.example.test', 'nameserver_2' => 'ns2.example.test'];

        return [$p, $user, $values];
    }

    public function test_native_checkout_collects_registrant_details_and_shows_the_distinct_renewal_price(): void
    {
        [$p, $user, $values] = $this->fixture();
        Http::fake(['*/domains/available.json*' => Http::response(['synthetic-domain.com' => ['status' => 'available']])]);
        Livewire::test(Checkout::class, ['category' => $p->product->category, 'product' => $p->product->slug])
            ->assertSee('Registrant name')->assertSee('$14.00')->assertSee('checkoutConfig.registrant_country', false)
            ->set('checkoutConfig', $values + ['resellerclub_order_id' => 'injected'])
            ->call('checkout')->assertHasNoErrors()->assertRedirect(route('cart'));
        $cart = Cart::firstOrFail();
        $this->assertSame('synthetic-domain.com', $cart->items->first()->checkout_config['domain']);
        $this->assertArrayNotHasKey('resellerclub_order_id', $cart->items->first()->checkout_config);
        $this->assertSame(0, Invoice::count());
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_optional_privacy_is_billed_at_twenty_percent_markup_for_the_selected_term(): void
    {
        [$p, $user, $values] = $this->fixture();
        $metadata = json_decode($p->product->settings()->where('key', CatalogSync::KEY)->value('value'), true);
        $metadata['prices']['privacy'] = ['supported' => true, 'annual_cost' => '5.00', 'currency' => 'USD'];
        $second = $p->product->plans()->create(['name' => '2 years', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 2]);
        $second->prices()->create(['currency_code' => 'USD', 'price' => '18.00', 'setup_fee' => '0.00']);
        $metadata['plans'][2] = $second->id;
        $metadata['prices']['register'][2] = '18.00';
        $metadata['prices']['renew'][2] = '26.00';
        $p->product->settings()->where('key', CatalogSync::KEY)->update(['value' => json_encode($metadata)]);
        Http::fake(['*/domains/available.json*' => Http::response(['synthetic-domain.com' => ['status' => 'available']])]);
        $component = Livewire::test(Checkout::class, ['category' => $p->product->category, 'product' => $p->product->slug]);
        $this->assertSame(10.0, (float) $component->get('total')->total);
        $instance = $component->instance();
        Once::flush();
        $instance->checkoutConfig = $values + ['whois_protection' => true];
        $instance->updatedCheckoutConfig();
        $instance->plan_id = $second->id;
        $instance->updatedPlanId($second->id);
        $this->assertSame(30.0, (float) $instance->total->total);
        Once::flush();
        $component->set(['checkoutConfig' => $values + ['whois_protection' => true], 'plan_id' => $second->id]);
        $this->assertSame(30.0, (float) $component->get('total')->total);
        $component->call('checkout')->assertHasNoErrors();
        $item = CartItem::firstOrFail();
        $this->assertSame(30.0, (float) $item->price->total);
        $this->assertTrue($item->checkout_config['whois_protection']);
        Once::flush();
        Livewire::withCookies(['cart' => $item->cart->ulid])->test(CartComponent::class)
            ->assertSee('12.00 USD for 2 year(s)')->assertDontSee('Enabled (Included)');
        $this->assertSame('ns1.example.test', $item->checkout_config['nameserver_1']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_cart_shows_paid_privacy_for_the_items_two_year_term(): void
    {
        [$p, $user, $values] = $this->fixture();
        $metadata = json_decode($p->product->settings()->where('key', CatalogSync::KEY)->value('value'), true);
        $metadata['prices']['privacy'] = ['supported' => true, 'annual_cost' => '5.00', 'currency' => 'USD'];
        $second = $p->product->plans()->create(['name' => '2 years', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 2]);
        $second->prices()->create(['currency_code' => 'USD', 'price' => '18.00', 'setup_fee' => '0.00']);
        $metadata['plans'][2] = $second->id;
        $metadata['prices']['register'][2] = '18.00';
        $metadata['prices']['renew'][2] = '26.00';
        $p->product->settings()->where('key', CatalogSync::KEY)->update(['value' => json_encode($metadata)]);
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $p->product->id, 'plan_id' => $second->id,
            'checkout_config' => $values + ['whois_protection' => true], 'config_options' => [], 'quantity' => 1]);
        Once::flush();
        Livewire::withCookies(['cart' => $cart->ulid])->test(CartComponent::class)
            ->assertSee('12.00 USD for 2 year(s)')->assertSee('$30.00')->assertDontSee('Enabled (Included)');
        Http::assertNothingSent();
    }

    public function test_client_nameservers_are_normalized_and_retained_in_the_native_cart(): void
    {
        [$p, $user, $values] = $this->fixture();
        $values = array_replace($values, ['nameserver_1' => ' NS1.Custom.Example. ', 'nameserver_2' => 'ns2.custom.example', 'nameserver_3' => 'ns3.custom.example', 'nameserver_4' => '']);
        Http::fake(['*/domains/available.json*' => Http::response(['synthetic-domain.com' => ['status' => 'available']])]);
        Livewire::test(Checkout::class, ['category' => $p->product->category, 'product' => $p->product->slug])
            ->set('checkoutConfig', $values)->call('checkout')->assertHasNoErrors();
        $this->assertSame('ns1.custom.example', CartItem::firstOrFail()->checkout_config['nameserver_1']);
        $this->assertSame('ns3.custom.example', CartItem::firstOrFail()->checkout_config['nameserver_3']);
    }

    public function test_duplicate_nameservers_or_another_tld_cannot_enter_the_cart(): void
    {
        [$p, $user, $values] = $this->fixture();
        foreach ([['domain' => 'synthetic-domain.net', 'nameserver_1' => 'ns1.example.test', 'nameserver_2' => 'ns2.example.test'],
            ['nameserver_1' => 'NS1.EXAMPLE.TEST', 'nameserver_2' => 'ns1.example.test']] as $changes) {
            Livewire::test(Checkout::class, ['category' => $p->product->category, 'product' => $p->product->slug])
                ->set('checkoutConfig', array_replace($values, $changes))->call('checkout');
            $this->assertSame(0, CartItem::count());
        }
        Http::assertNothingSent();
    }

    public function test_root_dot_does_not_make_duplicate_nameservers_distinct(): void
    {
        [$p, $user, $values] = $this->fixture();
        Http::fake(['*/domains/available.json*' => Http::response(['synthetic-domain.com' => ['status' => 'available']])]);
        Livewire::test(Checkout::class, ['category' => $p->product->category, 'product' => $p->product->slug])
            ->set('checkoutConfig', array_replace($values, ['nameserver_1' => 'NS1.EXAMPLE.TEST.', 'nameserver_2' => 'ns1.example.test']))->call('checkout');
        $this->assertSame(0, CartItem::count());
        Http::assertNothingSent();
    }

    public function test_premium_domain_cannot_enter_the_native_cart_at_a_standard_price(): void
    {
        [$p, $user, $values] = $this->fixture();
        Http::fake(['*/domains/available.json*' => Http::response(['synthetic-domain.com' => ['status' => 'available', 'costHash' => ['create' => '900', 'renew' => '900', 'currency' => 'USD']]])]);
        Livewire::test(Checkout::class, ['category' => $p->product->category, 'product' => $p->product->slug])
            ->set('checkoutConfig', $values)->call('checkout');
        $this->assertSame(0, CartItem::count());
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_unsupported_idn_cannot_be_sold_as_an_ordinary_domain(): void
    {
        [$p, $user, $values] = $this->fixture();
        $values['domain'] = 'xn--bcher-kva.com';
        Http::fake(['*/domains/available.json*' => Http::response(['xn--bcher-kva.com' => ['status' => 'available']])]);
        Livewire::test(Checkout::class, ['category' => $p->product->category, 'product' => $p->product->slug])
            ->set('checkoutConfig', $values)->call('checkout');
        $this->assertSame(0, CartItem::count());
        Http::assertNothingSent();
    }

    public function test_registrar_contact_details_are_not_bound_to_browser_urls_or_prefilled_from_them(): void
    {
        [$p, $user, $values] = $this->fixture();
        $component = Livewire::withQueryParams(['config' => $values])->test(Checkout::class, ['category' => $p->product->category, 'product' => $p->product->slug]);
        $this->assertArrayNotHasKey('checkoutConfig', $component->effects['url'] ?? []);
        $this->assertNull($component->get('checkoutConfig.registrant_address'));
        $component->set('checkoutConfig', $values);
        $this->assertArrayNotHasKey('checkoutConfig', $component->effects['url'] ?? []);
        Http::assertNothingSent();
    }

    public function test_ordinary_checkout_retains_its_existing_config_url_binding(): void
    {
        [$p, $user, $values] = $this->fixture();
        $p->product->update(['server_id' => null]);
        $component = Livewire::withQueryParams(['config' => ['example' => 'public-selection']])->test(Checkout::class, ['category' => $p->product->category, 'product' => $p->product->slug]);
        $this->assertSame('config', $component->effects['url']['checkoutConfig']['as']);
        $this->assertSame('public-selection', $component->get('checkoutConfig.example'));
    }

    public function test_native_cart_finalization_rechecks_availability_before_creating_an_invoice(): void
    {
        [$p, $user, $values] = $this->fixture();
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $p->product->id, 'plan_id' => $p->plan->id, 'checkout_config' => $values, 'config_options' => [], 'quantity' => 1]);
        Http::fake(['*/domains/available.json*' => Http::response(['synthetic-domain.com' => ['status' => 'regthroughothers']])]);
        Once::flush();
        Livewire::withCookies(['cart' => $cart->ulid])->test(CartComponent::class)->set('tos', true)->call('checkout');
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Service::count());
    }

    public function test_native_cart_invoice_payment_queues_the_registrar_hook_and_keeps_the_service_pending(): void
    {
        [$p, $user, $values] = $this->fixture();
        class_exists(FeeGateway::class);
        $gateway = Gateway::create(['name' => 'Synthetic registrar payment', 'extension' => 'FeeGateway', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'collection_enabled', 'value' => '1']);
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $p->product->id, 'plan_id' => $p->plan->id, 'checkout_config' => $values, 'config_options' => [], 'quantity' => 1]);
        Http::fake(['*/domains/available.json*' => Http::response(['synthetic-domain.com' => ['status' => 'available']])]);
        Once::flush();
        Livewire::withCookies(['cart' => $cart->ulid])->test(CartComponent::class)->set('tos', true)->call('checkout');
        $invoice = Invoice::firstOrFail();
        $this->assertEquals('10.00', $invoice->items->first()->price);
        $invoice->update(['status' => 'paid']);
        $service = Service::firstOrFail();
        $this->assertSame('pending', $service->status);
        $this->assertNull($service->expires_at);
        $quote = json_decode(Crypt::decryptString($service->properties()->where('key', 'resellerclub_registration_quote')->value('value')), true);
        $this->assertSame('10.00', $quote['gross']);
        $this->assertSame('10.00', $quote['register']);
        $this->assertSame($user->id, $quote['user_id']);
        Queue::assertPushed(PaidInvoiceJob::class, fn ($job) => $job->invoiceItemId === $invoice->items->first()->id);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
}
