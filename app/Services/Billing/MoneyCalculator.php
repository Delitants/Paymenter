<?php

namespace App\Services\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class MoneyCalculator
{
    public function money(string $value): BigDecimal
    {
        return $this->decimal($value, 2);
    }

    public function rate(string $value): BigDecimal
    {
        $rate = $this->decimal($value, 4);
        if ($rate->isGreaterThan('999.9999')) {
            throw new InvalidArgumentException('Percentage exceeds supported precision.');
        }

        return $rate;
    }

    private function decimal(string $value, int $scale): BigDecimal
    {
        if (!preg_match('/\A\d+(?:\.\d+)?\z/D', $value)) {
            throw new InvalidArgumentException('Expected a nonnegative decimal.');
        }
        try {
            $number = BigDecimal::of($value)->toScale($scale);
        } catch (MathException $e) {
            throw new InvalidArgumentException('Decimal precision would be lost.', previous: $e);
        }
        if ($scale === 2 && $number->isGreaterThan('9999999999999999.99')) {
            throw new InvalidArgumentException('Amount exceeds supported precision.');
        }

        return $number;
    }

    public function product(string $unitPrice, string $setupPrice, int $quantity, string $taxRate, bool $inclusive): ProductAmounts
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }
        $rate = $this->rate($taxRate);
        [$net, $tax, $gross] = $this->unit($this->money($unitPrice), $rate, $inclusive);
        [$setupNet, $setupTax, $setupGross] = $this->unit($this->money($setupPrice), $rate, $inclusive);

        return new ProductAmounts(
            (string) $net, (string) $tax, (string) $gross,
            (string) $setupNet, (string) $setupTax, (string) $setupGross,
            $quantity,
            (string) $net->plus($setupNet)->multipliedBy($quantity),
            (string) $tax->plus($setupTax)->multipliedBy($quantity),
            (string) $gross->plus($setupGross)->multipliedBy($quantity),
        );
    }

    private function unit(BigDecimal $price, BigDecimal $rate, bool $inclusive): array
    {
        if ($inclusive) {
            $tax = $price->multipliedBy($rate)->dividedBy($rate->plus(100), 2, RoundingMode::HALF_UP);

            return [$price->minus($tax), $tax, $price];
        }
        $tax = $price->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);

        return [$price, $tax, $price->plus($tax)];
    }

    public function fee(string $netBase, string $percent, string $fixed): string
    {
        $net = $this->money($netBase);
        $rate = $this->rate($percent);
        $fixed = $this->money($fixed);
        if ($net->isZero()) {
            return '0.00';
        }

        return (string) $net->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP)->plus($fixed);
    }

    public function allocateRemaining(string $net, string $tax, string $remainingGross): array
    {
        $net = $this->money($net);
        $tax = $this->money($tax);
        $remaining = $this->money($remainingGross);
        $gross = $net->plus($tax);
        if ($remaining->isGreaterThan($gross)) {
            throw new InvalidArgumentException('Remaining amount exceeds product gross.');
        }
        if ($gross->isZero() || $remaining->isZero()) {
            return ['net' => '0.00', 'tax' => '0.00'];
        }
        $unpaidNet = $remaining->multipliedBy($net)->dividedBy($gross, 2, RoundingMode::HALF_UP);

        return ['net' => (string) $unpaidNet, 'tax' => (string) $remaining->minus($unpaidNet)];
    }
}
