<?php

namespace Tests\Feature;

use App\Classes\Price;
use App\Models\Coupon;
use App\Models\TaxRate;
use App\Services\Billing\MoneyCalculator;
use Tests\TestCase;

class CheckoutTaxCalculationTest extends TestCase
{
    private function currency(): object
    {
        return (object) ['format' => '1,000.00', 'prefix' => '$', 'suffix' => ''];
    }

    public function test_price_honors_explicit_tax_including_zero(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $rate = new TaxRate(['rate' => '7.1250']);
        $price = new Price(['price' => '109.88', 'currency' => $this->currency(), 'tax_amount' => '7.13'], tax: $rate);
        $this->assertSame('7.13', $price->total_tax);
        $this->assertSame('$109.88', $price->formatted->total);
        $zero = new Price(['price' => '107.13', 'currency' => $this->currency(), 'tax_amount' => '0.00'], tax: $rate);
        $this->assertSame('0.00', $zero->total_tax);
    }

    public function test_percentage_coupon_is_applied_to_net_before_tax(): void
    {
        $coupon = (new Coupon)->forceFill(['type' => 'percentage', 'value' => '10.00', 'applies_to' => 'all']);
        $this->assertSame('10.00', $coupon->calculateDiscountDecimal('100.00'));
        $p = (new MoneyCalculator)->product('90.00', '0.00', 1, '7.1250', false);
        $this->assertSame('6.41', $p->tax);
        $this->assertSame('96.41', $p->gross);
        $this->assertSame('2.50', (new MoneyCalculator)->fee($p->net, '2.5', '0.25'));
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $price = new Price(['price' => '90.00', 'currency' => $this->currency()], apply_exclusive_tax: true, tax: new TaxRate(['rate' => '7.1250']));
        $this->assertSame('96.41', $price->total);
    }

    public function test_formatting_keeps_large_decimal_cents(): void
    {
        config(['settings.tax_enabled' => false]);
        $price = new Price(['price' => '9999999999999999.99', 'currency' => $this->currency()]);
        $this->assertSame('$9,999,999,999,999,999.99', $price->formatted->total);
    }

    public function test_fixed_coupon_is_capped_and_respects_setup_scope(): void
    {
        $coupon = (new Coupon)->forceFill(['type' => 'fixed', 'value' => '20.00', 'applies_to' => 'setup_fee']);
        $this->assertSame('0.00', $coupon->calculateDiscountDecimal('100.00'));
        $this->assertSame('12.34', $coupon->calculateDiscountDecimal('12.34', 'setup_fee'));
    }
}
