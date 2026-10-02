<?php

namespace App\Services\Gateways;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Services\Billing\InvoicePricing;
use App\Services\Billing\PaymentSummary;
use App\Services\BillmanagerMigration\MigrationHold;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class PaymentAttempts
{
    public function assertCollection(Gateway $gateway): void
    {
        $enabled = $gateway->settings()->where('key', 'collection_enabled')->first()?->value;
        if (!$gateway->enabled || !filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            throw new CollectionDisabledException('Gateway collection is disabled');
        }
    }

    public function assertInvoice(Invoice $invoice): void
    {
        MigrationHold::assertAllowed($invoice, 'gateway payment');
        foreach ($invoice->items as $item) {
            $record = $item->reference;
            if ($record instanceof Model) {
                MigrationHold::assertAllowed($record, 'gateway payment for invoice item');
            }
        }
    }

    public function remaining(Invoice $invoice): string
    {
        $summary = (new InvoicePricing)->summary($invoice);

        return (string) BigDecimal::of($summary->total)->minus($summary->paid)->toScale(2);
    }

    public function begin(Gateway $gateway, Invoice $invoice, string $merchantFingerprint, ?string $expectedCurrency = null, ?string $expectedReference = null): GatewayPaymentAttempt
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('External payment initiation requires a durable claim outside an enclosing transaction.');
        }
        Gate::authorize('update', $invoice);

        return DB::transaction(function () use ($gateway, $invoice, $merchantFingerprint, $expectedCurrency, $expectedReference) {
            $gateway = Gateway::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
            $this->assertCollection($gateway);
            $dependencies = new InvoicePaymentDependencies;
            $locked = $dependencies->lock([$invoice->id]);
            $invoice = $locked->firstWhere('id', $invoice->id);
            $dependencies->assertCollectable($invoice, $locked);
            Gate::authorize('update', $invoice);
            $this->assertInvoice($invoice);
            if ($expectedCurrency !== null && $invoice->currency_code !== $expectedCurrency) {
                throw new RuntimeException('Invoice currency does not match the merchant currency');
            }
            $amount = $this->remaining($invoice);
            if ($invoice->status !== 'pending' || !BigDecimal::of($amount)->isPositive()) {
                throw new RuntimeException('Invoice is not payable');
            }
            $attempts = GatewayPaymentAttempt::where('invoice_id', $invoice->id)->whereIn('state', ['open', 'initializing', 'paid'])->orderBy('id')->lockForUpdate()->get();
            if ($attempts->isNotEmpty()) {
                if ($attempts->count() !== 1 || $attempts->first()->gateway_id !== $gateway->id || $attempts->first()->state === 'paid') {
                    throw new RuntimeException('Existing payment attempt requires reconciliation before switching gateways.');
                }
                $attempt = $attempts->first();
                if ($expectedReference !== null && $attempt->reference !== $expectedReference) {
                    throw new RuntimeException('Checkout reference is no longer active.');
                }

                return $this->validate($gateway, $attempt->reference, $merchantFingerprint, $amount, $invoice->currency_code);
            }
            if ($expectedReference !== null) {
                throw new RuntimeException('Checkout reference is no longer active.');
            }
            $policy = new GatewayFeePolicy;
            $policy->assertSupported($gateway, $invoice->currency_code);
            $pricing = new InvoicePricing;
            $pricing->freezeLegacy($invoice);
            $quote = $policy->quote($pricing->summary($invoice), $gateway);
            $invoice->items()->where('kind', 'gateway_fee')->get()->each->delete();
            if (BigDecimal::of($quote->gatewayFee)->isPositive()) {
                $invoice->items()->create(['kind' => 'gateway_fee', 'description' => 'Payment gateway fee', 'gateway_id' => $gateway->id, 'price' => $quote->gatewayFee, 'quantity' => 1, 'tax_amount' => '0.00', 'reference_type' => null, 'reference_id' => null]);
            }
            $invoice->refresh();

            return GatewayPaymentAttempt::create([
                'gateway_id' => $gateway->id, 'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id,
                'reference' => (string) random_int(100000000000000, 999999999999998),
                'merchant_fingerprint' => $merchantFingerprint, 'amount' => $quote->payable,
                'currency_code' => $invoice->currency_code, 'state' => 'open',
                'pricing_payload' => $this->pricingPayload($invoice, $quote, $policy->values($gateway, $invoice->currency_code)),
                'pricing_fingerprint' => $pricing->fingerprint($invoice),
            ]);
        });
    }

    private function pricingPayload(Invoice $invoice, PaymentSummary $summary, array $policy): array
    {
        $pricing = new InvoicePricing;
        $lines = [];
        foreach ($invoice->items()->orderBy('id')->get() as $item) {
            $tax = $item->tax_amount ?? $pricing->lineTax($invoice, $item->price, $item->quantity);
            $lines[] = ['id' => $item->id, 'kind' => $item->kind, 'gateway_id' => $item->gateway_id,
                'description' => $item->description, 'unit_gross' => $item->price, 'tax_amount' => $tax,
                'total_gross' => $item->total(), 'quantity' => $item->quantity,
                'reference_type' => $item->reference_type, 'reference_id' => $item->reference_id];
        }

        return ['schema_version' => 1, 'currency' => $summary->currency, 'product_net' => $summary->productNet,
            'product_tax' => $summary->productTax, 'product_gross' => $summary->productGross,
            'unpaid_net' => $summary->unpaidNet, 'unpaid_tax' => $summary->unpaidTax,
            'gateway_fee' => $summary->gatewayFee, 'total' => $summary->total, 'paid' => $summary->paid,
            'payable' => $summary->payable, 'tax_context' => $pricing->context($invoice),
            'fee_policy' => $policy, 'lines' => $lines];
    }

    public function validate(Gateway $gateway, string $reference, string $merchantFingerprint, string $amount, string $currency): GatewayPaymentAttempt
    {
        $this->assertCollection($gateway);
        $attempt = GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('reference', $reference)->first();
        if (!$attempt || !hash_equals($attempt->merchant_fingerprint, $merchantFingerprint) || $attempt->currency_code !== $currency || !BigDecimal::of($amount)->isEqualTo($attempt->amount)) {
            throw new RuntimeException('Payment identity, currency or amount does not match a destination attempt');
        }
        $invoice = $attempt->invoice;
        $this->assertInvoice($invoice);
        if ($invoice->user_id !== $attempt->user_id || $invoice->currency_code !== $currency) {
            throw new RuntimeException('Invoice identity changed');
        }
        if ($attempt->pricing_payload !== null) {
            $payload = $attempt->pricing_payload;
            if (!$attempt->pricing_fingerprint || !hash_equals($attempt->pricing_fingerprint, (new InvoicePricing)->fingerprint($invoice))) {
                throw new RuntimeException('Invoice pricing changed and requires reconciliation.');
            }
            $fees = $invoice->items()->where('kind', 'gateway_fee')->get();
            $expectedFee = $payload['gateway_fee'];
            if (BigDecimal::of($expectedFee)->isZero() ? $fees->isNotEmpty() :
                ($fees->count() !== 1 || $fees->first()->price !== $expectedFee || $fees->first()->quantity !== 1 ||
                    $fees->first()->tax_amount !== '0.00' || $fees->first()->gateway_id !== $gateway->id ||
                    $fees->first()->reference_type !== null || $fees->first()->reference_id !== null)) {
                throw new RuntimeException('Invoice fee identity changed and requires reconciliation.');
            }
        }
        if ($attempt->state === 'paid' && ($invoice->status !== 'paid' || !$attempt->provider_transaction_id || !$invoice->transactions()
            ->where('gateway_id', $gateway->id)->where('transaction_id', 'gateway:' . $gateway->id . ':' . $attempt->provider_transaction_id)
            ->where('amount', $attempt->amount)->where('status', InvoiceTransactionStatus::Succeeded)
            ->where('is_credit_transaction', false)->exists())) {
            throw new RuntimeException('Native payment records changed and require reconciliation.');
        }
        if ($attempt->state !== 'paid' && ($invoice->status !== 'pending' || $this->remaining($invoice) !== $attempt->amount)) {
            throw new RuntimeException('Invoice amount or state changed');
        }

        return $attempt;
    }

    public function settle(Gateway $gateway, string $reference, string $merchantFingerprint, string $amount, string $currency, string $transactionId): void
    {
        if ($transactionId === '' || strlen($transactionId) > 190) {
            throw new RuntimeException('Provider transaction identity is required');
        }
        DB::transaction(function () use ($gateway, $reference, $merchantFingerprint, $amount, $currency, $transactionId) {
            // Merchant, connected invoices in ascending order, then attempt and service.
            $gateway = Gateway::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
            $id = GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('reference', $reference)->value('invoice_id');
            if (!$id) {
                throw new RuntimeException('Unknown destination payment reference');
            }
            $dependencies = new InvoicePaymentDependencies;
            $locked = $dependencies->lock([$id]);
            $dependencies->assertCollectable($locked->firstWhere('id', $id), $locked);
            GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('reference', $reference)->lockForUpdate()->firstOrFail();
            $attempt = $this->validate($gateway, $reference, $merchantFingerprint, $amount, $currency);
            if ($attempt->state === 'paid') {
                if ($attempt->provider_transaction_id !== $transactionId) {
                    throw new RuntimeException('A different payment already settled this attempt');
                }

                return;
            }
            if (GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('provider_transaction_id', $transactionId)->exists()) {
                throw new RuntimeException('Provider transaction is already assigned');
            }
            $invoice = $attempt->invoice;
            $attempt->update(['provider_transaction_id' => $transactionId]);
            (new PaymentWriteGuard)->duringSettlement($attempt, fn () => ExtensionHelper::addPayment($invoice, $gateway, $attempt->amount, transactionId: 'gateway:' . $gateway->id . ':' . $transactionId));
            if ($invoice->fresh()->status !== 'paid') {
                throw new RuntimeException('Native invoice settlement was not completed');
            }
            $attempt->update(['state' => 'paid', 'provider_transaction_id' => $transactionId]);
        });
    }
}
