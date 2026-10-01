<?php

namespace Tests\Concerns;

use App\Models\Gateway;
use App\Models\Invoice;

trait UsesTaxedGatewayInvoice
{
    private function taxAndFee(Invoice $invoice, Gateway $gateway): void
    {
        $invoice->forceFill(['pricing_tax_rate' => '7.1250', 'pricing_tax_name' => 'Synthetic tax',
            'pricing_tax_country' => 'all', 'pricing_tax_inclusive' => false])->save();
        $invoice->items()->firstOrFail()->update(['price' => '107.13', 'quantity' => 1, 'tax_amount' => '7.13']);
        foreach (['customer_fee_enabled' => '1', 'customer_fee_percent' => '2.5',
            'customer_fee_fixed' => '0.25', 'customer_fee_currency' => 'USD'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $gateway->unsetRelation('settings');
    }
}
