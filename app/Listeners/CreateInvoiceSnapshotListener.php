<?php

namespace App\Listeners;

use App\Events\Invoice\Paid;
use App\Services\Billing\InvoicePricing;

class CreateInvoiceSnapshotListener
{
    /**
     * Handle the event.
     */
    public function handle(Paid $event): void
    {
        if (!config('settings.invoice_snapshot', true)) {
            return;
        }

        $invoice = $event->invoice;

        // Only create snapshot if it doesn't already exist
        if ($invoice->snapshot) {
            return;
        }

        $snapshotData = [
            'name' => $invoice->user->name,
            'properties' => $invoice->user_properties,
            'bill_to' => config('settings.bill_to_text', config('settings.company_name')),
        ];

        $tax = (new InvoicePricing)->context($invoice);
        $snapshotData['tax_name'] = $tax['name'];
        $snapshotData['tax_rate'] = $tax['rate'];
        $snapshotData['tax_country'] = $tax['country'];

        $invoice->snapshot()->create($snapshotData);
    }
}
