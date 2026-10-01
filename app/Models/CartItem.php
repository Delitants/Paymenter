<?php

namespace App\Models;

use App\Classes\Price;
use App\Helpers\ExtensionHelper;
use App\Observers\CartItemObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(CartItemObserver::class)]
class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'plan_id',
        'config_options',
        'checkout_config',
        'quantity',
    ];

    protected $casts = [
        'config_options' => 'array',
        'checkout_config' => 'array',
    ];

    // Set default loads

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function price(): Attribute
    {
        return Attribute::make(
            get: function () {
                $total = 0;
                $setup_fee = 0;
                $total += $this->plan->price()->price;
                $setup_fee += $this->plan->price()->setup_fee;
                $this->product->configOptions->each(function ($option) use (&$total, &$setup_fee) {
                    $selected = (object) collect($this->config_options)->firstWhere('option_id', $option->id);

                    // If checkbox and selected, add price of first child (only one)
                    if ($option->type === 'checkbox' && $selected?->value) {
                        $total += $option->children->first()?->price(billing_period: $this->plan->billing_period, billing_unit: $this->plan->billing_unit)->price;
                        $setup_fee += $option->children->first()?->price(billing_period: $this->plan->billing_period, billing_unit: $this->plan->billing_unit)->setup_fee;

                        return;
                    }

                    // Skip text, number and checkbox types as they have no price
                    if (in_array($option->type, ['text', 'number', 'checkbox'])) {
                        $total += 0;
                        $setup_fee += 0;

                        return;
                    }
                    if (!$selected || !isset($selected->value)) {
                        return;
                    }

                    $total += $option->children->where('id', $selected?->value)->first()?->price(billing_period: $this->plan->billing_period, billing_unit: $this->plan->billing_unit)->price;
                    $setup_fee += $option->children->where('id', $selected?->value)->first()?->price(billing_period: $this->plan->billing_period, billing_unit: $this->plan->billing_unit)->setup_fee;
                });

                // Add checkout config prices (e.g., IP addresses section)
                if (is_array($this->checkout_config)) {
                    $checkoutConfig = $this->checkout_config;
                } else {
                    $checkoutConfig = json_decode($this->checkout_config, true);
                }
                if (is_array($checkoutConfig)) {
                    // Get checkout config from product extension
                    $extensionCheckoutConfig = ExtensionHelper::getCheckoutConfig($this->product, $checkoutConfig, $this->plan);
                    foreach ($extensionCheckoutConfig as $config) {
                        // Handle section type with nested fields
                        if (isset($config['type']) && $config['type'] === 'section' && isset($config['fields'])) {
                            foreach ($config['fields'] as $field) {
                                if (isset($field['prices']) && isset($checkoutConfig[$field['name']])) {
                                    $selectedValue = $checkoutConfig[$field['name']];
                                    if (isset($field['prices'][$selectedValue])) {
                                        $total += $field['prices'][$selectedValue] / 100; // Convert from cents
                                    }
                                }
                            }
                        }
                        // Handle simple priced fields
                        elseif (isset($config['prices']) && isset($checkoutConfig[$config['name']])) {
                            $selectedValue = $checkoutConfig[$config['name']];
                            if (isset($config['prices'][$selectedValue])) {
                                $total += $config['prices'][$selectedValue] / 100;
                            }
                        }
                    }
                }

                $price = new Price([
                    'price' => $total,
                    'currency' => $this->plan->price()->currency,
                    'setup_fee' => $setup_fee,
                ], apply_exclusive_tax: true);

                if (!$this->cart->coupon_id || !$this->cart->coupon) {
                    return $price;
                }

                if ($this->cart->coupon->products->isNotEmpty() && !$this->cart->coupon->products->contains($this->product_id)) {
                    return $price;
                }

                $coupon = $this->cart->coupon;
                $pdiscount = $coupon->calculateDiscount($price->price);
                $sdiscount = $coupon->calculateDiscount($price->setup_fee, 'setup_fee');

                $price->price -= $pdiscount;
                $price->setup_fee -= $sdiscount;

                $price->setDiscount($pdiscount + $sdiscount);

                return $price;
            }
        );
    }
}
