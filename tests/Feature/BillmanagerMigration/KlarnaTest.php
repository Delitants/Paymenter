<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Gateways\Klarna\Klarna;
use Tests\TestCase;

class KlarnaTest extends TestCase
{
    use RefreshDatabase;

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
                return Http::response(array_replace(['order_id' => 'synthetic-order', 'status' => 'CAPTURED', 'fraud_status' => 'ACCEPTED', 'purchase_currency' => 'USD', 'order_amount' => 1234, 'captured_amount' => 1234, 'refunded_amount' => 0, 'merchant_reference1' => GatewayPaymentAttempt::sole()->reference], $changes));
            }
            throw new \RuntimeException('Unexpected request');
        });
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
        $this->assertSame(1,$calls);
        $this->assertSame('initializing',GatewayPaymentAttempt::sole()->state);
    }
}
