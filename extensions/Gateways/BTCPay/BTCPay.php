<?php

namespace Paymenter\Extensions\Gateways\BTCPay;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Models\Gateway as GatewayRecord;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CollectionDisabledException;
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
                'metadata' => ['orderId' => $this->orderId($attempt)],
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
        if ($attempt->state !== 'expired') {
            $ledger->validate($gateway, $reference, $store->merchantFingerprint(), $attempt->amount, $attempt->currency_code);
        }
        $remote = $this->readInvoice($store, $attempt);
        if (!$remote) {
            return 'pending';
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
                || !$this->fullyConfirmed($store->paymentMethods($remote['id'])))) {
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
            $this->assertInvoice($store, $attempt, $remote);

            return $remote;
        }
        if ($attempt->state !== 'initializing') {
            return null;
        }
        $matches = $store->findInvoiceIds($this->orderId($attempt));
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
        $this->assertInvoice($store, $attempt, $remote);
        GatewayPaymentAttempt::whereKey($attempt->id)->where('state', 'initializing')->whereNull('provider_reference')
            ->update(['provider_reference' => $remote['id'], 'provider_webhook_reference' => $remote['id'], 'state' => 'open']);
        $attempt->refresh();
        if ($attempt->provider_reference !== $remote['id'] || $attempt->provider_webhook_reference !== $remote['id']) {
            throw new RuntimeException('BTCPay invoice binding changed during checkout.');
        }
    }

    private function assertInvoice(Greenfield $store, GatewayPaymentAttempt $attempt, array $remote): void
    {
        $id = $remote['id'] ?? null;
        $amount = Greenfield::decimal($remote['amount'] ?? null);
        $tolerance = $remote['checkout']['paymentTolerance'] ?? null;
        if (!Greenfield::validId($id) || ($attempt->provider_reference && $attempt->provider_reference !== $id)
            || ($remote['storeId'] ?? null) !== $store->storeId || ($remote['type'] ?? null) !== 'Standard'
            || ($remote['metadata']['orderId'] ?? null) !== $this->orderId($attempt)
            || ($remote['currency'] ?? null) !== self::CURRENCY || !$amount?->isEqualTo($attempt->amount)
            || ($remote['checkout']['speedPolicy'] ?? null) !== 'MediumSpeed'
            || !Greenfield::decimal($tolerance)?->isZero()) {
            throw new RuntimeException('BTCPay invoice identity or payment terms did not match.');
        }
        if (($remote['checkoutLink'] ?? null) !== $store->checkoutLink($id)) {
            throw new RuntimeException('BTCPay checkout address did not match the stored invoice.');
        }
    }

    private function fullyConfirmed(array $methods): bool
    {
        if (!array_is_list($methods)) {
            return false;
        }
        $dueSettled = false;
        foreach ($methods as $method) {
            if (!is_array($method) || !is_array($method['payments'] ?? null) || !array_is_list($method['payments'])) {
                return false;
            }
            foreach ($method['payments'] as $payment) {
                if (($payment['status'] ?? null) !== 'Settled') {
                    return false;
                }
            }
            if ($method['payments'] && Greenfield::decimal($method['due'] ?? null)?->isZero()) {
                $dueSettled = true;
            }
        }

        return $dueSettled;
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

    private function orderId(GatewayPaymentAttempt $attempt): string
    {
        return 'paymenter-' . $attempt->reference;
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
