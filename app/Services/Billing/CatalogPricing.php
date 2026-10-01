<?php

namespace App\Services\Billing;

use App\Classes\Price;
use App\Classes\Settings;
use App\Helpers\ExtensionHelper;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class CatalogPricing
{
    public function quote(Product $product, Plan $plan, array $options, array $checkoutConfig, string $currency, ?Coupon $coupon = null, ?User $user = null, ?array $taxContext = null, ?array $checkoutFields = null): Price
    {
        [$unit, $setup] = $this->planAmounts($plan, $currency);
        foreach ($product->configOptions as $option) {
            $selected = collect($options)->firstWhere('option_id', $option->id);
            $value = $selected['value'] ?? null;
            $child = $option->type === 'checkbox' ? ($value ? $option->children->first() : null) :
                (in_array($option->type, ['text', 'number']) ? null : $option->children->firstWhere('id', $value));
            if (!$child) {
                continue;
            }
            $childPlan = $child->availablePlans($currency)->firstWhere('type', 'free') ??
                $child->availablePlans($currency)->where('billing_period', $plan->billing_period)->where('billing_unit', $plan->billing_unit)->first();
            if (!$childPlan) {
                throw new InvalidArgumentException('Selected option is unavailable for this billing period and currency.');
            }
            [$optionUnit, $optionSetup] = $this->planAmounts($childPlan, $currency);
            $unit = $unit->plus($optionUnit);
            $setup = $setup->plus($optionSetup);
        }

        $unit = $unit->plus($this->checkoutAmount($checkoutFields ?? ExtensionHelper::getCheckoutConfig($product, $checkoutConfig, $plan), $checkoutConfig));
        $tax = config('settings.tax_enabled', false) ? Settings::tax($user) : null;
        $context = $taxContext ?? ['rate' => (string) ($tax?->rate ?? '0'), 'inclusive' => config('settings.tax_type', 'inclusive') === 'inclusive'];
        $calculator = new MoneyCalculator;
        $original = $calculator->product((string) $unit->toScale(2), (string) $setup->toScale(2), 1, $context['rate'], $context['inclusive']);
        $amounts = $original;
        $unitDiscount = $setupDiscount = BigDecimal::of('0.00');
        if ($coupon && ($coupon->products->isEmpty() || $coupon->products->contains($product->id))) {
            $unitDiscount = BigDecimal::of($coupon->calculateDiscountDecimal($original->unitNet));
            $setupDiscount = BigDecimal::of($coupon->calculateDiscountDecimal($original->setupNet, 'setup_fee'));
            $net = BigDecimal::of($original->unitNet)->minus($unitDiscount);
            $setupNet = BigDecimal::of($original->setupNet)->minus($setupDiscount);
            $amounts = $calculator->product((string) $net->toScale(2), (string) $setupNet->toScale(2), 1, $context['rate'], false);
        }
        $price = new Price(['price' => $unitDiscount->isZero() ? $original->unitGross : $amounts->unitGross,
            'setup_fee' => $setupDiscount->isZero() ? $original->setupGross : $amounts->setupGross,
            'currency' => Currency::findOrFail($currency),
            'tax_amount' => $unitDiscount->isZero() ? $original->unitTax : $amounts->unitTax,
            'setup_tax_amount' => $setupDiscount->isZero() ? $original->setupTax : $amounts->setupTax]);
        $price->setOriginalAmounts($original->unitGross, $original->setupGross);
        $price->setDiscount((string) BigDecimal::of($original->unitGross)->plus($original->setupGross)->minus($price->total)->toScale(2));

        return $price;
    }

    private function planAmounts(Plan $plan, string $currency): array
    {
        if ($plan->type === 'free') {
            return [BigDecimal::of('0.00'), BigDecimal::of('0.00')];
        }
        $price = $plan->prices->firstWhere('currency_code', $currency);
        if (!$price) {
            throw new InvalidArgumentException('Plan is unavailable in this currency.');
        }

        return [(new MoneyCalculator)->money((string) $price->getRawOriginal('price')), (new MoneyCalculator)->money((string) ($price->getRawOriginal('setup_fee') ?? '0'))];
    }

    private function checkoutAmount(array $fields, array $values): BigDecimal
    {
        $amount = BigDecimal::of('0.00');
        foreach ($fields as $field) {
            if (($field['type'] ?? null) === 'section') {
                $amount = $amount->plus($this->checkoutAmount($field['fields'] ?? [], $values));
            } elseif (isset($field['prices'], $values[$field['name']]) && isset($field['prices'][$values[$field['name']]])) {
                $minor = (string) $field['prices'][$values[$field['name']]];
                if (!preg_match('/\A\d+\z/D', $minor)) {
                    throw new InvalidArgumentException('Checkout option price must be nonnegative integer minor units.');
                }
                $amount = $amount->plus(BigDecimal::of($minor)->dividedBy(100, 2));
            }
        }

        return $amount;
    }
}
