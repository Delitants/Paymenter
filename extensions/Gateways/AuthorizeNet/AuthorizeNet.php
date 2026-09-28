<?php

namespace Paymenter\Extensions\Gateways\AuthorizeNet;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Models\Gateway as GatewayRecord;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CollectionDisabledException;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use RuntimeException;

#[ExtensionMeta(name: 'Authorize.Net', description: 'Accept Hosted checkout with verified merchant transaction readback', version: '0.1.0', author: 'Paymenter Community')]
class AuthorizeNet extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('gateways.authorizenet', __DIR__ . '/resources/views');
    }

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'api_login_id', 'label' => 'API login ID', 'type' => 'text', 'required' => true],
            ['name' => 'transaction_key', 'label' => 'Transaction key', 'type' => 'password', 'encrypted' => true, 'required' => true],
            ['name' => 'signature_key', 'label' => 'Webhook signature key', 'type' => 'password', 'encrypted' => true, 'required' => true],
            ['name' => 'environment', 'label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Sandbox', 'production' => 'Production'], 'default' => 'test', 'required' => true],
            ['name' => 'currency', 'label' => 'Verified merchant settlement currency', 'type' => 'select', 'options' => ['USD' => 'USD'], 'default' => 'USD', 'required' => true],
            ['name' => 'collection_enabled', 'label' => 'Enable payment collection after handover approval', 'type' => 'checkbox', 'default' => false],
        ];
    }

    private function merchant(): string
    {
        if (!$this->config('api_login_id') || !$this->config('transaction_key') || !preg_match('/^[a-fA-F0-9]{128}$/D', (string) $this->config('signature_key')) || !in_array($this->config('environment'), ['test', 'production'], true) || $this->config('currency') !== 'USD') {
            throw new RuntimeException('Authorize.Net merchant configuration is incomplete or unsupported');
        }

        return hash('sha256', $this->config('api_login_id') . ':' . $this->config('environment') . ':' . $this->config('currency'));
    }

    private function api(string $operation, array $parameters = []): array
    {
        $this->merchant();
        $url = $this->config('environment') === 'test' ? 'https://apitest.authorize.net/xml/v1/request.api' : 'https://api.authorize.net/xml/v1/request.api';
        try {
            $response = Http::acceptJson()->asJson()->withoutRedirecting()->connectTimeout(10)->timeout(30)->post($url, [$operation => array_merge(['merchantAuthentication' => ['name' => $this->config('api_login_id'), 'transactionKey' => $this->config('transaction_key')]], $parameters)]);
            if (!$response->successful() || strlen($response->body()) > 1048576) {
                throw new RuntimeException;
            }
            $body = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $response->body()), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($body) || ($body['messages']['resultCode'] ?? null) !== 'Ok') {
                throw new RuntimeException;
            }

            return $body;
        } catch (\Throwable) {
            throw new RuntimeException('Authorize.Net request could not be verified; reconcile before retrying');
        }
    }

    public function testConfig(): bool|string
    {
        try {
            $this->api('authenticateTestRequest');

            return true;
        } catch (RuntimeException) {
            return 'Authorize.Net authentication could not be verified';
        }
    }

    public function pay(Invoice $invoice, $total)
    {
        if (!$this->gatewayRecord) {
            throw new RuntimeException('Explicit gateway record binding is required');
        }
        $attempt = (new PaymentAttempts)->begin($this->gatewayRecord, $invoice, $this->merchant(), (string) $this->config('currency'));
        $payload = $attempt->provider_payload;
        if (!$payload) {
            // Persist the claim before networking. A timeout leaves it claimed for reconciliation.
            $claimed = GatewayPaymentAttempt::whereKey($attempt->id)->where('state', 'open')->whereNull('provider_payload')->update(['state' => 'initializing']);
            if (!$claimed) {
                throw new RuntimeException('Checkout initialization requires reconciliation');
            }
            $result = $this->api('getHostedPaymentPageRequest', [
                'transactionRequest' => ['transactionType' => 'authCaptureTransaction', 'amount' => $attempt->amount, 'order' => ['invoiceNumber' => $attempt->reference]],
                'hostedPaymentSettings' => ['setting' => [
                    ['settingName' => 'hostedPaymentReturnOptions', 'settingValue' => json_encode(['showReceipt' => true, 'url' => route('invoices.show', $invoice), 'cancelUrl' => route('invoices.show', $invoice)])],
                    ['settingName' => 'hostedPaymentPaymentOptions', 'settingValue' => json_encode(['showCreditCard' => true, 'showBankAccount' => false])],
                ]],
            ]);
            if (!is_string($result['token'] ?? null) || $result['token'] === '' || strlen($result['token']) > 8192) {
                throw new RuntimeException('Hosted checkout token could not be verified');
            }
            $payload = ['token' => $result['token'], 'expires_at' => now()->addMinutes(14)->timestamp];
            $attempt->update(['provider_payload' => $payload, 'state' => 'open']);
        }
        if (($payload['expires_at'] ?? 0) <= now()->timestamp) {
            throw new RuntimeException('Hosted checkout expired; reconcile the payment before starting again');
        }
        View::addNamespace('gateways.authorizenet', __DIR__ . '/resources/views');

        return view('gateways.authorizenet::pay', ['attempt' => $attempt, 'token' => $payload['token'], 'formUrl' => $this->config('environment') === 'test' ? 'https://test.authorize.net/payment/payment' : 'https://accept.authorize.net/payment/payment']);
    }

    public function notify(Request $request, GatewayRecord $gateway)
    {
        if ($gateway->extension !== 'AuthorizeNet') {
            abort(404);
        }
        $extension = (new self($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        try {
            return $extension->processNotification($request);
        } catch (MigrationHeldException|CollectionDisabledException) {
            return response('Payment processing is held', 409);
        } catch (RuntimeException|\InvalidArgumentException|\JsonException) {
            return response('Payment verification failed', 422);
        }
    }

    private function processNotification(Request $request)
    {
        $ledger = new PaymentAttempts;
        $ledger->assertCollection($this->gatewayRecord);
        $merchant = $this->merchant();
        $raw = $request->getContent();
        $signature = $request->header('X-ANET-Signature', '');
        if (strlen($raw) > 65536 || !preg_match('/^sha512=([a-fA-F0-9]{128})$/Di', $signature, $matches) || !hash_equals(strtoupper(hash_hmac('sha512', $raw, (string) $this->config('signature_key'))), strtoupper($matches[1]))) {
            throw new RuntimeException('Invalid notification signature');
        }
        $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new RuntimeException('Invalid notification');
        }
        if (($event['eventType'] ?? null) !== 'net.authorize.payment.authcapture.created') {
            return response('Event does not settle an invoice');
        }
        $id = $event['payload']['id'] ?? null;
        if (($event['payload']['entityName'] ?? null) !== 'transaction' || !is_string($id) || !preg_match('/^[0-9]+$/D', $id)) {
            throw new RuntimeException('Invalid provider transaction identity');
        }
        $transaction = $this->api('getTransactionDetailsRequest', ['transId' => $id])['transaction'] ?? null;
        if (!is_array($transaction) || (string) ($transaction['transId'] ?? '') !== $id || ($transaction['transactionType'] ?? null) !== 'authCaptureTransaction' || !in_array($transaction['transactionStatus'] ?? null, ['capturedPendingSettlement', 'settledSuccessfully'], true)) {
            throw new RuntimeException('Provider transaction is not captured');
        }
        $reference = $transaction['order']['invoiceNumber'] ?? null;
        $amount = $transaction['settleAmount'] ?? null;
        if (!is_string($reference) || !preg_match('/^[0-9]{15}$/D', $reference) || !is_scalar($amount) || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', (string) $amount)) {
            throw new RuntimeException('Provider transaction does not identify a destination attempt');
        }
        // The merchant account fixes currency; never take an amount or currency from webhook JSON.
        if (isset($transaction['currencyCode']) && $transaction['currencyCode'] !== $this->config('currency')) {
            throw new RuntimeException('Provider currency does not match merchant');
        }
        $ledger->settle($this->gatewayRecord,$reference,$merchant,(string) $amount,(string) $this->config('currency'),$id);

        return response('OK');
    }
}
