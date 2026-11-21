<?php

namespace App\Classes;

use App\Models\TaxRate;
use App\Services\Billing\MoneyCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Class Price
 */
class Price
{
    public $price;

    public $currency;

    public $setup_fee;

    public $has_setup_fee;

    public $is_free;

    public $dontShowUnavailablePrice;

    public object $formatted;

    public $tax = 0;

    public $setup_fee_tax = 0;

    public $discount = 0;

    public $original_price;

    public $original_setup_fee;

    private string $decimalPrice = '0.00';

    private string $decimalSetup = '0.00';

    private string $decimalOriginalPrice = '0.00';

    private string $decimalOriginalSetup = '0.00';

    private function decimalValue($current, string $original): string
    {
        return (float) $original === $current ? $original : (string) BigDecimal::of((string) ($current ?? 0))->toScale(2, RoundingMode::HALF_UP);
    }

    private function compatibilityAmount(mixed $value): string
    {
        $amount = BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
        // Catalog and collection inputs remain strict in MoneyCalculator. Native
        // display/proration consumers also need signed, rounded adjustments.
        (new MoneyCalculator)->money((string) $amount->abs());

        return (string) $amount;
    }

    public function setDiscount($discount)
    {
        $this->discount = $discount;
    }

    public function setOriginalAmounts(string $price, string $setup): void
    {
        $this->decimalOriginalPrice = (string) (new MoneyCalculator)->money($price);
        $this->decimalOriginalSetup = (string) (new MoneyCalculator)->money($setup);
        $this->original_price = (float) $this->decimalOriginalPrice;
        $this->original_setup_fee = (float) $this->decimalOriginalSetup;
    }

    public function hasDiscount(): bool
    {
        return $this->discount > 0;
    }

    public function __construct($priceAndCurrency = null, $free = false, $dontShowUnavailablePrice = false, $apply_exclusive_tax = false, TaxRate|int|null $tax = null)
    {
        if (is_array($priceAndCurrency)) {
            $priceAndCurrency = (object) $priceAndCurrency;
        }
        if ($free) {
            $this->price = 0;
            $this->currency = $priceAndCurrency->currency ?? null;
            $this->is_free = true;

            $this->formatted = (object) [
                'price' => $this->format($this->price_decimal),
                'setup_fee' => $this->format($this->setup_fee_decimal),
                'tax' => $this->format($this->tax),
                'setup_fee_tax' => $this->format($this->setup_fee_tax),
                'total' => $this->format($this->total),
                'total_tax' => $this->format($this->total_tax),
            ];

            return;
        }

        $this->price = $this->compatibilityAmount($priceAndCurrency->price->price ?? $priceAndCurrency->price ?? '0');
        $this->currency = $priceAndCurrency->currency;
        if (is_array($this->currency)) {
            $this->currency = (object) $this->currency;
        }
        $this->setup_fee = $this->compatibilityAmount($priceAndCurrency->price->setup_fee ?? $priceAndCurrency->setup_fee ?? '0');

        // We save the original so we can revert back to it when removing a coupon
        $this->original_price = $this->price;
        $this->original_setup_fee = $this->setup_fee;

        // Explicit amounts belong to an issued calculation, independent of today's settings.
        $explicitTax = property_exists($priceAndCurrency, 'tax_amount');
        $explicitSetupTax = property_exists($priceAndCurrency, 'setup_tax_amount');
        $tax ??= $priceAndCurrency->tax ?? null;
        if (config('settings.tax_enabled', false) && (!$explicitTax || (!$explicitSetupTax && $this->setup_fee > 0))) {
            $tax ??= Settings::tax();
            if ($tax) {
                $amounts = (new MoneyCalculator)->product(
                    (string) BigDecimal::of($this->price)->abs(), (string) BigDecimal::of($this->setup_fee)->abs(), 1, (string) ($tax instanceof TaxRate ? $tax->rate : $tax),
                    config('settings.tax_type', 'inclusive') === 'inclusive' || !$apply_exclusive_tax,
                );
                if (!$explicitTax) {
                    $negative = BigDecimal::of($this->price)->isNegative();
                    $this->price = $this->original_price = (string) ($negative ? BigDecimal::of($amounts->unitGross)->negated() : BigDecimal::of($amounts->unitGross));
                    $this->tax = (string) ($negative ? BigDecimal::of($amounts->unitTax)->negated() : BigDecimal::of($amounts->unitTax));
                }
                if (!$explicitSetupTax) {
                    $negative = BigDecimal::of($this->setup_fee)->isNegative();
                    $this->setup_fee = $this->original_setup_fee = (string) ($negative ? BigDecimal::of($amounts->setupGross)->negated() : BigDecimal::of($amounts->setupGross));
                    $this->setup_fee_tax = (string) ($negative ? BigDecimal::of($amounts->setupTax)->negated() : BigDecimal::of($amounts->setupTax));
                }
            }
        }
        if ($explicitTax) {
            $this->tax = (string) (new MoneyCalculator)->money((string) $priceAndCurrency->tax_amount);
        }
        if ($explicitSetupTax) {
            $this->setup_fee_tax = (string) (new MoneyCalculator)->money((string) $priceAndCurrency->setup_tax_amount);
        }
        $this->decimalPrice = $this->price;
        $this->decimalSetup = $this->setup_fee;
        $this->decimalOriginalPrice = $this->original_price;
        $this->decimalOriginalSetup = $this->original_setup_fee;
        // Retain the legacy numeric display interface. Billing consumers use decimal accessors.
        $this->price = (float) $this->price;
        $this->setup_fee = (float) $this->setup_fee;
        $this->original_price = (float) $this->original_price;
        $this->original_setup_fee = (float) $this->original_setup_fee;
        $this->has_setup_fee = isset($this->setup_fee) ? $this->setup_fee > 0 : false;
        $this->dontShowUnavailablePrice = $dontShowUnavailablePrice;

        $this->formatted = (object) [
            'total' => $this->format($this->total),
            'price' => $this->format($this->price_decimal),
            'setup_fee' => $this->format($this->setup_fee_decimal),
            'tax' => $this->format($this->tax),
            'setup_fee_tax' => $this->format($this->setup_fee_tax),
            'total_tax' => $this->format($this->total_tax),
        ];
    }

    public function format($price)
    {
        if ($this->is_free) {
            return 'Free';
        }
        if (!$this->currency) {
            if ($this->dontShowUnavailablePrice) {
                return '';
            }

            return 'Not available in your currency';
        }
        $decimal = (string) BigDecimal::of((string) ($price ?? 0))->toScale(2, RoundingMode::HALF_UP);
        [$integer, $fraction] = explode('.', $decimal);
        [$point, $group] = match ($this->currency->format) {
            '1.000,00' => [',', '.'],
            '1 000,00' => [',', ' '],
            '1 000.00' => ['.', ' '],
            '1,000.00' => ['.', ','],
            default => ['.', ''],
        };
        $integer = $group === '' ? $integer : preg_replace('/\B(?=(\d{3})+(?!\d))/', $group, $integer);
        $price = $integer . $point . $fraction;

        return $this->currency->prefix . $price . $this->currency->suffix;
    }

    public function __toString()
    {
        return $this->formatted->total;
    }

    public function __get($name)
    {
        return match ($name) {
            'price_decimal' => $this->decimalValue($this->price, $this->decimalPrice),
            'setup_fee_decimal' => $this->decimalValue($this->setup_fee, $this->decimalSetup),
            'original_price_decimal' => $this->decimalValue($this->original_price, $this->decimalOriginalPrice),
            'original_setup_fee_decimal' => $this->decimalValue($this->original_setup_fee, $this->decimalOriginalSetup),
            'total' => (string) BigDecimal::of($this->price_decimal)->plus($this->setup_fee_decimal)->toScale(2),
            'total_tax' => (string) BigDecimal::of($this->tax)->plus($this->setup_fee_tax)->toScale(2),
            // Subtotal is price + setup_fee - tax - setup_fee_tax
            'subtotal' => (string) BigDecimal::of($this->total)->minus($this->total_tax)->toScale(2),
            'available' => $this->currency || $this->is_free ? true : false,
            default => $this->$name ?? null,
        };
    }
}
