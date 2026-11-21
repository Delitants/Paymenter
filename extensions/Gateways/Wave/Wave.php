<?php

namespace Paymenter\Extensions\Gateways\Wave;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Models\Gateway as GatewayRecord;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CollectionDisabledException;
use App\Services\Gateways\CustomerBindings;
use App\Services\Gateways\PaymentAttempts;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use RuntimeException;

#[ExtensionMeta(name: 'Wave', description: 'Native Wave invoices with merchant-bound payment reconciliation', version: '0.1.0', author: 'Paymenter Community')]
class Wave extends Gateway
{
    private const INVOICE_FIELDS = 'id internalId business { id } customer { id } invoiceNumber status currency { code } total { value } amountPaid { value } amountDue { value } viewUrl items { description quantity unitPrice subtotal { value } total { value } product { id } taxes { amount { value } salesTax { id business { id } rate(for: $taxDate) isCompound isArchived } } }';

    public function supportsCustomerFeeCollection(): bool
    {
        return true;
    }

    public function boot()
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('gateways.wave', __DIR__ . '/resources/views');
    }

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'access_token', 'label' => 'Wave access token', 'type' => 'password', 'encrypted' => true, 'required' => true],
            ['name' => 'business_id', 'label' => 'GraphQL business ID', 'type' => 'text', 'required' => true],
            ['name' => 'product_id', 'label' => 'Invoice product ID', 'type' => 'text', 'required' => true],
            ['name' => 'sales_tax_id', 'label' => 'Existing business sales tax ID (required for taxed invoices)', 'type' => 'text'],
            ['name' => 'currency', 'label' => 'Settlement currency', 'type' => 'text', 'required' => true],
            ['name' => 'webhook_business_id', 'label' => 'Webhook business ID (verify separately from GraphQL ID)', 'type' => 'text'],
            ['name' => 'webhook_secret', 'label' => 'Webhook signing secret', 'type' => 'password', 'encrypted' => true],
            ['name' => 'collection_enabled', 'label' => 'Enable payment collection after handover approval', 'type' => 'checkbox', 'default' => false],
        ];
    }

    private function merchant(): string
    {
        if (!$this->config('access_token') || !$this->config('business_id') || !$this->config('product_id') || !preg_match('/^[A-Z]{3}$/D', (string) $this->config('currency'))) {
            throw new RuntimeException('Wave configuration is incomplete');
        }

        return hash('sha256', $this->config('business_id') . ':' . $this->config('currency'));
    }

    private function api(string $query, array $variables): array
    {
        $this->merchant();
        try {
            $response = Http::withToken($this->config('access_token'))->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(10)->timeout(30)->post('https://gql.waveapps.com/graphql/public', ['query' => $query, 'variables' => $variables]);
            if (!$response->successful() || strlen($response->body()) > 1048576) {
                throw new RuntimeException;
            }
            $body = $response->json();
            if (!is_array($body) || !empty($body['errors']) || !is_array($body['data'] ?? null)) {
                throw new RuntimeException;
            }

            return $body['data'];
        } catch (\Throwable) {
            throw new RuntimeException('Wave request could not be verified; reconcile before retrying');
        }
    }

    public function testConfig(): bool|string
    {
        try {
            $r = $this->api('query WaveReadiness($id: ID!) { business(id: $id) { id } }', ['id' => $this->config('business_id')]);

            return ($r['business']['id'] ?? null) === $this->config('business_id') ? true : 'Wave business identity does not match';
        } catch (RuntimeException) {
            return 'Wave authentication could not be verified';
        }
    }

    public function pay(Invoice $invoice, $total)
    {
        if (!$this->gatewayRecord) {
            throw new RuntimeException('Explicit gateway record binding is required');
        }
        if ($invoice->transactions()->exists()) {
            throw new RuntimeException('Wave requires reconciled tax and payment order lines for this invoice');
        }
        $a = (new PaymentAttempts)->begin($this->gatewayRecord, $invoice, $this->merchant(), (string) $this->config('currency'));
        $payload = $a->provider_payload;
        if (!$payload || !isset($payload['view_url'])) {
            if ($a->state !== 'open' || $a->provider_payload !== null) {
                throw new RuntimeException('Checkout initialization requires reconciliation');
            }
            if ($a->pricing_payload === null) {
                throw new RuntimeException('Legacy Wave tax allocation requires reconciliation.');
            }
            $taxDate = now()->toDateString();
            $tax = null;
            $allocator = new OrderLines;
            if (BigDecimal::of($a->pricing_payload['product_tax'])->isPositive()) {
                $taxId = $this->config('sales_tax_id');
                if (!is_string($taxId) || $taxId === '') {
                    throw new RuntimeException('Wave requires an existing business sales tax ID.');
                }
                $r = $this->api('query WaveSalesTax($business: ID!, $tax: ID!, $taxDate: Date!) { business(id: $business) { id salesTax(id: $tax) { id business { id } rate(for: $taxDate) isCompound isArchived } } }',
                    ['business' => $this->config('business_id'), 'tax' => $taxId, 'taxDate' => $taxDate]);
                $tax = $r['business']['salesTax'] ?? [];
                if (($r['business']['id'] ?? null) !== $this->config('business_id') || ($tax['id'] ?? null) !== $taxId) {
                    throw new RuntimeException('Wave business sales tax identity does not match.');
                }
                $allocator->assertTax($tax, $this->config('business_id'), $a->pricing_payload['tax_context']['rate']);
            }
            $items = $allocator->build($a, (string) $this->config('product_id'), $tax);
            $customerId = (new CustomerBindings)->resolve('Wave', hash('sha256', (string) $this->config('business_id')),
                $a->invoice->user, fn () => $this->findOrCreateCustomer($a));
            if (!GatewayPaymentAttempt::whereKey($a->id)->where('state', 'open')->whereNull('provider_payload')->update(['state' => 'initializing'])) {
                throw new RuntimeException('Checkout initialization requires reconciliation');
            }
            $payload = ['customer_id' => $customerId, 'items' => $items, 'verified_tax' => $tax,
                'tax_date' => $taxDate, 'product_id' => $this->config('product_id')];
            $a->update(['provider_payload' => $payload]);
            $r = $this->api('mutation WaveCreateInvoice($input: InvoiceCreateInput!, $taxDate: Date!) { invoiceCreate(input: $input) { didSucceed invoice { ' . self::INVOICE_FIELDS . ' } } }', ['taxDate' => $taxDate, 'input' => [
                'businessId' => $this->config('business_id'), 'customerId' => $customerId, 'status' => 'DRAFT', 'currency' => $a->currency_code, 'invoiceNumber' => 'PAY-' . $a->reference, 'invoiceDate' => $taxDate,
                'items' => $items,
            ]]);
            if (($r['invoiceCreate']['didSucceed'] ?? null) !== true) {
                throw new RuntimeException('Wave invoice creation was not confirmed');
            }$remote = $r['invoiceCreate']['invoice'] ?? [];
            $this->verifyInvoice($a, $remote, false);
            if (!is_string($remote['id'] ?? null) || $remote['id'] === '') {
                throw new RuntimeException('Wave invoice identity is missing');
            }
            $a->update(['provider_reference' => $remote['id'], 'provider_webhook_reference' => isset($remote['internalId']) ? (string) $remote['internalId'] : null]);
            $r = $this->api('mutation WaveApproveInvoice($input: InvoiceApproveInput!, $taxDate: Date!) { invoiceApprove(input: $input) { didSucceed invoice { ' . self::INVOICE_FIELDS . ' } } }', ['taxDate' => $taxDate, 'input' => ['invoiceId' => $remote['id']]]);
            if (($r['invoiceApprove']['didSucceed'] ?? null) !== true) {
                throw new RuntimeException('Wave invoice approval was not confirmed');
            }$remote = $r['invoiceApprove']['invoice'] ?? [];
            $this->verifyInvoice($a, $remote, false);
            $this->checkUrl($remote['viewUrl'] ?? '');
            $payload['view_url'] = $remote['viewUrl'];
            $a->provider_payload = $payload;
            if (!GatewayPaymentAttempt::whereKey($a->id)->where('state', 'initializing')->update(['provider_payload' => $a->getAttributes()['provider_payload'], 'state' => 'open'])) {
                throw new RuntimeException('Checkout initialization requires reconciliation');
            }
        }
        $this->checkUrl($payload['view_url']);
        View::addNamespace('gateways.wave', __DIR__ . '/resources/views');

        return view('gateways.wave::pay', ['viewUrl' => $payload['view_url'], 'attempt' => $a]);
    }

    private function findOrCreateCustomer(GatewayPaymentAttempt $a): string
    {
        $email = $a->invoice->user->email;
        $r = $this->api('query WaveCustomers($business: ID!, $email: String!) { business(id: $business) { id customers(email: $email, page: 1, pageSize: 2) { pageInfo { totalCount } edges { node { id email isArchived } } } } }', ['business' => $this->config('business_id'), 'email' => $email]);
        if (($r['business']['id'] ?? null) !== $this->config('business_id')) {
            throw new RuntimeException('Wave customer business does not match');
        }
        $customers = $r['business']['customers'] ?? [];
        $count = $customers['pageInfo']['totalCount'] ?? null;
        $edges = $customers['edges'] ?? null;
        if (!is_int($count) || !is_array($edges) || $count > 1 || $count < 0 || count($edges) !== $count) {
            throw new RuntimeException('Wave customer identity requires reconciliation');
        }
        if ($count === 1) {
            $customer = $edges[0]['node'];
            if (($customer['email'] ?? null) !== $email || ($customer['isArchived'] ?? true)) {
                throw new RuntimeException('Wave customer identity does not match');
            }
        } else {
            $r = $this->api('mutation WaveCreateCustomer($input: CustomerCreateInput!) { customerCreate(input: $input) { didSucceed customer { id email } } }', ['input' => ['businessId' => $this->config('business_id'), 'name' => $a->invoice->user_name, 'email' => $email, 'currency' => $a->currency_code]]);
            if (($r['customerCreate']['didSucceed'] ?? null) !== true) {
                throw new RuntimeException('Wave customer creation was not confirmed');
            }$customer = $r['customerCreate']['customer'] ?? [];
            if (($customer['email'] ?? null) !== $email) {
                throw new RuntimeException('Wave customer identity does not match');
            }
        }
        if (!is_string($customer['id'] ?? null) || $customer['id'] === '') {
            throw new RuntimeException('Wave customer identity is missing');
        }

        return $customer['id'];
    }

    private function checkUrl(string $url): void
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || !in_array($p['host'] ?? '', ['next.waveapps.com', 'my.waveapps.com', 'invoice.waveapps.com'], true) || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            throw new RuntimeException('Unexpected Wave invoice URL');
        }
    }

    private function verifyInvoice(GatewayPaymentAttempt $a, array $r, bool $paid): void
    {
        if (($r['business']['id'] ?? null) !== $this->config('business_id') || ($r['customer']['id'] ?? null) !== ($a->provider_payload['customer_id'] ?? null) || ($r['invoiceNumber'] ?? null) !== 'PAY-' . $a->reference || ($r['currency']['code'] ?? null) !== $a->currency_code || ($a->provider_reference && ($r['id'] ?? null) !== $a->provider_reference)) {
            throw new RuntimeException('Wave invoice identity does not match');
        }
        if (!is_string($r['total']['value'] ?? null) || !BigDecimal::of($r['total']['value'])->isEqualTo($a->amount)) {
            throw new RuntimeException('Wave invoice amount does not match');
        }
        (new OrderLines)->assertInvoice($a, $r);
        if ($paid && (($r['status'] ?? null) !== 'PAID' || !is_string($r['amountDue']['value'] ?? null) || !is_string($r['amountPaid']['value'] ?? null) || !BigDecimal::of($r['amountDue']['value'])->isZero() || !BigDecimal::of($r['amountPaid']['value'])->isEqualTo($a->amount))) {
            throw new RuntimeException('Wave invoice is not fully paid');
        }
    }

    public function syncPayment(string $reference): void
    {
        if (!$this->gatewayRecord) {
            throw new RuntimeException('Explicit gateway record binding is required');
        }$ledger = new PaymentAttempts;
        $ledger->assertCollection($this->gatewayRecord);
        $a = GatewayPaymentAttempt::where('gateway_id', $this->gatewayRecord->id)->where('reference', $reference)->first();
        if (!$a || !$a->provider_reference) {
            throw new RuntimeException('Unknown destination invoice');
        }
        $ledger->validate($this->gatewayRecord, $reference, $this->merchant(), $a->amount, $a->currency_code);
        $r = $this->api('query WaveInvoice($business: ID!, $invoice: ID!, $taxDate: Date!) { business(id: $business) { id invoice(id: $invoice) { ' . self::INVOICE_FIELDS . ' } } }', ['business' => $this->config('business_id'), 'invoice' => $a->provider_reference, 'taxDate' => $a->provider_payload['tax_date'] ?? now()->toDateString()]);
        if (($r['business']['id'] ?? null) !== $this->config('business_id')) {
            throw new RuntimeException('Wave business does not match');
        }$this->verifyInvoice($a, $r['business']['invoice'] ?? [], true);
        $ledger->settle($this->gatewayRecord, $reference, $this->merchant(), $a->amount, $a->currency_code, 'invoice:' . $a->provider_reference);
    }

    public function notify(Request $request, GatewayRecord $gateway)
    {
        if ($gateway->extension !== 'Wave') {
            abort(404);
        }$e = (new self($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        try {
            return $e->processNotification($request);
        } catch (MigrationHeldException|CollectionDisabledException) {
            return response('Payment processing is held', 409);
        } catch (RuntimeException|\InvalidArgumentException|\JsonException) {
            return response('Payment verification failed', 422);
        }
    }

    private function processNotification(Request $request)
    {
        (new PaymentAttempts)->assertCollection($this->gatewayRecord);
        $secret = $this->config('webhook_secret');
        $raw = $request->getContent();
        $signature = $request->header('x-wave-signature', '');
        if (!$secret || !$this->config('webhook_business_id') || strlen($raw) > 65536 || !preg_match('/^t=([0-9]{10}),v1=([a-fA-F0-9]{64})$/D', $signature, $m) || $request->header('x-wave-timestamp') !== $m[1] || abs(now()->timestamp - (int) $m[1]) > 300 || !hash_equals(hash_hmac('sha256', $m[1] . '.' . $raw, $secret), strtolower($m[2]))) {
            throw new RuntimeException('Invalid Wave signature');
        }
        $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (($event['business_id'] ?? null) !== $this->config('webhook_business_id')) {
            throw new RuntimeException('Unexpected Wave merchant');
        }
        if (($event['event_type'] ?? null) !== 'invoice.paid') {
            return response('Event does not settle an invoice');
        }
        $id = $event['data']['invoice_id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('Missing Wave invoice identity');
        }
        $a = GatewayPaymentAttempt::where('gateway_id', $this->gatewayRecord->id)->where('provider_webhook_reference', $id)->first();
        if (!$a || ($event['data']['currency_code'] ?? null) !== $a->currency_code) {
            throw new RuntimeException('Unknown destination Wave invoice');
        }
        $this->syncPayment($a->reference);

        return response('OK');
    }
}
