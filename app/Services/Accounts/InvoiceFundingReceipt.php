<?php

namespace App\Services\Accounts;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Services\Billing\InvoicePricing;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\PaymentWriteGuard;
use RuntimeException;

final class InvoiceFundingReceipt
{
    public static function capture(Invoice $invoice, ?User $actor, mixed $amount, bool $automatic): array
    {
        $invoice = Invoice::whereKey($invoice->id)->firstOrFail();
        $principal = $automatic ? AccountAmount::parse((new InvoicePricing)->summary($invoice)->unpaidNet)
            ->add(AccountAmount::parse((new InvoicePricing)->summary($invoice)->unpaidTax))->floorCents() : $amount;

        return ['financial' => self::financial($invoice), 'principal' => AccountAmount::positiveCents($principal)->floorCents(),
            'actor_id' => $automatic ? null : $actor?->id, 'origin' => $automatic ? 'scheduler' : 'client'];
    }

    private static function financial(Invoice $invoice): array
    {
        return ['invoice_id' => $invoice->id, 'owner_id' => $invoice->user_id, 'currency' => $invoice->currency_code,
            'pricing_fingerprint' => (new InvoicePricing)->fingerprint($invoice, false)];
    }

    public static function assertCurrent(AccountWriteContext $context): Invoice
    {
        if (!AccountPaymentLocks::covers([$context->invoiceId])) {
            throw new RuntimeException('Internal funding receipt requires its complete prelocked graph.');
        }
        $invoice = Invoice::whereKey($context->invoiceId)->lockForUpdate()->firstOrFail();
        if (self::financial($invoice) !== ($context->receiptProof['financial'] ?? null) || !in_array($invoice->status, ['pending', 'paid'], true)) {
            throw new RuntimeException('Internal funding receipt invoice identity or pricing changed.');
        }
        $owner = User::whereKey($invoice->user_id)->lockForUpdate()->firstOrFail();
        $actorId = $context->receiptProof['actor_id'];
        if (($actorId === null && $context->receiptProof['origin'] !== 'scheduler') ||
            ($actorId !== null && ($actorId !== $owner->id || !(new InvoicePolicy)->update($owner, $invoice)))) {
            throw new RuntimeException('Only the current invoice owner may spend account funding.');
        }
        MigrationHold::assertAllowed($owner, 'fund invoice account', true);
        MigrationHold::assertAllowed($invoice, 'fund invoice account', true);
        (new PaymentWriteGuard)->assertEditable($invoice);
        $items = $invoice->items()->orderBy('id')->lockForUpdate()->get();
        if ($items->isEmpty() || $items->contains(fn ($item) => $item->kind === 'credit_allocation' || $item->reference_type === Credit::class)) {
            throw new RuntimeException('A deposit invoice cannot be funded from its own account.');
        }
        foreach ($items as $item) {
            $serviceId = null;
            if ($item->reference_type === Service::class) {
                $serviceId = $item->reference_id;
            } elseif ($item->reference_type === ServiceUpgrade::class) {
                if (!$item->reference_id || !AccountPaymentLocks::coversUpgrade($item->reference_id)) {
                    throw new RuntimeException('Internal funding upgrade is outside the prelocked graph.');
                }
                $upgrade = ServiceUpgrade::whereKey($item->reference_id)->lockForUpdate()->firstOrFail();
                if ($upgrade->invoice_id !== $invoice->id) {
                    throw new RuntimeException('Internal funding upgrade receipt belongs to another invoice.');
                }
                $serviceId = $upgrade->service_id;
            }
            if (in_array($item->reference_type, [Service::class, ServiceUpgrade::class], true)) {
                if (!$serviceId) {
                    throw new RuntimeException('Internal funding receipt service identity is missing.');
                }
                AccountPaymentLocks::currentInvoices([], $serviceId);
                $service = Service::whereKey($serviceId)->lockForUpdate()->firstOrFail();
                if ($service->user_id !== $owner->id) {
                    throw new RuntimeException('Internal funding receipt service belongs to another financial owner.');
                }
            }
        }

        return $invoice;
    }
}
