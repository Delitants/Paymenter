<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Gateways\Klarna\Klarna;
use Paymenter\Extensions\Gateways\Klarna\OrderLines;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesTaxedGatewayInvoice;
use Tests\TestCase;

class KlarnaTest extends TestCase
{
    use UsesCommittedDatabase, UsesTaxedGatewayInvoice;

    private bool $taxed = false;

    private function fixture(): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $u = User::factory()->create();
        $this->actingAs($u);
        $i = Invoice::factory()->create(['user_id' => $u->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $i->id, 'price' => '12.34', 'quantity' => 1]);
        $g = Gateway::create(['name' => 'Synthetic Klarna', 'extension' => 'Klarna', 'type' => 'gateway', 'enabled' => true]);
        foreach (['merchant_id' => 'synthetic-merchant', 'secret' => 'synthetic-secret', 'environment' => 'test', 'region' => 'eu', 'currency' => 'USD', 'purchase_country' => 'US', 'locale' => 'en-US', 'collection_enabled' => '1'] as $k => $v) {
            $g->settings()->create(['key' => $k, 'value' => $v]);
        }
        $e = (new Klarna($g->settings->pluck('value', 'key')->all()))->bindRecord($g);
        $e->boot();

        return [$i->fresh(), $g, $e];
    }

    private function fakeApi(array $changes = [], string $status = 'COMPLETED'): void
    {
        Http::fake(function ($r) use ($changes, $status) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/payments/v1/sessions')) {
                return Http::response(['session_id' => 'synthetic-kp'], 200);
            }
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/hpp/v1/sessions')) {
                return Http::response(['session_id' => 'synthetic-hpp', 'session_url' => 'https://api.playground.klarna.com/hpp/v1/sessions/synthetic-hpp', 'redirect_url' => 'https://pay.playground.klarna.com/eu/hpp/payments/synthetic-hpp', 'expires_at' => now()->addHour()->toIso8601String()], 201);
            }
            if (str_ends_with($r->url(), '/hpp/v1/sessions/synthetic-hpp')) {
                return Http::response(['session_id' => 'synthetic-hpp', 'status' => $status, 'order_id' => 'synthetic-order']);
            }
            if (str_ends_with($r->url(), '/ordermanagement/v1/orders/synthetic-order')) {
                return Http::response(array_replace(['order_id' => 'synthetic-order', 'status' => 'CAPTURED', 'fraud_status' => 'ACCEPTED',
                    'purchase_country' => 'US', 'purchase_currency' => 'USD', 'order_amount' => $this->taxed ? 10988 : 1234,
                    'order_tax_amount' => $this->taxed ? 713 : 0, 'order_lines' => $this->providerLines(),
                    'captured_amount' => $this->taxed ? 10988 : 1234, 'refunded_amount' => 0, 'merchant_reference1' => GatewayPaymentAttempt::sole()->reference], $changes));
            }
            throw new \RuntimeException('Unexpected request');
        });
    }

    private function providerLines(): array
    {
        $invoice = GatewayPaymentAttempt::sole()->invoice;
        $id = $invoice->items()->where('kind', 'product')->firstOrFail()->id;
        $lines = [['type' => 'digital', 'reference' => 'paymenter-' . $id, 'quantity' => 1,
            'unit_price' => $this->taxed ? 10000 : 1234, 'total_amount' => $this->taxed ? 10000 : 1234, 'total_tax_amount' => 0]];
        if ($this->taxed) {
            $fee = $invoice->items()->where('kind', 'gateway_fee')->sole()->id;
            $lines[] = ['type' => 'surcharge', 'reference' => 'paymenter-' . $fee, 'quantity' => 1, 'unit_price' => 275, 'total_amount' => 275, 'total_tax_amount' => 0];
            $lines[] = ['type' => 'sales_tax', 'reference' => 'paymenter-tax', 'name' => 'Sales Tax', 'quantity' => 1, 'unit_price' => 713, 'total_amount' => 713, 'total_tax_amount' => 0];
        }

        return $lines;
    }

    public function test_tax_and_untaxed_fee_lines_match_native_capture(): void
    {
        [$invoice, $gateway, $extension] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $this->taxed = true;
        $this->fakeApi();
        $extension->pay($invoice->fresh(), '107.13');
        $request = Http::recorded(fn ($r) => str_ends_with($r->url(), '/payments/v1/sessions'))->sole()[0];
        $this->assertSame(10988, $request['order_amount']);
        $this->assertSame(713, $request['order_tax_amount']);
        $this->assertSame(10988, array_sum(array_column($request['order_lines'], 'total_amount')));
        $this->assertSame([10000, 275, 713], array_column($request['order_lines'], 'total_amount'));
        foreach ($request['order_lines'] as $line) {
            $this->assertArrayNotHasKey('tax_rate', $line);
        }
        $this->assertSame('US', GatewayPaymentAttempt::sole()->provider_payload['purchase_country']);
        $gateway->settings()->where('key', 'purchase_country')->first()->update(['value' => 'DE']);
        (new Klarna($gateway->fresh()->settings->pluck('value', 'key')->all()))->bindRecord($gateway)->pay($invoice->fresh(), '109.88');
        $this->assertCount(2, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->notify($gateway)->assertOk();
        $this->notify($gateway)->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('109.88', $invoice->transactions()->sole()->amount);
    }

    public function test_bad_tax_allocation_cannot_approve_or_settle(): void
    {
        [$invoice, $gateway, $extension] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $this->taxed = true;
        $this->fakeApi();
        $extension->pay($invoice->fresh(), '107.13');
        $lines = $this->providerLines();
        $badFee = $lines;
        $badFee[1]['total_amount'] = 276;
        $badTax = $lines;
        $badTax[2]['total_amount'] = 712;
        $badQuantity = $lines;
        $badQuantity[0]['quantity'] = 2;
        foreach ([['order_tax_amount' => 712], ['order_lines' => $badFee], ['order_lines' => $badTax], ['order_lines' => $badQuantity], ['order_lines' => array_merge($lines, [$lines[0]])], ['purchase_country' => 'DE']] as $change) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->fakeApi($change);
            $this->notify($gateway)->assertStatus(422);
        }
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_global_tax_preserves_fractional_native_rate_and_exact_line_tax(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $attempt = (new PaymentAttempts)->begin($gateway, $invoice->fresh(), hash('sha256', 'synthetic merchant'), 'USD');
        $body = (new OrderLines)->build($attempt, 'DE');
        $this->assertSame(10988, $body['order_amount']);
        $this->assertSame(713, $body['order_tax_amount']);
        $this->assertSame(713, $body['order_lines'][0]['tax_rate']);
        $this->assertSame(713, $body['order_lines'][0]['total_tax_amount']);
        $this->assertSame(0, $body['order_lines'][1]['tax_rate']);
        $this->assertSame('7.1250', $attempt->pricing_payload['tax_context']['rate']);
    }

    public function test_global_small_unit_tax_is_refused_before_a_provider_session(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $invoice->items()->first()->update(['price' => '0.11', 'quantity' => 3, 'tax_amount' => '0.03']);
        $gateway->settings()->where('key', 'purchase_country')->first()->update(['value' => 'DE']);
        $extension = (new Klarna($gateway->fresh()->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        try {
            $extension->pay($invoice->fresh(), '0.33');
            $this->fail('Unrepresentable native tax was submitted');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('tax allocation', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_legacy_initialized_attempt_retains_original_callback_contract(): void
    {
        [$invoice, $gateway, $extension] = $this->fixture();
        $this->fakeApi();
        $extension->pay($invoice, '12.34');
        $attempt = GatewayPaymentAttempt::sole();
        $payload = $attempt->provider_payload;
        unset($payload['purchase_country'], $payload['locale'], $payload['order_allocation']);
        $attempt->update(['pricing_payload' => null, 'pricing_fingerprint' => null, 'provider_payload' => $payload]);
        $extension->pay($invoice, '12.34');
        $this->notify($gateway)->assertOk();
        $this->notify($gateway)->assertOk();
        $this->assertSame('12.34', $invoice->transactions()->sole()->amount);
        $this->assertSame(0, $invoice->items()->where('kind', 'gateway_fee')->count());
    }

    public function test_partial_credit_allocation_remains_unavailable_before_provider_calls(): void
    {
        [$invoice, $gateway, $extension] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $invoice->transactions()->create(['amount' => '10.00', 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => true]);
        try {
            $extension->pay($invoice->fresh(), '97.13');
            $this->fail('Unaccepted partial-credit allocation submitted');
        } catch (\RuntimeException) {
            $this->assertSame(0, GatewayPaymentAttempt::count());
        }
        Http::assertNothingSent();
    }

    private function notify(Gateway $g, ?string $token = null)
    {
        $a = GatewayPaymentAttempt::sole();

        return $this->postJson('/extensions/klarna/' . $g->id . '/notify/' . $a->reference . '?token=' . ($token ?? $a->provider_payload['callback_token']), ['session' => ['session_id' => 'synthetic-hpp', 'status' => 'COMPLETED', 'order_id' => 'untrusted-order']]);
    }

    public function test_hosted_checkout_and_authenticated_order_readback_settle_once(): void
    {
        [$i,$g,$e] = $this->fixture();
        $this->fakeApi();
        $view = $e->pay($i, '12.34');
        $this->assertStringContainsString('pay.playground.klarna.com', $view->render());
        $e->pay($i, '12.34');
        $this->assertCount(2, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->notify($g)->assertOk();
        $this->notify($g)->assertOk();
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame(1, $i->transactions()->count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payments/v1/sessions') && $r['order_amount'] === 1234 && $r['merchant_reference1'] === GatewayPaymentAttempt::sole()->reference);
    }

    public function test_wrong_callback_token_does_not_query_provider(): void
    {
        [$i,$g,$e] = $this->fixture();
        $this->fakeApi();
        $e->pay($i, '12.34');
        $this->notify($g, 'wrong')->assertStatus(422);
        Http::assertNotSent(fn ($r) => $r->method() === 'GET');
        $this->assertSame(0, $i->transactions()->count());
    }

    public function test_order_identity_currency_amount_fraud_and_refund_mismatches_never_pay(): void
    {
        [$i,$g,$e] = $this->fixture();
        $this->fakeApi();
        $e->pay($i, '12.34');
        foreach ([['order_id' => 'different'], ['merchant_reference1' => (string) $i->id], ['purchase_currency' => 'EUR'], ['captured_amount' => 1233], ['order_amount' => 1235], ['fraud_status' => 'PENDING'], ['refunded_amount' => 1234], ['status' => 'AUTHORIZED']] as $change) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->fakeApi($change);
            $this->notify($g)->assertStatus(422);
        }
        $this->assertSame(0, $i->transactions()->count());
    }

    public function test_pending_provider_state_and_holds_never_pay(): void
    {
        [$i,$g,$e] = $this->fixture();
        $this->fakeApi(status: 'IN_PROGRESS');
        $e->pay($i, '12.34');
        $this->notify($g)->assertOk();
        $this->assertSame(0, $i->transactions()->count());
        DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $i->id, 'reason' => 'Synthetic hold']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->notify($g)->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_uncertain_session_creation_is_not_retried(): void
    {
        [$i,,$e] = $this->fixture();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('synthetic-secret');
        });
        for ($n = 0; $n < 2; $n++) {
            try {
                $e->pay($i, '12.34');
                $this->fail('Unknown checkout allowed');
            } catch (\RuntimeException $ex) {
                $this->assertStringNotContainsString('synthetic-secret', $ex->getMessage());
            }
        }
        $this->assertSame(1, $calls);
        $this->assertSame('initializing', GatewayPaymentAttempt::sole()->state);
    }
}
