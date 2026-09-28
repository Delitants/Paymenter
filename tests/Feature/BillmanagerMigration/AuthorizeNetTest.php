<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Helpers\ExtensionHelper;
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
use Paymenter\Extensions\Gateways\AuthorizeNet\AuthorizeNet;
use Tests\TestCase;

class AuthorizeNetTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $u = User::factory()->create();
        $this->actingAs($u);
        $invoice = Invoice::factory()->create(['user_id' => $u->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '12.34', 'quantity' => 1]);
        Gateway::create(['name' => 'Different merchant', 'extension' => 'AuthorizeNet', 'type' => 'gateway', 'enabled' => false]);
        $g = Gateway::create(['name' => 'Synthetic Authorize.Net', 'extension' => 'AuthorizeNet', 'type' => 'gateway', 'enabled' => true]);
        $config = ['api_login_id' => 'synthetic-login', 'transaction_key' => 'synthetic-transaction-key', 'signature_key' => str_repeat('AB', 64), 'environment' => 'test', 'currency' => 'USD', 'collection_enabled' => '1'];
        foreach ($config as $k => $v) {
            $g->settings()->create(['key' => $k, 'value' => $v]);
        }
        $e = (new AuthorizeNet($config))->bindRecord($g);
        $e->boot();

        return [$invoice->fresh(), $g, $e];
    }

    private function sendNotification(Gateway $g, string $id = '123456', ?string $key = null, string $event = 'net.authorize.payment.authcapture.created')
    {
        $raw = json_encode(['notificationId' => 'synthetic-event', 'eventType' => $event, 'payload' => ['entityName' => 'transaction', 'id' => $id, 'authAmount' => 12.34]]);

        return $this->call('POST', '/extensions/authorizenet/' . $g->id . '/notify', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ANET_SIGNATURE' => 'sha512=' . strtoupper(hash_hmac('sha512', $raw, $key ?? str_repeat('AB', 64)))], $raw);
    }

    private function fakeApi(?array $transactionChanges = null): void
    {
        Http::fake(function ($request) use ($transactionChanges) {
            $d = $request->data();
            if (isset($d['authenticateTestRequest'])) {
                return Http::response(['messages' => ['resultCode' => 'Ok']]);
            }
            if (isset($d['getHostedPaymentPageRequest'])) {
                return Http::response(['token' => 'synthetic-hosted-token', 'messages' => ['resultCode' => 'Ok']]);
            }
            if (isset($d['getTransactionDetailsRequest'])) {
                $a = GatewayPaymentAttempt::sole();

                return Http::response(['transaction' => array_replace(['transId' => '123456', 'transactionType' => 'authCaptureTransaction', 'transactionStatus' => 'capturedPendingSettlement', 'authAmount' => '12.34', 'settleAmount' => '12.34', 'order' => ['invoiceNumber' => $a->reference]], $transactionChanges ?? []), 'messages' => ['resultCode' => 'Ok']]);
            }
            throw new \RuntimeException('Unexpected synthetic API request');
        });
    }

    public function test_native_hosted_checkout_caches_token_and_verified_callback_settles_the_correct_gateway_once(): void
    {
        [$invoice,$g,$e] = $this->fixture();
        $this->fakeApi();
        $this->assertTrue($e->testConfig());
        $view = ExtensionHelper::pay($g, $invoice);
        $this->assertStringContainsString('test.authorize.net', $view->render());
        ExtensionHelper::pay($g, $invoice);
        $this->assertSame(1, GatewayPaymentAttempt::count());
        $this->assertCount(1, Http::recorded(fn ($r) => isset($r->data()['getHostedPaymentPageRequest'])));
        $this->sendNotification($g)->assertOk();
        $this->sendNotification($g)->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame($g->id, $invoice->transactions()->sole()->gateway_id);
    }

    public function test_signature_failure_does_not_call_provider_or_credit_invoice(): void
    {
        [$invoice,$g,$e] = $this->fixture();
        $this->fakeApi();
        $e->pay($invoice, '12.34');
        $this->sendNotification($g, key: 'different-signature-key')->assertStatus(422);
        Http::assertNotSent(fn ($r) => isset($r->data()['getTransactionDetailsRequest']));
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_provider_declined_amount_or_reference_mismatch_cannot_be_overridden_by_notification(): void
    {
        [$invoice,$g,$e] = $this->fixture();
        $this->fakeApi(['transactionStatus' => 'declined']);
        $e->pay($invoice, '12.34');
        $this->sendNotification($g)->assertStatus(422);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_unknown_checkout_result_is_not_automatically_retried(): void
    {
        [$invoice,,$e] = $this->fixture();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('synthetic-secret-in-error');
        });
        for ($i = 0; $i < 2; $i++) {
            try {
                $e->pay($invoice, '12.34');
                $this->fail('Unknown form creation accepted');
            } catch (\RuntimeException $ex) {
                $this->assertStringNotContainsString('synthetic-secret-in-error', $ex->getMessage());
            }
        }
        $this->assertSame(1, GatewayPaymentAttempt::count());
        $this->assertSame('initializing', GatewayPaymentAttempt::sole()->state);
        $this->assertSame(1, $calls);
    }

    public function test_held_callback_has_no_financial_effect(): void
    {
        [$invoice,$g,$e] = $this->fixture();
        $this->fakeApi();
        $e->pay($invoice, '12.34');
        DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $invoice->id, 'reason' => 'Synthetic hold']);
        $this->sendNotification($g)->assertStatus(409);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_checkout_token_is_encrypted_and_expiry_never_silently_creates_another_attempt(): void
    {
        [$invoice,,$e] = $this->fixture();
        $this->fakeApi();
        $e->pay($invoice, '12.34');
        $stored = DB::table('gateway_payment_attempts')->value('provider_payload');
        $this->assertStringNotContainsString('synthetic-hosted-token', $stored);
        $this->travel(16)->minutes();
        try {
            $e->pay($invoice, '12.34');
            $this->fail('Expired token reused');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('expired', $ex->getMessage());
        }
        $this->assertSame(1, GatewayPaymentAttempt::count());
        $this->assertCount(1, Http::recorded(fn ($r) => isset($r->data()['getHostedPaymentPageRequest'])));
    }

    public function test_wrong_remote_amount_reference_currency_and_old_event_do_not_settle(): void
    {
        [$invoice,$g,$e] = $this->fixture();
        $this->fakeApi();
        $e->pay($invoice, '12.34');
        foreach ([['settleAmount' => '12.35'], ['order' => ['invoiceNumber' => (string) $invoice->id]], ['currencyCode' => 'EUR'], ['transId' => '999'], ['transactionType' => 'refundTransaction']] as $change) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->fakeApi($change);
            $this->sendNotification($g)->assertStatus(422);
        }
        $this->sendNotification($g, event: 'net.authorize.payment.authorization.created')->assertOk();
        $this->assertSame(0, $invoice->transactions()->count());
        $g->settings()->where('key', 'collection_enabled')->update(['value' => '0']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->sendNotification($g)->assertStatus(409);
        Http::assertNothingSent();
    }
}
