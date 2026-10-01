<?php

namespace Paymenter\Extensions\Gateways\Klarna;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Models\Gateway as GatewayRecord;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CollectionDisabledException;
use App\Services\Gateways\PaymentAttempts;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use RuntimeException;

#[ExtensionMeta(name: 'Klarna', description: 'Hosted digital-service checkout with captured-order verification', version: '0.1.0', author: 'Paymenter Community')]
class Klarna extends Gateway
{
    public function supportsCustomerFeeCollection(): bool
    {
        return true;
    }

    public function boot()
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('gateways.klarna', __DIR__ . '/resources/views');
    }

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'merchant_id', 'label' => 'API username', 'type' => 'text', 'required' => true],
            ['name' => 'secret', 'label' => 'API password', 'type' => 'password', 'encrypted' => true, 'required' => true],
            ['name' => 'environment', 'label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Playground', 'production' => 'Production'], 'default' => 'test', 'required' => true],
            ['name' => 'region', 'label' => 'Merchant API region', 'type' => 'select', 'options' => ['eu' => 'Europe', 'na' => 'North America', 'oc' => 'Oceania'], 'required' => true],
            ['name' => 'currency', 'label' => 'Settlement currency (two decimal places)', 'type' => 'text', 'required' => true],
            ['name' => 'purchase_country', 'label' => 'Enabled purchase country (ISO code)', 'type' => 'text', 'required' => true],
            ['name' => 'locale', 'label' => 'Checkout locale (for example en-GB)', 'type' => 'text', 'required' => true],
            ['name' => 'collection_enabled', 'label' => 'Enable payment collection after handover approval', 'type' => 'checkbox', 'default' => false],
        ];
    }

    private function base(): string
    {
        $region = $this->config('region');
        $environment = $this->config('environment');
        if (!in_array($region, ['eu', 'na', 'oc'], true) || !in_array($environment, ['test', 'production'], true) || !$this->config('merchant_id') || !$this->config('secret')) {
            throw new RuntimeException('Klarna merchant configuration is incomplete');
        }

        return 'https://api' . ($region === 'eu' ? '' : '-' . $region) . ($environment === 'test' ? '.playground' : '') . '.klarna.com';
    }

    private function merchant(): string
    {
        return hash('sha256', $this->base() . ':' . $this->config('merchant_id') . ':' . $this->config('currency'));
    }

    private function api(string $method, string $url, array $data = []): array
    {
        $base = $this->base();
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== parse_url($base, PHP_URL_HOST) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new RuntimeException('Unexpected Klarna endpoint');
        }
        try {
            $response = Http::withBasicAuth($this->config('merchant_id'), $this->config('secret'))->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(10)->timeout(20)->send($method, $url, $method === 'GET' ? [] : ['json' => $data]);
            if (!$response->successful() || strlen($response->body()) > 1048576) {
                throw new RuntimeException;
            }
            $body = $response->json();
            if (!is_array($body)) {
                throw new RuntimeException;
            }

            return $body;
        } catch (\Throwable) {
            throw new RuntimeException('Klarna request could not be verified; reconcile before retrying');
        }
    }

    public function testConfig(): bool|string
    {
        return 'Klarna credentials, market and hosted checkout require playground acceptance before collection';
    }

    public function pay(Invoice $invoice, $total)
    {
        if (!$this->gatewayRecord) {
            throw new RuntimeException('Explicit gateway record binding is required');
        }
        if (!preg_match('/^[A-Z]{3}$/D', (string) $this->config('currency')) || !preg_match('/^[A-Z]{2}$/D', (string) $this->config('purchase_country')) || !preg_match('/^[a-z]{2}-[A-Z]{2}$/D', (string) $this->config('locale'))) {
            throw new RuntimeException('Klarna purchase market is not configured');
        }
        if ($invoice->transactions()->exists()) {
            throw new RuntimeException('Klarna requires reconciled tax and payment order lines for this invoice');
        }
        $ledger = new PaymentAttempts;
        $a = $ledger->begin($this->gatewayRecord, $invoice, $this->merchant(), (string) $this->config('currency'));
        $payload = $a->provider_payload;
        if (!$payload || !isset($payload['redirect_url'])) {
            $allocation = (new OrderLines)->build($a, (string) $this->config('purchase_country'));
            if (!GatewayPaymentAttempt::whereKey($a->id)->where('state', 'open')->whereNull('provider_payload')->update(['state' => 'initializing'])) {
                throw new RuntimeException('Checkout initialization requires reconciliation');
            }
            $payload = ['callback_token' => bin2hex(random_bytes(32)), 'purchase_country' => $this->config('purchase_country'),
                'locale' => $this->config('locale'), 'order_allocation' => $allocation];
            $a->update(['provider_payload' => $payload]);
            $session = $this->api('POST', $this->base() . '/payments/v1/sessions', array_merge($allocation, [
                'purchase_country' => $payload['purchase_country'], 'purchase_currency' => $a->currency_code,
                'locale' => $payload['locale'], 'merchant_reference1' => $a->reference,
            ]));
            if (!is_string($session['session_id'] ?? null) || !preg_match('/^[A-Za-z0-9-]{1,100}$/D', $session['session_id'])) {
                throw new RuntimeException('Invalid Klarna payment session');
            }
            $payload['payment_session_id'] = $session['session_id'];
            $a->update(['provider_payload' => $payload]);
            $hpp = $this->api('POST', $this->base() . '/hpp/v1/sessions', [
                'payment_session_url' => $this->base() . '/payments/v1/sessions/' . $session['session_id'],
                'merchant_urls' => ['success' => route('invoices.show', $invoice), 'cancel' => route('invoices.show', $invoice), 'failure' => route('invoices.show', $invoice), 'status_update' => url('/extensions/klarna/' . $this->gatewayRecord->id . '/notify/' . $a->reference) . '?token=' . $payload['callback_token']],
                'options' => ['place_order_mode' => 'CAPTURE_ORDER'],
            ]);
            if (!is_string($hpp['session_id'] ?? null) || !preg_match('/^[A-Za-z0-9-]{1,100}$/D', $hpp['session_id']) || !is_string($hpp['session_url'] ?? null) || !str_starts_with($hpp['session_url'], $this->base() . '/hpp/v1/sessions/')) {
                throw new RuntimeException('Invalid Klarna hosted session');
            }
            $this->checkRedirect($hpp['redirect_url'] ?? '');
            $expires = strtotime($hpp['expires_at'] ?? '');
            if (!$expires || $expires <= now()->timestamp) {
                throw new RuntimeException('Invalid Klarna session expiry');
            }
            $payload = array_merge($payload, ['redirect_url' => $hpp['redirect_url'], 'session_url' => $hpp['session_url'], 'expires_at' => $expires]);
            $a->update(['provider_reference' => $hpp['session_id'], 'provider_payload' => $payload, 'state' => 'open']);
        }
        if (($payload['expires_at'] ?? 0) <= now()->timestamp) {
            throw new RuntimeException('Klarna checkout expired; reconcile before retrying');
        }
        $this->checkRedirect($payload['redirect_url']);
        View::addNamespace('gateways.klarna', __DIR__ . '/resources/views');

        return view('gateways.klarna::pay', ['redirectUrl' => $payload['redirect_url'], 'attempt' => $a]);
    }

    private function checkRedirect(string $url): void
    {
        $p = parse_url($url);
        $host = $this->config('environment') === 'test' ? 'pay.playground.klarna.com' : 'pay.klarna.com';
        if (($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') !== $host || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            throw new RuntimeException('Unexpected Klarna payment URL');
        }
    }

    public function notify(Request $request, GatewayRecord $gateway, string $reference)
    {
        if ($gateway->extension !== 'Klarna') {
            abort(404);
        }$e = (new self($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        try {
            return $e->processNotification($request, $reference);
        } catch (MigrationHeldException|CollectionDisabledException) {
            return response('Payment processing is held', 409);
        } catch (RuntimeException|\InvalidArgumentException) {
            return response('Payment verification failed', 422);
        }
    }

    private function processNotification(Request $request, string $reference)
    {
        $ledger = new PaymentAttempts;
        $ledger->assertCollection($this->gatewayRecord);
        $a = GatewayPaymentAttempt::where('gateway_id', $this->gatewayRecord->id)->where('reference', $reference)->first();
        $token = $request->query('token');
        if (!$a || !is_string($token) || !is_string($a->provider_payload['callback_token'] ?? null) || !hash_equals($a->provider_payload['callback_token'], $token) || !$a->provider_reference) {
            throw new RuntimeException('Invalid callback identity');
        }
        $ledger->validate($this->gatewayRecord, $reference, $this->merchant(), $a->amount, $a->currency_code);
        $session = $this->api('GET', $a->provider_payload['session_url']);
        if (($session['session_id'] ?? null) !== $a->provider_reference) {
            throw new RuntimeException('Hosted session identity changed');
        }
        if (($session['status'] ?? null) !== 'COMPLETED') {
            return response('Session is not complete');
        }
        $id = $session['order_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]{1,100}$/D', $id)) {
            throw new RuntimeException('Invalid order identity');
        }
        $order = $this->api('GET', $this->base() . '/ordermanagement/v1/orders/' . $id);
        $minor = BigDecimal::of($a->amount)->multipliedBy(100)->toInt();
        if (($order['order_id'] ?? null) !== $id || ($order['merchant_reference1'] ?? null) !== $reference || ($order['purchase_currency'] ?? null) !== $a->currency_code || ($order['status'] ?? null) !== 'CAPTURED' || ($order['fraud_status'] ?? null) !== 'ACCEPTED' || ($order['order_amount'] ?? null) !== $minor || ($order['captured_amount'] ?? null) !== $minor || ($order['refunded_amount'] ?? null) !== 0) {
            throw new RuntimeException('Order identity, amount or captured state does not match');
        }
        (new OrderLines)->assertCaptured($a, $order);
        $ledger->settle($this->gatewayRecord, $reference, $this->merchant(), $a->amount, $a->currency_code, $id);

        return response('OK');
    }
}
