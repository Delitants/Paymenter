<?php

namespace App\Services\Billing;

use App\Classes\Settings;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class InvoicePricing
{
    public function capture(Invoice $invoice): void
    {
        $tax = config('settings.tax_enabled', false) ? Settings::tax($invoice->user) : null;
        $invoice->pricing_tax_rate = (string) (new MoneyCalculator)->rate((string) ($tax ? $tax->rate : '0'));
        $invoice->pricing_tax_name = $tax ? $tax->name : null;
        $invoice->pricing_tax_country = $tax ? $tax->country : null;
        $invoice->pricing_tax_inclusive = config('settings.tax_type', 'inclusive') === 'inclusive';
    }

    public function context(Invoice $invoice): array
    {
        // Historical paid snapshots are the source of truth, including an explicit zero.
        if ($invoice->status === 'paid' && ($snapshot = $invoice->snapshot) && $snapshot->tax_rate !== null) {
            return ['rate' => $snapshot->tax_rate, 'name' => $snapshot->tax_name, 'country' => $snapshot->tax_country, 'inclusive' => $invoice->pricing_tax_inclusive ?? true];
        }
        if ($invoice->pricing_tax_rate !== null) {
            return ['rate' => $invoice->pricing_tax_rate, 'name' => $invoice->pricing_tax_name, 'country' => $invoice->pricing_tax_country, 'inclusive' => $invoice->pricing_tax_inclusive];
        }
        $tax = config('settings.tax_enabled', false) ? Settings::tax($invoice->user) : null;

        return ['rate' => (string) (new MoneyCalculator)->rate((string) ($tax ? $tax->rate : '0')), 'name' => $tax ? $tax->name : null, 'country' => $tax ? $tax->country : null, 'inclusive' => config('settings.tax_type', 'inclusive') === 'inclusive'];
    }

    public function lineTax(Invoice $invoice, string $grossUnit, int $quantity): string
    {
        if ($invoice->pricing_tax_rate === null) {
            // Untouched legacy invoice rows used aggregate gross extraction.
            return (new MoneyCalculator)->product((string) BigDecimal::of($grossUnit)->multipliedBy($quantity), '0.00', 1, $this->context($invoice)['rate'], true)->tax;
        }

        return (new MoneyCalculator)->product($grossUnit, '0.00', $quantity, $this->context($invoice)['rate'], true)->tax;
    }

    private function legacyTaxes(Invoice $invoice): array
    {
        if ($invoice->pricing_tax_rate !== null) {
            return [];
        }
        $gross = $tax = BigDecimal::of('0.00');
        $taxes = [];
        $query = $invoice->items()->whereNull('tax_amount')->where('kind', 'product')->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        foreach ($query->get() as $item) {
            $gross = $gross->plus(BigDecimal::of($item->price)->multipliedBy($item->quantity));
            $nextTax = BigDecimal::of($this->lineTax($invoice, (string) $gross, 1));
            $taxes[$item->id] = (string) $nextTax->minus($tax);
            $tax = $nextTax;
        }

        return $taxes;
    }

    public function freezeLegacy(Invoice $invoice): void
    {
        if ($invoice->pricing_tax_rate !== null) {
            return;
        }
        $taxes = $this->legacyTaxes($invoice);
        $context = $this->context($invoice);
        $invoice->forceFill(['pricing_tax_rate' => $context['rate'], 'pricing_tax_name' => $context['name'],
            'pricing_tax_country' => $context['country'], 'pricing_tax_inclusive' => $context['inclusive']])->save();
        foreach ($invoice->items()->whereNull('tax_amount')->lockForUpdate()->get() as $item) {
            $item->update(['tax_amount' => $taxes[$item->id] ?? '0.00']);
        }
    }

    public function summary(Invoice $invoice): PaymentSummary
    {
        $gross = $tax = $fee = BigDecimal::of('0.00');
        $legacyTaxes = $this->legacyTaxes($invoice);
        $items = $invoice->items();
        $transactions = $invoice->transactions();
        if (DB::transactionLevel() > 0) {
            $items->lockForUpdate();
            $transactions->lockForUpdate();
        }
        foreach ($items->get() as $item) {
            $line = BigDecimal::of($item->price)->multipliedBy($item->quantity);
            $gross = $gross->plus($line);
            if ($item->kind === 'gateway_fee') {
                $fee = $fee->plus($line);
            } elseif ($item->kind !== 'credit_allocation') {
                $lineTax = $item->tax_amount ?? $legacyTaxes[$item->id] ?? $this->lineTax($invoice, $item->price, $item->quantity);
                if (BigDecimal::of($lineTax)->isNegative() || BigDecimal::of($lineTax)->isGreaterThan($line)) {
                    throw new InvalidArgumentException('Invoice line tax exceeds its gross amount.');
                }
                $tax = $tax->plus($lineTax);
            }
        }
        $paid = BigDecimal::of('0.00');
        foreach ($transactions->get() as $transaction) {
            if ($transaction->status === InvoiceTransactionStatus::Succeeded && $transaction->settlement_state !== 'unsettled') {
                $paid = $paid->plus($transaction->amount);
            }
        }
        $productGross = $gross->minus($fee);
        $net = $productGross->minus($tax);
        $remaining = $gross->minus($paid);
        $remaining = $remaining->isNegative() || $invoice->status === 'paid' ? BigDecimal::of('0.00') : $remaining;
        $unpaidProduct = $productGross->minus($paid);
        $unpaidProduct = $unpaidProduct->isNegative() ? BigDecimal::of('0.00') : $unpaidProduct;
        $unpaid = $remaining->isZero() ? ['net' => '0.00', 'tax' => '0.00'] : (new MoneyCalculator)->allocateRemaining((string) $net->toScale(2), (string) $tax->toScale(2), (string) $unpaidProduct->toScale(2));

        return new PaymentSummary($invoice->currency_code, (string) $net->toScale(2), (string) $tax->toScale(2), (string) $productGross->toScale(2), $unpaid['net'], $unpaid['tax'], (string) $fee->toScale(2), (string) $gross->toScale(2), (string) $paid->toScale(2), (string) $remaining->toScale(2));
    }

    public function fingerprint(Invoice $invoice): string
    {
        $lines = [];
        $legacyTaxes = $this->legacyTaxes($invoice);
        $items = $invoice->items()->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $items->lockForUpdate();
        }
        foreach ($items->get() as $item) {
            $lines[] = ['id' => $item->id, 'kind' => $item->kind, 'gateway_id' => $item->gateway_id, 'price' => $item->price, 'quantity' => (int) $item->quantity, 'tax' => $item->tax_amount ?? $legacyTaxes[$item->id] ?? $this->lineTax($invoice, $item->price, $item->quantity), 'reference_type' => $item->reference_type, 'reference_id' => $item->reference_id];
        }

        return hash('sha256', json_encode(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'currency' => $invoice->currency_code, 'tax' => $this->context($invoice), 'lines' => $lines], JSON_THROW_ON_ERROR));
    }
}
