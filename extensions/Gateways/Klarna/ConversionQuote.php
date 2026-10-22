<?php

namespace Paymenter\Extensions\Gateways\Klarna;

use App\Models\GatewayPaymentAttempt;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use RuntimeException;
use Throwable;

final class ConversionQuote
{
    public function create(GatewayPaymentAttempt $attempt, array $market, array $rates): array
    {
        $country = $market['purchase_country'];
        if ($attempt->currency_code !== 'USD' || Markets::currency($country) !== $market['billing_currency'] ||
            $market['locale'] !== 'en-' . $country || !in_array($rates['source'] ?? null, ['ECB', 'identity'], true) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rates['date'] ?? '')) {
            throw new RuntimeException('The conversion market or native currency is invalid.');
        }
        $ratio = $this->ratio($rates);
        if ($market['billing_currency'] === 'USD' && !$ratio->isEqualTo(1)) {
            throw new RuntimeException('USD checkout requires identity conversion.');
        }
        $createdAt = now()->timestamp;
        $quote = ['version' => 1, 'attempt_id' => $attempt->id, 'reference' => $attempt->reference, 'invoice_id' => $attempt->invoice_id,
            'gateway_id' => $attempt->gateway_id, 'user_id' => $attempt->user_id, 'merchant_fingerprint' => $attempt->merchant_fingerprint,
            'pricing_fingerprint' => $attempt->pricing_fingerprint, 'native_currency' => 'USD', 'native_amount' => $attempt->amount,
            'provider_currency' => $market['billing_currency'], 'purchase_country' => $country, 'locale' => $market['locale'], 'rates' => $rates,
            'created_at' => $createdAt, 'confirm_before' => $createdAt + 1800,
            'allocation' => $this->allocation($attempt, $country, $ratio)];
        $quote['fingerprint'] = $this->fingerprint($quote);

        return $quote;
    }

    public function validate(GatewayPaymentAttempt $attempt): array
    {
        try {
            $payload = $attempt->provider_payload;
            $q = $payload['conversion_quote'];
            if (($q['version'] ?? null) !== 1 || !is_string($q['fingerprint'] ?? null) || !hash_equals($q['fingerprint'], $this->fingerprint($q))) {
                throw new RuntimeException;
            }
            foreach (['attempt_id' => 'id', 'reference' => 'reference', 'invoice_id' => 'invoice_id', 'gateway_id' => 'gateway_id', 'user_id' => 'user_id',
                'merchant_fingerprint' => 'merchant_fingerprint', 'pricing_fingerprint' => 'pricing_fingerprint', 'native_currency' => 'currency_code', 'native_amount' => 'amount'] as $field => $attribute) {
                if ($q[$field] !== $attempt->$attribute) {
                    throw new RuntimeException;
                }
            }
            if ($q['native_currency'] !== 'USD' || Markets::currency($q['purchase_country']) !== $q['provider_currency'] || $q['locale'] !== 'en-' . $q['purchase_country'] ||
                ($payload['purchase_country'] ?? null) !== $q['purchase_country'] || ($payload['locale'] ?? null) !== $q['locale'] ||
                ($payload['order_allocation'] ?? null) !== $q['allocation'] || !is_int($q['created_at']) || !is_int($q['confirm_before']) ||
                $q['confirm_before'] !== $q['created_at'] + 1800 || $q['allocation'] !== $this->allocation($attempt, $q['purchase_country'], $this->ratio($q['rates']))) {
                throw new RuntimeException;
            }

            return $q;
        } catch (Throwable) {
            throw new RuntimeException('The saved conversion quote could not be verified.');
        }
    }

    public function refund(array $quote, int $previous, int $amount): int
    {
        $native = BigDecimal::of($quote['native_amount'])->multipliedBy(100)->toInt();
        $provider = $quote['allocation']['order_amount'];
        if ($native <= 0 || !is_int($provider) || $provider <= 0 || $previous < 0 || $amount <= 0 || $amount > $native - $previous) {
            throw new RuntimeException('The converted refund exceeds the captured amount.');
        }
        $ratio = BigRational::of($provider)->dividedBy($native);
        $delta = $this->round($ratio->multipliedBy($previous + $amount)) - $this->round($ratio->multipliedBy($previous));
        if ($delta <= 0) {
            throw new RuntimeException('Choose a larger refund amount; this amount converts to zero.');
        }

        return $delta;
    }

    public function providerRefunded(array $quote, int $nativeRefunded): int
    {
        if ($nativeRefunded === 0) {
            return 0;
        }

        return $this->refund($quote, 0, $nativeRefunded);
    }

    private function fingerprint(array $quote): string
    {
        unset($quote['fingerprint']);

        return hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
    }

    private function ratio(array $rates): BigRational
    {
        foreach (['numerator', 'denominator'] as $key) {
            if (!is_string($rates[$key] ?? null) || !preg_match('/^[0-9]+(?:\.[0-9]{1,12})?$/D', $rates[$key]) || !BigDecimal::of($rates[$key])->isPositive()) {
                throw new RuntimeException('Invalid exact conversion rate.');
            }
        }

        return BigRational::of($rates['numerator'])->dividedBy($rates['denominator']);
    }

    private function round(BigRational $value): int
    {
        return $value->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    /** Preserve the rounded aggregate, with stable line-order tie breaking. */
    private function apportioned(array $values, BigRational $ratio): array
    {
        $parts = [];
        $sum = 0;
        $floors = 0;
        foreach ($values as $index => $amount) {
            $exact = $ratio->multipliedBy($amount);
            $floor = $exact->toScale(0, RoundingMode::DOWN)->toInt();
            $parts[$index] = ['amount' => $floor, 'remainder' => $exact->minus($floor)];
            $sum += $amount;
            $floors += $floor;
        }
        $order = array_keys($parts);
        usort($order, fn ($a, $b) => -$parts[$a]['remainder']->compareTo($parts[$b]['remainder']) ?: $a <=> $b);
        $residual = $this->round($ratio->multipliedBy($sum)) - $floors;
        foreach (array_slice($order, 0, $residual) as $index) {
            $parts[$index]['amount']++;
        }

        return array_map(fn ($p) => $p['amount'], $parts);
    }

    private function allocation(GatewayPaymentAttempt $attempt, string $country, BigRational $ratio): array
    {
        $native = (new OrderLines)->build($attempt, $country);
        if ($native['order_amount'] <= 0 || count($native['order_lines']) > 1000) {
            throw new RuntimeException('The converted order amount or line count is unavailable.');
        }
        if ($ratio->isEqualTo(1)) {
            return $native;
        }
        $gross = $this->apportioned(array_column($native['order_lines'], 'total_amount'), $ratio);
        $tax = $this->apportioned(array_column($native['order_lines'], 'total_tax_amount'), $ratio);
        $lines = [];
        foreach ($native['order_lines'] as $index => $line) {
            $quantity = $line['quantity'];
            $g = $gross[$index];
            $t = $tax[$index];
            if ($g < $t || $quantity < 1) {
                throw new RuntimeException('Converted tax allocation is invalid.');
            }
            $unit = intdiv($g, $quantity);
            $unitTax = intdiv($t, $quantity);
            $breaks = array_values(array_unique([0, $g % $quantity, $t % $quantity, $quantity]));
            sort($breaks);
            for ($part = 1; $part < count($breaks); $part++) {
                $n = $breaks[$part] - $breaks[$part - 1];
                $price = $unit + ($breaks[$part - 1] < $g % $quantity ? 1 : 0);
                $taxPrice = $unitTax + ($breaks[$part - 1] < $t % $quantity ? 1 : 0);
                $rate = $taxPrice === 0 ? 0 : $line['tax_rate'];
                if ($taxPrice > 0 && ($price <= $taxPrice || abs(BigRational::of($taxPrice * 10000)->dividedBy($price - $taxPrice)->toScale(0, RoundingMode::HALF_UP)->toInt() - $rate) > 1)) {
                    throw new RuntimeException('Converted tax allocation cannot preserve the invoice tax rate.');
                }
                $split = array_replace($line, ['reference' => $line['reference'] . (count($breaks) > 2 ? '-fx-' . $part : ''),
                    'quantity' => $n, 'unit_price' => $price, 'total_amount' => $price * $n, 'total_tax_amount' => $taxPrice * $n, 'tax_rate' => $rate]);
                $lines[] = $split;
            }
        }
        $amount = array_sum(array_column($lines, 'total_amount'));
        if ($amount <= 0 || count($lines) > 1000) {
            throw new RuntimeException('The converted order amount or line count is unavailable.');
        }

        return ['order_amount' => $amount, 'order_tax_amount' => array_sum(array_column($lines, 'total_tax_amount')), 'order_lines' => $lines];
    }
}
