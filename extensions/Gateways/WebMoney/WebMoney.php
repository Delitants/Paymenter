<?php

namespace Paymenter\Extensions\Gateways\WebMoney;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Models\Gateway as GatewayRecord;
use App\Models\Invoice;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CollectionDisabledException;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Http\Request;
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
            ['name' => 'purse', 'label' => 'Merchant WMZ purse', 'type' => 'text', 'required' => true],
            ['name' => 'secret', 'label' => 'Merchant secret key', 'type' => 'password', 'encrypted' => true, 'required' => true],
            ['name' => 'currency', 'label' => 'Settlement currency', 'type' => 'select', 'options' => ['USD' => 'USD'], 'default' => 'USD', 'required' => true],
            ['name' => 'test_mode', 'label' => 'Merchant is in test mode', 'type' => 'checkbox', 'default' => true],
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
        if (!preg_match('/^Z[0-9]{12}$/D', (string) $this->config('purse')) || !$this->config('secret') || $this->config('currency') !== 'USD') {
            throw new RuntimeException('WebMoney merchant configuration is incomplete or currency is unsupported');
        }

        return hash('sha256', $this->config('purse') . ':' . (filter_var($this->config('test_mode'), FILTER_VALIDATE_BOOLEAN) ? 'test' : 'live'));
    }

    public function pay(Invoice $invoice, $total)
    {
        if (!$this->gatewayRecord) {
            throw new RuntimeException('Explicit gateway record binding is required');
        }
        if ($invoice->currency_code !== $this->config('currency')) {
            throw new RuntimeException('Invoice currency does not match the merchant currency');
        }
        $attempt = (new PaymentAttempts)->begin($this->gatewayRecord, $invoice, $this->merchant(), (string) $this->config('currency'));
        View::addNamespace('gateways.webmoney', __DIR__ . '/resources/views');

        return view('gateways.webmoney::pay', ['purse' => $this->config('purse'), 'attempt' => $attempt, 'gateway' => $this->gatewayRecord, 'invoice' => $invoice, 'testMode' => filter_var($this->config('test_mode'), FILTER_VALIDATE_BOOLEAN)]);
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
        foreach (['LMI_PAYEE_PURSE', 'LMI_PAYMENT_AMOUNT', 'LMI_PAYMENT_NO', 'LMI_MODE'] as $key) {
            if (!isset($p[$key]) || !is_string($p[$key])) {
                throw new RuntimeException('Missing payment field');
            }
        }
        if (isset($p['LMI_HOLD']) || $p['LMI_PAYEE_PURSE'] !== $this->config('purse') || !preg_match('/^[0-9]{1,15}$/D', $p['LMI_PAYMENT_NO']) || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $p['LMI_PAYMENT_AMOUNT']) || $p['LMI_MODE'] !== (filter_var($this->config('test_mode'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0')) {
            throw new RuntimeException('Unexpected merchant, amount or payment mode');
        }
        if (($p['LMI_PREREQUEST'] ?? null) === '1') {
            $ledger->validate($this->gatewayRecord, $p['LMI_PAYMENT_NO'], $merchant, $p['LMI_PAYMENT_AMOUNT'], 'USD');

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
        $ledger->settle($this->gatewayRecord, $p['LMI_PAYMENT_NO'], $merchant, $p['LMI_PAYMENT_AMOUNT'], 'USD', $p['LMI_SYS_TRANS_NO']);

        return response('OK');
    }
}
