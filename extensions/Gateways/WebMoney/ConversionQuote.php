<?php

namespace Paymenter\Extensions\Gateways\WebMoney;

use App\Models\GatewayPaymentAttempt;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use RuntimeException;
use Throwable;

final class ConversionQuote
{
    public function create(GatewayPaymentAttempt $attempt, array $rates): array
    {
        if ($attempt->currency_code !== 'USD') {
            throw new RuntimeException('WebMoney conversion requires a USD invoice.');
        }
        $created = now()->timestamp;
        $quote = ['version' => 1, 'attempt_id' => $attempt->id, 'reference' => $attempt->reference,
            'invoice_id' => $attempt->invoice_id, 'gateway_id' => $attempt->gateway_id, 'user_id' => $attempt->user_id,
            'merchant_fingerprint' => $attempt->merchant_fingerprint, 'pricing_fingerprint' => $attempt->pricing_fingerprint,
            'native_currency' => 'USD', 'native_amount' => $attempt->amount, 'provider_currency' => 'EUR',
            'provider_amount' => $this->converted($attempt->amount, $rates), 'rates' => $rates,
            'created_at' => $created, 'confirm_before' => $created + 1800];
        $quote['fingerprint'] = $this->fingerprint($quote);

        return $quote;
    }

    public function validate(GatewayPaymentAttempt $attempt): array
    {
        try {
            $quote = $attempt->provider_payload['webmoney_conversion_quote'];
            if (($quote['version'] ?? null) !== 1 || !is_string($quote['fingerprint'] ?? null) ||
                !hash_equals($quote['fingerprint'], $this->fingerprint($quote))) {
                throw new RuntimeException;
            }
            foreach (['attempt_id' => 'id', 'reference' => 'reference', 'invoice_id' => 'invoice_id', 'gateway_id' => 'gateway_id',
                'user_id' => 'user_id', 'merchant_fingerprint' => 'merchant_fingerprint', 'pricing_fingerprint' => 'pricing_fingerprint',
                'native_currency' => 'currency_code', 'native_amount' => 'amount'] as $field => $attribute) {
                if ($quote[$field] !== $attempt->$attribute) {
                    throw new RuntimeException;
                }
            }
            if ($quote['native_currency'] !== 'USD' || $quote['provider_currency'] !== 'EUR' ||
                $quote['provider_amount'] !== $this->converted($attempt->amount, $quote['rates']) ||
                !is_int($quote['created_at']) || !is_int($quote['confirm_before']) || $quote['created_at'] > now()->timestamp ||
                $quote['confirm_before'] !== $quote['created_at'] + 1800) {
                throw new RuntimeException;
            }

            return $quote;
        } catch (Throwable) {
            throw new RuntimeException('The saved WebMoney conversion quote could not be verified.');
        }
    }

    public function refund(array $quote, int $previous, int $amount): int
    {
        $native = BigDecimal::of($quote['native_amount'])->multipliedBy(100)->toInt();
        $provider = BigDecimal::of($quote['provider_amount'])->multipliedBy(100)->toInt();
        if ($native <= 0 || $provider <= 0 || $previous < 0 || $amount <= 0 || $amount > $native - $previous) {
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
        return $nativeRefunded === 0 ? 0 : $this->refund($quote, 0, $nativeRefunded);
    }

    private function round(BigRational $amount): int
    {
        return $amount->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    private function converted(string $amount, array $rates): string
    {
        if (($rates['source'] ?? null) !== 'ECB' || ($rates['numerator'] ?? null) !== '1' ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rates['date'] ?? '') || !is_string($rates['denominator'] ?? null) ||
            !preg_match('/^[0-9]+(?:\.[0-9]{1,12})?$/D', $rates['denominator']) || !BigDecimal::of($rates['denominator'])->isPositive()) {
            throw new RuntimeException('A verified EUR reference rate is required.');
        }
        $native = BigDecimal::of($amount);
        $provider = BigRational::of($native)->dividedBy($rates['denominator'])->toScale(2, RoundingMode::HALF_UP);
        if (!$native->isPositive() || !$provider->isPositive()) {
            throw new RuntimeException('The converted payment amount is unavailable.');
        }

        return (string) $provider;
    }

    private function fingerprint(array $quote): string
    {
        unset($quote['fingerprint']);

        return hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
    }
}
