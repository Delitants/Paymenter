<?php

namespace Tests\Unit;

use App\Models\GatewayPaymentAttempt;
use Paymenter\Extensions\Gateways\Klarna\OrderLines;
use RuntimeException;
use Tests\TestCase;

class KlarnaCapturedOrderLinesTest extends TestCase
{
    private function allocation(string $country): array
    {
        $us = $country === 'US';
        $lines = [
            ['type' => 'digital', 'reference' => 'product', 'quantity' => 1, 'unit_price' => $us ? 10000 : 10713,
                'total_amount' => $us ? 10000 : 10713, 'total_tax_amount' => $us ? 0 : 713],
            ['type' => 'surcharge', 'reference' => 'fee', 'quantity' => 1, 'unit_price' => 275,
                'total_amount' => 275, 'total_tax_amount' => 0],
        ];
        if ($us) {
            $lines[] = ['type' => 'sales_tax', 'reference' => 'tax', 'name' => 'Sales Tax', 'quantity' => 1,
                'unit_price' => 713, 'total_amount' => 713, 'total_tax_amount' => 0];
        } else {
            $lines[0]['tax_rate'] = 713;
            $lines[1]['tax_rate'] = 0;
        }
        $allocation = ['order_amount' => 10988, 'order_tax_amount' => 713, 'order_lines' => $lines];
        $attempt = new GatewayPaymentAttempt(['pricing_payload' => ['product_tax' => '7.13'],
            'provider_payload' => ['purchase_country' => $country, 'order_allocation' => $allocation]]);
        $order = ['purchase_country' => $country, 'order_amount' => 10988, 'order_lines' => $lines];

        return [$attempt, $order];
    }

    public function test_us_order_management_readback_without_aggregate_tax_matches_exact_sales_tax_line(): void
    {
        [$attempt, $order] = $this->allocation('US');
        (new OrderLines)->assertCaptured($attempt, $order);
        $this->addToAssertionCount(1);
    }

    public function test_inclusive_order_management_readback_without_aggregate_tax_matches_exact_line_taxes(): void
    {
        [$attempt, $order] = $this->allocation('DE');
        (new OrderLines)->assertCaptured($attempt, $order);
        $this->addToAssertionCount(1);
    }

    public function test_missing_aggregate_tax_does_not_allow_altered_sales_tax_or_fee(): void
    {
        foreach ([1, 2] as $index) {
            [$attempt, $order] = $this->allocation('US');
            $order['order_lines'][$index]['total_amount']++;
            try {
                (new OrderLines)->assertCaptured($attempt, $order);
                $this->fail('Altered captured allocation was accepted');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('does not match', $exception->getMessage());
            }
        }
    }

    public function test_missing_aggregate_tax_does_not_allow_altered_inclusive_tax(): void
    {
        [$attempt, $order] = $this->allocation('DE');
        $order['order_lines'][0]['total_tax_amount']--;
        $this->expectException(RuntimeException::class);
        (new OrderLines)->assertCaptured($attempt, $order);
    }

    public function test_present_aggregate_tax_must_still_match(): void
    {
        [$attempt, $order] = $this->allocation('US');
        $order['order_tax_amount'] = 712;
        $this->expectException(RuntimeException::class);
        (new OrderLines)->assertCaptured($attempt, $order);
    }
}
