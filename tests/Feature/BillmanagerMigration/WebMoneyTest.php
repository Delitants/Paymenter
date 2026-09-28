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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Gateways\WebMoney\WebMoney;
use Tests\TestCase;

class WebMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $p = $this->createProduct();
        $s = Service::factory()->create(['user_id' => $user->id, 'product_id' => $p->product->id, 'plan_id' => $p->plan->id, 'price' => '10.00', 'status' => 'active', 'expires_at' => '2026-12-01', 'currency_code' => 'USD']);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'reference_type' => Service::class, 'reference_id' => $s->id, 'price' => '10.00', 'quantity' => 1]);
        $g = Gateway::create(['name' => 'Synthetic WebMoney', 'extension' => 'WebMoney', 'type' => 'gateway', 'enabled' => true]);
        foreach (['purse' => 'Z123456789012', 'secret' => 'synthetic-merchant-secret', 'currency' => 'USD', 'test_mode' => '0', 'collection_enabled' => '1'] as $key => $value) {
            $g->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->actingAs($user);
        $extension = new WebMoney($g->settings->pluck('value', 'key')->all());
        $extension->bindRecord($g);
        $extension->boot();

        return [$invoice->fresh(), $s, $g, $extension];
    }

    private function notification(GatewayPaymentAttempt $attempt, array $changes = []): array
    {
        $p = array_replace(['LMI_PAYEE_PURSE' => 'Z123456789012', 'LMI_PAYMENT_AMOUNT' => '10.00', 'LMI_PAYMENT_NO' => $attempt->reference, 'LMI_MODE' => '0', 'LMI_SYS_INVS_NO' => '12345', 'LMI_SYS_TRANS_NO' => '67890', 'LMI_SYS_TRANS_DATE' => '20261001 00:00:00', 'LMI_PAYER_PURSE' => 'Z234567890123', 'LMI_PAYER_WM' => '123456789012'], $changes);
        $text = $p['LMI_PAYEE_PURSE'] . $p['LMI_PAYMENT_AMOUNT'] . $p['LMI_PAYMENT_NO'] . $p['LMI_MODE'] . $p['LMI_SYS_INVS_NO'] . $p['LMI_SYS_TRANS_NO'] . $p['LMI_SYS_TRANS_DATE'] . 'synthetic-merchant-secret' . $p['LMI_PAYER_PURSE'] . $p['LMI_PAYER_WM'];
        $p['LMI_HASH'] = strtoupper(hash('sha256', $text));

        return $p;
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
            $this->assertStringContainsString('currency',$ex->getMessage());
        }
        $this->assertSame(0,GatewayPaymentAttempt::count());
    }
}
