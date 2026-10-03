<?php

namespace App\Observers;

use App\Events\InvoiceItem as InvoiceItemEvent;
use App\Models\InvoiceItem;
use App\Services\Billing\InvoicePricing;
use Brick\Math\BigDecimal;

class InvoiceItemObserver
{
    /**
     * Handle the InvoiceItem "creating" event.
     */
    public function creating(InvoiceItem $invoice): void
    {
        $this->price($invoice);
        event(new InvoiceItemEvent\Creating($invoice));
    }

    /**
     * Handle the InvoiceItem "created" event.
     */
    public function created(InvoiceItem $invoice): void
    {
        event(new InvoiceItemEvent\Created($invoice));
    }

    /**
     * Handle the InvoiceItem "updating" event.
     */
    public function updating(InvoiceItem $invoice): void
    {
        $this->price($invoice);
        event(new InvoiceItemEvent\Updating($invoice));
    }

    /**
     * Handle the InvoiceItem "updated" event.
     */
    public function updated(InvoiceItem $invoice): void
    {
        event(new InvoiceItemEvent\Updated($invoice));
    }

    /**
     * Handle the InvoiceItem "deleted" event.
     */
    public function deleted(InvoiceItem $invoice): void
    {
        event(new InvoiceItemEvent\Deleted($invoice));
    }

    private function price(InvoiceItem $item): void
    {
        $item->kind ??= 'product';
        $item->quantity ??= 1;
        if (in_array($item->kind, ['gateway_fee', 'credit_allocation'], true)) {
            $item->tax_amount = '0.00';
        } elseif (!$item->exists || $item->isDirty(['price', 'quantity', 'invoice_id', 'kind', 'tax_amount'])) {
            if ($item->tax_amount === null || ($item->exists && !$item->isDirty('tax_amount'))) {
                $item->tax_amount = (new InvoicePricing)->lineTax($item->invoice()->firstOrFail(), $item->price, (int) $item->quantity);
            }
            $gross = BigDecimal::of($item->price)->multipliedBy($item->quantity);
            if (BigDecimal::of($item->tax_amount)->isNegative() || BigDecimal::of($item->tax_amount)->isGreaterThan($gross)) {
                throw new \InvalidArgumentException('Invoice line tax exceeds its gross amount.');
            }
        }
    }
}
