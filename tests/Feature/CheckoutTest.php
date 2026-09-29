<?php

namespace Tests\Feature;

use App\Livewire\Products\Checkout;
use App\Models\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private $product = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = $this->createProduct();
    }

    public function test_product_visible_on_overview_page(): void
    {
        $response = $this->get(route('category.show', [
            $this->product->product->category->slug,
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_product_visible_on_show_page(): void
    {
        $response = $this->get(route('products.show', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));
        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_checkout_page_redirects_to_cart_if_one_plan(): void
    {
        $response = $this->get(route('products.checkout', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));

        $response->assertRedirect(route('cart'));
        $cart = Cart::where('currency_code', 'USD')->first();
        $this->assertNotNull($cart);

        $this->assertNotNull($cart->items()->first());
        $response->assertCookie('cart', $cart->ulid);

        Once::flush();

        $response = $this->withCookie('cart', $cart->ulid)->get(route('cart'));
        $response->assertCookie('cart', $cart->ulid);
        $response->assertStatus(200);
        $response->assertSeeText($this->product->product->name);

        $this->assertDatabaseHas('carts', [
            'currency_code' => 'USD',
        ]);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
        ]);
    }

    public function test_checkout_page_with_multiple_plans(): void
    {
        // Add plan
        $plan = $this->product->product->plans()->create([
            'name' => 'Test Plan 2',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $plan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'USD',
        ]);

        $response = $this->get(route('products.checkout', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_checkout_page_with_changed_plan(): void
    {
        // Add plan
        $plan = $this->product->product->plans()->create([
            'name' => 'Test Plan 2',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $plan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'USD',
        ]);

        // Change plan
        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSee($this->product->product->name)
            ->assertSee('$10.00')
            ->set('plan_id', $plan->id)
            ->call('updatePricing')
            ->assertSee($this->product->plan->name)
            ->assertSee('$20.00')
            ->call('checkout');

        $this->assertDatabaseHas('carts', [
            'currency_code' => 'USD',
        ]);
    }

    public function test_checkout_page_with_plan_not_in_product(): void
    {
        $plan = $this->product->product->plans()->create([
            'name' => 'Test Plan 2',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $plan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'USD',
        ]);

        // Add plan
        $plan = $this->createProduct()->plan;

        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSee($this->product->product->name)
            ->assertSee('$10.00')
            ->set('plan_id', $plan->id)
            ->call('checkout')
            ->assertHasErrors(['plan_id' => 'exists']);
    }

    public function test_checkout_config_prices_are_calculated_from_cents(): void
    {
        $component = new class extends Checkout
        {
            public array $checkoutConfigForTest = [];

            public function getCheckoutConfig()
            {
                return $this->checkoutConfigForTest;
            }
        };

        $component->product = $this->product->product;
        $component->plan = $this->product->plan;
        $component->configOptions = [];
        $component->checkoutConfig = ['ipv4_count' => '2'];
        $component->checkoutConfigForTest = [
            [
                'name' => 'ip_addresses_section',
                'type' => 'section',
                'fields' => [
                    [
                        'name' => 'ipv4_count',
                        'type' => 'radio',
                        'prices' => [
                            '1' => 0,
                            '2' => 400,
                        ],
                    ],
                ],
            ],
        ];

        $component->updatePricing();

        $this->assertSame(14.00, $component->total->price);
    }

    public function test_checkout_view_has_client_side_price_summary_state(): void
    {
        $view = file_get_contents(base_path('themes/default/views/products/checkout.blade.php'));

        $this->assertStringContainsString('x-data="checkoutPricing', $view);
        $this->assertStringContainsString('x-text="formatPrice(totalCents)"', $view);
    }

    public function test_nested_checkout_fields_validate_their_allowed_values(): void
    {
        $component = new class extends Checkout
        {
            public function getCheckoutConfig()
            {
                return [['name' => 'network', 'type' => 'section', 'fields' => [
                    ['name' => 'ipv4_count', 'label' => 'IPv4 count', 'type' => 'radio', 'required' => true, 'options' => ['1' => 'One', '2' => 'Two']],
                    ['name' => 'ipv6_enabled', 'label' => 'IPv6', 'type' => 'checkbox'],
                ]]];
            }
        };
        $component->product = $this->product->product;
        $component->configOptions = [];
        foreach ([['ipv4_count' => '999', 'ipv6_enabled' => false], ['ipv4_count' => '1', 'ipv6_enabled' => 'invalid']] as $invalid) {
            $validator = Validator::make(['plan_id' => $this->product->plan->id, 'checkoutConfig' => $invalid], $component->rules());
            $this->assertTrue($validator->fails(), 'Nested invalid selection was accepted');
        }
        $validator = Validator::make(['plan_id' => $this->product->plan->id, 'checkoutConfig' => ['ipv4_count' => '2', 'ipv6_enabled' => true]], $component->rules());
        $this->assertFalse($validator->fails());
        $this->assertSame('IPv4 count', $component->attributes()['checkoutConfig.ipv4_count']);
    }
}
