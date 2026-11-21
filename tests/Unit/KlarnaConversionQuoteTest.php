<?php

namespace Tests\Unit;

use App\Models\GatewayPaymentAttempt;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Gateways\Klarna\ConversionQuote;
use Paymenter\Extensions\Gateways\Klarna\ReferenceRates;
use RuntimeException;
use Tests\TestCase;

class KlarnaConversionQuoteTest extends TestCase
{
    private function attempt(string $amount = '109.01'): GatewayPaymentAttempt
    {
        return new GatewayPaymentAttempt(['id' => 101, 'invoice_id' => 102, 'gateway_id' => 103, 'user_id' => 104,
            'reference' => 'synthetic-quote', 'merchant_fingerprint' => hash('sha256', 'synthetic-merchant'),
            'pricing_fingerprint' => hash('sha256', 'synthetic-pricing'), 'currency_code' => 'USD', 'amount' => $amount,
            'pricing_payload' => ['paid' => '0.00', 'product_tax' => '4.71', 'tax_context' => ['rate' => '4.7100'],
                'lines' => [['id' => 1, 'quantity' => 1, 'description' => 'Synthetic service', 'kind' => 'product', 'total_gross' => '104.71', 'tax_amount' => '4.71'],
                    ['id' => 2, 'quantity' => 1, 'description' => 'Synthetic gateway fee', 'kind' => 'gateway_fee', 'total_gross' => '4.30', 'tax_amount' => '0.00']]]]);
    }

    private function quote(GatewayPaymentAttempt $attempt): array
    {
        return (new ConversionQuote)->create($attempt, ['purchase_country' => 'DE', 'billing_currency' => 'EUR', 'locale' => 'en-DE'],
            ['source' => 'ECB', 'date' => now()->utc()->format('Y-m-d'), 'numerator' => '1', 'denominator' => '1.1']);
    }

    public function test_exact_quote_preserves_native_usd_and_converts_tax_without_taxing_the_fee(): void
    {
        $attempt = $this->attempt();
        $quote = $this->quote($attempt);
        $this->assertSame('109.01', $attempt->amount);
        $this->assertSame('USD', $quote['native_currency']);
        $this->assertSame('EUR', $quote['provider_currency']);
        $this->assertSame(9910, $quote['allocation']['order_amount']);
        $this->assertSame(428, $quote['allocation']['order_tax_amount']);
        $this->assertSame([9519, 391], array_column($quote['allocation']['order_lines'], 'total_amount'));
        $this->assertSame([428, 0], array_column($quote['allocation']['order_lines'], 'total_tax_amount'));
        $attempt->provider_payload = ['conversion_quote' => $quote, 'purchase_country' => 'DE', 'locale' => 'en-DE', 'order_allocation' => $quote['allocation']];
        $this->assertSame($quote, (new ConversionQuote)->validate($attempt));
    }

    public function test_snapshot_cannot_be_reused_for_a_different_invoice_or_mutated_money(): void
    {
        foreach (['invoice', 'amount', 'allocation'] as $change) {
            $attempt = $this->attempt();
            $quote = $this->quote($attempt);
            if ($change === 'invoice') {
                $attempt->invoice_id++;
            }
            if ($change === 'amount') {
                $attempt->amount = '109.00';
            }
            if ($change === 'allocation') {
                $quote['allocation']['order_amount']--;
            }
            $attempt->provider_payload = ['conversion_quote' => $quote, 'purchase_country' => 'DE', 'locale' => 'en-DE', 'order_allocation' => $quote['allocation']];
            $rejected = false;
            try {
                (new ConversionQuote)->validate($attempt);
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Altered conversion identity was accepted');
        }
    }

    public function test_partial_refunds_use_the_captured_pair_and_the_final_refund_consumes_every_unit(): void
    {
        $quote = $this->quote($this->attempt());
        $helper = new ConversionQuote;
        $first = $helper->refund($quote, 0, 500);
        $second = $helper->refund($quote, 500, 500);
        $remaining = $helper->refund($quote, 1000, 9901);
        $this->assertSame(455, $first);
        $this->assertSame(454, $second);
        $this->assertSame(9910, $first + $second + $remaining);
        $this->assertSame(9910, $helper->refund($quote, 0, 10901));
    }

    public function test_a_refund_that_converts_to_zero_cannot_move_native_or_provider_money(): void
    {
        $quote = $this->quote($this->attempt());
        $quote['allocation']['order_amount'] = 10;
        $this->expectException(RuntimeException::class);
        (new ConversionQuote)->refund($quote, 0, 1);
    }

    public function test_converted_quantities_split_without_a_rounding_surcharge(): void
    {
        $attempt = $this->attempt('100.02');
        $attempt->pricing_payload = ['paid' => '0.00', 'product_tax' => '0.00', 'tax_context' => ['rate' => '0.0000'],
            'lines' => [['id' => 1, 'quantity' => 3, 'description' => 'Synthetic service', 'kind' => 'product', 'total_gross' => '100.02', 'tax_amount' => '0.00']]];
        $quote = (new ConversionQuote)->create($attempt, ['purchase_country' => 'DE', 'billing_currency' => 'EUR', 'locale' => 'en-DE'],
            ['source' => 'ECB', 'date' => now()->utc()->format('Y-m-d'), 'numerator' => '0.9', 'denominator' => '1']);
        $lines = $quote['allocation']['order_lines'];
        $this->assertSame(9002, array_sum(array_column($lines, 'total_amount')));
        $this->assertSame(3, array_sum(array_column($lines, 'quantity')));
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertSame($line['unit_price'] * $line['quantity'], $line['total_amount']);
            $this->assertSame('digital', $line['type']);
        }
    }

    public function test_reference_rates_are_dated_exact_inputs_and_identity_currency_needs_no_network(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $date = now()->utc()->format('Y-m-d');
        Http::fake(['www.ecb.europa.eu/*' => Http::response($this->xml($date, '<Cube currency="USD" rate="1.1"/><Cube currency="GBP" rate="0.85"/>'))]);
        $rates = new ReferenceRates;
        $this->assertSame(['source' => 'identity', 'date' => $date, 'numerator' => '1', 'denominator' => '1'], $rates->get('USD'));
        Http::assertNothingSent();
        $this->assertSame(['source' => 'ECB', 'date' => $date, 'numerator' => '0.85', 'denominator' => '1.1'], $rates->get('GBP'));
        $this->assertSame('1', $rates->get('EUR')['numerator']);
        Http::assertSentCount(1);
    }

    public function test_stale_future_duplicate_and_entity_rates_are_refused(): void
    {
        $today = now()->utc()->format('Y-m-d');
        foreach ([$this->xml(now()->utc()->subDays(5)->format('Y-m-d'), '<Cube currency="USD" rate="1.1"/>'),
            $this->xml(now()->utc()->addDay()->format('Y-m-d'), '<Cube currency="USD" rate="1.1"/>'),
            $this->xml($today, '<Cube currency="USD" rate="1.1"/><Cube currency="USD" rate="1.2"/>'),
            $this->xml($today, '<Cube currency="USD" rate="0"/>'),
            '<!DOCTYPE Envelope [<!ENTITY secret SYSTEM "file:///etc/passwd">]><Envelope>&secret;</Envelope>'] as $xml) {
            Cache::flush();
            Http::fake(['www.ecb.europa.eu/*' => Http::response($xml)]);
            $rejected = false;
            try {
                (new ReferenceRates)->get('EUR');
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Unsafe reference rate accepted');
        }
    }

    public function test_identity_checkout_still_refuses_more_than_one_thousand_provider_lines(): void
    {
        $attempt = $this->attempt('10.01');
        $lines = [];
        for ($id = 1; $id <= 1001; $id++) {
            $lines[] = ['id' => $id, 'quantity' => 1, 'description' => 'Synthetic service', 'kind' => 'product', 'total_gross' => '0.01', 'tax_amount' => '0.00'];
        }
        $attempt->pricing_payload = ['paid' => '0.00', 'product_tax' => '0.00', 'tax_context' => ['rate' => '0.0000'], 'lines' => $lines];
        $this->expectException(RuntimeException::class);
        (new ConversionQuote)->create($attempt, ['purchase_country' => 'US', 'billing_currency' => 'USD', 'locale' => 'en-US'],
            ['source' => 'identity', 'date' => now()->utc()->format('Y-m-d'), 'numerator' => '1', 'denominator' => '1']);
    }

    public function test_quote_confirmation_deadline_survives_a_clock_tick_during_creation(): void
    {
        $fixed = CarbonImmutable::parse('2026-10-03T00:00:00Z');
        $ticks = 0;
        Carbon::setTestNow(function () use ($fixed, &$ticks) {
            return $fixed->addSeconds($ticks++);
        });
        try {
            $quote = $this->quote($this->attempt());
            $this->assertSame(1800, $quote['confirm_before'] - $quote['created_at']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function xml(string $date, string $rates): string
    {
        return '<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="' . $date . '">' . $rates . '</Cube></Cube></gesmes:Envelope>';
    }
}
