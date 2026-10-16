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
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use RuntimeException;

#[ExtensionMeta(name: 'Klarna', description: 'Hosted digital-service checkout with captured-order verification', version: '0.2.0', author: 'Paymenter Community')]
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
            ['name' => 'consumer_fx_enabled', 'label' => 'Customer country selection (Consumer FX confirmed by Klarna)', 'type' => 'checkbox', 'default' => false,
                'description' => 'Enable only after Klarna confirms Consumer FX, USD settlement and each purchase country for this merchant.'],
            ['name' => 'enabled_purchase_countries', 'label' => 'Enabled customer countries (comma-separated ISO codes)', 'type' => 'text', 'default' => '',
                'description' => 'Only countries accepted for this merchant. Local billing currency is linked to country; Paymenter invoices stay in USD.'],
            ['name' => 'local_currency_enabled', 'label' => 'Local-currency checkout against USD invoices', 'type' => 'checkbox', 'default' => false,
                'description' => 'Merchant-approved countries only. Uses ECB reference pricing with no extra currency markup; actual bank settlement can differ. Mutually exclusive with Consumer FX for new checkouts.'],
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
        $claimedMarket = GatewayPaymentAttempt::where('gateway_id', $this->gatewayRecord?->id)
            ->where('invoice_id', $invoice->id)->whereIn('state', ['open', 'initializing', 'paid'])
            ->whereNotNull('provider_payload')->exists();
        if (!$claimedMarket && $this->consumerFx() && $this->localCurrency()) {
            throw new RuntimeException('Choose either local-currency checkout or Consumer FX.');
        }
        $countries = ($this->consumerFx() || $this->localCurrency()) && !$claimedMarket ? $this->countries() : [];
        $a = $this->beginInvoice($invoice);
        if (isset($a->provider_payload['conversion_quote']) && !isset($a->provider_payload['redirect_url'])) {
            $q = (new ConversionQuote)->validate($a);
            if ($a->state !== 'open' || $q['confirm_before'] <= now()->timestamp) {
                throw new RuntimeException('Conversion quote expired or requires reconciliation.');
            }

            return $this->quoteView($invoice, $a);
        }
        if ($a->provider_payload === null && ($this->consumerFx() || $this->localCurrency())) {
            $countries = $countries ?: $this->countries();
            if ($a->state !== 'open') {
                throw new RuntimeException('Checkout initialization requires reconciliation');
            }
            View::addNamespace('gateways.klarna', __DIR__ . '/resources/views');

            return view('gateways.klarna::pay', ['redirectUrl' => null, 'attempt' => $a,
                'countries' => $countries, 'invoice' => $invoice, 'gatewayId' => $this->gatewayRecord->id, 'localCurrency' => $this->localCurrency()]);
        }

        $a = $this->initialize($invoice, $a, ['purchase_country' => $this->config('purchase_country'), 'locale' => $this->config('locale')]);

        return view('gateways.klarna::pay', ['redirectUrl' => $a->provider_payload['redirect_url'], 'attempt' => $a,
            'countries' => [], 'invoice' => $invoice, 'gatewayId' => $this->gatewayRecord->id]);
    }

    private function localCurrency(): bool
    {
        return filter_var($this->config('local_currency_enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    private function quoteView(Invoice $invoice, GatewayPaymentAttempt $attempt)
    {
        View::addNamespace('gateways.klarna', __DIR__ . '/resources/views');

        return view('gateways.klarna::selection', ['redirectUrl' => null, 'attempt' => $attempt,
            'countries' => [], 'invoice' => $invoice, 'gatewayId' => $this->gatewayRecord->id, 'localCurrency' => true]);
    }

    private function consumerFx(): bool
    {
        return filter_var($this->config('consumer_fx_enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    private function countries(): array
    {
        return (new Markets)->enabled((string) $this->config('enabled_purchase_countries'), (string) $this->config('currency'));
    }

    private function beginInvoice(Invoice $invoice, ?string $reference = null): GatewayPaymentAttempt
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

        return (new PaymentAttempts)->begin($this->gatewayRecord, $invoice, $this->merchant(), (string) $this->config('currency'), $reference);
    }

    public function checkout(Request $request, GatewayRecord $gateway, Invoice $invoice, string $reference)
    {
        abort_unless($gateway->extension === 'Klarna', 404);
        abort_unless(GatewayPaymentAttempt::where('gateway_id', $gateway->id)->where('invoice_id', $invoice->id)->where('reference', $reference)->exists(), 404);
        $e = (new self($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        try {
            $a = $e->beginInvoice($invoice, $reference);
            $request->validate(['purchase_country' => ['required', 'string', 'regex:/^[A-Z]{2}$/D']]);
            $local = $e->localCurrency() || isset($a->provider_payload['conversion_quote']);
            if (array_diff(array_keys($request->except('_token')), $local ? ['purchase_country', 'quote_fingerprint'] : ['purchase_country']) !== []) {
                throw ValidationException::withMessages(['purchase_country' => __('Only the country can be selected for this checkout.')]);
            }
            $country = $request->input('purchase_country');
            $payload = $a->provider_payload;
            if ($payload !== null) {
                if (($payload['purchase_country'] ?? null) !== $country) {
                    throw new RuntimeException('Checkout country is already fixed.');
                }
                $market = $payload;
            } else {
                if (!$e->consumerFx() && !$e->localCurrency()) {
                    throw ValidationException::withMessages(['purchase_country' => __('Customer country selection is unavailable.')]);
                }
                $countries = $e->countries();
                if (!isset($countries[$country])) {
                    throw ValidationException::withMessages(['purchase_country' => __('Choose an enabled country of your Klarna account.')]);
                }
                $market = array_merge($countries[$country], ['purchase_country' => $country]);
            }
            if ($local && !isset($payload['redirect_url'])) {
                if ($payload === null) {
                    if ($request->has('quote_fingerprint') || ($e->consumerFx() && $e->localCurrency())) {
                        throw new RuntimeException('Conversion selection requires reconciliation.');
                    }
                    $quote = (new ConversionQuote)->create($a, $market, (new ReferenceRates)->get($market['billing_currency']));
                    $a->provider_payload = ['conversion_quote' => $quote, 'purchase_country' => $country, 'locale' => $market['locale'],
                        'billing_currency' => $market['billing_currency'], 'order_allocation' => $quote['allocation']];
                    if (!GatewayPaymentAttempt::whereKey($a->id)->where('state', 'open')->whereNull('provider_payload')
                        ->update(['provider_payload' => $a->getAttributes()['provider_payload']])) {
                        throw new RuntimeException('Conversion quote requires reconciliation.');
                    }

                    return response($e->quoteView($invoice, $a->fresh())->render());
                }
                $quote = (new ConversionQuote)->validate($a);
                if ($quote['confirm_before'] <= now()->timestamp || $a->state !== 'open') {
                    throw new RuntimeException('Conversion quote expired or requires reconciliation.');
                }
                if (!$request->has('quote_fingerprint')) {
                    return response($e->quoteView($invoice, $a)->render());
                }
                if (!is_string($request->input('quote_fingerprint')) || !hash_equals($quote['fingerprint'], $request->input('quote_fingerprint'))) {
                    throw new RuntimeException('Conversion confirmation changed.');
                }
            }
            $a = $e->initialize($invoice, $a, $market);

            return redirect()->away($a->provider_payload['redirect_url']);
        } catch (ValidationException $error) {
            if ($request->expectsJson()) {
                throw $error;
            }
            try {
                if ($a->provider_payload !== null || (!$e->consumerFx() && !$e->localCurrency())) {
                    throw new RuntimeException('Country selection is unavailable.');
                }
                View::addNamespace('gateways.klarna', __DIR__ . '/resources/views');

                return response()->view('gateways.klarna::selection', ['redirectUrl' => null, 'attempt' => $a,
                    'countries' => $e->countries(), 'invoice' => $invoice, 'gatewayId' => $gateway->id,
                    'localCurrency' => $e->localCurrency(), 'errors' => (new ViewErrorBag)->put('default', $error->validator->errors())], 422);
            } catch (RuntimeException) {
                return response('Klarna country selection is unavailable. Return to your invoice.', 409);
            }
        } catch (MigrationHeldException|CollectionDisabledException) {
            return response('Payment processing is held', 409);
        } catch (RuntimeException|\InvalidArgumentException) {
            return response('Klarna checkout requires reconciliation before retrying.', 409);
        }
    }

    private function initialize(Invoice $invoice, GatewayPaymentAttempt $a, array $market): GatewayPaymentAttempt
    {
        $payload = $a->provider_payload;
        if (!$payload || !isset($payload['redirect_url'])) {
            $quote = isset($payload['conversion_quote']) ? (new ConversionQuote)->validate($a) : null;
            $allocation = $quote ? $quote['allocation'] : (new OrderLines)->build($a, $market['purchase_country']);
            $claim = GatewayPaymentAttempt::whereKey($a->id)->where('state', 'open');
            $claim = $payload === null ? $claim->whereNull('provider_payload') : $claim->where('provider_payload', $a->getRawOriginal('provider_payload'));
            if (!$claim->update(['state' => 'initializing'])) {
                throw new RuntimeException('Checkout initialization requires reconciliation');
            }
            $a->refresh();
            $payload = array_merge($payload ?? [], ['callback_token' => bin2hex(random_bytes(32)), 'purchase_country' => $market['purchase_country'],
                'locale' => $market['locale'], 'order_allocation' => $allocation]);
            if (isset($market['billing_currency'])) {
                $payload['billing_currency'] = $market['billing_currency'];
            }
            $a->update(['provider_payload' => $payload]);
            $session = $this->api('POST', $this->base() . '/payments/v1/sessions', array_merge($allocation, [
                'purchase_country' => $payload['purchase_country'], 'purchase_currency' => $quote ? $quote['provider_currency'] : $a->currency_code,
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

        return $a;
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
        $quote = isset($a->provider_payload['conversion_quote']) ? (new ConversionQuote)->validate($a) : null;
        $minor = $quote ? $quote['allocation']['order_amount'] : BigDecimal::of($a->amount)->multipliedBy(100)->toInt();
        $providerCurrency = $quote ? $quote['provider_currency'] : $a->currency_code;
        if (($order['order_id'] ?? null) !== $id || ($order['merchant_reference1'] ?? null) !== $reference || ($order['purchase_currency'] ?? null) !== $providerCurrency || ($order['status'] ?? null) !== 'CAPTURED' || ($order['fraud_status'] ?? null) !== 'ACCEPTED' || ($order['order_amount'] ?? null) !== $minor || ($order['captured_amount'] ?? null) !== $minor || ($order['refunded_amount'] ?? null) !== 0) {
            throw new RuntimeException('Order identity, amount or captured state does not match');
        }
        (new OrderLines)->assertCaptured($a, $order);
        $ledger->settle($this->gatewayRecord, $reference, $this->merchant(), $a->amount, $a->currency_code, $id);

        return response('OK');
    }
}
