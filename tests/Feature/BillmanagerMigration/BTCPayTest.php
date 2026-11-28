<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Gateways\CollectionDisabledException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Gateways\BTCPay\BTCPay;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesTaxedGatewayInvoice;
use Tests\TestCase;

class BTCPayTest extends TestCase
{
    use UsesCommittedDatabase, UsesTaxedGatewayInvoice;

    private const ORIGIN = 'https://btcpay.example.test';

    private const INVOICE_ID = 'FixtureCryptoInvoice1';

    private const SECRET = 'synthetic-btcpay-webhook-secret';

    private function fixture(array $settings = [], string $amount = '12.34'): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->actingAs($user);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => $amount, 'quantity' => 1]);
        $gateway = Gateway::create(['name' => 'Bitcoin', 'extension' => 'BTCPay', 'type' => 'gateway', 'enabled' => true]);
        $settings += ['server_url' => self::ORIGIN, 'store_id' => 'FixtureStore', 'api_key' => str_repeat('ab', 20),
            'webhook_secret' => self::SECRET, 'collection_enabled' => '1'];
        foreach ($settings as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        (new BTCPay($settings))->bindRecord($gateway)->boot();

        return [$invoice->fresh(), $gateway];
    }

    private function providerInvoice(array $changes = []): array
    {
        $attempt = GatewayPaymentAttempt::latest('id')->firstOrFail();

        return $changes + ['id' => self::INVOICE_ID, 'storeId' => 'FixtureStore', 'type' => 'Standard',
            'metadata' => ['orderId' => 'paymenter-' . $attempt->reference], 'currency' => $attempt->currency_code,
            'amount' => $attempt->amount, 'paidAmount' => '0.00', 'status' => 'New', 'additionalStatus' => 'None',
            'checkout' => ['speedPolicy' => 'MediumSpeed', 'paymentTolerance' => 0],
            'checkoutLink' => self::ORIGIN . '/i/' . self::INVOICE_ID];
    }

    private function start(Invoice $invoice, Gateway $gateway): GatewayPaymentAttempt
    {
        $this->fakeProvider(fn () => Http::response($this->providerInvoice(), 201));
        $this->assertSame(self::ORIGIN . '/i/' . self::INVOICE_ID, ExtensionHelper::pay($gateway, $invoice));

        return GatewayPaymentAttempt::latest('id')->firstOrFail();
    }

    private function fakeProvider(callable $response): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($response) {
            $this->assertStringStartsWith(self::ORIGIN . '/api/v1/', $request->url());

            return $response($request);
        });
    }

    private function webhook(Gateway $gateway, array $changes = [], ?string $signature = null)
    {
        $event = $changes + ['type' => 'InvoiceSettled', 'storeId' => 'FixtureStore', 'invoiceId' => self::INVOICE_ID];
        $raw = json_encode($event, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/extensions/btcpay/' . $gateway->id . '/notify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_BTCPAY_SIG' => $signature ?? 'sha256=' . hash_hmac('sha256', $raw, self::SECRET),
        ], $raw);
    }

    public function test_configuration_checks_store_identity_read_only(): void
    {
        [, $gateway] = $this->fixture();
        $merchant = 'AnotherStore';
        $this->fakeProvider(function (Request $request) use (&$merchant) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(self::ORIGIN . '/api/v1/stores/FixtureStore', $request->url());

            return Http::response(['id' => $merchant]);
        });
        $extension = (new BTCPay($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        $this->assertSame('BTCPay store identity did not match.', $extension->testConfig());
        $merchant = 'FixtureStore';
        $this->assertTrue($extension->testConfig());
    }

    public function test_checkout_keeps_shared_tax_and_untaxed_gateway_fee_and_reuses_remote_invoice(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $this->fakeProvider(function (Request $request) use ($invoice) {
            if ($request->method() === 'POST') {
                $this->assertSame(self::ORIGIN . '/api/v1/stores/FixtureStore/invoices', $request->url());
                $this->assertTrue($request->hasHeader('Authorization', 'token ' . str_repeat('ab', 20)));
                $this->assertSame('109.88', $request['amount']);
                $this->assertSame('USD', $request['currency']);
                $this->assertSame(['orderId' => 'paymenter-' . GatewayPaymentAttempt::sole()->reference], $request['metadata']);
                $this->assertSame(0, $request['checkout']['paymentTolerance']);
                $this->assertSame(route('invoices.show', $invoice), $request['checkout']['redirectURL']);

                return Http::response($this->providerInvoice(), 201);
            }

            return Http::response($this->providerInvoice());
        });
        $link = self::ORIGIN . '/i/' . self::INVOICE_ID;
        $this->assertSame($link, ExtensionHelper::pay($gateway, $invoice->fresh()));
        $this->assertSame($link, ExtensionHelper::pay($gateway, $invoice->fresh()));
        $this->assertSame('0.00', $invoice->items()->where('kind', 'gateway_fee')->sole()->tax_amount);
        $this->assertSame(self::INVOICE_ID, GatewayPaymentAttempt::sole()->provider_reference);
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_minimum_is_enforced_before_creating_an_attempt(): void
    {
        [$invoice, $gateway] = $this->fixture(amount: '9.99');
        $this->assertSame([], ExtensionHelper::getCheckoutGateways('9.99', 'USD', 'invoice'));
        $this->assertSame([$gateway->id], collect(ExtensionHelper::getCheckoutGateways('10.00', 'USD', 'invoice'))->pluck('id')->all());
        Http::fake();
        try {
            ExtensionHelper::pay($gateway, $invoice);
            $this->fail('BTCPay accepted a below-minimum charge.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('minimum', $error->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('gateway_payment_attempts', 0);
    }

    public function test_disabled_collection_refuses_checkout_and_callback_before_provider_access(): void
    {
        [$invoice, $gateway] = $this->fixture(['collection_enabled' => '0']);
        Http::fake();
        $this->webhook($gateway)->assertConflict();
        try {
            ExtensionHelper::pay($gateway, $invoice);
            $this->fail('A held gateway accepted payment.');
        } catch (CollectionDisabledException) {
            $this->assertDatabaseCount('gateway_payment_attempts', 0);
        }
        Http::assertNothingSent();
    }

    public function test_checkout_recovers_lost_create_response_without_posting_again(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->fakeProvider(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response([], 502);
            }
            if (str_ends_with($request->url(), '/invoices/' . self::INVOICE_ID)) {
                return Http::response($this->providerInvoice());
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $this->assertSame('paymenter-' . GatewayPaymentAttempt::sole()->reference, $query['orderId'] ?? null);
            $this->assertSame('true', $query['includeArchived'] ?? null);

            return Http::response([$this->providerInvoice()]);
        });
        try {
            ExtensionHelper::pay($gateway, $invoice);
            $this->fail('BTCPay returned a link without a verified invoice.');
        } catch (RuntimeException) {
            $this->assertSame('initializing', GatewayPaymentAttempt::sole()->state);
        }
        $this->assertSame(self::ORIGIN . '/i/' . self::INVOICE_ID, ExtensionHelper::pay($gateway, $invoice->fresh()));
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
    }

    public function test_provider_checkout_must_match_the_merchant_and_bound_invoice(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->fakeProvider(fn () => Http::response($this->providerInvoice(['checkoutLink' => 'https://elsewhere.example.test/i/' . self::INVOICE_ID]), 201));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checkout address');
        ExtensionHelper::pay($gateway, $invoice);
    }

    public function test_webhook_checks_signature_provider_status_and_exact_amount_and_posts_once(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $attempt = $this->start($invoice, $gateway);
        $status = 'Processing';
        $received = '12.34';
        $paymentStatus = 'Processing';
        $this->fakeProvider(function (Request $request) use (&$status, &$received, &$paymentStatus) {
            if (str_contains($request->url(), '/payment-methods')) {
                return Http::response([['due' => '0.00000000', 'payments' => [['status' => $paymentStatus]]]]);
            }

            return Http::response($this->providerInvoice(['status' => $status, 'paidAmount' => $received]));
        });
        $this->webhook($gateway, signature: 'sha256=' . str_repeat('0', 64))->assertForbidden();
        $this->webhook($gateway, ['storeId' => 'OtherStore'])->assertForbidden();
        Http::assertNothingSent();
        $this->webhook($gateway)->assertNoContent();
        $this->assertSame('pending', $invoice->fresh()->status);
        $status = 'Settled';
        $received = '12.33';
        $this->webhook($gateway)->assertNoContent();
        $this->assertSame(0, $invoice->transactions()->count());
        $received = '12.34';
        $this->webhook($gateway)->assertNoContent();
        $this->assertSame(0, $invoice->transactions()->count());
        $paymentStatus = 'Settled';
        $this->webhook($gateway)->assertNoContent();
        $this->webhook($gateway)->assertNoContent();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('paid', $attempt->fresh()->state);
        $payment = $invoice->transactions()->sole();
        $this->assertSame('12.34', $payment->amount);
        $this->assertSame('Settled', $payment->settlement_label);
    }

    public function test_provider_marked_settlement_is_visible_as_manual_in_paymenter(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->start($invoice, $gateway);
        $this->fakeProvider(fn () => Http::response($this->providerInvoice(['status' => 'Settled', 'additionalStatus' => 'Marked'])));
        $this->webhook($gateway)->assertNoContent();
        $this->webhook($gateway)->assertNoContent();
        $payment = $invoice->transactions()->sole();
        $this->assertSame('provider_marked', $payment->settlement_origin);
        $this->assertSame('settled', $payment->settlement_state);
        $this->assertStringContainsString('Manually settled', $payment->settlement_label);
    }

    public function test_scheduled_reconciliation_recovers_a_missed_confirmation(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->start($invoice, $gateway);
        $this->fakeProvider(function (Request $request) {
            if (str_contains($request->url(), '/payment-methods')) {
                return Http::response([['due' => '0.00', 'payments' => [['status' => 'Settled']]]]);
            }

            return Http::response($this->providerInvoice(['status' => 'Settled', 'paidAmount' => '12.34']));
        });
        $this->artisan('btcpay:sync-payments')->assertSuccessful();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
    }

    public function test_an_expired_invoice_without_payments_can_be_replaced_once(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $original = $this->start($invoice, $gateway);
        $late = $this->providerInvoice(['status' => 'Settled', 'additionalStatus' => 'PaidLate', 'paidAmount' => '12.34']);
        $this->fakeProvider(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response($this->providerInvoice(['id' => 'ReplacementInvoice', 'checkoutLink' => self::ORIGIN . '/i/ReplacementInvoice']), 201);
            }
            if (str_contains($request->url(), '/payment-methods')) {
                return Http::response([['due' => '0.0001', 'payments' => []]]);
            }

            return Http::response($this->providerInvoice(['status' => 'Expired']));
        });
        $this->webhook($gateway, ['type' => 'InvoiceExpired'])->assertNoContent();
        $this->assertSame('expired', $original->fresh()->state);
        $this->assertSame(self::ORIGIN . '/i/ReplacementInvoice', ExtensionHelper::pay($gateway, $invoice->fresh()));
        $this->assertDatabaseCount('gateway_payment_attempts', 2);
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
        $this->fakeProvider(fn () => Http::response($late));
        $this->webhook($gateway, ['type' => 'InvoiceReceivedPayment'])->assertNoContent();
        $this->assertSame('expired', $original->fresh()->state);
        $this->assertSame(0, $invoice->transactions()->count());
    }
}
