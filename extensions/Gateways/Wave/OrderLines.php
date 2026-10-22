<?php

namespace Paymenter\Extensions\Gateways\Wave;

use App\Models\GatewayPaymentAttempt;
use App\Services\Billing\MoneyCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use RuntimeException;

final class OrderLines
{
    public function assertTax(array $tax, string $businessId, string $expectedRate): void
    {
        if (!is_string($tax['id'] ?? null) || $tax['id'] === '' || ($tax['business']['id'] ?? null) !== $businessId ||
            ($tax['isCompound'] ?? null) !== false || ($tax['isArchived'] ?? null) !== false ||
            !is_string($tax['rate'] ?? null) || !preg_match('/\A\d+(?:\.\d+)?\z/D', $tax['rate']) ||
            !BigDecimal::of($tax['rate'])->multipliedBy(100)->isEqualTo((new MoneyCalculator)->rate($expectedRate))) {
            throw new RuntimeException('Wave business sales tax does not match the issued tax.');
        }
    }

    public function build(GatewayPaymentAttempt $attempt, string $productId, ?array $verifiedTax): array
    {
        $pricing = $attempt->pricing_payload;
        if (!$pricing || !BigDecimal::of($pricing['paid'])->isZero() || $productId === '') {
            throw new RuntimeException('Wave tax allocation requires an unpaid frozen invoice.');
        }
        $money = new MoneyCalculator;
        $items = [];
        $sum = $taxSum = BigDecimal::of('0.00');
        foreach ($pricing['lines'] as $line) {
            $quantity = $line['quantity'];
            $gross = $money->money($line['total_gross']);
            $tax = $money->money($line['tax_amount']);
            if ($quantity < 1 || $tax->isGreaterThan($gross) || ($line['kind'] !== 'product' && !$tax->isZero())) {
                throw new RuntimeException('Wave tax allocation is inconsistent.');
            }
            $net = $gross->minus($tax);
            try {
                $unit = $net->dividedBy($quantity, 2);
            } catch (MathException) {
                throw new RuntimeException('Wave tax allocation cannot preserve native unit precision.');
            }
            $taxes = [];
            $split = false;
            if ($tax->isPositive()) {
                if (!$verifiedTax) {
                    throw new RuntimeException('Wave requires a verified business sales tax.');
                }
                $this->assertTax($verifiedTax, $verifiedTax['business']['id'] ?? '', $pricing['tax_context']['rate']);
                $taxes = [['salesTaxId' => $verifiedTax['id']]];
                $calculated = $net->multipliedBy($verifiedTax['rate'])->toScale(2, RoundingMode::HALF_UP);
                if (!$calculated->isEqualTo($tax)) {
                    if (!$unit->multipliedBy($verifiedTax['rate'])->toScale(2, RoundingMode::HALF_UP)->multipliedBy($quantity)->isEqualTo($tax)) {
                        throw new RuntimeException('Wave tax allocation cannot preserve native tax rounding.');
                    }
                    $split = true;
                }
            }
            $count = $split ? $quantity : 1;
            if (count($items) + $count > 1000) {
                throw new RuntimeException('Wave tax allocation exceeds supported line count.');
            }
            for ($index = 0; $index < $count; $index++) {
                $items[] = ['productId' => $productId, 'description' => 'Paymenter item ' . $line['id'] . ($split ? ' / ' . ($index + 1) : ''),
                    'quantity' => $split ? '1' : (string) $quantity, 'unitPrice' => (string) $unit, 'taxes' => $taxes];
            }
            $sum = $sum->plus($gross);
            $taxSum = $taxSum->plus($tax);
        }
        if (!$sum->isEqualTo($attempt->amount) || !$taxSum->isEqualTo($pricing['product_tax'])) {
            throw new RuntimeException('Wave tax allocation does not match the frozen amount.');
        }

        return $items;
    }

    public function assertInvoice(GatewayPaymentAttempt $attempt, array $invoice): void
    {
        if ($attempt->pricing_payload === null) {
            return;
        }
        $payload = $attempt->provider_payload;
        $items = $payload['items'] ?? null;
        if (!is_array($items) || !is_array($invoice['items'] ?? null) || count($items) !== count($invoice['items'])) {
            throw new RuntimeException('Wave invoice tax allocation does not match.');
        }
        $remoteItems = [];
        foreach ($invoice['items'] as $item) {
            $description = $item['description'] ?? null;
            if (!is_string($description) || isset($remoteItems[$description])) {
                throw new RuntimeException('Wave invoice line identity does not match.');
            }
            $remoteItems[$description] = $item;
        }
        $taxSum = $total = BigDecimal::of('0.00');
        foreach ($items as $item) {
            $remote = $remoteItems[$item['description']] ?? [];
            if (($remote['product']['id'] ?? null) !== $item['productId'] ||
                !$this->equal($remote['quantity'] ?? null, $item['quantity']) || !$this->equal($remote['unitPrice'] ?? null, $item['unitPrice']) ||
                !is_array($remote['taxes'] ?? null) || count($remote['taxes']) !== count($item['taxes'])) {
                throw new RuntimeException('Wave invoice line identity, amount or taxes do not match.');
            }
            $subtotal = BigDecimal::of($item['unitPrice'])->multipliedBy($item['quantity']);
            $tax = BigDecimal::of('0.00');
            if ($item['taxes']) {
                $record = $remote['taxes'][0]['salesTax'] ?? [];
                $this->assertTax($record, $invoice['business']['id'] ?? '', $attempt->pricing_payload['tax_context']['rate']);
                if ($record['id'] !== $item['taxes'][0]['salesTaxId'] || !is_array($payload['verified_tax'] ?? null) ||
                    $record['id'] !== $payload['verified_tax']['id']) {
                    throw new RuntimeException('Wave invoice sales tax identity does not match.');
                }
                $tax = $subtotal->multipliedBy($payload['verified_tax']['rate'])->toScale(2, RoundingMode::HALF_UP);
                if (!$this->equal($remote['taxes'][0]['amount']['value'] ?? null, (string) $tax)) {
                    throw new RuntimeException('Wave invoice tax amount does not match.');
                }
            }
            if (!$this->equal($remote['subtotal']['value'] ?? null, (string) $subtotal) ||
                !$this->equal($remote['total']['value'] ?? null, (string) $subtotal->plus($tax))) {
                throw new RuntimeException('Wave invoice line total does not match.');
            }
            $taxSum = $taxSum->plus($tax);
            $total = $total->plus($subtotal)->plus($tax);
        }
        if (!$taxSum->isEqualTo($attempt->pricing_payload['product_tax']) || !$total->isEqualTo($attempt->amount)) {
            throw new RuntimeException('Wave invoice allocation does not match the frozen amount.');
        }
    }

    private function equal(mixed $value, string $expected): bool
    {
        return is_string($value) && preg_match('/\A\d+(?:\.\d+)?\z/D', $value) && BigDecimal::of($value)->isEqualTo($expected);
    }
}
