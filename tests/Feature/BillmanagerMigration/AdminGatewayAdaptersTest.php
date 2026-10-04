<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Gateways\Operations\GatewayOperations;
use App\Services\Gateways\Operations\ProviderOperations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Gateways\Klarna\Klarna;
use Paymenter\Extensions\Gateways\WebMoney\ConversionQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

class AdminGatewayAdaptersTest extends TestCase
{
    use UsesCommittedDatabase;

    private string $stripeMerchant = 'acct_synthetic';

    private int $stripeRefunded = 0;

    private bool $stripeAuthorized = true;

    private bool $stripeDuplicate = false;

    private array $childChanges = [];

    private string $webmoneyChildAmount = '25.00';

    private string $authorizeRefundStatus = 'refundSettledSuccessfully';

    private array $stripeRefundChanges = [];

    private array $stripeOriginalChanges = [];

    private bool $stripeFaked = false;

    private bool $stripeReadFailure = false;

    private bool $stripeWriteFailure = false;

    private bool $stripePaginate = false;

    private const KEY = 'df645892-7400-408d-afb8-d275bff0401d';

    protected function fixture(string $extension = 'Stripe', array $settings = [], string $reference = 'pi_synthetic'): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'currency_code' => 'USD']);
        $gateway = Gateway::create(['name' => 'Synthetic operations', 'type' => 'gateway', 'extension' => $extension, 'enabled' => false]);
        foreach ($settings ?: ['stripe_secret_key' => 'rk_test_synthetic'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value, 'encrypted' => in_array($key, ['wm_certificate', 'wm_private_key', 'wm_key_passphrase'], true)]);
        }
        $transaction = $invoice->transactions()->create(['gateway_id' => $gateway->id, 'transaction_id' => $reference, 'amount' => '109.88', 'status' => InvoiceTransactionStatus::Succeeded]);

        return [$invoice, $gateway, $transaction, (new GatewayOperations)->for($gateway)];
    }

    protected function operation(array $context, ?string $reference = null): PaymentOperation
    {
        return new PaymentOperation(['kind' => 'provider_refund', 'request_key' => self::KEY, 'state' => 'processing',
            'invoice_id' => $context['invoice_id'], 'gateway_id' => $context['gateway_id'], 'amount' => $context['amount'], 'currency_code' => 'USD',
            'original_transaction_id' => $context['transaction_id'], 'provider_reference' => $reference, 'payload' => ['provider_context' => $context]]);
    }

    protected function stripeOriginal(Invoice $invoice, array $change = []): array
    {
        return array_replace_recursive(['id' => 'pi_synthetic', 'object' => 'payment_intent', 'livemode' => false, 'status' => 'succeeded', 'currency' => 'usd',
            'amount' => 10988, 'amount_received' => 10988, 'metadata' => ['invoice_id' => (string) $invoice->id], 'latest_charge' => 'ch_synthetic'], $change);
    }

    protected function stripeRefund(array $change = []): array
    {
        return array_replace_recursive(['id' => 're_synthetic', 'object' => 'refund', 'payment_intent' => 'pi_synthetic', 'charge' => 'ch_synthetic',
            'amount' => 2500, 'currency' => 'usd', 'status' => 'succeeded', 'metadata' => ['paymenter_operation' => self::KEY]], $change);
    }

    protected function fakeStripe(Invoice $invoice, array $refund = [], array $original = []): void
    {
        $this->stripeRefundChanges = $refund;
        $this->stripeOriginalChanges = $original;
        if ($this->stripeFaked) {
            return;
        }
        $this->stripeFaked = true;
        Http::fake(function ($request) use ($invoice) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/account') {
                return $this->stripeAuthorized ? Http::response(['id' => $this->stripeMerchant, 'object' => 'account']) : Http::response([], 401);
            }
            if ($path === '/v1/payment_intents/pi_synthetic') {
                return Http::response($this->stripeOriginal($invoice, $this->stripeOriginalChanges));
            }
            if ($path === '/v1/charges/ch_synthetic') {
                return Http::response(['id' => 'ch_synthetic', 'payment_intent' => 'pi_synthetic', 'livemode' => false, 'captured' => true, 'paid' => true, 'amount' => 10988, 'amount_captured' => 10988, 'amount_refunded' => $this->stripeRefunded, 'currency' => 'usd']);
            }
            if ($path === '/v1/refunds/re_synthetic') {
                return $this->stripeReadFailure ? Http::failedConnection() : Http::response(array_replace($this->stripeRefund(), $this->stripeRefundChanges));
            }
            if ($path === '/v1/refunds' && $request->method() === 'POST') {
                return $this->stripeWriteFailure ? Http::failedConnection() : Http::response(['id' => 're_synthetic']);
            }
            if ($path === '/v1/refunds' && $this->stripePaginate) {
                return str_contains($request->url(), 'starting_after=re_unrelated')
                    ? Http::response(['data' => $this->stripeDuplicate ? [$this->stripeRefund(), $this->stripeRefund(['id' => 're_duplicate'])] : [$this->stripeRefund()], 'has_more' => false])
                    : Http::response(['data' => [$this->stripeRefund(['id' => 're_unrelated', 'metadata' => ['paymenter_operation' => 'other']])], 'has_more' => true]);
            }
            throw new \RuntimeException('Unexpected synthetic Stripe request');
        });
    }

    public function test_stripe_refund_has_one_uuid_write_and_independent_exact_readback(): void
    {
        [$invoice, $gateway, $transaction, $adapter] = $this->fixture();
        $this->assertTrue($adapter->capabilities()['refund']);
        $fingerprint = $adapter->fingerprint();
        Http::assertNothingSent();
        $this->fakeStripe($invoice);
        $context = $adapter->prepare($invoice, $transaction, 'provider_refund', 'pi_synthetic', '25.00', 'USD');
        $this->assertSame('acct_synthetic', $context['merchant']);
        $this->assertSame('test', $context['environment']);
        $this->assertNull($context['attempt_id']);
        $this->assertSame('109.88', $context['original_amount']);
        $operation = $this->operation($context);
        $result = $adapter->execute($operation);
        $this->assertSame('succeeded', $result->state);
        $result->assertVerified($operation);
        $this->assertSame($fingerprint, $adapter->fingerprint());
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.stripe.com/v1/refunds' &&
            $request->hasHeader('Idempotency-Key', self::KEY) && $request->hasHeader('Stripe-Version', '2025-07-30.basil') &&
            $request['charge'] === 'ch_synthetic' && (string) $request['amount'] === '2500' && $request['metadata']['paymenter_operation'] === self::KEY);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertStringNotContainsString('rk_test_synthetic', json_encode($result->safeEvidence()));
    }

    public static function invalidRefunds(): array
    {
        return [['amount', 2499], ['currency', 'eur'], ['charge', 'ch_other'], ['payment_intent', 'pi_other'], ['metadata', []], ['id', 're_other']];
    }

    #[DataProvider('invalidRefunds')]
    public function test_stripe_never_finalizes_mismatched_child(string $field, mixed $value): void
    {
        [$invoice, , $transaction, $adapter] = $this->fixture();
        $this->assertTrue($adapter->capabilities()['refund']);
        $this->fakeStripe($invoice);
        $context = $adapter->prepare($invoice, $transaction, 'provider_refund', 'pi_synthetic', '25.00', 'USD');
        $this->stripeRefundChanges = [$field => $value];
        $result = $adapter->reconcile($this->operation($context, 're_synthetic'));
        $this->assertSame('uncertain', $result->state);
        $this->assertCount(0, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_stripe_pending_and_network_unknown_remain_reserved_without_replay(): void
    {
        [$invoice, , $transaction, $adapter] = $this->fixture();
        $this->assertTrue($adapter->capabilities()['refund']);
        $this->fakeStripe($invoice, ['status' => 'pending']);
        $context = $adapter->prepare($invoice, $transaction, 'provider_refund', 'pi_synthetic', '25.00', 'USD');
        $this->assertSame('pending', $adapter->reconcile($this->operation($context, 're_synthetic'))->state);
        $this->stripeWriteFailure = true;
        $this->assertSame('uncertain', $adapter->execute($this->operation($context))->state);
        $this->assertLessThanOrEqual(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_stripe_lost_reference_recovery_paginates_and_requires_unique_uuid(): void
    {
        [$invoice, , $transaction, $adapter] = $this->fixture();
        $this->assertTrue($adapter->capabilities()['refund']);
        $this->fakeStripe($invoice);
        $context = $adapter->prepare($invoice, $transaction, 'provider_refund', 'pi_synthetic', '25.00', 'USD');
        $this->stripePaginate = true;
        $operation = $this->operation($context);
        $result = $adapter->reconcile($operation);
        $this->assertSame('succeeded', $result->state);
        $result->assertVerified($operation);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'starting_after=re_unrelated'));
        $this->stripeDuplicate = true;
        $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        $this->assertCount(0, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_stripe_rejects_wrong_invoice_environment_and_non_two_decimal_money_before_write(): void
    {
        [$invoice, , $transaction, $adapter] = $this->fixture();
        $this->assertTrue($adapter->capabilities()['refund']);
        foreach ([['metadata' => ['invoice_id' => '0']], ['livemode' => true], ['currency' => 'jpy']] as $change) {
            $this->fakeStripe($invoice, original: $change);
            try {
                $adapter->prepare($invoice, $transaction, 'provider_refund', 'pi_synthetic', '25.00', 'USD');
                $this->fail('Mismatched original accepted');
            } catch (\RuntimeException) {
                $this->assertCount(0, Http::recorded(fn ($request) => $request->method() === 'POST'));
            }
        }
    }

    public function test_mollie_refund_uses_profile_mode_and_uuid_child_readback(): void
    {
        [$invoice, , $transaction, $adapter] = $this->fixture('Mollie', ['api_key' => 'test_synthetic'], 'tr_synthetic');
        $this->assertTrue($adapter->capabilities()['refund']);
        Http::fake([
            'api.mollie.com/v2/profiles/me' => Http::response(['id' => 'pfl_synthetic']),
            'api.mollie.com/v2/payments/tr_synthetic' => Http::response(['resource' => 'payment', 'id' => 'tr_synthetic', 'profileId' => 'pfl_synthetic', 'mode' => 'test', 'status' => 'paid', 'method' => 'creditcard',
                'metadata' => ['invoice_id' => $invoice->id], 'amount' => ['currency' => 'USD', 'value' => '109.88'], 'amountRefunded' => ['currency' => 'USD', 'value' => '0.00']]),
            'api.mollie.com/v2/payments/tr_synthetic/refunds' => Http::response(['id' => 're_synthetic']),
            'api.mollie.com/v2/payments/tr_synthetic/refunds?*' => fn ($request) => str_contains($request->url(), 'from=re_unrelated')
                ? Http::response(['_embedded' => ['refunds' => [['id' => 're_synthetic', 'metadata' => ['paymenter_operation' => self::KEY]]]], '_links' => ['next' => null]])
                : Http::response(['_embedded' => ['refunds' => [['id' => 're_unrelated', 'metadata' => ['paymenter_operation' => 'other']]]], '_links' => ['next' => ['href' => 'https://api.mollie.com/v2/payments/tr_synthetic/refunds?from=re_unrelated']]]),
            'api.mollie.com/v2/payments/tr_synthetic/refunds/re_synthetic' => fn () => Http::response(array_replace(['resource' => 'refund', 'id' => 're_synthetic', 'paymentId' => 'tr_synthetic', 'mode' => 'test',
                'status' => 'refunded', 'amount' => ['currency' => 'USD', 'value' => '25.00'], 'metadata' => ['paymenter_operation' => self::KEY]], $this->childChanges)),
        ]);
        $operation = $this->operation($adapter->prepare($invoice, $transaction, 'provider_refund', 'tr_synthetic', '25.00', 'USD'));
        $result = $adapter->execute($operation);
        $this->assertSame('succeeded', $result->state);
        $result->assertVerified($operation);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('Idempotency-Key', self::KEY) &&
            $request['amount'] === ['currency' => 'USD', 'value' => '25.00'] && $request['metadata']['paymenter_operation'] === self::KEY);
        $operation->provider_reference = 're_synthetic';
        foreach ([['paymentId' => 'tr_other'], ['mode' => 'live'], ['metadata' => []], ['amount' => ['currency' => 'EUR', 'value' => '25.00']], ['amount' => ['currency' => 'USD', 'value' => '24.99']]] as $change) {
            $this->childChanges = $change;
            $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        }
        $this->childChanges = ['status' => 'pending'];
        $this->assertSame('pending', $adapter->reconcile($operation)->state);
        $this->childChanges = ['status' => 'failed'];
        $failure = $adapter->reconcile($operation);
        $this->assertSame('failed', $failure->state);
        $failure->assertVerified($operation);
        $this->childChanges = [];
        $operation->provider_reference = null;
        $recovered = $adapter->reconcile($operation);
        $pages = Http::recorded(fn ($request) => $request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/v2/payments/tr_synthetic/refunds')->map(fn ($pair) => $pair[0]->url())->values()->all();
        $this->assertSame(['https://api.mollie.com/v2/payments/tr_synthetic/refunds?limit=250', 'https://api.mollie.com/v2/payments/tr_synthetic/refunds?from=re_unrelated'], $pages);
        $this->assertSame('succeeded', $recovered->state);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'from=re_unrelated'));
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_paypal_refund_proves_order_payee_and_uuid_but_lost_id_stays_uncertain(): void
    {
        [$invoice, , $transaction, $adapter] = $this->fixture('PayPal', ['client_id' => 'synthetic', 'client_secret' => 'synthetic-secret', 'test_mode' => '1'], 'CAPTURE_SYNTHETIC');
        $this->assertTrue($adapter->capabilities()['refund']);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'synthetic-token', 'token_type' => 'Bearer']),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE_SYNTHETIC' => Http::response(['id' => 'CAPTURE_SYNTHETIC', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '109.88'],
                'payee' => ['merchant_id' => 'MERCHANT_SYNTHETIC'], 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER_SYNTHETIC']]]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER_SYNTHETIC' => Http::response(['id' => 'ORDER_SYNTHETIC', 'intent' => 'CAPTURE', 'status' => 'COMPLETED', 'purchase_units' => [[
                'invoice_id' => (string) $invoice->id, 'payee' => ['merchant_id' => 'MERCHANT_SYNTHETIC'], 'amount' => ['currency_code' => 'USD', 'value' => '109.88'],
                'payments' => ['captures' => [['id' => 'CAPTURE_SYNTHETIC', 'amount' => ['currency_code' => 'USD', 'value' => '109.88']]]]]]]),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE_SYNTHETIC/refund' => Http::response(['id' => 'REFUND_SYNTHETIC']),
            'api-m.sandbox.paypal.com/v2/payments/refunds/REFUND_SYNTHETIC' => fn () => Http::response(array_replace(['id' => 'REFUND_SYNTHETIC', 'status' => 'COMPLETED', 'custom_id' => self::KEY,
                'amount' => ['currency_code' => 'USD', 'value' => '25.00'], 'links' => [['rel' => 'up', 'method' => 'GET', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE_SYNTHETIC']]], $this->childChanges)),
        ]);
        $operation = $this->operation($adapter->prepare($invoice, $transaction, 'provider_refund', 'CAPTURE_SYNTHETIC', '25.00', 'USD'));
        $result = $adapter->execute($operation);
        $this->assertSame('succeeded', $result->state);
        $result->assertVerified($operation);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund') && $request->hasHeader('PayPal-Request-Id', self::KEY) && $request['custom_id'] === self::KEY);
        $writes = Http::recorded(fn ($request) => str_ends_with($request->url(), '/refund'))->count();
        $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        $this->assertSame($writes, Http::recorded(fn ($request) => str_ends_with($request->url(), '/refund'))->count());
        $operation->provider_reference = 'REFUND_SYNTHETIC';
        foreach ([['custom_id' => 'other'], ['amount' => ['currency_code' => 'USD', 'value' => '24.99']], ['amount' => ['currency_code' => 'EUR', 'value' => '25.00']], ['links' => [['rel' => 'up', 'method' => 'GET', 'href' => 'https://api-m.paypal.com/v2/payments/captures/CAPTURE_SYNTHETIC']]]] as $change) {
            $this->childChanges = $change;
            $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        }
        $this->childChanges = ['status' => 'PENDING'];
        $this->assertSame('pending', $adapter->reconcile($operation)->state);
        $this->assertSame($writes, Http::recorded(fn ($request) => str_ends_with($request->url(), '/refund'))->count());
    }

    public function test_paypal_lost_response_discovers_only_unique_exact_uuid_child(): void
    {
        [$invoice, , $transaction, $adapter] = $this->fixture('PayPal', ['client_id' => 'synthetic', 'client_secret' => 'synthetic-secret', 'test_mode' => '1'], 'CAPTURE_SYNTHETIC');
        $children = [];
        $merchant = 'MERCHANT_SYNTHETIC';
        $change = [];
        Http::fake(function ($request) use ($invoice, &$children, &$merchant, &$change) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/oauth2/token') {
                return Http::response(['access_token' => 'synthetic-token', 'token_type' => 'Bearer']);
            }
            if ($path === '/v2/payments/captures/CAPTURE_SYNTHETIC') {
                return Http::response(['id' => 'CAPTURE_SYNTHETIC', 'status' => $children ? 'PARTIALLY_REFUNDED' : 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '109.88'],
                    'payee' => ['merchant_id' => $merchant], 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER_SYNTHETIC']]]);
            }
            if ($path === '/v2/checkout/orders/ORDER_SYNTHETIC') {
                return Http::response(['id' => 'ORDER_SYNTHETIC', 'intent' => 'CAPTURE', 'status' => 'COMPLETED', 'purchase_units' => [[
                    'invoice_id' => (string) $invoice->id, 'payee' => ['merchant_id' => $merchant], 'amount' => ['currency_code' => 'USD', 'value' => '109.88'],
                    'payments' => ['captures' => [['id' => 'CAPTURE_SYNTHETIC', 'amount' => ['currency_code' => 'USD', 'value' => '109.88']]],
                        'refunds' => array_map(fn ($id) => ['id' => $id], $children)]]]]);
            }
            if ($request->method() === 'POST') {
                return Http::response([], 503); // Accepted remotely, response unavailable; child appears later.
            }
            if (str_starts_with($path, '/v2/payments/refunds/')) {
                return Http::response(array_replace(['id' => basename($path), 'status' => 'COMPLETED', 'custom_id' => self::KEY,
                    'amount' => ['currency_code' => 'USD', 'value' => '25.00'], 'links' => [['rel' => 'up', 'method' => 'GET', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE_SYNTHETIC']]], $change));
            }
            throw new \RuntimeException('Unexpected synthetic PayPal request');
        });
        $operation = $this->operation($adapter->prepare($invoice, $transaction, 'provider_refund', 'CAPTURE_SYNTHETIC', '25.00', 'USD'));
        $this->assertSame('uncertain', $adapter->execute($operation)->state);
        $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        $children = ['REFUND_SYNTHETIC'];
        $result = $adapter->reconcile($operation);
        $this->assertSame('succeeded', $result->state);
        $this->assertSame('REFUND_SYNTHETIC', $result->providerReference);
        $result->assertVerified($operation);
        $children[] = 'REFUND_DUPLICATE';
        $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        $children = ['REFUND_SYNTHETIC'];
        foreach ([['custom_id' => 'other'], ['id' => 'different'], ['amount' => ['currency_code' => 'USD', 'value' => '24.99']],
            ['amount' => ['currency_code' => 'EUR', 'value' => '25.00']],
            ['links' => [['rel' => 'up', 'method' => 'GET', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/OTHER_CAPTURE']]],
            ['links' => [['rel' => 'up', 'method' => 'GET', 'href' => 'https://api-m.paypal.com/v2/payments/captures/CAPTURE_SYNTHETIC']]]] as $change) {
            $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        }
        $change = [];
        $merchant = 'OTHER_MERCHANT';
        $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/refund') && $request->method() === 'POST'));
    }

    protected function nativeAttempt(Invoice $invoice, Gateway $gateway, InvoiceTransaction $transaction, string $reference, string $fingerprint): GatewayPaymentAttempt
    {
        $transaction->update(['transaction_id' => 'gateway:' . $gateway->id . ':' . $reference]);

        return GatewayPaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway_id' => $gateway->id, 'user_id' => $invoice->user_id, 'state' => 'paid',
            'reference' => '123456789012345', 'provider_transaction_id' => $reference, 'amount' => '109.88', 'currency_code' => 'USD', 'merchant_fingerprint' => $fingerprint]);
    }

    public function test_klarna_refund_retains_native_attempt_and_exact_child_reference(): void
    {
        [$invoice, $gateway, $transaction, $adapter] = $this->fixture('Klarna', ['merchant_id' => 'synthetic-merchant', 'secret' => 'synthetic-secret', 'environment' => 'test', 'region' => 'na', 'currency' => 'USD'], 'order-synthetic');
        $this->assertTrue($adapter->capabilities()['refund']);
        $attempt = $this->nativeAttempt($invoice, $gateway, $transaction, 'order-synthetic', hash('sha256', 'https://api-na.playground.klarna.com:synthetic-merchant:USD'));
        $lines = [['type' => 'digital', 'reference' => 'synthetic-line', 'name' => 'Synthetic purchase', 'quantity' => 1, 'unit_price' => 10988, 'total_amount' => 10988, 'total_tax_amount' => 0]];
        $attempt->update(['provider_payload' => ['purchase_country' => 'US', 'order_allocation' => ['order_amount' => 10988, 'order_tax_amount' => 0, 'order_lines' => $lines]]]);
        Http::fake([
            'api-na.playground.klarna.com/ordermanagement/v1/orders/order-synthetic' => Http::response(['order_id' => 'order-synthetic', 'merchant_reference1' => $attempt->reference, 'purchase_currency' => 'USD', 'purchase_country' => 'US',
                'order_amount' => 10988, 'original_order_amount' => 10988, 'captured_amount' => 10988, 'refunded_amount' => 0, 'status' => 'CAPTURED', 'fraud_status' => 'ACCEPTED', 'order_lines' => $lines, 'captures' => [['capture_id' => 'capture-synthetic']], 'refunds' => []]),
            'api-na.playground.klarna.com/ordermanagement/v1/orders/order-synthetic/refunds' => Http::response(['refund_id' => 'refund-synthetic'], 201),
            'api-na.playground.klarna.com/ordermanagement/v1/orders/order-synthetic/refunds/refund-synthetic' => fn () => Http::response(array_replace(['refund_id' => 'refund-synthetic', 'reference' => self::KEY, 'refunded_amount' => 2500, 'refunded_at' => '2026-10-01T12:00:00Z'], $this->childChanges)),
        ]);
        $operation = $this->operation($adapter->prepare($invoice, $transaction->fresh(), 'provider_refund', 'order-synthetic', '25.00', 'USD'));
        $result = $adapter->execute($operation);
        $this->assertSame('succeeded', $result->state);
        $result->assertVerified($operation);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('Klarna-Idempotency-Key', self::KEY) && $request['reference'] === self::KEY && $request['refunded_amount'] === 2500);
        $operation->provider_reference = 'refund-synthetic';
        foreach ([['refund_id' => 'other'], ['reference' => 'other'], ['refunded_amount' => 2499], ['refunded_at' => null]] as $change) {
            $this->childChanges = $change;
            $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        }
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_authorizenet_settled_card_refund_preparation_requires_masked_card_and_original_attempt(): void
    {
        [$invoice, $gateway, $transaction, $adapter] = $this->fixture('AuthorizeNet', ['api_login_id' => 'synthetic-login', 'transaction_key' => 'synthetic-key', 'environment' => 'test', 'currency' => 'USD'], '1234567');
        $this->assertTrue($adapter->capabilities()['refund']);
        $attempt = $this->nativeAttempt($invoice, $gateway, $transaction, '1234567', hash('sha256', 'synthetic-login:test:USD'));
        Http::fake(['apitest.authorize.net/xml/v1/request.api' => function ($request) use ($attempt) {
            if (isset($request['getTransactionDetailsRequest'])) {
                return Http::response(['messages' => ['resultCode' => 'Ok'], 'transaction' => ['transId' => '1234567', 'transactionType' => 'authCaptureTransaction', 'transactionStatus' => 'settledSuccessfully',
                    'order' => ['invoiceNumber' => $attempt->reference], 'settleAmount' => 109.88, 'authAmount' => 109.88, 'submitTimeUTC' => now()->subDays(2)->toIso8601String(),
                    'payment' => ['creditCard' => ['cardNumber' => 'XXXX1111', 'expirationDate' => 'XXXX']]]]);
            }

            return Http::response(['messages' => ['resultCode' => 'Ok'], 'transactions' => [], 'batchList' => [], 'totalNumInResultSet' => 0]);
        }]);
        $context = $adapter->prepare($invoice, $transaction->fresh(), 'provider_refund', '1234567', '25.00', 'USD');
        $this->assertSame($attempt->id, $context['attempt_id']);
        $this->assertSame('refundTransaction', $context['action']);
        $this->assertStringNotContainsString('XXXX1111', json_encode($context));
        $this->assertCount(0, Http::recorded(fn ($request) => isset($request['createTransactionRequest'])));
    }

    public static function authorizeEmptyReporting(): array
    {
        $empty = ['messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00004', 'text' => 'No records found.']]]];

        return [
            'provider omits both empty collections' => [$empty, $empty + ['totalNumInResultSet' => 0], true],
            'provider omits empty transaction collection' => [$empty + ['batchList' => []], $empty + ['totalNumInResultSet' => 0], true],
            'provider omits empty batch collection' => [$empty, $empty + ['transactions' => [], 'totalNumInResultSet' => 0], true],
            'provider returns explicit empty collections' => [$empty + ['batchList' => []], $empty + ['transactions' => [], 'totalNumInResultSet' => 0], true],
            'settled batch omits its empty transaction collection' => [['messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]], 'batchList' => [['batchId' => '7654321']]], $empty + ['totalNumInResultSet' => 0], true],
            'missing list with nonzero total' => [$empty + ['batchList' => []], $empty + ['totalNumInResultSet' => 1], false],
            'explicit empty list with positive total' => [$empty + ['batchList' => []], $empty + ['transactions' => [], 'totalNumInResultSet' => 1], false],
            'missing list without total' => [$empty + ['batchList' => []], $empty, false],
            'missing list without no records code' => [$empty + ['batchList' => []], ['messages' => ['resultCode' => 'Ok'], 'totalNumInResultSet' => 0], false],
            'missing batch without no records code' => [['messages' => ['resultCode' => 'Ok']], $empty + ['totalNumInResultSet' => 0], false],
            'explicit null batch list' => [$empty + ['batchList' => null], $empty + ['totalNumInResultSet' => 0], false],
            'explicit null list' => [$empty + ['batchList' => []], $empty + ['transactions' => null, 'totalNumInResultSet' => 0], false],
            'nonempty list with zero total' => [$empty + ['batchList' => []], $empty + ['transactions' => [['transId' => '7654321', 'transactionStatus' => 'settledSuccessfully']], 'totalNumInResultSet' => 0], false],
            'negative total' => [$empty + ['batchList' => []], $empty + ['transactions' => [], 'totalNumInResultSet' => -1], false],
            'fractional total' => [$empty + ['batchList' => []], $empty + ['transactions' => [], 'totalNumInResultSet' => '0.5'], false],
        ];
    }

    #[DataProvider('authorizeEmptyReporting')]
    public function test_authorizenet_preparation_distinguishes_proven_empty_reporting_from_missing_evidence(array $batches, array $unsettled, bool $accepted): void
    {
        [$invoice, $gateway, $transaction, $adapter] = $this->fixture('AuthorizeNet', ['api_login_id' => 'synthetic-login', 'transaction_key' => 'synthetic-key', 'environment' => 'test', 'currency' => 'USD'], '1234567');
        $attempt = $this->nativeAttempt($invoice, $gateway, $transaction, '1234567', hash('sha256', 'synthetic-login:test:USD'));
        Http::fake(['apitest.authorize.net/xml/v1/request.api' => function ($request) use ($attempt, $batches, $unsettled) {
            if (isset($request['getTransactionDetailsRequest'])) {
                return Http::response(['messages' => ['resultCode' => 'Ok'], 'transaction' => ['transId' => '1234567', 'transactionType' => 'authCaptureTransaction', 'transactionStatus' => 'settledSuccessfully',
                    'order' => ['invoiceNumber' => $attempt->reference], 'settleAmount' => '109.88', 'authAmount' => '109.88', 'submitTimeUTC' => now()->subDays(2)->toIso8601String(),
                    'payment' => ['creditCard' => ['cardNumber' => 'XXXX1111']]]]);
            }
            if (isset($request['getSettledBatchListRequest'])) {
                return Http::response($batches);
            }
            if (isset($request['getUnsettledTransactionListRequest']) || isset($request['getTransactionListRequest'])) {
                return Http::response($unsettled);
            }
            throw new \RuntimeException('Unexpected synthetic reporting request');
        }]);
        $context = $error = null;
        try {
            $context = $adapter->prepare($invoice, $transaction->fresh(), 'provider_refund', '1234567', '25.00', 'USD');
        } catch (\RuntimeException $e) {
            $error = $e;
        }
        if ($accepted) {
            $this->assertNull($error, $error?->getMessage() ?? '');
            $this->assertSame('0.00', $context['already_refunded']);
        } else {
            $this->assertInstanceOf(\RuntimeException::class, $error, 'Incomplete reporting evidence authorized refund preparation.');
            $this->assertSame(0, PaymentOperation::count());
            if (is_array($batches['batchList'] ?? null)) {
                $this->assertNotEmpty(Http::recorded(fn ($request) => isset($request['getUnsettledTransactionListRequest'])));
            }
        }
        if (!empty($batches['batchList'])) {
            Http::assertSent(fn ($request) => ($request['getTransactionListRequest']['batchId'] ?? null) === '7654321');
        }
        $this->assertCount(0, Http::recorded(fn ($request) => isset($request['createTransactionRequest'])));
    }

    public function test_webmoney_missing_certificate_and_test_mode_never_offer_movement(): void
    {
        [, , , $adapter] = $this->fixture('WebMoney', ['purse' => 'Z123456789012', 'secret_key' => 'synthetic-secret', 'test_mode' => '1'], '1234567');
        $this->assertStringContainsString('WebMoney', $adapter::class);
        $this->assertFalse($adapter->capabilities()['refund']);
        $this->assertFalse($adapter->capabilities()['capture']);
        $this->assertNotEmpty($adapter->capabilities()['reason']);
        $this->assertNotEmpty($adapter->fingerprint());
        Http::assertNothingSent();
    }

    public function test_accepted_id_survives_readback_outage_and_recovers_without_second_write(): void
    {
        [$invoice, , $transaction, $adapter] = $this->fixture();
        $this->fakeStripe($invoice);
        $context = $adapter->prepare($invoice, $transaction, 'provider_refund', 'pi_synthetic', '25.00', 'USD');
        $this->stripeReadFailure = true;
        $operation = $this->operation($context);
        $result = $adapter->execute($operation);
        $this->assertSame('uncertain', $result->state);
        $this->assertSame('re_synthetic', $result->providerReference);
        $this->stripeReadFailure = false;
        $operation->provider_reference = $result->providerReference;
        $this->assertSame('succeeded', $adapter->reconcile($operation)->state);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    protected function persistOperation(PaymentOperation $operation): PaymentOperation
    {
        // Adapter fixtures bypass only the journal creation guard; service/journal authorization is covered separately.
        $operation->forceFill(['actor_id' => null, 'actor_snapshot' => [], 'reason' => 'Synthetic adapter test', 'effective_at' => now(),
            'request_fingerprint' => hash('sha256', $operation->request_key), 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('payment_operations')->insertGetId($operation->getAttributes());

        return PaymentOperation::findOrFail($id);
    }

    public function test_authorizenet_refund_binds_accepted_write_before_exact_readback(): void
    {
        $this->assertTrue(Schema::hasTable('gateway_operation_requests'));
        [$invoice, $gateway, $transaction, $adapter] = $this->fixture('AuthorizeNet', ['api_login_id' => 'synthetic-login', 'transaction_key' => 'synthetic-key', 'environment' => 'test', 'currency' => 'USD'], '1234567');
        $attempt = $this->nativeAttempt($invoice, $gateway, $transaction, '1234567', hash('sha256', 'synthetic-login:test:USD'));
        $written = false;
        Http::fake(['apitest.authorize.net/xml/v1/request.api' => function ($request) use ($attempt, &$written) {
            if (isset($request['createTransactionRequest'])) {
                $write = $request['createTransactionRequest'];
                $this->assertSame('refundTransaction', $write['transactionRequest']['transactionType']);
                $this->assertSame(['transactionType', 'amount', 'payment', 'refTransId', 'order', 'transactionSettings'], array_keys($write['transactionRequest']), 'Authorize.Net JSON is converted to its ordered XML schema.');
                $this->assertSame('1234567', $write['transactionRequest']['refTransId']);
                $this->assertSame('25.00', $write['transactionRequest']['amount']);
                $this->assertSame('1111', $write['transactionRequest']['payment']['creditCard']['cardNumber']);
                $this->assertLessThanOrEqual(20, strlen($write['refId']));
                $written = true;

                return Http::response(['refId' => $write['refId'], 'messages' => ['resultCode' => 'Ok'], 'transactionResponse' => ['responseCode' => '1', 'transId' => '7654321', 'refTransID' => '1234567']]);
            }
            if (isset($request['getTransactionDetailsRequest'])) {
                $child = $request['getTransactionDetailsRequest']['transId'] === '7654321';

                return Http::response(['messages' => ['resultCode' => 'Ok'], 'transaction' => ['transId' => $child ? '7654321' : '1234567', 'refTransId' => $child ? '1234567' : '',
                    'transactionType' => $child ? 'refundTransaction' : 'authCaptureTransaction', 'transactionStatus' => $child ? $this->authorizeRefundStatus : 'settledSuccessfully',
                    'order' => ['invoiceNumber' => $attempt->reference, 'description' => $child ? self::KEY : 'Original'], 'settleAmount' => $child ? '25.00' : '109.88', 'authAmount' => $child ? '25.00' : '109.88',
                    'submitTimeUTC' => now()->subDays(2)->toIso8601String(), 'payment' => ['creditCard' => ['cardNumber' => 'XXXX1111']]]]);
            }

            return Http::response(['messages' => ['resultCode' => 'Ok'], 'transactions' => [], 'batchList' => [], 'totalNumInResultSet' => 0]);
        }]);
        $operation = $this->persistOperation($this->operation($adapter->prepare($invoice, $transaction->fresh(), 'provider_refund', '1234567', '25.00', 'USD')));
        $result = $adapter->execute($operation);
        $this->assertTrue($written);
        $this->assertSame('succeeded', $result->state);
        $result->assertVerified($operation);
        $this->assertSame('succeeded', $adapter->reconcile($operation)->state);
        $this->assertCount(1, Http::recorded(fn ($request) => isset($request['createTransactionRequest'])));
        $this->assertSame(1, DB::table('gateway_operation_requests')->where('request_key', self::KEY)->count());
        $this->authorizeRefundStatus = 'generalError';
        $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
        $this->assertCount(1, Http::recorded(fn ($request) => isset($request['createTransactionRequest'])));
    }

    public function test_new_webmoney_secrets_are_encrypted_and_fully_redacted_from_audits(): void
    {
        [, $gateway] = $this->fixture('WebMoney', ['purse' => 'Z123456789012']);
        config(['audit.enabled' => true, 'audit.console' => true]);
        Setting::enableAuditing();
        foreach (['wm_certificate', 'wm_private_key', 'wm_key_passphrase'] as $name) {
            $secret = 'SYNTHETIC-PRIVATE-PREFIX-' . str_repeat('x', 150);
            $setting = $gateway->settings()->create(['key' => $name, 'value' => $secret, 'encrypted' => true]);
            $raw = DB::table('settings')->where('id', $setting->id)->value('value');
            $this->assertNotSame($secret, $raw);
            $this->assertSame($secret, $setting->fresh()->value);
            $setting->setAuditEvent('created');
            $audit = $setting->toAudit();
            $this->assertSame('[REDACTED]', $audit['new_values']['value']);
        }
    }

    #[DataProvider('webmoneyOutcomes')]
    public function test_configured_webmoney_x14_is_durable_and_never_replays_unknown_partial_write(bool $lost, bool $euro): void
    {
        $merchantPurse = $euro ? 'E123456789012' : 'Z123456789012';
        $payerPurse = $euro ? 'E999999999999' : 'Z999999999999';
        $providerTotal = $euro ? '87.90' : '109.88';
        $providerRefund = $euro ? '20.00' : '25.00';
        $this->webmoneyChildAmount = $providerRefund;
        $this->assertTrue(Schema::hasTable('gateway_operation_sequences'));
        $this->assertTrue(Schema::hasTable('gateway_operation_requests'));
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'Synthetic certificate'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $private);
        [$invoice, $gateway, $transaction, $adapter] = $this->fixture('WebMoney', ['purse' => $merchantPurse, 'secret' => 'synthetic-secret', 'currency' => $euro ? 'EUR' : 'USD', 'test_mode' => '0',
            'wm_wmid' => '123456789012', 'wm_certificate' => $pem, 'wm_private_key' => $private, 'wm_key_passphrase' => '', 'wm_sequence_floor' => '100', 'wm_exclusive_sequence' => '1'], '1234567');
        $attempt = $this->nativeAttempt($invoice, $gateway, $transaction, '1234567', hash('sha256', $merchantPurse . ':live'));
        if ($euro) {
            $quote = (new ConversionQuote)->create($attempt, ['source' => 'ECB', 'date' => now()->utc()->format('Y-m-d'), 'numerator' => '1', 'denominator' => '1.25']);
            $attempt->update(['provider_payload' => ['webmoney_conversion_quote' => $quote]]);
        }
        $this->assertSame($pem, $gateway->settings()->where('key', 'wm_certificate')->firstOrFail()->value);
        $this->assertSame($private, $gateway->settings()->where('key', 'wm_private_key')->firstOrFail()->value);
        $this->assertTrue($adapter->capabilities()['refund']);
        $connection = DB::connection();
        config(['database.connections.synthetic_wm_reader' => $connection->getConfig()]);
        $reader = DB::connection('synthetic_wm_reader')->getPdo();
        $connection->setReadPdo($reader);
        $this->assertNotSame($connection->getPdo(), $connection->getReadPdo());
        $writerId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false)->connection_id;
        $lock = 'paymenter-wm-' . substr(hash('sha256', $connection->getDatabaseName()), 0, 48);
        $numbers = [];
        $refundSubmitted = false;
        $secondRefund = false;
        $secondSubmitted = false;
        Http::fake(function ($request) use (&$numbers, &$refundSubmitted, &$secondRefund, &$secondSubmitted, $lost, $writerId, $lock, $merchantPurse, $payerPurse, $providerTotal, $providerRefund) {
            $respond = fn ($body) => Http::response(strtr($body, ['Z123456789012' => $merchantPurse, 'Z999999999999' => $payerPurse, '109.88' => $providerTotal, '25.00' => $providerRefund]));
            $xml = simplexml_load_string($request->body());
            if (str_contains($request->url(), 'XMLTransGet.asp')) {
                return $respond('<merchant.response><retval>0</retval><operation wmtransid="1234567"><amount>109.88</amount><pursefrom>Z999999999999</pursefrom><wmidfrom>999999999999</wmidfrom><hold_period>0</hold_period><hold_state>0</hold_state><capitallerflag>0</capitallerflag><paymer_number></paymer_number><sdp_type>0</sdp_type></operation></merchant.response>');
            }
            $this->assertSame($writerId, (int) DB::connection()->selectOne('SELECT IS_USED_LOCK(?) AS holder', [$lock], false)->holder);
            $this->assertSame(0, DB::transactionLevel());
            $number = (string) $xml->reqn;
            $numbers[] = (int) $number;
            if (str_contains($request->url(), 'XMLPursesCert.asp')) {
                return $respond('<w3s.response><reqn>' . $number . '</reqn><retval>0</retval><purses cnt="1"><purse id="1"><pursename>Z123456789012</pursename></purse></purses></w3s.response>');
            }
            if (str_contains($request->url(), 'XMLTransMoneybackCert.asp')) {
                $this->assertSame('1234567', (string) $xml->trans->inwmtranid);
                $this->assertSame($secondRefund ? '67.90' : $providerRefund, (string) $xml->trans->amount);
                $refundSubmitted = true;

                if ($secondRefund) {
                    $secondSubmitted = true;

                    return $respond('<w3s.response><reqn>' . $number . '</reqn><retval>0</retval><operation id="7654322"><inwmtranid>1234567</inwmtranid><pursesrc>Z123456789012</pursesrc><pursedest>Z999999999999</pursedest><amount>67.90</amount><comiss>0.00</comiss></operation></w3s.response>');
                }

                return $lost ? Http::failedConnection() : $respond('<w3s.response><reqn>' . $number . '</reqn><retval>0</retval><operation id="7654321"><inwmtranid>1234567</inwmtranid><pursesrc>Z123456789012</pursesrc><pursedest>Z999999999999</pursedest><amount>25.00</amount><comiss>0.00</comiss></operation></w3s.response>');
            }
            if ((string) $xml->getoperations->wmtranid === '7654322' && $secondSubmitted) {
                return $respond('<w3s.response><reqn>' . $number . '</reqn><retval>0</retval><operations cnt="1"><operation id="7654322"><pursesrc>Z123456789012</pursesrc><pursedest>Z999999999999</pursedest><amount>67.90</amount><opertype>0</opertype><desc>Moneyback transaction WMTranId: 1234567. (Synthetic original)</desc></operation></operations></w3s.response>');
            }
            if ((string) $xml->getoperations->wmtranid === '7654321') {
                return $respond('<w3s.response><reqn>' . $number . '</reqn><retval>0</retval><operations cnt="1"><operation id="7654321"><pursesrc>Z123456789012</pursesrc><pursedest>Z999999999999</pursedest><amount>' . $this->webmoneyChildAmount . '</amount><opertype>0</opertype><desc>Moneyback transaction WMTranId: 1234567. (Synthetic original)</desc></operation></operations></w3s.response>');
            }

            $body = '<w3s.response><reqn>' . $number . '</reqn><retval>0</retval><operations cnt="1"><operation id="1234567"><pursesrc>Z999999999999</pursesrc><pursedest>Z123456789012</pursedest><amount>109.88</amount><opertype>0</opertype><period>0</period><orderid>123456789012345</orderid><desc>Synthetic original</desc><datecrt>' . now()->subDays(1)->format('Ymd H:i:s') . '</datecrt></operation></operations></w3s.response>';
            if ((string) $xml->getoperations->wmtranid === '0' && $refundSubmitted) {
                $body = str_replace('cnt="1"', 'cnt="2"', $body);
                $body = str_replace('</operations>', '<operation id="7654321"><pursesrc>Z123456789012</pursesrc><pursedest>Z999999999999</pursedest><amount>' . $this->webmoneyChildAmount . '</amount><opertype>0</opertype><desc>Moneyback transaction WMTranId: 1234567. (Synthetic original)</desc></operation></operations>', $body);
            }

            if ((string) $xml->getoperations->wmtranid === '0' && $secondSubmitted) {
                $body = str_replace('cnt="2"', 'cnt="3"', $body);
                $body = str_replace('</operations>', '<operation id="7654322"><pursesrc>Z123456789012</pursesrc><pursedest>Z999999999999</pursedest><amount>67.90</amount><opertype>0</opertype><desc>Moneyback transaction WMTranId: 1234567. (Synthetic original)</desc></operation></operations>', $body);
            }

            return $respond($body);
        });
        try {
            $context = $adapter->prepare($invoice, $transaction->fresh(), 'provider_refund', '1234567', '25.00', 'USD');
        } catch (\RuntimeException $exception) {
            $this->fail('The configured purse must refund using its captured currency: ' . $exception->getMessage());
        }
        $this->assertSame('109.88', $context['original_amount']);
        if ($euro) {
            $this->assertSame('20.00', $context['provider_amount']);
            $this->assertSame('EUR', $context['provider_currency']);
        }
        $operation = $this->persistOperation($this->operation($context));
        $this->assertSame('25.00', $operation->amount);
        $this->assertSame('USD', $operation->currency_code);
        $result = $adapter->execute($operation);
        $this->assertSame($lost ? 'uncertain' : 'succeeded', $result->state);
        if (!$lost) {
            $result->assertVerified($operation);
            if ($euro) {
                DB::transaction(fn () => $operation->recordProviderResult($result, $invoice->user));
                $this->assertSame('succeeded', $adapter->reconcile($operation)->state);
                $remaining = $adapter->prepare($invoice, $transaction->fresh(), 'provider_refund', '1234567', '84.88', 'USD');
                $this->assertSame('25.00', $remaining['already_refunded']);
                $this->assertSame('67.90', $remaining['provider_amount']);
            }
            $this->webmoneyChildAmount = $euro ? '19.99' : '24.99';
            $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
            $this->webmoneyChildAmount = $providerRefund;
        }
        $this->assertSame($lost ? 'uncertain' : 'succeeded', $adapter->reconcile($operation)->state);
        $this->assertSame('uncertain', $adapter->execute($operation)->state);
        if ($euro && !$lost) {
            $second = $this->operation($remaining);
            $second->request_key = '00000000-0000-4000-8000-000000000002';
            $second = $this->persistOperation($second);
            $secondRefund = true;
            $secondResult = $adapter->execute($second);
            $this->assertSame('succeeded', $secondResult->state);
            DB::transaction(fn () => $second->recordProviderResult($secondResult, $invoice->user));
            $this->assertSame('succeeded', $adapter->reconcile($operation)->state, 'Later refunds must not change the original refund quote.');
            $this->assertSame('succeeded', $adapter->reconcile($second)->state);
            $this->assertSame('84.88', $second->amount);
            $this->assertSame('USD', $second->currency_code);
        }
        $this->assertCount($euro && !$lost ? 2 : 1, Http::recorded(fn ($request) => str_contains($request->url(), 'XMLTransMoneybackCert.asp')));
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers);
        $this->assertSame(count($numbers), count(array_unique($numbers)));
        $this->assertGreaterThan(100, min($numbers));
        $this->assertSame(1, (int) $connection->selectOne('SELECT IS_FREE_LOCK(?) AS released', [$lock], false)->released);
        $this->assertSame(1, DB::table('gateway_operation_requests')->where('request_key', self::KEY)->count());
        $connection->setReadPdo($connection->getPdo());
        DB::purge('synthetic_wm_reader');
    }

    public function test_klarna_capture_uses_real_native_checkout_attempt_and_frozen_lines(): void
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $role = Role::create(['name' => 'Synthetic capture administrator', 'permissions' => ['*']]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);
        $this->withSession($this->loginUser($user));
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '12.34', 'quantity' => 1]);
        $gateway = Gateway::create(['name' => 'Synthetic native capture', 'type' => 'gateway', 'extension' => 'Klarna', 'enabled' => true]);
        foreach (['merchant_id' => 'synthetic-merchant', 'secret' => 'synthetic-secret', 'environment' => 'test', 'region' => 'eu', 'currency' => 'USD', 'purchase_country' => 'US', 'locale' => 'en-US', 'collection_enabled' => '1', 'admin_payment_operations_enabled' => '1'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value, 'encrypted' => in_array($key, ['wm_certificate', 'wm_private_key', 'wm_key_passphrase'], true)]);
        }
        $adapter = (new GatewayOperations)->for($gateway);
        $this->assertTrue($adapter->capabilities()['capture']);
        $captured = $refunded = $duplicateCapture = false;
        $captureChanges = [];
        $refundKey = '13965b6d-c7b1-4c64-97f0-b3661f5cfc07';
        Http::fake(function ($request) use (&$captured, &$refunded, &$duplicateCapture, &$captureChanges, $refundKey) {
            if (str_ends_with($request->url(), '/payments/v1/sessions')) {
                return Http::response(['session_id' => 'synthetic-kp']);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/hpp/v1/sessions')) {
                return Http::response(['session_id' => 'synthetic-hpp', 'session_url' => 'https://api.playground.klarna.com/hpp/v1/sessions/synthetic-hpp',
                    'redirect_url' => 'https://pay.playground.klarna.com/eu/hpp/payments/synthetic-hpp', 'expires_at' => now()->addHour()->toIso8601String()]);
            }
            if (str_ends_with($request->url(), '/hpp/v1/sessions/synthetic-hpp')) {
                return Http::response(['session_id' => 'synthetic-hpp', 'status' => 'COMPLETED', 'order_id' => 'synthetic-order']);
            }
            $attempt = GatewayPaymentAttempt::sole();
            if (str_ends_with($request->url(), '/orders/synthetic-order')) {
                return Http::response(['order_id' => 'synthetic-order', 'merchant_reference1' => $attempt->reference, 'purchase_currency' => 'USD', 'purchase_country' => 'US', 'fraud_status' => 'ACCEPTED',
                    'order_amount' => 1234, 'original_order_amount' => 1234, 'captured_amount' => $captured ? 1234 : 0, 'refunded_amount' => $refunded ? 500 : 0, 'remaining_authorized_amount' => $captured ? 0 : 1234,
                    'status' => $captured ? 'CAPTURED' : 'AUTHORIZED', 'expires_at' => now()->addDays(1)->toIso8601String(), 'order_lines' => $attempt->provider_payload['order_allocation']['order_lines'],
                    'captures' => $captured ? ($duplicateCapture ? [['capture_id' => 'synthetic-capture', 'reference' => self::KEY], ['capture_id' => 'outside-capture', 'reference' => 'outside']] : [['capture_id' => 'synthetic-capture', 'reference' => self::KEY]]) : []]);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/captures')) {
                $this->assertSame($attempt->provider_payload['order_allocation']['order_lines'], $request['order_lines']);
                $this->assertSame(1234, $request['captured_amount']);
                $this->assertSame(self::KEY, $request['reference']);
                $captured = true;

                return Http::response(['capture_id' => 'synthetic-capture'], 201);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/orders/synthetic-order/refunds')) {
                $this->assertSame(500, $request['refunded_amount']);
                $this->assertSame($refundKey, $request['reference']);
                $this->assertArrayNotHasKey('order_lines', $request->data());
                $refunded = true;

                return Http::response(['refund_id' => 'synthetic-refund'], 201);
            }
            if (str_ends_with($request->url(), '/orders/synthetic-order/refunds/synthetic-refund')) {
                return Http::response(['refund_id' => 'synthetic-refund', 'reference' => $refundKey, 'refunded_amount' => 500, 'refunded_at' => '2026-10-01T12:05:00Z']);
            }

            return Http::response(array_replace(['capture_id' => 'synthetic-capture', 'reference' => self::KEY, 'captured_amount' => 1234, 'captured_at' => '2026-10-01T12:00:00Z'], $captureChanges));
        });
        $extension = (new Klarna($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        $extension->boot();
        Route::getRoutes()->refreshNameLookups();
        $extension->pay($invoice->fresh(), '12.34');
        $attempt = GatewayPaymentAttempt::sole();
        $context = $adapter->prepare($invoice->fresh(), null, 'provider_capture', $attempt->provider_reference, '12.34', 'USD');
        $this->assertSame($attempt->id, $context['attempt_id']);
        $operation = (new ProviderOperations)->capture($user, $invoice->fresh(), $gateway->fresh(), $attempt->provider_reference, 'Capture original quote', self::KEY);
        $this->assertSame('succeeded', $operation->state);
        $receipt = InvoiceTransaction::findOrFail($operation->result_transaction_id);
        $this->assertSame('manual_capture', $receipt->settlement_origin);
        $this->assertSame('gateway:' . $gateway->id . ':synthetic-capture', $receipt->transaction_id);
        $this->assertSame('paid', $attempt->fresh()->state);
        $this->assertSame('paid', $invoice->fresh()->status);
        $refund = $adapter->prepare($invoice->fresh(), $receipt, 'provider_refund', 'synthetic-capture', '5.00', 'USD');
        $this->assertSame('synthetic-capture', $refund['original_reference']);
        $this->assertSame('capture', $refund['provider_object_type']);
        $this->assertSame('synthetic-order', $refund['order']);
        $this->assertSame($attempt->id, $refund['attempt_id']);
        foreach ([['capture_id' => 'outside-capture'], ['reference' => $refundKey], ['captured_amount' => 1233]] as $change) {
            $captureChanges = $change;
            try {
                $adapter->prepare($invoice->fresh(), $receipt, 'provider_refund', 'synthetic-capture', '5.00', 'USD');
                $this->fail('Unrelated native capture child accepted');
            } catch (\RuntimeException) {
                $this->assertFalse($refunded);
            }
        }
        $captureChanges = [];
        $duplicateCapture = true;
        try {
            $adapter->prepare($invoice->fresh(), $receipt, 'provider_refund', 'synthetic-capture', '5.00', 'USD');
            $this->fail('Ambiguous provider captures accepted');
        } catch (\RuntimeException) {
            $this->assertFalse($refunded);
        }
        $duplicateCapture = false;
        $refundOperation = (new ProviderOperations)->refund($user, $receipt->fresh(), '5.00', false, 'Refund captured payment', $refundKey);
        $this->assertTrue($refunded, 'The synthetic refund write did not complete its request assertions.');
        $this->assertSame('synthetic-refund', $refundOperation->provider_reference, 'The accepted synthetic refund ID must be preserved.');
        $this->assertSame('succeeded', $refundOperation->state, $refundOperation->outcome_code);
        $this->assertSame('synthetic-capture', $refundOperation->payload['provider_context']['original_reference']);
        $this->assertSame('synthetic-order', $refundOperation->payload['provider_context']['order']);
        $this->assertSame('manual_capture', $receipt->fresh()->settlement_origin);
        $this->assertSame($operation->getAttributes(), $operation->fresh()->getAttributes());
        $copy = $operation->getAttributes();
        unset($copy['id']);
        $copy['request_key'] = '015eb378-a42b-4c78-86f4-1e35d7f75a7c';
        DB::table('payment_operations')->insert($copy);
        try {
            $adapter->prepare($invoice->fresh(), $receipt->fresh(), 'provider_refund', 'synthetic-capture', '1.00', 'USD');
            $this->fail('Ambiguous prior capture binding accepted');
        } catch (\RuntimeException) {
            $this->assertTrue($refunded);
        }
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/captures')));
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/refunds')));
    }

    public function test_authorizenet_full_void_is_distinct_and_partial_unsettled_refund_is_refused(): void
    {
        [$invoice, $gateway, $transaction, $adapter] = $this->fixture('AuthorizeNet', ['api_login_id' => 'synthetic-login', 'transaction_key' => 'synthetic-key', 'environment' => 'test', 'currency' => 'USD'], '1234567');
        $attempt = $this->nativeAttempt($invoice, $gateway, $transaction, '1234567', hash('sha256', 'synthetic-login:test:USD'));
        $voided = false;
        Http::fake(['apitest.authorize.net/xml/v1/request.api' => function ($request) use ($attempt, &$voided) {
            if (isset($request['createTransactionRequest'])) {
                $write = $request['createTransactionRequest'];
                $this->assertSame('voidTransaction', $write['transactionRequest']['transactionType']);
                $this->assertSame('1234567', $write['transactionRequest']['refTransId']);
                $voided = true;

                return Http::response(['refId' => $write['refId'], 'messages' => ['resultCode' => 'Ok'], 'transactionResponse' => ['responseCode' => '1', 'transId' => '1234567']]);
            }
            if (isset($request['getTransactionDetailsRequest'])) {
                return Http::response(['messages' => ['resultCode' => 'Ok'], 'transaction' => ['transId' => '1234567', 'transactionType' => 'authCaptureTransaction', 'transactionStatus' => $voided ? 'voided' : 'capturedPendingSettlement',
                    'order' => ['invoiceNumber' => $attempt->reference], 'authAmount' => '109.88', 'settleAmount' => '109.88', 'submitTimeUTC' => now()->subHour()->toIso8601String(), 'payment' => ['creditCard' => ['cardNumber' => 'XXXX1111']]]]);
            }

            return Http::response(['messages' => ['resultCode' => 'Ok'], 'transactions' => [], 'batchList' => [], 'totalNumInResultSet' => 0]);
        }]);
        try {
            $adapter->prepare($invoice, $transaction->fresh(), 'provider_refund', '1234567', '25.00', 'USD');
            $this->fail('Partial unsettled refund was accepted');
        } catch (\RuntimeException) {
            $this->assertFalse($voided);
        }
        $operation = $this->persistOperation($this->operation($adapter->prepare($invoice, $transaction->fresh(), 'provider_refund', '1234567', '109.88', 'USD')));
        $result = $adapter->execute($operation);
        $this->assertSame('succeeded', $result->state);
        $this->assertSame('provider_void', $result->outcomeCode);
        $result->assertVerified($operation);
        $this->assertSame('succeeded', $adapter->reconcile($operation)->state);
        $this->assertCount(1, Http::recorded(fn ($request) => isset($request['createTransactionRequest'])));
        DB::table('gateway_operation_requests')->where('request_key', self::KEY)->update(['accepted_proof' => null]);
        $this->assertSame('uncertain', $adapter->reconcile($operation)->state);
    }

    public function test_provider_sequence_rollback_refuses_durable_numbers_without_losing_tables(): void
    {
        $scope = str_repeat('a', 64);
        DB::table('gateway_operation_sequences')->insert(['scope' => $scope, 'last_reqn' => 123]);
        $migration = require database_path('migrations/2026_10_01_000002_create_gateway_operation_proofs.php');
        try {
            $migration->down();
            $this->fail('Durable request sequence was discarded');
        } catch (\RuntimeException) {
            $this->assertSame(123, (int) DB::table('gateway_operation_sequences')->where('scope', $scope)->value('last_reqn'));
            $this->assertTrue(Schema::hasTable('gateway_operation_requests'));
        }
    }

    public static function webmoneyOutcomes(): array
    {
        return [[true, false], [false, false], [true, true], [false, true]];
    }

    public function test_credential_rotation_can_reconcile_but_merchant_drift_and_outside_refund_block_writes(): void
    {
        [$invoice, $gateway, $transaction, $adapter] = $this->fixture();
        $this->fakeStripe($invoice);
        $context = $adapter->prepare($invoice, $transaction, 'provider_refund', 'pi_synthetic', '25.00', 'USD');
        $operation = $this->operation($context, 're_synthetic');
        $gateway->settings()->where('key', 'stripe_secret_key')->firstOrFail()->update(['value' => 'rk_test_synthetic_rotated']);
        $rotated = (new GatewayOperations)->for($gateway->fresh());
        $this->assertNotSame($adapter->fingerprint(), $rotated->fingerprint());
        $this->assertSame('succeeded', $rotated->reconcile($operation)->state);
        $this->stripeMerchant = 'acct_other';
        $this->assertSame('uncertain', $rotated->reconcile($operation)->state);
        $this->stripeMerchant = 'acct_synthetic';
        $this->stripeRefunded = 2500;
        $this->assertSame('uncertain', $rotated->execute($this->operation($context))->state);
        $this->assertCount(0, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->stripeAuthorized = false;
        try {
            $rotated->prepare($invoice, $transaction, 'provider_refund', 'pi_synthetic', '25.00', 'USD');
            $this->fail('Missing API authorization was accepted');
        } catch (\RuntimeException) {
            $this->assertCount(0, Http::recorded(fn ($request) => $request->method() === 'POST'));
        }
    }
}
