<?php

namespace App\Models;

use App\Classes\Price;
use App\Observers\CartItemObserver;
use App\Services\Billing\CatalogPricing;
use App\Services\Billing\InvoicePricing;
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

    public function priceForInvoice(?Invoice $invoice = null): Price
    {
        return (new CatalogPricing)->quote(
            $this->product, $this->plan, $this->config_options ?? [], $this->checkout_config ?? [],
            $this->cart->currency_code, $this->cart->coupon, auth()->user() ?? $this->cart->user,
            $invoice ? (new InvoicePricing)->context($invoice) : null,
        );
    }

    public function price(): Attribute
    {
        return Attribute::make(get: fn () => $this->priceForInvoice());
    }
}
