<?php

namespace App\Services\Gateways;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
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
        $total = BigDecimal::zero();
        foreach ($invoice->items as $item) {
            $total = $total->plus(BigDecimal::of((string) $item->price)->multipliedBy((string) $item->quantity));
        }
        foreach ($invoice->transactions as $transaction) {
            if ($transaction->status === InvoiceTransactionStatus::Succeeded) {
                $total = $total->minus($transaction->amount);
            }
        }

        return (string) $total->toScale(2);
    }

    public function begin(Gateway $gateway, Invoice $invoice, string $merchantFingerprint, ?string $expectedCurrency = null): GatewayPaymentAttempt
    {
        Gate::authorize('update', $invoice);

        return DB::transaction(function () use ($gateway, $invoice, $merchantFingerprint, $expectedCurrency) {
            $gateway = Gateway::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
            $this->assertCollection($gateway);
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertInvoice($invoice);
            if ($expectedCurrency !== null && $invoice->currency_code !== $expectedCurrency) {
                throw new RuntimeException('Invoice currency does not match the merchant currency');
            }
            $amount = $this->remaining($invoice);
            if ($invoice->status !== 'pending' || !BigDecimal::of($amount)->isPositive()) {
                throw new RuntimeException('Invoice is not payable');
            }
            $attempt = GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('invoice_id', $invoice->id)->whereIn('state', ['open', 'initializing'])->first();
            if ($attempt) {
                if ($attempt->amount !== $amount || $attempt->currency_code !== $invoice->currency_code || $attempt->user_id !== $invoice->user_id || !hash_equals($attempt->merchant_fingerprint, $merchantFingerprint)) {
                    throw new RuntimeException('Existing payment attempt requires reconciliation');
                }

                return $attempt;
            }

            return GatewayPaymentAttempt::create(['gateway_id' => $gateway->id, 'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'reference' => (string) random_int(100000000000000, 999999999999998), 'merchant_fingerprint' => $merchantFingerprint, 'amount' => $amount, 'currency_code' => $invoice->currency_code, 'state' => 'open']);
        });
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
            // Serialize callbacks for this merchant, then invoice and attempt, in that order.
            $gateway = Gateway::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
            $id = GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('reference', $reference)->value('invoice_id');
            if (!$id) {
                throw new RuntimeException('Unknown destination payment reference');
            }
            Invoice::whereKey($id)->lockForUpdate()->firstOrFail();
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
            ExtensionHelper::addPayment($invoice, $gateway, $attempt->amount, transactionId: 'gateway:' . $gateway->id . ':' . $transactionId);
            if ($invoice->fresh()->status !== 'paid') {
                throw new RuntimeException('Native invoice settlement was not completed');
            }
            $attempt->update(['state' => 'paid', 'provider_transaction_id' => $transactionId]);
        });
    }
}
