<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\MoneyCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyCalculatorTest extends TestCase
{
    public function test_fractional_rate_and_untaxed_fee(): void
    {
        $c = new MoneyCalculator;
        $p = $c->product('100.00', '0.00', 1, '7.1250', false);
        $this->assertSame('100.00', $p->net);
        $this->assertSame('7.13', $p->tax);
        $this->assertSame('107.13', $p->gross);
        $this->assertSame('2.75', $c->fee($p->net, '2.5', '0.25'));
        $this->assertSame('0.00', $c->fee('0.00', '2.5', '0.25'));
    }

    public function test_unit_rounding_precedes_quantity(): void
    {
        $p = (new MoneyCalculator)->product('0.10', '0.10', 3, '7.1250', false);
        $this->assertSame('0.01', $p->unitTax);
        $this->assertSame('0.01', $p->setupTax);
        $this->assertSame('0.60', $p->net);
        $this->assertSame('0.06', $p->tax);
        $this->assertSame('0.66', $p->gross);
        $p = (new MoneyCalculator)->product('0.10', '0.00', 3, '7.1250', false);
        $this->assertSame('0.30', $p->net);
        $this->assertSame('0.03', $p->tax);
        $this->assertSame('0.33', $p->gross);
    }

    public function test_inclusive_price_is_not_taxed_twice(): void
    {
        $p = (new MoneyCalculator)->product('107.13', '21.43', 2, '7.1250', true);
        $this->assertSame('100.00', $p->unitNet);
        $this->assertSame('7.13', $p->unitTax);
        $this->assertSame('21.43', $p->setupGross);
        $this->assertSame('17.12', $p->tax);
    }

    public function test_credit_allocation_and_fee(): void
    {
        $c = new MoneyCalculator;
        $a = $c->allocateRemaining('100.00', '7.13', '50.00');
        $this->assertSame(['net' => '46.67', 'tax' => '3.33'], $a);
        $this->assertSame('1.42', $c->fee($a['net'], '2.5', '0.25'));
        $this->assertSame(['net' => '0.00', 'tax' => '0.00'], $c->allocateRemaining('0.00', '0.00', '0.00'));
    }

    public function test_inclusive_product_and_setup_round_tax_half_up_at_a_half_cent(): void
    {
        $p = (new MoneyCalculator)->product('0.03', '0.03', 2, '20.0000', true);
        $this->assertSame('0.02', $p->unitNet);
        $this->assertSame('0.01', $p->unitTax);
        $this->assertSame('0.02', $p->setupNet);
        $this->assertSame('0.01', $p->setupTax);
        $this->assertSame('0.04', $p->tax);
        $this->assertSame('0.12', $p->gross);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_decimal_and_negative_values_are_rejected(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MoneyCalculator)->product($amount, '0.00', 1, '7.1250', false);
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($value) => [$value], ['NaN', 'INF', '1e2', '-0.01', '1.001', '', ' 1.00', '1,000.00']);
    }

    public function test_invalid_rate_and_allocation_are_rejected(): void
    {
        foreach (['-1', '1e1', '7.12345'] as $rate) {
            try {
                (new MoneyCalculator)->fee('100.00', $rate, '0.25');
                $this->fail('Invalid fee rate accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        (new MoneyCalculator)->allocateRemaining('100.00', '7.13', '107.14');
    }
}
