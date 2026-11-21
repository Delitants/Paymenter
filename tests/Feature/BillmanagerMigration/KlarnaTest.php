<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Admin\Actions\PaymentActions;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentOperation;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\InvoicePricing;
use App\Services\Gateways\Operations\ProviderOperations;
use App\Services\Gateways\PaymentAttempts;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Paymenter\Extensions\Gateways\Klarna\Klarna;
use Paymenter\Extensions\Gateways\Klarna\OrderLines;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesTaxedGatewayInvoice;
use Tests\TestCase;

class KlarnaTest extends TestCase
{
    use UsesCommittedDatabase, UsesTaxedGatewayInvoice;

    private bool $taxed = false;

    private bool $omitAggregateTax = false;

    private bool $localReadback = false;

    private array $localRefunds = [];

    private bool $refundReadFailure = false;

    private bool $localAuthorization = false;

    private ?string $localCaptureKey = null;

    private string $providerCountry = 'US';

    private function fixture(): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $u = User::factory()->create();
        $this->actingAs($u);
        $this->withSession($this->loginUser($u));
        $i = Invoice::factory()->create(['user_id' => $u->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $i->id, 'price' => '12.34', 'quantity' => 1]);
        $g = Gateway::create(['name' => 'Synthetic Klarna', 'extension' => 'Klarna', 'type' => 'gateway', 'enabled' => true]);
        foreach (['merchant_id' => 'synthetic-merchant', 'secret' => 'synthetic-secret', 'environment' => 'test', 'region' => 'eu', 'currency' => 'USD', 'purchase_country' => 'US', 'locale' => 'en-US', 'collection_enabled' => '1'] as $k => $v) {
            $g->settings()->create(['key' => $k, 'value' => $v]);
        }
        $e = (new Klarna($g->settings->pluck('value', 'key')->all()))->bindRecord($g);
        $e->boot();
        Route::getRoutes()->refreshNameLookups();
        View::share('errors', new ViewErrorBag);

        return [$i->fresh(), $g, $e];
    }

    public function test_local_currency_selection_prepares_a_quote_before_any_klarna_session(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $gateway->settings()->create(['key' => 'local_currency_enabled', 'value' => '1']);
        $gateway->settings()->create(['key' => 'enabled_purchase_countries', 'value' => 'US,DE,GB']);
        $this->fakeApi();
        $extension = (new Klarna($gateway->fresh()->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        $html = $extension->pay($invoice, '12.34')->render();
        $this->assertStringContainsString('name="purchase_country"', $html);
        Http::assertNothingSent();
    }

    private function localCheckout(Invoice $invoice, Gateway $gateway): Klarna
    {
        Cache::flush();
        foreach (['local_currency_enabled' => '1', 'enabled_purchase_countries' => 'US,DE,GB'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $extension = (new Klarna($gateway->fresh()->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
        $extension->pay($invoice, '12.34');

        return $extension;
    }

    public function test_local_quote_confirmation_charges_eur_and_settles_usd_once(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->fakeApi();
        $this->localCheckout($invoice, $gateway);
        $url = $this->checkoutUrl($invoice, $gateway);
        $this->post($url, ['purchase_country' => 'DE'])->assertOk()->assertSee('11.22 EUR');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $attempt = GatewayPaymentAttempt::sole();
        $fingerprint = $attempt->provider_payload['conversion_quote']['fingerprint'];
        $this->assertSame('12.34', $attempt->amount);
        $this->post($url, ['purchase_country' => 'DE', 'quote_fingerprint' => $fingerprint])->assertRedirect();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payments/v1/sessions') && $r['purchase_currency'] === 'EUR' && $r['order_amount'] === 1122);
        $this->localReadback = true;
        $this->notify($gateway)->assertOk();
        $this->notify($gateway)->assertOk();
        $this->assertSame('12.34', $invoice->transactions()->sole()->amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertCount(2, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_local_gbp_quote_is_fixed_and_expired_or_altered_confirmation_cannot_initialize(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->fakeApi();
        $this->localCheckout($invoice, $gateway);
        $url = $this->checkoutUrl($invoice, $gateway);
        $this->post($url, ['purchase_country' => 'GB'])->assertOk()->assertSee('9.54 GBP');
        $q = GatewayPaymentAttempt::sole()->provider_payload['conversion_quote'];
        $this->post($url, ['purchase_country' => 'DE', 'quote_fingerprint' => $q['fingerprint']])->assertStatus(409);
        $this->post($url, ['purchase_country' => 'GB', 'quote_fingerprint' => 'altered'])->assertStatus(409);
        $this->travel(31)->minutes();
        $this->post($url, ['purchase_country' => 'GB', 'quote_fingerprint' => $q['fingerprint']])->assertStatus(409);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertSame('open', GatewayPaymentAttempt::sole()->state);
    }

    public function test_local_capture_currency_or_amount_mismatch_cannot_settle_the_usd_invoice(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->fakeApi();
        $this->localCheckout($invoice, $gateway);
        $url = $this->checkoutUrl($invoice, $gateway);
        $this->post($url, ['purchase_country' => 'DE'])->assertOk();
        $q = GatewayPaymentAttempt::sole()->provider_payload['conversion_quote'];
        $this->post($url, ['purchase_country' => 'DE', 'quote_fingerprint' => $q['fingerprint']])->assertRedirect();
        $this->localReadback = true;
        foreach ([['purchase_currency' => 'USD'], ['captured_amount' => 1234], ['order_amount' => 1121]] as $changes) {
            Http::swap(new Factory);
            $this->fakeApi($changes);
            $this->notify($gateway)->assertStatus(422);
        }
        $this->assertSame(0, $invoice->transactions()->count());
    }

    private function localPaidReceipt(bool $authorized = false): array
    {
        [$invoice, $gateway] = $this->fixture();
        $this->fakeApi();
        $this->localCheckout($invoice, $gateway);
        $url = $this->checkoutUrl($invoice, $gateway);
        $this->post($url, ['purchase_country' => 'DE'])->assertOk();
        $q = GatewayPaymentAttempt::sole()->provider_payload['conversion_quote'];
        $this->post($url, ['purchase_country' => 'DE', 'quote_fingerprint' => $q['fingerprint']])->assertRedirect();
        $this->localReadback = true;
        $this->localAuthorization = $authorized;
        $this->notify($gateway)->assertStatus($authorized ? 422 : 200);
        $role = Role::create(['name' => 'Synthetic currency admin', 'permissions' => ['*']]);
        $admin = $invoice->user;
        $admin->update(['role_id' => $role->id]);
        $this->actingAs($admin->fresh());
        $gateway->settings()->create(['key' => 'admin_payment_operations_enabled', 'value' => '1']);

        return [$admin->fresh(), $invoice->fresh(), $gateway->fresh(), $authorized ? null : $invoice->transactions()->sole()];
    }

    public function test_local_native_admin_partial_and_remaining_refunds_preserve_both_currencies_and_replay_once(): void
    {
        [$admin, $invoice, $gateway, $receipt] = $this->localPaidReceipt();
        $ops = new ProviderOperations;
        $key = 'dd244ea3-356b-4c8b-aaed-2800e74cd9bd';
        $first = $ops->refund($admin, $receipt, '5.00', false, 'Synthetic partial refund', $key);
        $this->assertSame('succeeded', $first->state);
        $this->assertSame('USD', $first->currency_code);
        $this->assertSame('5.00', $first->amount);
        $this->assertSame('EUR', $first->payload['provider_context']['provider_currency']);
        $this->assertSame('4.55', $first->payload['provider_context']['provider_amount']);
        $last = $first->outcome_evidence;
        $this->assertSame('4.55', end($last)['evidence']['provider_amount']);
        $this->assertSame($first->id, $ops->refund($admin, $receipt->fresh(), '5.00', false, 'Synthetic partial refund', $key)->id);
        $remaining = $ops->refund($admin, $receipt->fresh(), '7.34', false, 'Synthetic remaining refund', 'b77f446b-4070-4a29-8d20-4daec24e5072');
        $this->assertSame('succeeded', $remaining->state);
        $this->assertSame('6.67', $remaining->payload['provider_context']['provider_amount']);
        $this->assertSame('12.34', $receipt->fresh()->refunded_amount);
        $this->assertSame(1122, array_sum(array_column($this->localRefunds, 'refunded_amount')));
        $this->assertCount(2, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/refunds')));
    }

    public function test_local_unknown_refund_readback_reconciles_without_another_money_write(): void
    {
        [$admin, $invoice, $gateway, $receipt] = $this->localPaidReceipt();
        $ops = new ProviderOperations;
        $this->refundReadFailure = true;
        $op = $ops->refund($admin, $receipt, '5.00', false, 'Synthetic refund pending readback', '9980c10f-7a8d-4569-89b3-9f39689aa76e');
        $this->assertSame('uncertain', $op->state);
        $this->assertSame('0.00', $receipt->fresh()->refunded_amount);
        $this->refundReadFailure = false;
        $this->assertSame('succeeded', $ops->reconcile($admin, $op)->state);
        $this->assertSame('5.00', $receipt->fresh()->refunded_amount);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/refunds')));
    }

    public function test_unexplained_local_provider_refund_blocks_a_new_native_refund(): void
    {
        [$admin, $invoice, $gateway, $receipt] = $this->localPaidReceipt();
        $this->localRefunds['synthetic-refund-outside'] = ['refund_id' => 'synthetic-refund-outside', 'reference' => 'outside', 'refunded_amount' => 1, 'refunded_at' => now()->toIso8601String()];
        $rejected = false;
        try {
            (new ProviderOperations)->refund($admin, $receipt, '5.00', false, 'Synthetic refund', 'd1355159-ec88-46fc-96f3-2d05d27ebd46');
        } catch (\RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Unexplained provider refund was accepted');
        $this->assertSame(0, PaymentOperation::count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/refunds'));
    }

    public function test_local_native_admin_capture_then_refund_preserves_capture_ancestry_and_usd_receipt(): void
    {
        [$admin, $invoice, $gateway] = $this->localPaidReceipt(true);
        $ops = new ProviderOperations;
        $preview = $ops->capturePreview($admin, $invoice);
        $this->assertSame('12.34', $preview['amount']);
        $this->assertSame('USD', $preview['currency']);
        $capture = $ops->capture($admin, $invoice, $gateway, $preview['reference'], 'Synthetic converted capture', '021e25be-2f27-4d02-a857-3b09173c3e55');
        $this->assertSame('succeeded', $capture->state);
        $this->assertSame('11.22', $capture->payload['provider_context']['provider_amount']);
        $receipt = $invoice->transactions()->sole();
        $this->assertSame('12.34', $receipt->amount);
        $this->assertSame('manual_capture', $receipt->settlement_origin);
        $refund = $ops->refund($admin, $receipt, '5.00', false, 'Synthetic refund of captured currency payment', '68d79b04-ebc4-4d23-bc58-4c55c1c38c87');
        $this->assertSame('succeeded', $refund->state);
        $this->assertSame('capture', $refund->payload['provider_context']['provider_object_type']);
        $this->assertSame('4.55', $refund->payload['provider_context']['provider_amount']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/captures') && $r['captured_amount'] === 1122);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/captures')));
    }

    public function test_local_admin_refund_preview_shows_the_exact_klarna_amount_beside_native_usd(): void
    {
        [$admin, $invoice, $gateway, $receipt] = $this->localPaidReceipt();
        $preview = PaymentActions::refundPreview($admin, $receipt, 'partial', '5.00', false, true);
        $this->assertSame('USD', $preview['currency']);
        $this->assertSame('5.00', $preview['amount']);
        $this->assertSame('EUR', $preview['provider_currency'] ?? null);
        $this->assertSame('4.55', $preview['provider_amount'] ?? null);
    }

    public function test_local_admin_zero_provider_unit_refuses_a_write_before_claim(): void
    {
        [$admin, $invoice, $gateway, $receipt] = $this->localPaidReceipt();
        (new ProviderOperations)->refund($admin, $receipt, '0.05', false, 'Synthetic prior refund', '0f495844-8b8f-4160-8ef1-c44882d41ca5');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->callTableAction('provider_refund', $receipt->fresh(), data: ['refund_mode' => 'partial', 'amount' => '0.01', 'include_fee' => false,
                'reason' => 'Synthetic zero-unit refund', 'request_key' => '3596829c-f2ec-4c62-9f0d-79db2fe24c25'])
            ->assertHasTableActionErrors(['reason']);
        $this->assertSame(1, PaymentOperation::count());
        $this->assertSame('0.05', $receipt->fresh()->refunded_amount);
        Http::assertNothingSent();
    }

    public function test_local_admin_zero_provider_unit_allows_external_refund_record_without_http(): void
    {
        [$admin, $invoice, $gateway, $receipt] = $this->localPaidReceipt();
        (new ProviderOperations)->refund($admin, $receipt, '0.05', false, 'Synthetic prior refund', '0f495844-8b8f-4160-8ef1-c44882d41ca5');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->callTableAction('external_refund', $receipt->fresh(), data: ['refund_mode' => 'partial', 'amount' => '0.01', 'include_fee' => false,
                'reference' => 'synthetic-external-refund', 'reason' => 'External refund confirmed', 'effective_at' => '2026-10-03 12:00:00',
                'request_key' => '74e957dd-e0cf-4b09-a909-11d2e24fcefb'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('succeeded', PaymentOperation::where('kind', 'external_refund')->sole()->state);
        $this->assertSame('0.06', $receipt->fresh()->refunded_amount);
        Http::assertNothingSent();
    }

    private function fakeApi(array $changes = [], string $status = 'COMPLETED'): void
    {
        Http::fake(function ($r) use ($changes, $status) {
            if (str_starts_with($r->url(), 'https://www.ecb.europa.eu/')) {
                return Http::response('<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="' . now()->utc()->format('Y-m-d') . '"><Cube currency="USD" rate="1.1"/><Cube currency="GBP" rate="0.85"/></Cube></Cube></gesmes:Envelope>');
            }
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/payments/v1/sessions')) {
                return Http::response(['session_id' => 'synthetic-kp'], 200);
            }
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/hpp/v1/sessions')) {
                return Http::response(['session_id' => 'synthetic-hpp', 'session_url' => 'https://api.playground.klarna.com/hpp/v1/sessions/synthetic-hpp', 'redirect_url' => 'https://pay.playground.klarna.com/eu/hpp/payments/synthetic-hpp', 'expires_at' => now()->addHour()->toIso8601String()], 201);
            }
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/captures')) {
                $this->localAuthorization = false;
                $this->localCaptureKey = $r['reference'];

                return Http::response(['capture_id' => 'synthetic-manual-capture'], 201);
            }
            if (str_ends_with($r->url(), '/captures/synthetic-manual-capture')) {
                return Http::response(['capture_id' => 'synthetic-manual-capture', 'reference' => $this->localCaptureKey,
                    'captured_amount' => 1122, 'captured_at' => now()->toIso8601String()]);
            }
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/refunds')) {
                $id = 'synthetic-refund-' . (count($this->localRefunds) + 1);
                $this->localRefunds[$id] = ['refund_id' => $id, 'reference' => $r['reference'], 'refunded_amount' => $r['refunded_amount'], 'refunded_at' => now()->toIso8601String()];

                return Http::response(['refund_id' => $id], 201);
            }
            if (str_contains($r->url(), '/refunds/synthetic-refund-')) {
                if ($this->refundReadFailure) {
                    throw new ConnectionException('synthetic failed read');
                }

                return Http::response($this->localRefunds[basename($r->url())]);
            }
            if (str_ends_with($r->url(), '/hpp/v1/sessions/synthetic-hpp')) {
                return Http::response(['session_id' => 'synthetic-hpp', 'status' => $status, 'order_id' => 'synthetic-order']);
            }
            if (str_ends_with($r->url(), '/ordermanagement/v1/orders/synthetic-order')) {
                $order = array_replace(['order_id' => 'synthetic-order', 'status' => 'CAPTURED', 'fraud_status' => 'ACCEPTED',
                    'purchase_country' => $this->providerCountry, 'purchase_currency' => 'USD', 'order_amount' => $this->taxed ? 10988 : 1234,
                    'order_tax_amount' => $this->taxed ? 713 : 0, 'order_lines' => $this->providerLines(),
                    'captured_amount' => $this->taxed ? 10988 : 1234, 'refunded_amount' => 0, 'merchant_reference1' => GatewayPaymentAttempt::sole()->reference], $changes);
                if ($this->localReadback) {
                    $q = GatewayPaymentAttempt::sole()->provider_payload['conversion_quote'];
                    $order = array_replace($order, ['purchase_currency' => $q['provider_currency'], 'purchase_country' => $q['purchase_country'],
                        'order_amount' => $q['allocation']['order_amount'], 'captured_amount' => $this->localAuthorization ? 0 : $q['allocation']['order_amount'],
                        'status' => $this->localAuthorization ? 'AUTHORIZED' : 'CAPTURED',
                        'expires_at' => now()->addHour()->toIso8601String(), 'remaining_authorized_amount' => $this->localAuthorization ? $q['allocation']['order_amount'] : 0,
                        'order_tax_amount' => $q['allocation']['order_tax_amount'], 'order_lines' => $q['allocation']['order_lines'],
                        'original_order_amount' => $q['allocation']['order_amount'], 'refunded_amount' => array_sum(array_column($this->localRefunds, 'refunded_amount')),
                        'refunds' => array_values($this->localRefunds), 'captures' => $this->localAuthorization ? [] : ($this->localCaptureKey ? [['capture_id' => 'synthetic-manual-capture', 'reference' => $this->localCaptureKey]] : [['capture_id' => 'synthetic-auto-capture']])], $changes);
                }
                if ($this->omitAggregateTax) {
                    unset($order['order_tax_amount']);
                }

                return Http::response($order);
            }
            throw new \RuntimeException('Unexpected request');
        });
    }

    private function providerLines(): array
    {
        $invoice = GatewayPaymentAttempt::sole()->invoice;
        $id = $invoice->items()->where('kind', 'product')->firstOrFail()->id;
        if ($this->taxed && $this->providerCountry === 'DE') {
            $fee = $invoice->items()->where('kind', 'gateway_fee')->sole()->id;

            return [
                ['type' => 'digital', 'reference' => 'paymenter-' . $id, 'quantity' => 1, 'unit_price' => 10713, 'total_amount' => 10713, 'total_tax_amount' => 713, 'tax_rate' => 713],
                ['type' => 'surcharge', 'reference' => 'paymenter-' . $fee, 'quantity' => 1, 'unit_price' => 275, 'total_amount' => 275, 'total_tax_amount' => 0, 'tax_rate' => 0],
            ];
        }
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

    public function test_order_management_readback_without_aggregate_tax_settles_and_replays_once(): void
    {
        [$invoice, $gateway, $extension] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $this->taxed = true;
        $this->omitAggregateTax = true;
        $this->fakeApi();
        $extension->pay($invoice->fresh(), '107.13');
        $this->notify($gateway)->assertOk();
        $this->notify($gateway)->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('109.88', $invoice->transactions()->sole()->amount);
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
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/hpp/v1/sessions') && $r['options']['place_order_mode'] === 'CAPTURE_ORDER');
        $this->assertCount(2, Http::recorded(fn ($r) => $r->method() === 'POST'));
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

    private function consumerFx(Gateway $gateway, array $changes = []): Klarna
    {
        foreach (array_replace(['consumer_fx_enabled' => '1', 'enabled_purchase_countries' => 'US,DE,GB'], $changes) as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return (new Klarna($gateway->fresh()->settings->pluck('value', 'key')->all()))->bindRecord($gateway);
    }

    private function checkoutUrl(Invoice $invoice, Gateway $gateway): string
    {
        return '/extensions/klarna/' . $gateway->id . '/checkout/' . $invoice->id . '/' . GatewayPaymentAttempt::sole()->reference;
    }

    public function test_consumer_fx_waits_for_country_selection_before_creating_a_provider_session(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $extension = $this->consumerFx($gateway);
        $this->fakeApi();
        $html = $extension->pay($invoice, '12.34')->render();
        Http::assertNothingSent();
        $this->assertSame('open', GatewayPaymentAttempt::sole()->state);
        $this->assertNull(GatewayPaymentAttempt::sole()->provider_payload);
        $this->assertStringContainsString('name="purchase_country"', $html);
        $this->assertStringContainsString('value="DE"', $html);
        $this->assertStringContainsString('EUR', $html);
        $this->assertStringNotContainsString('value="AU"', $html);
        $this->assertStringNotContainsString('name="purchase_currency"', $html);
        $this->assertStringContainsString('12.34', $html);
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $this->assertTrue((new \DOMXPath($dom))->query('//select[@name="purchase_country" and @required]')->length === 1);
    }

    public function test_selected_country_preserves_usd_tax_fee_and_automatic_capture_then_settles_once(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $this->taxed = true;
        $this->providerCountry = 'DE';
        $extension = $this->consumerFx($gateway);
        $this->fakeApi();
        $extension->pay($invoice->fresh(), '107.13');
        $url = $this->checkoutUrl($invoice, $gateway);
        $this->post($url, ['purchase_country' => 'DE'])->assertRedirect('https://pay.playground.klarna.com/eu/hpp/payments/synthetic-hpp');
        $this->assertSame('open', GatewayPaymentAttempt::sole()->state);
        $request = Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/payments/v1/sessions'))->sole()[0];
        $this->assertSame('DE', $request['purchase_country']);
        $this->assertSame('USD', $request['purchase_currency']);
        $this->assertSame('en-DE', $request['locale']);
        $this->assertSame(10988, $request['order_amount']);
        $this->assertSame(713, $request['order_tax_amount']);
        $this->assertSame([10713, 275], array_column($request['order_lines'], 'total_amount'));
        $this->assertSame([713, 0], array_column($request['order_lines'], 'total_tax_amount'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/hpp/v1/sessions') && $r['options']['place_order_mode'] === 'CAPTURE_ORDER');
        $this->assertSame('EUR', GatewayPaymentAttempt::sole()->provider_payload['billing_currency']);
        $this->post($url, ['purchase_country' => 'DE'])->assertRedirect();
        $this->notify($gateway)->assertOk();
        $this->notify($gateway)->assertOk();
        $this->assertCount(2, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('109.88', $invoice->transactions()->sole()->amount);
        $this->assertSame('USD', $invoice->fresh()->currency_code);
    }

    public function test_selected_market_is_frozen_across_retries_and_admin_market_changes(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $extension = $this->consumerFx($gateway);
        $this->fakeApi();
        $extension->pay($invoice, '12.34');
        $url = $this->checkoutUrl($invoice, $gateway);
        $this->post($url, ['purchase_country' => 'DE'])->assertRedirect();
        $this->post($url, ['purchase_country' => 'GB'])->assertStatus(409);
        $changed = $this->consumerFx($gateway, ['enabled_purchase_countries' => 'US', 'purchase_country' => 'US', 'consumer_fx_enabled' => '0']);
        $this->post($url, ['purchase_country' => 'DE'])->assertRedirect();
        $changed->pay($invoice, '12.34');
        $this->consumerFx($gateway, ['enabled_purchase_countries' => ''])->pay($invoice, '12.34');
        $this->assertSame('DE', GatewayPaymentAttempt::sole()->provider_payload['purchase_country']);
        $this->assertCount(2, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_disallowed_countries_and_posted_money_cannot_initialize_checkout(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->consumerFx($gateway)->pay($invoice, '12.34');
        $url = $this->checkoutUrl($invoice, $gateway);
        foreach ([[], ['purchase_country' => 'AU'], ['purchase_country' => 'ZZ'], ['purchase_country' => ['DE']], ['purchase_country' => 'DE', 'purchase_currency' => 'EUR'], ['purchase_country' => 'DE', 'amount' => '0.01'], ['purchase_country' => 'DE', 'locale' => 'de-DE']] as $body) {
            $this->postJson($url, $body)->assertStatus(422);
        }
        Http::assertNothingSent();
        $this->assertNull(GatewayPaymentAttempt::sole()->provider_payload);
        $this->assertSame('12.34', GatewayPaymentAttempt::sole()->amount);
    }

    public function test_country_checkout_requires_the_invoice_owner_and_matching_attempt(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->consumerFx($gateway)->pay($invoice, '12.34');
        $url = $this->checkoutUrl($invoice, $gateway);
        $owner = $invoice->user;
        $reader = User::factory()->create();
        $account = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 10010, 'owner_user_id' => $owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $account, 'user_id' => $reader->id, 'source_user_id' => 10011]);
        $this->assertTrue($reader->can('view', $invoice));
        $this->assertFalse($reader->can('update', $invoice));
        $this->actingAs($reader)->withSession($this->loginUser($reader));
        // Paymenter masks native authorization denials as 404.
        $this->postJson($url, ['purchase_country' => 'DE'])->assertNotFound();
        $this->actingAs($owner)->withSession($this->loginUser($owner));
        $wrong = Invoice::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);
        $this->post(str_replace('/checkout/' . $invoice->id . '/', '/checkout/' . $wrong->id . '/', $url), ['purchase_country' => 'DE'])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withSession(['user_session' => null]);
        $this->post($url, ['purchase_country' => 'DE'])->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->assertSame(1, GatewayPaymentAttempt::count());
    }

    public function test_stale_country_form_cannot_create_or_initialize_a_replacement_attempt(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $extension = $this->consumerFx($gateway);
        $extension->pay($invoice, '12.34');
        $oldUrl = $this->checkoutUrl($invoice, $gateway);
        GatewayPaymentAttempt::sole()->update(['state' => 'reconciled']);
        $fingerprint = (new InvoicePricing)->fingerprint($invoice);
        $this->post($oldUrl, ['purchase_country' => 'DE'])->assertStatus(409);
        $this->assertSame(1, GatewayPaymentAttempt::count());
        $this->assertSame($fingerprint, (new InvoicePricing)->fingerprint($invoice->fresh()));
        $extension->pay($invoice, '12.34');
        $current = GatewayPaymentAttempt::where('state', 'open')->sole();
        $this->post($oldUrl, ['purchase_country' => 'DE'])->assertStatus(409);
        $this->assertNull($current->fresh()->provider_payload);
        $this->assertSame(2, GatewayPaymentAttempt::count());
        Http::assertNothingSent();
    }

    public function test_retained_historical_market_does_not_bypass_a_new_country_selection(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $extension = $this->consumerFx($gateway);
        $this->fakeApi();
        $extension->pay($invoice, '12.34');
        $this->post($this->checkoutUrl($invoice, $gateway), ['purchase_country' => 'DE'])->assertRedirect();
        $old = GatewayPaymentAttempt::sole();
        $old->update(['state' => 'reconciled']);
        $html = $extension->pay($invoice, '12.34')->render();
        $this->assertStringContainsString('name="purchase_country"', $html);
        $this->assertNull(GatewayPaymentAttempt::where('state', 'open')->sole()->provider_payload);
        $this->assertSame('DE', $old->fresh()->provider_payload['purchase_country']);
        Http::assertSentCount(2);
    }

    public function test_browser_validation_keeps_the_country_form_and_visible_error(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->consumerFx($gateway)->pay($invoice, '12.34');
        $url = $this->checkoutUrl($invoice, $gateway);
        $this->consumerFx($gateway, ['enabled_purchase_countries' => 'US']);
        $this->from(route('invoices.show', $invoice))->post($url, ['purchase_country' => 'DE'])
            ->assertStatus(422)->assertSee('Choose an enabled country of your Klarna account.')
            ->assertSee('name="purchase_country"', false)->assertSee('Continue with Klarna');
        Http::assertNothingSent();
        $this->assertNull(GatewayPaymentAttempt::sole()->provider_payload);
    }

    public function test_country_submission_binds_the_invoice_id_even_when_another_invoice_number_matches(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->consumerFx($gateway)->pay($invoice, '12.34');
        $historical = Invoice::factory()->create(['user_id' => $invoice->user_id, 'status' => 'paid']);
        // Historical imports preserve source numbers without the native generator.
        DB::table('invoices')->where('id', $historical->id)->update(['number' => (string) $invoice->id]);
        $this->fakeApi();
        $this->post($this->checkoutUrl($invoice, $gateway), ['purchase_country' => 'DE'])->assertRedirect();
        $this->assertSame($invoice->id, GatewayPaymentAttempt::sole()->invoice_id);
        $this->assertSame('DE', GatewayPaymentAttempt::sole()->provider_payload['purchase_country']);
    }

    public function test_country_checkout_honors_collection_and_migration_holds(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->consumerFx($gateway)->pay($invoice, '12.34');
        $url = $this->checkoutUrl($invoice, $gateway);
        $gateway->update(['enabled' => false]);
        $this->post($url, ['purchase_country' => 'DE'])->assertStatus(409);
        $gateway->update(['enabled' => true]);
        $gateway->settings()->where('key', 'collection_enabled')->first()->update(['value' => '0']);
        $this->post($url, ['purchase_country' => 'DE'])->assertStatus(409);
        $gateway->settings()->where('key', 'collection_enabled')->first()->update(['value' => '1']);
        DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $invoice->id, 'reason' => 'Synthetic hold']);
        $this->post($url, ['purchase_country' => 'DE'])->assertStatus(409);
        Http::assertNothingSent();
        $this->assertNull(GatewayPaymentAttempt::sole()->provider_payload);
    }

    public function test_unknown_country_session_outcome_keeps_market_and_refuses_new_provider_writes(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->consumerFx($gateway)->pay($invoice, '12.34');
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('synthetic-secret');
        });
        $url = $this->checkoutUrl($invoice, $gateway);
        $this->post($url, ['purchase_country' => 'DE'])->assertStatus(409)->assertDontSee('synthetic-secret');
        $this->post($url, ['purchase_country' => 'DE'])->assertStatus(409);
        $this->post($url, ['purchase_country' => 'GB'])->assertStatus(409);
        $this->assertSame(1, $calls);
        $this->assertSame('initializing', GatewayPaymentAttempt::sole()->state);
        $this->assertSame('DE', GatewayPaymentAttempt::sole()->provider_payload['purchase_country']);
    }

    public function test_consumer_fx_configuration_fails_closed_without_supported_markets_or_usd(): void
    {
        [$invoice, $gateway] = $this->fixture();
        $this->fakeApi();
        foreach ([['enabled_purchase_countries' => ''], ['enabled_purchase_countries' => 'US,ZZ'], ['currency' => 'EUR']] as $changes) {
            try {
                $this->consumerFx($gateway, array_replace(['currency' => 'USD'], $changes))->pay($invoice, '12.34');
                $this->fail('Unconfigured Consumer FX checkout was accepted');
            } catch (\RuntimeException) {
                $this->assertSame(0, GatewayPaymentAttempt::count());
            }
        }
        Http::assertNothingSent();
    }
}
