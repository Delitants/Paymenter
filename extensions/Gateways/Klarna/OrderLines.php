<?php

namespace Paymenter\Extensions\Gateways\Klarna;

use App\Models\GatewayPaymentAttempt;
use App\Services\Billing\MoneyCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

final class OrderLines
{
    public function build(GatewayPaymentAttempt $attempt, string $purchaseCountry): array
    {
        $pricing = $attempt->pricing_payload;
        if (!$pricing || !BigDecimal::of($pricing['paid'])->isZero()) {
            throw new RuntimeException('Klarna tax allocation requires an unpaid frozen invoice.');
        }
        $money = new MoneyCalculator;
        $us = $purchaseCountry === 'US';
        $rate = $money->rate($pricing['tax_context']['rate'])->multipliedBy(100)->toScale(0, RoundingMode::HALF_UP)->toInt();
        $lines = [];
        $taxTotal = 0;
        foreach ($pricing['lines'] as $line) {
            $quantity = $line['quantity'];
            $gross = $money->money($line['total_gross'])->multipliedBy(100)->toInt();
            $tax = $money->money($line['tax_amount'])->multipliedBy(100)->toInt();
            if ($quantity < 1 || $tax > $gross || ($line['kind'] !== 'product' && $tax !== 0)) {
                throw new RuntimeException('Klarna tax allocation is inconsistent.');
            }
            $total = $us ? $gross - $tax : $gross;
            if ($total % $quantity !== 0) {
                throw new RuntimeException('Klarna tax allocation cannot preserve native unit precision.');
            }
            $item = ['type' => $line['kind'] === 'gateway_fee' ? 'surcharge' : 'digital',
                'reference' => 'paymenter-' . $line['id'], 'name' => $line['description'], 'quantity' => $quantity,
                'unit_price' => intdiv($total, $quantity), 'total_amount' => $total, 'total_tax_amount' => $us ? 0 : $tax];
            if (!$us) {
                $item['tax_rate'] = $tax === 0 ? 0 : $rate;
                if ($tax > 0 && ($gross === $tax || BigDecimal::of($tax)->multipliedBy(10000)
                    ->dividedBy($gross - $tax, 0, RoundingMode::HALF_UP)->minus($rate)->abs()->isGreaterThan(1))) {
                    throw new RuntimeException('Klarna tax allocation cannot represent the native tax rate.');
                }
            }
            $lines[] = $item;
            $taxTotal += $tax;
        }
        if ($us && $taxTotal > 0) {
            $lines[] = ['type' => 'sales_tax', 'reference' => 'paymenter-tax', 'name' => 'Sales Tax',
                'quantity' => 1, 'unit_price' => $taxTotal, 'total_amount' => $taxTotal, 'total_tax_amount' => 0];
        }
        $amount = $money->money($attempt->amount)->multipliedBy(100)->toInt();
        if (array_sum(array_column($lines, 'total_amount')) !== $amount ||
            $taxTotal !== $money->money($pricing['product_tax'])->multipliedBy(100)->toInt()) {
            throw new RuntimeException('Klarna tax allocation does not match the frozen amount.');
        }

        return ['order_amount' => $amount, 'order_tax_amount' => $taxTotal, 'order_lines' => $lines];
    }

    public function assertCaptured(GatewayPaymentAttempt $attempt, array $order): void
    {
        if ($attempt->pricing_payload === null) {
            return;
        }
        $payload = $attempt->provider_payload;
        $expected = $payload['order_allocation'] ?? null;
        if (!is_array($expected) || ($order['purchase_country'] ?? null) !== ($payload['purchase_country'] ?? null) ||
            (array_key_exists('order_tax_amount', $order) && $order['order_tax_amount'] !== $expected['order_tax_amount']) ||
            ($order['order_amount'] ?? null) !== $expected['order_amount'] || !is_array($order['order_lines'] ?? null) ||
            count($order['order_lines']) !== count($expected['order_lines'])) {
            throw new RuntimeException('Klarna captured tax allocation does not match.');
        }
        $byReference = [];
        foreach ($order['order_lines'] as $line) {
            $reference = $line['reference'] ?? null;
            if (!is_string($reference) || isset($byReference[$reference])) {
                throw new RuntimeException('Klarna captured line identity does not match.');
            }
            $byReference[$reference] = $line;
        }
        $capturedTax = 0;
        foreach ($expected['order_lines'] as $line) {
            $remote = $byReference[$line['reference']] ?? [];
            foreach (['type', 'reference', 'quantity', 'unit_price', 'total_amount', 'total_tax_amount'] as $field) {
                if (($remote[$field] ?? null) !== $line[$field]) {
                    throw new RuntimeException('Klarna captured line amount or tax does not match.');
                }
            }
            if (($remote['tax_rate'] ?? 0) !== ($line['tax_rate'] ?? 0) ||
                ($line['type'] === 'sales_tax' && ($remote['name'] ?? null) !== 'Sales Tax')) {
                throw new RuntimeException('Klarna captured line tax rate does not match.');
            }
            // Order Management readbacks omit the Payments API's aggregate tax field.
            $capturedTax += $payload['purchase_country'] === 'US'
                ? ($remote['type'] === 'sales_tax' ? $remote['total_amount'] : 0)
                : $remote['total_tax_amount'];
        }
        if ($capturedTax !== $expected['order_tax_amount']) {
            throw new RuntimeException('Klarna captured tax allocation does not match.');
        }
    }
}
