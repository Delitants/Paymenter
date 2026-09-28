<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHeldException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Gateways\Wave\Wave;
use Tests\TestCase;

class WaveTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $u = User::factory()->create(['email' => 'wave-fixture@example.test']);
        $this->actingAs($u);
        $i = Invoice::factory()->create(['user_id' => $u->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $i->id, 'price' => '12.34', 'quantity' => 1]);
        $g = Gateway::create(['name' => 'Synthetic Wave', 'extension' => 'Wave', 'type' => 'gateway', 'enabled' => true]);
        foreach (['access_token' => 'synthetic-access-token', 'business_id' => 'synthetic-business', 'webhook_business_id' => 'synthetic-webhook-business', 'product_id' => 'synthetic-product', 'webhook_secret' => 'synthetic-webhook-secret', 'currency' => 'USD', 'collection_enabled' => '1'] as $k => $v) {
            $g->settings()->create(['key' => $k, 'value' => $v]);
        }
        $e = (new Wave($g->settings->pluck('value', 'key')->all()))->bindRecord($g);
        $e->boot();

        return [$i->fresh(), $g, $e];
    }

    private function providerInvoice(array $changes = []): array
    {
        return array_replace(['id' => 'synthetic-wave-invoice', 'internalId' => '12345', 'business' => ['id' => 'synthetic-business'], 'customer' => ['id' => 'synthetic-customer'], 'invoiceNumber' => 'PAY-' . GatewayPaymentAttempt::sole()->reference, 'status' => 'PAID', 'currency' => ['code' => 'USD'], 'total' => ['value' => '12.34'], 'amountPaid' => ['value' => '12.34'], 'amountDue' => ['value' => '0.00'], 'viewUrl' => 'https://next.waveapps.com/a/invoices/synthetic'], $changes);
    }

    private function fakeApi(array $changes = [], bool $newCustomer = false): void
    {
        Http::fake(function ($r) use ($changes, $newCustomer) {
            $query = $r['query'];
            if (str_contains($query, 'WaveReadiness')) {
                return Http::response(['data' => ['business' => ['id' => 'synthetic-business']]]);
            }
            if (str_contains($query, 'WaveCustomers')) {
                return Http::response(['data' => ['business' => ['id' => 'synthetic-business', 'customers' => ['pageInfo' => ['totalCount' => $newCustomer ? 0 : 1], 'edges' => $newCustomer ? [] : [['node' => ['id' => 'synthetic-customer', 'email' => 'wave-fixture@example.test', 'isArchived' => false]]]]]]]);
            }
            if (str_contains($query, 'WaveCreateCustomer')) {
                return Http::response(['data' => ['customerCreate' => ['didSucceed' => true, 'customer' => ['id' => 'synthetic-customer', 'email' => 'wave-fixture@example.test']]]]);
            }
            if (str_contains($query, 'WaveCreateInvoice')) {
                return Http::response(['data' => ['invoiceCreate' => ['didSucceed' => true, 'invoice' => $this->providerInvoice(['status' => 'DRAFT'])]]]);
            }
            if (str_contains($query, 'WaveApproveInvoice')) {
                return Http::response(['data' => ['invoiceApprove' => ['didSucceed' => true, 'invoice' => $this->providerInvoice(['status' => 'SAVED'])]]]);
            }
            if (str_contains($query, 'WaveInvoice')) {
                return Http::response(['data' => ['business' => ['id' => 'synthetic-business', 'invoice' => $this->providerInvoice($changes)]]]);
            }
            throw new \RuntimeException('Unexpected GraphQL request');
        });
    }

    private function notify(Gateway $g, array $changes = [], ?string $key = null, int $offset = 0)
    {
        $event = array_replace_recursive(['event_type' => 'invoice.paid', 'event_id' => 'synthetic-event', 'business_id' => 'synthetic-webhook-business', 'data' => ['invoice_id' => '12345', 'currency_code' => 'USD', 'amount_paid' => '12.34', 'remaining_balance' => '0.00']], $changes);
        $raw = json_encode($event);
        $time = (string) (now()->timestamp + $offset);
        $signature = hash_hmac('sha256', $time . '.' . $raw, $key ?? 'synthetic-webhook-secret');

        return $this->call('POST', '/extensions/wave/' . $g->id . '/notify', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WAVE_TIMESTAMP' => $time, 'HTTP_X_WAVE_SIGNATURE' => 't=' . $time . ',v1=' . $signature], $raw);
    }

    public function test_native_checkout_reuses_customer_invoice_and_signed_paid_event_settles_once(): void
    {
        [$i,$g,$e] = $this->fixture();
        $this->fakeApi();
        $this->assertTrue($e->testConfig());
        $this->assertStringContainsString('next.waveapps.com', $e->pay($i, '12.34')->render());
        $e->pay($i, '12.34');
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r['query'], 'WaveCreateInvoice')));
        $this->notify($g)->assertOk();
        $this->notify($g)->assertOk();
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame(1, $i->transactions()->count());
        Http::assertNotSent(fn ($r) => str_contains($r['query'], 'invoiceSend'));
    }

    public function test_bad_signature_expired_event_wrong_business_and_legacy_invoice_do_not_pay(): void
    {
        [$i,$g,$e] = $this->fixture();
        $this->fakeApi();
        $e->pay($i, '12.34');
        $this->notify($g, key: 'wrong')->assertStatus(422);
        $this->notify($g, offset: -301)->assertStatus(422);
        $this->notify($g, ['business_id' => 'other'])->assertStatus(422);
        $this->notify($g, ['data' => ['invoice_id' => 'legacy-invoice']])->assertStatus(422);
        Http::assertNotSent(fn ($r) => str_contains($r['query'], 'WaveInvoice('));
        $this->assertSame(0, $i->transactions()->count());
    }

    public function test_provider_business_customer_invoice_amount_and_currency_mismatches_never_pay(): void
    {
        [$i,$g,$e] = $this->fixture();
        $this->fakeApi();
        $e->pay($i, '12.34');
        foreach ([['business' => ['id' => 'wrong']], ['customer' => ['id' => 'wrong']], ['invoiceNumber' => 'old'], ['id' => 'other'], ['currency' => ['code' => 'EUR']], ['total' => ['value' => '12.35']], ['amountDue' => ['value' => '1.00']], ['amountPaid' => ['value' => '11.34']], ['status' => 'SAVED']] as $change) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->fakeApi($change);
            $this->notify($g)->assertStatus(422);
        }$this->assertSame(0, $i->transactions()->count());
    }

    public function test_polling_uses_same_verified_settlement_and_holds_block_before_provider_calls(): void
    {
        [$i,,$e] = $this->fixture();
        $this->fakeApi();
        $e->pay($i, '12.34');
        $a = GatewayPaymentAttempt::sole();
        DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $i->id, 'reason' => 'Synthetic hold']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        try {
            $e->syncPayment($a->reference);
            $this->fail('Held reconciliation accepted');
        } catch (MigrationHeldException) {
            $this->assertSame(0, $i->transactions()->count());
        }Http::assertNothingSent();
    }

    public function test_new_customer_creation_is_bound_to_invoice_owner(): void
    {
        [$i,,$e] = $this->fixture();
        $this->fakeApi(newCustomer: true);
        $e->pay($i, '12.34');
        Http::assertSent(fn ($r) => str_contains($r['query'], 'WaveCreateCustomer') && $r['variables']['input']['email'] === 'wave-fixture@example.test');
        $this->assertSame('synthetic-customer', GatewayPaymentAttempt::sole()->provider_payload['customer_id']);
    }

    public function test_unknown_create_response_remains_claimed_without_retry(): void
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
                $this->fail('Unknown checkout accepted');
            } catch (\RuntimeException $ex) {
                $this->assertStringNotContainsString('synthetic-secret',$ex->getMessage());
            }
        }
        $this->assertSame(1,$calls);
        $this->assertSame('initializing',GatewayPaymentAttempt::sole()->state);
    }
}
