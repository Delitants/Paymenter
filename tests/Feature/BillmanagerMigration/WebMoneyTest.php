<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Helpers\ExtensionHelper;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHeldException;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\WebMoney\WebMoney;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesTaxedGatewayInvoice;
use Tests\TestCase;

class WebMoneyTest extends TestCase
{
    use UsesCommittedDatabase, UsesTaxedGatewayInvoice;

    private function fixture(array $settings = [], string $currency = 'USD'): array
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Currency::firstOrCreate(['code' => $currency], ['name' => $currency, 'prefix' => '', 'suffix' => '', 'format' => '1,000.00']);
        $user = User::factory()->create();
        $p = $this->createProduct();
        DB::table('prices')->where('plan_id', $p->plan->id)->update(['currency_code' => $currency]);
        $s = Service::factory()->create(['user_id' => $user->id, 'product_id' => $p->product->id, 'plan_id' => $p->plan->id, 'price' => '10.00', 'status' => 'active', 'expires_at' => '2026-12-01', 'currency_code' => $currency]);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending', 'currency_code' => $currency]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'reference_type' => Service::class, 'reference_id' => $s->id, 'price' => '10.00', 'quantity' => 1]);
        $g = Gateway::create(['name' => 'Synthetic WebMoney', 'extension' => 'WebMoney', 'type' => 'gateway', 'enabled' => true]);
        foreach (array_replace(['purse' => 'Z123456789012', 'secret' => 'synthetic-merchant-secret', 'currency' => 'USD', 'test_mode' => '0', 'collection_enabled' => '1'], $settings) as $key => $value) {
            $g->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->actingAs($user);
        $this->withSession($this->loginUser($user));
        $extension = new WebMoney($g->settings->pluck('value', 'key')->all());
        $extension->bindRecord($g);
        $extension->boot();
        Route::getRoutes()->refreshNameLookups();

        return [$invoice->fresh(), $s, $g, $extension];
    }

    private function notification(GatewayPaymentAttempt $attempt, array $changes = []): array
    {
        $p = array_replace(['LMI_PAYEE_PURSE' => 'Z123456789012', 'LMI_PAYMENT_AMOUNT' => '10.00', 'LMI_PAYMENT_NO' => $attempt->reference, 'LMI_MODE' => '0', 'LMI_SYS_INVS_NO' => '12345', 'LMI_SYS_TRANS_NO' => '67890', 'LMI_SYS_TRANS_DATE' => '20261001 00:00:00', 'LMI_PAYER_PURSE' => 'Z234567890123', 'LMI_PAYER_WM' => '123456789012'], $changes);
        $text = $p['LMI_PAYEE_PURSE'] . $p['LMI_PAYMENT_AMOUNT'] . $p['LMI_PAYMENT_NO'] . $p['LMI_MODE'] . $p['LMI_SYS_INVS_NO'] . $p['LMI_SYS_TRANS_NO'] . $p['LMI_SYS_TRANS_DATE'] . 'synthetic-merchant-secret' . $p['LMI_PAYER_PURSE'] . $p['LMI_PAYER_WM'];
        $p['LMI_HASH'] = strtoupper(hash('sha256', $text));

        return $p;
    }

    private function euroRates(): void
    {
        Cache::flush();
        Http::fake(['www.ecb.europa.eu/*' => Http::response('<Envelope><Cube><Cube time="2026-10-01"><Cube currency="USD" rate="1.25"/></Cube></Cube></Envelope>')]);
    }

    public function test_empty_merchant_availability_probe_does_not_settle_an_invoice(): void
    {
        foreach ([['Z123456789012', 'USD'], ['E123456789012', 'EUR']] as [$purse, $currency]) {
            [$invoice, $service, $gateway] = $this->fixture(['purse' => $purse, 'currency' => $currency]);
            $expiry = $service->expires_at;
            $this->post('/extensions/webmoney/' . $gateway->id . '/notify')->assertOk()->assertSee('YES');
            $this->assertSame('pending', $invoice->fresh()->status);
            $this->assertSame(0, $invoice->transactions()->count());
            $this->assertEquals($expiry, $service->fresh()->expires_at);
            $this->assertSame(0, GatewayPaymentAttempt::count());
        }
        Http::assertNothingSent();
    }

    public function test_incomplete_notifications_cannot_be_treated_as_availability_probes(): void
    {
        [$invoice, , $gateway] = $this->fixture();
        $path = '/extensions/webmoney/' . $gateway->id . '/notify';
        foreach ([['LMI_PREREQUEST' => '1'], ['LMI_MODE' => '1'], ['LMI_HASH' => 'invalid'], ['unknown' => 'field']] as $body) {
            $this->post($path, $body)->assertStatus(422);
        }
        $this->post($path, ['attachment' => UploadedFile::fake()->create('notification.txt', 1)])->assertStatus(422);
        $this->post($path . '?LMI_MODE=1')->assertStatus(422);
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'invalid')->assertStatus(422);
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=test', 'CONTENT_LENGTH' => '9'], '')->assertStatus(422);
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=test', 'HTTP_TRANSFER_ENCODING' => 'chunked'], '')->assertStatus(422);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(0, GatewayPaymentAttempt::count());
    }

    public function test_wme_checkout_collects_euro_and_settles_the_original_usd_once(): void
    {
        [$invoice, $service, $gateway] = $this->fixture(['purse' => 'E123456789012', 'currency' => 'EUR', 'test_mode' => '1']);
        $this->euroRates();
        try {
            $html = ExtensionHelper::pay($gateway, $invoice)->render();
        } catch (\RuntimeException $exception) {
            $this->fail('WME must quote a USD invoice in EUR: ' . $exception->getMessage());
        }
        $this->assertStringContainsString('10.00 USD', $html);
        $this->assertStringContainsString('8.00 EUR', $html);
        $this->assertStringNotContainsString('action="https://merchant.wmtransfer.com', $html);
        ExtensionHelper::pay($gateway, $invoice);
        $attempt = GatewayPaymentAttempt::sole();
        $this->assertSame('USD', $attempt->currency_code);
        $quote = $attempt->provider_payload['webmoney_conversion_quote'];
        $body = $this->notification($attempt, ['LMI_PAYEE_PURSE' => 'E123456789012', 'LMI_PAYMENT_AMOUNT' => '8.00', 'LMI_MODE' => '1', 'LMI_PAYER_PURSE' => 'E234567890123']);
        $path = '/extensions/webmoney/' . $gateway->id . '/notify';
        $this->post($path, $body)->assertStatus(422);
        $checkout = '/extensions/webmoney/' . $gateway->id . '/checkout/' . $invoice->id . '/' . $attempt->reference;
        $this->post($checkout, ['quote_fingerprint' => $quote['fingerprint']])->assertOk()->assertSee('name="LMI_PAYMENT_AMOUNT" value="8.00"', false);
        $this->post($path, $body + ['LMI_PREREQUEST' => '1'])->assertOk()->assertSee('YES');
        $this->assertSame(0, $invoice->transactions()->count());
        foreach ([['LMI_MODE' => '0'], ['LMI_PAYEE_PURSE' => 'Z123456789012'], ['LMI_PAYMENT_AMOUNT' => '10.00']] as $change) {
            $this->post($path, $this->notification($attempt, array_replace($body, $change)))->assertStatus(422);
        }
        $this->post($path, $body)->assertOk();
        $expiry = $service->fresh()->expires_at;
        $this->post($path, $body)->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('10.00', $invoice->transactions()->sole()->amount);
        $this->assertSame($gateway->id, $invoice->transactions()->sole()->gateway_id);
        $this->assertEquals($expiry, $service->fresh()->expires_at);
        $this->assertSame('USD', $invoice->fresh()->currency_code);
        Http::assertSentCount(1);
    }

    public function test_wme_preserves_tax_and_untaxed_usd_fee_before_conversion(): void
    {
        [$invoice, , $gateway] = $this->fixture(['purse' => 'E123456789012', 'currency' => 'EUR', 'test_mode' => '0']);
        $this->taxAndFee($invoice, $gateway);
        $this->euroRates();
        ExtensionHelper::pay($gateway, $invoice->fresh());
        $attempt = GatewayPaymentAttempt::sole();
        $this->assertSame('109.88', $attempt->amount);
        $quote = $attempt->provider_payload['webmoney_conversion_quote'];
        $this->assertSame('87.90', $quote['provider_amount']);
        $checkout = '/extensions/webmoney/' . $gateway->id . '/checkout/' . $invoice->id . '/' . $attempt->reference;
        $this->post($checkout, ['quote_fingerprint' => $quote['fingerprint']])->assertOk();
        $body = $this->notification($attempt, ['LMI_PAYEE_PURSE' => 'E123456789012', 'LMI_PAYMENT_AMOUNT' => '87.90', 'LMI_PAYER_PURSE' => 'E234567890123']);
        $this->post('/extensions/webmoney/' . $gateway->id . '/notify', $body)->assertOk();
        $this->assertSame('109.88', $invoice->transactions()->sole()->amount);
        $this->assertSame('7.13', $invoice->items()->where('kind', 'product')->sole()->tax_amount);
        $this->assertSame('0.00', $invoice->items()->where('kind', 'gateway_fee')->sole()->tax_amount);
    }

    public function test_wme_quote_confirmation_rejects_expiry_wrong_owner_and_changed_invoice(): void
    {
        [$invoice, , $gateway] = $this->fixture(['purse' => 'E123456789012', 'currency' => 'EUR', 'test_mode' => '1']);
        $this->euroRates();
        ExtensionHelper::pay($gateway, $invoice);
        $attempt = GatewayPaymentAttempt::sole();
        $quote = $attempt->provider_payload['webmoney_conversion_quote'];
        $checkout = '/extensions/webmoney/' . $gateway->id . '/checkout/' . $invoice->id . '/' . $attempt->reference;
        $this->post($checkout, ['quote_fingerprint' => str_repeat('0', 64)])->assertStatus(422);
        $this->post($checkout, ['quote_fingerprint' => $quote['fingerprint'], 'amount' => '1.00'])->assertStatus(422);
        $owner = $invoice->user;
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->withSession($this->loginUser($other));
        $this->post($checkout, ['quote_fingerprint' => $quote['fingerprint']])->assertNotFound();
        $this->actingAs($owner);
        $this->withSession($this->loginUser($owner));
        $this->travel(31)->minutes();
        $this->post($checkout, ['quote_fingerprint' => $quote['fingerprint']])->assertStatus(422);
        $this->travelTo(Carbon::createFromTimestamp($quote['created_at']));
        $invoice->items()->update(['price' => '11.00']);
        $this->post($checkout, ['quote_fingerprint' => $quote['fingerprint']])->assertStatus(422);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_wme_requires_usd_native_invoices_and_matching_purse_currency(): void
    {
        foreach ([['EUR', 'EUR'], ['USD', 'USD']] as [$settlement, $native]) {
            [$invoice, , , $extension] = $this->fixture(['purse' => 'E123456789012', 'currency' => $settlement, 'test_mode' => '1'], $native);
            try {
                $extension->pay($invoice, '10.00');
                $this->fail('Unsupported purse/native currency pair accepted');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('currency', $exception->getMessage());
                $this->assertSame(0, GatewayPaymentAttempt::count());
            }
        }
        Http::assertNothingSent();
    }

    public function test_tax_and_untaxed_fee_lines_match_native_capture(): void
    {
        [$invoice, $service, $gateway] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $view = ExtensionHelper::pay($gateway, $invoice->fresh());
        $this->assertStringContainsString('value="109.88"', $view->render());
        $attempt = GatewayPaymentAttempt::sole();
        $this->assertSame('109.88', $attempt->amount);
        $body = $this->notification($attempt, ['LMI_PAYMENT_AMOUNT' => '109.88']);
        $this->post('/extensions/webmoney/' . $gateway->id . '/notify', $body)->assertOk();
        $expiry = $service->fresh()->expires_at;
        $this->post('/extensions/webmoney/' . $gateway->id . '/notify', $body)->assertOk();
        $this->assertSame('109.88', $invoice->transactions()->sole()->amount);
        $this->assertEquals($expiry, $service->fresh()->expires_at);
        $this->assertSame('10.00', (string) $service->fresh()->getRawOriginal('price'));
        Http::assertNothingSent();
    }

    public function test_bad_tax_allocation_cannot_approve_or_settle(): void
    {
        [$invoice,, $gateway, $extension] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $extension->pay($invoice->fresh(), '107.13');
        $attempt = GatewayPaymentAttempt::sole();
        $this->post('/extensions/webmoney/' . $gateway->id . '/notify', $this->notification($attempt, ['LMI_PAYMENT_AMOUNT' => '109.87']))->assertStatus(422);
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->where('kind', 'product')->update(['tax_amount' => '7.12']);
        $this->post('/extensions/webmoney/' . $gateway->id . '/notify', $this->notification($attempt, ['LMI_PAYMENT_AMOUNT' => '109.88']))->assertStatus(422);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_native_checkout_reuses_attempt_and_signed_callback_renews_exactly_once(): void
    {
        [$invoice,$service,$g] = $this->fixture();
        $view = ExtensionHelper::pay($g, $invoice);
        $this->assertStringContainsString('merchant.wmtransfer.com', $view->render());
        ExtensionHelper::pay($g, $invoice);
        $this->assertSame(1, GatewayPaymentAttempt::count());
        $attempt = GatewayPaymentAttempt::sole();
        $this->assertSame('10.00', $attempt->amount);
        $this->assertNotSame((string) $invoice->id, $attempt->reference);
        $this->post('/extensions/webmoney/' . $g->id . '/notify', $this->notification($attempt))->assertOk();
        $expiry = $service->fresh()->expires_at->toDateString();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotSame('2026-12-01', $expiry);
        $this->post('/extensions/webmoney/' . $g->id . '/notify', $this->notification($attempt))->assertOk();
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame($expiry, $service->fresh()->expires_at->toDateString());
        $this->assertSame($g->id, $invoice->transactions()->sole()->gateway_id);
        Http::assertNothingSent();
    }

    public function test_bad_signature_wrong_purse_amount_mode_and_legacy_reference_never_pay(): void
    {
        [$invoice,,$g,$e] = $this->fixture();
        $e->pay($invoice, '10.00');
        $a = GatewayPaymentAttempt::sole();
        $cases = [['LMI_PAYEE_PURSE' => 'Z999999999999'], ['LMI_PAYMENT_AMOUNT' => '10.01'], ['LMI_MODE' => '1'], ['LMI_PAYMENT_NO' => (string) $invoice->id], ['LMI_HOLD' => '1']];
        foreach ($cases as $change) {
            $this->post('/extensions/webmoney/' . $g->id . '/notify', $this->notification($a, $change))->assertStatus(422);
        }
        $bad = $this->notification($a);
        $bad['LMI_HASH'] = str_repeat('0', 64);
        $this->post('/extensions/webmoney/' . $g->id . '/notify', $bad)->assertStatus(422);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_direct_pay_and_callbacks_honor_invoice_or_service_holds(): void
    {
        [$invoice,$service,$g,$e] = $this->fixture();
        $e->pay($invoice, '10.00');
        $a = GatewayPaymentAttempt::sole();
        DB::table('billmanager_holds')->insert(['model_type' => Service::class, 'model_id' => $service->id, 'reason' => 'Synthetic hold']);
        try {
            $e->pay($invoice, '10.00');
            $this->fail('Held service payment started');
        } catch (MigrationHeldException $ex) {
            $this->assertNotEmpty($ex->getMessage());
        }
        $this->post('/extensions/webmoney/' . $g->id . '/notify', $this->notification($a))->assertStatus(409);
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    public function test_collection_disabled_or_invoice_changed_refuses_payment(): void
    {
        [$invoice,,$g,$e] = $this->fixture();
        $e->pay($invoice, '10.00');
        $a = GatewayPaymentAttempt::sole();
        $g->settings()->where('key', 'collection_enabled')->update(['value' => '0']);
        $this->post('/extensions/webmoney/' . $g->id . '/notify', $this->notification($a))->assertStatus(409);
        $this->post('/extensions/webmoney/' . $g->id . '/notify')->assertStatus(409);
        $g->settings()->where('key', 'collection_enabled')->update(['value' => '1']);
        $invoice->items()->update(['price' => '11.00']);
        $this->post('/extensions/webmoney/' . $g->id . '/notify', $this->notification($a))->assertStatus(422);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_wrong_currency_and_unauthorized_user_cannot_start_attempt(): void
    {
        [$invoice,,,$e] = $this->fixture();
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '', 'suffix' => '', 'format' => '1,000.00']);
        $invoice->update(['currency_code' => 'EUR']);
        try {
            $e->pay($invoice, '10.00');
            $this->fail('Currency mismatch accepted');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('currency', $ex->getMessage());
        }
        $invoice->update(['currency_code' => 'USD']);
        $this->actingAs(User::factory()->create());
        $this->expectException(AuthorizationException::class);
        $e->pay($invoice, '10.00');
    }

    public function test_prerequest_does_not_mark_paid_and_reused_transaction_cannot_pay_another_invoice(): void
    {
        [$invoice,,$g,$e] = $this->fixture();
        $e->pay($invoice, '10.00');
        $a = GatewayPaymentAttempt::sole();
        $p = $this->notification($a);
        $p['LMI_PREREQUEST'] = '1';
        $this->post('/extensions/webmoney/' . $g->id . '/notify', $p)->assertOk()->assertSee('YES');
        $this->assertSame(0, $invoice->transactions()->count());
        $this->post('/extensions/webmoney/' . $g->id . '/notify', $this->notification($a))->assertOk();
        $second = Invoice::factory()->create(['user_id' => $invoice->user_id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $second->id, 'price' => '10.00', 'quantity' => 1]);
        $e->pay($second, '10.00');
        $b = GatewayPaymentAttempt::where('invoice_id', $second->id)->sole();
        $this->post('/extensions/webmoney/' . $g->id . '/notify', $this->notification($b))->assertStatus(422);
        $this->assertSame(0, $second->transactions()->count());
    }

    public function test_stale_invoice_currency_cannot_start_payment(): void
    {
        [$invoice,,,$e] = $this->fixture();
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '', 'suffix' => '', 'format' => '1,000.00']);
        Invoice::whereKey($invoice->id)->update(['currency_code' => 'EUR']);
        try {
            $e->pay($invoice, '10.00');
            $this->fail('Stale currency accepted');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('currency', $ex->getMessage());
        }
        $this->assertSame(0, GatewayPaymentAttempt::count());
    }
}
