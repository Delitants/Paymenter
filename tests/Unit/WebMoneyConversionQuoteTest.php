<?php

namespace Tests\Unit;

use App\Models\GatewayPaymentAttempt;
use Paymenter\Extensions\Gateways\WebMoney\ConversionQuote;
use RuntimeException;
use Tests\TestCase;

class WebMoneyConversionQuoteTest extends TestCase
{
    private function attempt(string $amount = '109.88'): GatewayPaymentAttempt
    {
        return new GatewayPaymentAttempt(['id' => 101, 'invoice_id' => 102, 'gateway_id' => 103, 'user_id' => 104,
            'reference' => 'synthetic-webmoney-quote', 'merchant_fingerprint' => hash('sha256', 'synthetic-merchant'),
            'pricing_fingerprint' => hash('sha256', 'synthetic-pricing'), 'currency_code' => 'USD', 'amount' => $amount]);
    }

    private function quote(GatewayPaymentAttempt $attempt): array
    {
        $quote = (new ConversionQuote)->create($attempt, ['source' => 'ECB', 'date' => now()->utc()->format('Y-m-d'), 'numerator' => '1', 'denominator' => '1.25']);
        $attempt->provider_payload = ['webmoney_conversion_quote' => $quote];

        return $quote;
    }

    public function test_quote_locks_rounded_euro_to_original_usd_and_rejects_mutation(): void
    {
        $attempt = $this->attempt();
        $quote = $this->quote($attempt);
        $this->assertSame('87.90', $quote['provider_amount']);
        $this->assertSame('109.88', $quote['native_amount']);
        $this->assertSame($quote, (new ConversionQuote)->validate($attempt));
        $quote['provider_amount'] = '87.91';
        $unsigned = $quote;
        unset($unsigned['fingerprint']);
        $quote['fingerprint'] = hash('sha256', json_encode($unsigned, JSON_THROW_ON_ERROR));
        $attempt->provider_payload = ['webmoney_conversion_quote' => $quote];
        $this->expectException(RuntimeException::class);
        (new ConversionQuote)->validate($attempt);
    }

    public function test_converted_refunds_use_cumulative_capture_rounding_and_exhaust_the_euro_total(): void
    {
        $quote = $this->quote($this->attempt());
        $converter = new ConversionQuote;
        try {
            $this->assertSame(2000, $converter->refund($quote, 0, 2500));
            $this->assertSame(2000, $converter->refund($quote, 2500, 2500));
            $this->assertSame(4790, $converter->refund($quote, 5000, 5988));
            $this->assertSame(8790, $converter->providerRefunded($quote, 10988));
            $this->assertSame(0, $converter->providerRefunded($quote, 0));
        } catch (\Error $error) {
            $this->fail('Converted refund amounts must be computed from the captured pair: ' . $error->getMessage());
        }
    }

    public function test_converted_refund_zero_provider_unit_is_rejected_before_a_write(): void
    {
        $quote = (new ConversionQuote)->create($this->attempt('1.00'), ['source' => 'ECB', 'date' => now()->utc()->format('Y-m-d'), 'numerator' => '1', 'denominator' => '3']);
        $this->expectException(RuntimeException::class);
        (new ConversionQuote)->refund($quote, 0, 1);
    }

    public function test_quote_cannot_be_reused_for_another_native_invoice(): void
    {
        $attempt = $this->attempt();
        $this->quote($attempt);
        $attempt->invoice_id++;
        $this->expectException(RuntimeException::class);
        (new ConversionQuote)->validate($attempt);
    }
}
