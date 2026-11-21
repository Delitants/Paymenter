<?php

namespace App\Services\Gateways;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Services\Billing\MoneyCalculator;
use App\Services\Billing\PaymentSummary;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use RuntimeException;

final class GatewayFeePolicy
{
    public function configFields(): array
    {
        return [
            ['name' => 'customer_fee_enabled', 'label' => 'Customer gateway fee', 'type' => 'checkbox', 'database_type' => 'boolean', 'default' => false, 'description' => 'Add an untaxed fee at payment initiation.'],
            ['name' => 'customer_fee_percent', 'label' => 'Percentage of unpaid product subtotal', 'type' => 'text', 'default' => '0', 'suffix' => '%', 'validation' => ['required', 'regex:/\A(?:0|[1-9]\d?)(?:\.\d{1,4})?\z/D']],
            ['name' => 'customer_fee_fixed', 'label' => 'Fixed fee', 'type' => 'text', 'default' => '0.00', 'validation' => ['required', 'regex:/\A\d+(?:\.\d{1,2})?\z/D']],
            ['name' => 'customer_fee_currency', 'label' => 'Fixed fee currency', 'type' => 'text', 'default' => '', 'description' => 'Required for a nonzero fixed fee. No currency conversion is performed.', 'validation' => ['nullable', 'regex:/\A[A-Z]{3}\z/D']],
        ];
    }

    public function values(Gateway $gateway, string $currency): array
    {
        $settings = $gateway->settings()->whereIn('key', array_column($this->configFields(), 'name'))->get()->pluck('value', 'key')->all();
        $enabled = filter_var($settings['customer_fee_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null || !preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new InvalidArgumentException('Invalid customer fee policy.');
        }
        $calculator = new MoneyCalculator;
        $percent = $calculator->rate((string) ($settings['customer_fee_percent'] ?? '0'));
        $fixed = $calculator->money((string) ($settings['customer_fee_fixed'] ?? '0'));
        $feeCurrency = (string) ($settings['customer_fee_currency'] ?? '');
        if ($percent->isGreaterThanOrEqualTo(100) || ($feeCurrency !== '' && !preg_match('/\A[A-Z]{3}\z/D', $feeCurrency)) || ($enabled && !$fixed->isZero() && $feeCurrency !== $currency)) {
            throw new InvalidArgumentException('Customer fee rate or fixed currency is unsupported.');
        }

        return ['enabled' => $enabled, 'percent' => (string) $percent, 'fixed' => (string) $fixed, 'currency' => $feeCurrency];
    }

    public function quote(PaymentSummary $base, Gateway $gateway): PaymentSummary
    {
        $values = $this->values($gateway, $base->currency);
        if (BigDecimal::of($base->payable)->isZero()) {
            return $base;
        }
        $fee = $values['enabled'] ? (new MoneyCalculator)->fee($base->unpaidNet, $values['percent'], $values['fixed']) : '0.00';
        $fees = BigDecimal::of($base->retainedGatewayFee)->plus($fee);
        $total = BigDecimal::of($base->productGross)->plus($fees);
        $payable = $total->minus($base->paid);

        return new PaymentSummary($base->currency, $base->productNet, $base->productTax, $base->productGross, $base->unpaidNet, $base->unpaidTax, (string) $fees->toScale(2), (string) $total->toScale(2), $base->paid, $payable->isNegative() ? '0.00' : (string) $payable->toScale(2), $base->retainedGatewayFee);
    }

    public function assertSupported(Gateway $gateway, string $currency): void
    {
        $values = $this->values($gateway, $currency);
        if ($values['enabled'] && (BigDecimal::of($values['percent'])->isPositive() || BigDecimal::of($values['fixed'])->isPositive()) && !ExtensionHelper::getExtension('gateway', $gateway->extension, $gateway->settings)->supportsCustomerFeeCollection()) {
            throw new RuntimeException('This gateway cannot collect customer fees safely.');
        }
    }
}
