<?php

namespace Paymenter\Extensions\Gateways\BTCPay;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Gateway as GatewayRecord;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\PaymentOperation;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CollectionDisabledException;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

#[ExtensionMeta(name: 'BTCPay', description: 'Hosted Bitcoin payments through BTCPay Server', version: '0.1.0', author: 'Paymenter Community')]
final class BTCPay extends Gateway
{
    private const CURRENCY = 'USD';

    public function boot(): void
    {
        require __DIR__ . '/routes.php';
    }

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'server_url', 'label' => 'BTCPay Server HTTPS URL', 'type' => 'text', 'required' => true],
            ['name' => 'store_id', 'label' => 'Store ID', 'type' => 'text', 'required' => true],
            ['name' => 'api_key', 'label' => 'Store Greenfield API key', 'type' => 'password', 'encrypted' => true, 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook signing secret', 'type' => 'password', 'encrypted' => true],
            ['name' => 'minimum_amount', 'label' => 'Minimum USD invoice balance', 'type' => 'text', 'default' => '10.00'],
            ['name' => 'collection_enabled', 'label' => 'Enable payment collection', 'type' => 'checkbox', 'default' => false],
        ];
    }

    public function supportsCustomerFeeCollection(): bool
    {
        return true;
    }

    public function paymentOperations(): ?Adapter
    {
        return $this->gatewayRecord ? new Operations($this->gatewayRecord) : null;
    }

    public function canUseGateway($total, $currency, $type, $items): bool
    {
        try {
            return $currency === self::CURRENCY && filter_var($this->config('collection_enabled'), FILTER_VALIDATE_BOOLEAN)
                && Greenfield::decimal((string) $total)?->isGreaterThanOrEqualTo($this->minimum()) === true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function testConfig(): bool|string
    {
        try {
            $store = $this->store();
            $this->minimum();

            return ($store->store()['id'] ?? null) === $store->storeId ? true : 'BTCPay store identity did not match.';
        } catch (RuntimeException) {
            return 'BTCPay store authentication could not be verified.';
        }
    }

    public function pay(Invoice $invoice, $total): string
    {
        $gateway = $this->gateway();
        $store = $this->store();
        $ledger = new PaymentAttempts;
        $attempt = $ledger->begin($gateway, $invoice, $store->merchantFingerprint(), self::CURRENCY, minimumAmount: $this->minimum());
        if ($attempt->state === 'open' && !$attempt->provider_reference && GatewayPaymentAttempt::whereKey($attempt->id)
            ->where('state', 'open')->whereNull('provider_reference')->update(['state' => 'initializing'])) {
            $remote = $store->createInvoice([
                'type' => 'Standard', 'amount' => $attempt->amount, 'currency' => $attempt->currency_code,
                'metadata' => ['orderId' => Greenfield::orderId($attempt)],
                'checkout' => ['speedPolicy' => 'MediumSpeed', 'paymentTolerance' => 0,
                    'redirectURL' => route('invoices.show', $invoice), 'redirectAutomatically' => false],
            ]);
            $this->bindInvoice($store, $attempt, $remote);
        } else {
            $remote = $this->readInvoice($store, $attempt->refresh());
        }
        if (!$remote || !in_array($remote['status'] ?? null, ['New', 'Processing'], true)) {
            throw new RuntimeException('This BTCPay invoice is unavailable. Check the invoice status before paying again.');
        }
        $attempt = $ledger->validate($gateway, $attempt->reference, $store->merchantFingerprint(), $attempt->amount, $attempt->currency_code);
        if ($attempt->state !== 'open' || $attempt->provider_reference !== $remote['id']) {
            throw new RuntimeException('The BTCPay checkout is no longer current.');
        }

        return $store->checkoutLink($attempt->provider_reference);
    }

    public function syncPayment(string $reference): string
    {
        $gateway = $this->gateway();
        $ledger = new PaymentAttempts;
        $ledger->assertCollection($gateway);
        $store = $this->store();
        $attempt = GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('reference', $reference)->firstOrFail();
        if (!hash_equals($attempt->merchant_fingerprint, $store->merchantFingerprint())) {
            throw new RuntimeException('BTCPay merchant identity changed.');
        }
        $ledger->assertInvoice($attempt->invoice);
        $unsettled = $this->wasManuallyUnsettled($attempt);
        if ($attempt->state !== 'expired' && !$unsettled) {
            $ledger->validate($gateway, $reference, $store->merchantFingerprint(), $attempt->amount, $attempt->currency_code);
        }
        $remote = $this->readInvoice($store, $attempt);
        if (!$remote) {
            return 'pending';
        }
        if ($unsettled) {
            return 'review';
        }
        $status = $remote['status'] ?? null;
        $additional = $remote['additionalStatus'] ?? null;
        $received = Greenfield::decimal($remote['paidAmount'] ?? null);
        $expiredWithoutPayment = $status === 'Expired' && $additional === 'None' && $received?->isZero() === true;
        if ($attempt->state === 'expired') {
            return $expiredWithoutPayment ? 'expired' : 'review';
        }
        if ($status === 'Settled') {
            $marked = $additional === 'Marked';
            if (!$marked && ($additional !== 'None' || !$received?->isEqualTo($attempt->amount)
                || !Greenfield::confirmedMethods($store->paymentMethods($remote['id'])))) {
                return 'review';
            }
            $ledger->settle($gateway, $reference, $store->merchantFingerprint(), $attempt->amount, $attempt->currency_code,
                'invoice:' . $remote['id'], marked: $marked);

            return 'paid';
        }
        if ($expiredWithoutPayment && $attempt->state === 'open' && $this->hasNoPayments($store->paymentMethods($remote['id']))) {
            $expired = GatewayPaymentAttempt::whereKey($attempt->id)->where('state', 'open')
                ->where('provider_reference', $remote['id'])->whereNull('provider_transaction_id')->update(['state' => 'expired']);

            return $expired ? 'expired' : 'review';
        }

        return in_array($status, ['New', 'Processing'], true) && in_array($additional, ['None', 'PaidPartial'], true)
            ? 'pending' : 'review';
    }

    private function wasManuallyUnsettled(GatewayPaymentAttempt $attempt): bool
    {
        if ($attempt->state !== 'paid' || !$attempt->provider_reference || $attempt->provider_transaction_id !== 'invoice:' . $attempt->provider_reference
            || $attempt->invoice->user_id !== $attempt->user_id || $attempt->invoice->currency_code !== $attempt->currency_code) {
            return false;
        }
        $receipt = $attempt->invoice->transactions()->where('gateway_id', $attempt->gateway_id)
            ->where('transaction_id', 'gateway:' . $attempt->gateway_id . ':' . $attempt->provider_transaction_id)
            ->where('amount', $attempt->amount)->where('status', InvoiceTransactionStatus::Succeeded)->where('is_credit_transaction', false)
            ->where('settlement_origin', 'provider_marked')->where('settlement_state', 'unsettled')->first();

        return $receipt && PaymentOperation::where('invoice_id', $attempt->invoice_id)->where('gateway_id', $attempt->gateway_id)
            ->where('original_transaction_id', $receipt->id)->where('result_transaction_id', $receipt->id)
            ->where('kind', 'manual_unsettle')->where('state', 'succeeded')->where('outcome_code', 'unsettled')->exists();
    }

    public function notify(Request $request, GatewayRecord $gateway): Response
    {
        abort_unless($gateway->extension === 'BTCPay', 404);
        $extension = (new self($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        try {
            (new PaymentAttempts)->assertCollection($gateway);
            $event = $extension->verifiedEvent($request);
            if (!$event) {
                return response('Invalid BTCPay notification.', 403);
            }
            if (!in_array($event['type'], ['InvoiceSettled', 'InvoicePaymentSettled', 'InvoiceReceivedPayment', 'InvoiceExpired', 'InvoiceInvalid'], true)) {
                return response()->noContent();
            }
            $attempt = GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('provider_webhook_reference', $event['invoiceId'])->first();
            if ($attempt && $extension->syncPayment($attempt->reference) === 'review') {
                Log::warning('BTCPay invoice requires review.', ['gateway_id' => $gateway->id, 'attempt_id' => $attempt->id]);
            }

            return response()->noContent();
        } catch (MigrationHeldException|CollectionDisabledException) {
            return response('Payment processing is held.', 409);
        } catch (RuntimeException|JsonException) {
            return response('BTCPay payment verification failed.', 422);
        }
    }

    private function verifiedEvent(Request $request): ?array
    {
        $secret = $this->config('webhook_secret');
        $body = $request->getContent();
        $signature = $request->header('BTCPay-Sig');
        if (!is_string($secret) || $secret === '' || strlen($body) > 65536 || !is_string($signature)
            || !hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $signature)) {
            return null;
        }
        $event = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($event) || ($event['storeId'] ?? null) !== $this->store()->storeId
            || !Greenfield::validId($event['invoiceId'] ?? null) || !is_string($event['type'] ?? null)) {
            return null;
        }

        return $event;
    }

    private function readInvoice(Greenfield $store, GatewayPaymentAttempt $attempt): ?array
    {
        if ($attempt->provider_reference) {
            $remote = $store->invoice($attempt->provider_reference);
            $store->assertInvoice($remote, $attempt);

            return $remote;
        }
        if ($attempt->state !== 'initializing') {
            return null;
        }
        $matches = $store->findInvoiceIds(Greenfield::orderId($attempt));
        if ($matches === []) {
            return null;
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('BTCPay checkout identity requires reconciliation.');
        }
        $remote = $store->invoice($matches[0]);
        if (($remote['id'] ?? null) !== $matches[0]) {
            throw new RuntimeException('BTCPay returned a different recovery invoice.');
        }
        $this->bindInvoice($store, $attempt, $remote);

        return $remote;
    }

    private function bindInvoice(Greenfield $store, GatewayPaymentAttempt $attempt, array $remote): void
    {
        $store->assertInvoice($remote, $attempt);
        GatewayPaymentAttempt::whereKey($attempt->id)->where('state', 'initializing')->whereNull('provider_reference')
            ->update(['provider_reference' => $remote['id'], 'provider_webhook_reference' => $remote['id'], 'state' => 'open']);
        $attempt->refresh();
        if ($attempt->provider_reference !== $remote['id'] || $attempt->provider_webhook_reference !== $remote['id']) {
            throw new RuntimeException('BTCPay invoice binding changed during checkout.');
        }
    }

    private function hasNoPayments(array $methods): bool
    {
        if (!array_is_list($methods)) {
            return false;
        }
        foreach ($methods as $method) {
            if (($method['payments'] ?? null) !== []) {
                return false;
            }
        }

        return true;
    }

    private function minimum(): string
    {
        $amount = (string) ($this->config('minimum_amount') ?? '10.00');
        if (!preg_match('/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/', $amount) || !Greenfield::decimal($amount)?->isPositive()) {
            throw new RuntimeException('BTCPay minimum payment amount is invalid.');
        }

        return $amount;
    }

    private function gateway(): GatewayRecord
    {
        return $this->gatewayRecord ?? throw new RuntimeException('Explicit gateway record binding is required.');
    }

    private function store(): Greenfield
    {
        return new Greenfield((string) $this->config('server_url'), (string) $this->config('store_id'), (string) $this->config('api_key'));
    }
}
