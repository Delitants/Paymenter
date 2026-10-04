<?php

namespace Paymenter\Extensions\Gateways\WebMoney;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Models\Gateway as GatewayRecord;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CollectionDisabledException;
use App\Services\Gateways\PaymentAttempts;
use App\Services\Gateways\ReferenceRates;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use RuntimeException;

#[ExtensionMeta(name: 'WebMoney', description: 'WebMoney SHA256 merchant payments with destination-bound references', version: '0.1.0', author: 'Paymenter Community')]
class WebMoney extends Gateway
{
    public function supportsCustomerFeeCollection(): bool
    {
        return true;
    }

    public function boot()
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('gateways.webmoney', __DIR__ . '/resources/views');
    }

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'purse', 'label' => 'Merchant WMZ or WME purse', 'type' => 'text', 'required' => true],
            ['name' => 'secret', 'label' => 'Merchant secret key', 'type' => 'password', 'encrypted' => true, 'required' => true],
            ['name' => 'currency', 'label' => 'Purse currency', 'type' => 'select', 'options' => ['USD' => 'USD (WMZ)', 'EUR' => 'EUR (WME)'], 'default' => 'USD', 'required' => true,
                'description' => 'Invoices remain in USD. WME checkout uses a saved EUR conversion quote.'],
            ['name' => 'test_mode', 'label' => 'Merchant is in test mode', 'type' => 'checkbox', 'default' => true],
            ['name' => 'wm_wmid', 'label' => 'Certificate-authorized merchant WMID', 'type' => 'text', 'required' => false],
            ['name' => 'wm_certificate', 'label' => 'WebPro client certificate (PEM)', 'type' => 'textarea', 'encrypted' => true, 'required' => false],
            ['name' => 'wm_private_key', 'label' => 'WebPro private key (PEM)', 'type' => 'textarea', 'encrypted' => true, 'required' => false],
            ['name' => 'wm_key_passphrase', 'label' => 'Private key passphrase', 'type' => 'password', 'encrypted' => true, 'required' => false],
            ['name' => 'wm_sequence_floor', 'label' => 'Request number floor (up to 15 digits)', 'type' => 'text', 'default' => '0',
                'description' => 'Set above every prior request number for this WMID before first use. This can raise the shared high-water mark; existing counters are never reduced or reset.'],
            ['name' => 'wm_exclusive_sequence', 'label' => 'All XML callers using these credentials share this database request sequence', 'type' => 'checkbox', 'default' => false,
                'description' => 'Refunds require all XML callers using these certificate credentials to share this Paymenter database sequence, including delegated WMIDs. External uncoordinated callers are unsupported. Test-mode refunds are unsupported.'],
            ['name' => 'collection_enabled', 'label' => 'Enable payment collection after handover approval', 'type' => 'checkbox', 'default' => false],
        ];
    }

    public function testConfig(): bool|string
    {
        // Web Merchant Interface has no credential-only authentication operation.
        return 'Merchant authentication and SHA256 notification mode require a sandbox payment verification';
    }

    private function merchant(): string
    {
        $purse = (string) $this->config('purse');
        $valid = (preg_match('/^Z[0-9]{12}$/D', $purse) && $this->config('currency') === 'USD') ||
            (preg_match('/^E[0-9]{12}$/D', $purse) && $this->config('currency') === 'EUR');
        if (!$valid || !$this->config('secret')) {
            throw new RuntimeException('WebMoney merchant configuration is incomplete or currency is unsupported');
        }

        return hash('sha256', $this->config('purse') . ':' . (filter_var($this->config('test_mode'), FILTER_VALIDATE_BOOLEAN) ? 'test' : 'live'));
    }

    public function pay(Invoice $invoice, $total)
    {
        if (!$this->gatewayRecord) {
            throw new RuntimeException('Explicit gateway record binding is required');
        }
        if ($invoice->currency_code !== 'USD') {
            throw new RuntimeException('Invoice currency does not match the merchant currency');
        }
        $attempt = (new PaymentAttempts)->begin($this->gatewayRecord, $invoice, $this->merchant(), 'USD');
        View::addNamespace('gateways.webmoney', __DIR__ . '/resources/views');
        if ($this->config('currency') === 'EUR') {
            $rates = isset($attempt->provider_payload['webmoney_conversion_quote']) ? null : (new ReferenceRates)->get('EUR');
            $attempt = DB::transaction(function () use ($attempt, $rates) {
                $locked = GatewayPaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                (new PaymentAttempts)->validate($this->gatewayRecord, $locked->reference, $this->merchant(), $locked->amount, 'USD');
                if (!isset($locked->provider_payload['webmoney_conversion_quote'])) {
                    if ($locked->state !== 'open' || $locked->provider_payload !== null || $rates === null) {
                        throw new RuntimeException('WebMoney checkout requires reconciliation.');
                    }
                    $locked->provider_payload = ['webmoney_conversion_quote' => (new ConversionQuote)->create($locked, $rates)];
                    $locked->save();
                }

                return $locked;
            });
            $quote = (new ConversionQuote)->validate($attempt);
            if ($quote['confirm_before'] <= now()->timestamp) {
                throw new RuntimeException('The conversion quote expired; reconcile before starting another payment.');
            }

            return view('gateways.webmoney::quote', ['quote' => $quote, 'attempt' => $attempt, 'invoice' => $invoice, 'gateway' => $this->gatewayRecord]);
        }

        return $this->paymentView($invoice, $attempt, $attempt->amount, 'USD');
    }

    private function paymentView(Invoice $invoice, GatewayPaymentAttempt $attempt, string $amount, string $currency)
    {
        View::addNamespace('gateways.webmoney', __DIR__ . '/resources/views');

        return view('gateways.webmoney::pay', ['purse' => $this->config('purse'), 'attempt' => $attempt, 'gateway' => $this->gatewayRecord, 'invoice' => $invoice,
            'amount' => $amount, 'currency' => $currency, 'testMode' => filter_var($this->config('test_mode'), FILTER_VALIDATE_BOOLEAN)]);
    }

    public function checkout(Request $request, GatewayRecord $gateway, Invoice $invoice, string $reference)
    {
        if ($gateway->extension !== 'WebMoney') {
            abort(404);
        }
        $extension = (new self($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        try {
            if ($extension->config('currency') !== 'EUR' || array_diff(array_keys($request->except('_token')), ['quote_fingerprint']) !== []) {
                throw new RuntimeException('Unexpected WebMoney checkout fields.');
            }
            $attempt = (new PaymentAttempts)->begin($gateway, $invoice, $extension->merchant(), 'USD', $reference);
            $attempt = DB::transaction(function () use ($attempt, $request) {
                $locked = GatewayPaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                $quote = (new ConversionQuote)->validate($locked);
                if ($locked->state !== 'open' || $quote['confirm_before'] <= now()->timestamp ||
                    !is_string($request->input('quote_fingerprint')) || !hash_equals($quote['fingerprint'], $request->input('quote_fingerprint'))) {
                    throw new RuntimeException('The conversion quote expired or changed.');
                }
                $payload = $locked->provider_payload;
                $payload['webmoney_confirmed_at'] ??= now()->timestamp;
                $locked->provider_payload = $payload;
                $locked->save();

                return $locked;
            });
            $quote = (new ConversionQuote)->validate($attempt);

            return response($extension->paymentView($invoice, $attempt, $quote['provider_amount'], 'EUR')->render());
        } catch (MigrationHeldException|CollectionDisabledException) {
            return response('Payment processing is held', 409);
        } catch (RuntimeException|\InvalidArgumentException) {
            return response('The conversion quote could not be confirmed. Please return to your invoice.', 422);
        }
    }

    public function notify(Request $request, GatewayRecord $gateway)
    {
        if ($gateway->extension !== 'WebMoney') {
            abort(404);
        }
        $extension = (new self($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        try {
            return $extension->processNotification($request);
        } catch (MigrationHeldException|CollectionDisabledException) {
            return response('Payment processing is held', 409);
        } catch (RuntimeException|\InvalidArgumentException) {
            return response('Payment verification failed', 422);
        }
    }

    private function processNotification(Request $request)
    {
        $ledger = new PaymentAttempts;
        $ledger->assertCollection($this->gatewayRecord);
        $merchant = $this->merchant();
        $p = $request->post();
        // Merchant also sends an empty availability check when prerequest parameters are disabled.
        // This acknowledges endpoint availability only; settlement still requires a signed notification.
        if ($p === [] && $request->getContent() === '' && $request->query->count() === 0 &&
            $request->files->count() === 0 && (int) $request->header('Content-Length', 0) === 0 &&
            !str_starts_with(strtolower(trim((string) $request->header('Content-Type', ''))), 'multipart/')) {
            return response('YES');
        }
        foreach (['LMI_PAYEE_PURSE', 'LMI_PAYMENT_AMOUNT', 'LMI_PAYMENT_NO', 'LMI_MODE'] as $key) {
            if (!isset($p[$key]) || !is_string($p[$key])) {
                throw new RuntimeException('Missing payment field');
            }
        }
        if (isset($p['LMI_HOLD']) || $p['LMI_PAYEE_PURSE'] !== $this->config('purse') || !preg_match('/^[0-9]{1,15}$/D', $p['LMI_PAYMENT_NO']) || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $p['LMI_PAYMENT_AMOUNT']) || $p['LMI_MODE'] !== (filter_var($this->config('test_mode'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0')) {
            throw new RuntimeException('Unexpected merchant, amount or payment mode');
        }
        $attempt = GatewayPaymentAttempt::where('gateway_id', $this->gatewayRecord->id)->where('reference', $p['LMI_PAYMENT_NO'])->firstOrFail();
        $quote = $this->config('currency') === 'EUR' ? (new ConversionQuote)->validate($attempt) : null;
        if ($quote) {
            $confirmed = $attempt->provider_payload['webmoney_confirmed_at'] ?? null;
            if (!is_int($confirmed) || $confirmed < $quote['created_at'] || $confirmed >= $quote['confirm_before'] ||
                !BigDecimal::of($p['LMI_PAYMENT_AMOUNT'])->isEqualTo($quote['provider_amount'])) {
                throw new RuntimeException('The EUR payment does not match its confirmed quote.');
            }
        } elseif (isset($attempt->provider_payload['webmoney_conversion_quote'])) {
            throw new RuntimeException('The purse currency changed.');
        }
        $nativeAmount = $quote ? $quote['native_amount'] : $p['LMI_PAYMENT_AMOUNT'];
        if (($p['LMI_PREREQUEST'] ?? null) === '1') {
            $ledger->validate($this->gatewayRecord, $p['LMI_PAYMENT_NO'], $merchant, $nativeAmount, 'USD');

            return response('YES');
        }
        foreach (['LMI_SYS_INVS_NO', 'LMI_SYS_TRANS_NO', 'LMI_SYS_TRANS_DATE', 'LMI_PAYER_PURSE', 'LMI_PAYER_WM', 'LMI_HASH'] as $key) {
            if (!isset($p[$key]) || !is_string($p[$key]) || $p[$key] === '') {
                throw new RuntimeException('Missing signed field');
            }
        }
        if (!preg_match('/^[0-9]+$/D', $p['LMI_SYS_TRANS_NO']) || !preg_match('/^[A-Fa-f0-9]{64}$/D', $p['LMI_HASH'])) {
            throw new RuntimeException('Invalid transaction or signature format');
        }
        $signed = $p['LMI_PAYEE_PURSE'] . $p['LMI_PAYMENT_AMOUNT'] . $p['LMI_PAYMENT_NO'] . $p['LMI_MODE'] . $p['LMI_SYS_INVS_NO'] . $p['LMI_SYS_TRANS_NO'] . $p['LMI_SYS_TRANS_DATE'] . $this->config('secret') . $p['LMI_PAYER_PURSE'] . $p['LMI_PAYER_WM'];
        if (!hash_equals(strtoupper(hash('sha256', $signed)), strtoupper($p['LMI_HASH']))) {
            throw new RuntimeException('Invalid payment signature');
        }
        $ledger->settle($this->gatewayRecord, $p['LMI_PAYMENT_NO'], $merchant, $nativeAmount, 'USD', $p['LMI_SYS_TRANS_NO']);

        return response('OK');
    }
}
