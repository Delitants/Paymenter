<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Gateways\Wave\OrderLines;
use Paymenter\Extensions\Gateways\Wave\Wave;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesTaxedGatewayInvoice;
use Tests\TestCase;

class WaveTest extends TestCase
{
    use UsesCommittedDatabase, UsesTaxedGatewayInvoice;

    private bool $taxed = false;

    private array $taxChanges = [];

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
        $amount = $this->taxed ? '109.88' : '12.34';

        return array_replace(['id' => 'synthetic-wave-invoice', 'internalId' => '12345', 'business' => ['id' => 'synthetic-business'], 'customer' => ['id' => 'synthetic-customer'], 'invoiceNumber' => 'PAY-' . GatewayPaymentAttempt::sole()->reference, 'status' => 'PAID', 'currency' => ['code' => 'USD'], 'total' => ['value' => $amount], 'amountPaid' => ['value' => $amount], 'amountDue' => ['value' => '0.00'], 'viewUrl' => 'https://next.waveapps.com/a/invoices/synthetic', 'items' => $this->providerItems()], $changes);
    }

    private function taxRecord(array $changes = []): array
    {
        return array_replace(['id' => 'synthetic-tax', 'business' => ['id' => 'synthetic-business'], 'rate' => '0.07125', 'isCompound' => false, 'isArchived' => false], $changes);
    }

    private function providerItems(): array
    {
        $invoice = GatewayPaymentAttempt::sole()->invoice;
        $id = $invoice->items()->where('kind', 'product')->firstOrFail()->id;
        $net = $this->taxed ? '100.00' : '12.34';
        $items = [['description' => 'Paymenter item ' . $id, 'quantity' => '1', 'unitPrice' => $net, 'subtotal' => ['value' => $net],
            'total' => ['value' => $this->taxed ? '107.13' : '12.34'], 'product' => ['id' => 'synthetic-product'],
            'taxes' => $this->taxed ? [['salesTax' => $this->taxRecord(), 'amount' => ['value' => '7.13']]] : []]];
        if ($this->taxed) {
            $id = $invoice->items()->where('kind', 'gateway_fee')->sole()->id;
            $items[] = ['description' => 'Paymenter item ' . $id, 'quantity' => '1', 'unitPrice' => '2.75', 'subtotal' => ['value' => '2.75'], 'total' => ['value' => '2.75'], 'product' => ['id' => 'synthetic-product'], 'taxes' => []];
        }

        return $items;
    }

    private function fakeApi(array $changes = [], bool $newCustomer = false, array $createChanges = []): void
    {
        Http::fake(function ($r) use ($changes, $newCustomer, $createChanges) {
            $query = $r['query'];
            if (str_contains($query, 'WaveSalesTax')) {
                return Http::response(['data' => ['business' => ['id' => 'synthetic-business', 'salesTax' => $this->taxRecord($this->taxChanges)]]]);
            }
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
                return Http::response(['data' => ['invoiceCreate' => ['didSucceed' => true, 'invoice' => $this->providerInvoice(array_replace(['status' => 'DRAFT'], $createChanges))]]]);
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

    private function taxedFixture(): array
    {
        [$invoice, $gateway] = $this->fixture();
        $this->taxAndFee($invoice, $gateway);
        $gateway->settings()->create(['key' => 'sales_tax_id', 'value' => 'synthetic-tax']);
        $this->taxed = true;

        return [$invoice->fresh(), $gateway, (new Wave($gateway->fresh()->settings->pluck('value', 'key')->all()))->bindRecord($gateway)];
    }

    public function test_tax_and_untaxed_fee_lines_match_native_capture(): void
    {
        [$invoice, $gateway, $extension] = $this->taxedFixture();
        $this->fakeApi(newCustomer: true);
        $extension->pay($invoice, '107.13');
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]['query'])->all();
        $this->assertStringContainsString('WaveSalesTax', $requests[0]);
        $request = Http::recorded(fn ($r) => str_contains($r['query'], 'WaveCreateInvoice'))->sole()[0];
        $items = $request['variables']['input']['items'];
        $this->assertSame(['100.00', '2.75'], array_column($items, 'unitPrice'));
        $this->assertSame([['salesTaxId' => 'synthetic-tax']], $items[0]['taxes']);
        $this->assertSame([], $items[1]['taxes']);
        $this->assertSame('109.88', GatewayPaymentAttempt::sole()->amount);
        $gateway->settings()->where('key', 'sales_tax_id')->first()->update(['value' => 'new-tax-setting']);
        (new Wave($gateway->fresh()->settings->pluck('value', 'key')->all()))->bindRecord($gateway)->pay($invoice->fresh(), '109.88');
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r['query'], 'WaveCreateInvoice')));
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r['query'], 'WaveSalesTax')));
        $this->notify($gateway)->assertOk();
        $this->notify($gateway)->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('109.88', $invoice->transactions()->sole()->amount);
        Http::assertNotSent(fn ($r) => str_contains($r['query'], 'salesTaxCreate') || str_contains($r['query'], 'salesTaxPatch'));
    }

    public function test_bad_tax_record_prevents_customer_and_invoice_writes(): void
    {
        [$invoice,, $extension] = $this->taxedFixture();
        foreach ([['id' => 'wrong-tax'], ['business' => ['id' => 'wrong-business']], ['rate' => '0.0712'], ['rate' => 'NaN'], ['isCompound' => true], ['isArchived' => true]] as $change) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->taxChanges = $change;
            $this->fakeApi(newCustomer: true);
            try {
                $extension->pay($invoice->fresh(), '107.13');
                $this->fail('Invalid business sales tax accepted');
            } catch (\RuntimeException) {
                Http::assertSent(fn ($r) => str_contains($r['query'], 'WaveSalesTax'));
                Http::assertNotSent(fn ($r) => str_contains($r['query'], 'mutation'));
            }
        }
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(0, DB::table('gateway_customer_bindings')->count());
    }

    public function test_invoice_date_and_readback_tax_date_stay_frozen_after_midnight(): void
    {
        [$invoice, $gateway, $extension] = $this->taxedFixture();
        $this->fakeApi();
        $date = now()->toDateString();
        $extension->pay($invoice, '107.13');
        $request = Http::recorded(fn ($r) => str_contains($r['query'], 'WaveCreateInvoice'))->sole()[0];
        $this->assertSame($date, $request['variables']['input']['invoiceDate'] ?? null);
        $this->travel(1)->days();
        $this->notify($gateway)->assertOk();
        $request = Http::recorded(fn ($r) => str_contains($r['query'], 'query WaveInvoice'))->sole()[0];
        $this->assertSame($date, $request['variables']['taxDate']);
        $this->assertStringContainsString('rate(for: $taxDate)', $request['query']);
        $this->assertSame('109.88', $invoice->transactions()->sole()->amount);
    }

    public function test_bad_tax_allocation_cannot_approve_or_settle(): void
    {
        [$invoice, $gateway, $extension] = $this->taxedFixture();
        $this->fakeApi();
        $extension->pay($invoice, '107.13');
        $items = $this->providerItems();
        $badTax = $items;
        $badTax[0]['taxes'][0]['amount']['value'] = '7.12';
        $badFee = $items;
        $badFee[1]['unitPrice'] = '2.76';
        $taxedFee = $items;
        $taxedFee[1]['taxes'] = [['salesTax' => $this->taxRecord(), 'amount' => ['value' => '0.20']]];
        $badProduct = $items;
        $badProduct[0]['product']['id'] = 'other-product';
        foreach ([$badTax, $badFee, $taxedFee, $badProduct, array_merge($items, [$items[0]])] as $allocation) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->fakeApi(['items' => $allocation]);
            $this->notify($gateway)->assertStatus(422);
        }
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_wrong_created_tax_cannot_approve_an_invoice(): void
    {
        [$invoice,, $extension] = $this->taxedFixture();
        $this->fakeApi(createChanges: ['items' => []]);
        try {
            $extension->pay($invoice, '107.13');
            $this->fail('Unverified provider allocation approved');
        } catch (\RuntimeException) {
            Http::assertNotSent(fn ($r) => str_contains($r['query'], 'WaveApproveInvoice'));
        }
        $this->assertSame('initializing', GatewayPaymentAttempt::sole()->state);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_small_native_unit_rounding_is_preserved_by_split_wave_lines(): void
    {
        [$invoice, $gateway] = $this->taxedFixture();
        $invoice->items()->first()->update(['price' => '0.11', 'quantity' => 3, 'tax_amount' => '0.03']);
        $attempt = (new PaymentAttempts)->begin($gateway, $invoice->fresh(), hash('sha256', 'synthetic merchant'), 'USD');
        $items = (new OrderLines)->build($attempt, 'synthetic-product', $this->taxRecord());
        $this->assertCount(4, $items);
        $this->assertSame(['0.10', '0.10', '0.10', '0.26'], array_column($items, 'unitPrice'));
        $this->assertSame(['1', '1', '1', '1'], array_column($items, 'quantity'));
        $this->assertSame('0.03', $attempt->pricing_payload['product_tax']);
    }

    public function test_legacy_initialized_attempt_retains_original_callback_contract(): void
    {
        [$invoice, $gateway, $extension] = $this->fixture();
        $this->fakeApi();
        $extension->pay($invoice, '12.34');
        $attempt = GatewayPaymentAttempt::sole();
        $payload = $attempt->provider_payload;
        unset($payload['items'], $payload['verified_tax'], $payload['tax_date'], $payload['product_id']);
        $attempt->update(['pricing_payload' => null, 'pricing_fingerprint' => null, 'provider_payload' => $payload]);
        $extension->pay($invoice, '12.34');
        $this->notify($gateway)->assertOk();
        $this->notify($gateway)->assertOk();
        $this->assertSame('12.34', $invoice->transactions()->sole()->amount);
        $this->assertSame(0, $invoice->items()->where('kind', 'gateway_fee')->count());
    }

    public function test_partial_credit_allocation_remains_unavailable_before_provider_calls(): void
    {
        [$invoice,, $extension] = $this->taxedFixture();
        $invoice->transactions()->create(['amount' => '10.00', 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => true]);
        try {
            $extension->pay($invoice->fresh(), '97.13');
            $this->fail('Unaccepted partial-credit allocation submitted');
        } catch (\RuntimeException) {
            $this->assertSame(0, GatewayPaymentAttempt::count());
        }
        Http::assertNothingSent();
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
            $rejected = false;
            try {
                $e->pay($i, '12.34');
            } catch (\RuntimeException $ex) {
                $rejected = true;
                $this->assertStringNotContainsString('synthetic-secret', $ex->getMessage());
            }
            $this->assertTrue($rejected, 'Unknown checkout was accepted');
        }
        $this->assertSame(1, $calls);
        $this->assertSame('open', GatewayPaymentAttempt::sole()->state);
        $this->assertSame('initializing', DB::table('gateway_customer_bindings')->sole()->state);
    }

    public function test_overlapping_invoices_create_one_customer_and_each_invoice_can_continue(): void
    {
        [$first, , $extension] = $this->fixture();
        $second = Invoice::factory()->create(['user_id' => $first->user_id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $second->id, 'price' => '12.34', 'quantity' => 1]);
        $customerCreates = 0;
        $lookups = 0;
        $overlapBlocked = false;
        $remotes = [];
        Http::fake(function ($request) use ($extension, $second, &$customerCreates, &$lookups, &$overlapBlocked, &$remotes) {
            $query = $request['query'];
            if (str_contains($query, 'WaveCustomers')) {
                $lookups++;

                return Http::response(['data' => ['business' => ['id' => 'synthetic-business', 'customers' => ['pageInfo' => ['totalCount' => 0], 'edges' => []]]]]);
            }
            if (str_contains($query, 'WaveCreateCustomer')) {
                $customerCreates++;
                if ($customerCreates === 1) {
                    try {
                        $extension->pay($second->fresh(), '12.34');
                    } catch (\RuntimeException $e) {
                        $overlapBlocked = str_contains($e->getMessage(), 'reconciliation');
                    }
                }

                return Http::response(['data' => ['customerCreate' => ['didSucceed' => true, 'customer' => ['id' => 'synthetic-customer', 'email' => 'wave-fixture@example.test']]]]);
            }
            $input = $request['variables']['input'];
            if (str_contains($query, 'WaveCreateInvoice')) {
                $id = 'synthetic-' . $input['invoiceNumber'];
                $remotes[$id] = ['id' => $id, 'internalId' => $id, 'business' => ['id' => 'synthetic-business'],
                    'customer' => ['id' => $input['customerId']], 'invoiceNumber' => $input['invoiceNumber'], 'status' => 'DRAFT',
                    'currency' => ['code' => 'USD'], 'total' => ['value' => '12.34'], 'amountPaid' => ['value' => '0.00'],
                    'amountDue' => ['value' => '12.34'], 'viewUrl' => 'https://next.waveapps.com/a/invoices/' . $id,
                    'items' => [['description' => $input['items'][0]['description'], 'product' => ['id' => 'synthetic-product'],
                        'quantity' => '1', 'unitPrice' => '12.34', 'subtotal' => ['value' => '12.34'], 'total' => ['value' => '12.34'], 'taxes' => []]]];

                return Http::response(['data' => ['invoiceCreate' => ['didSucceed' => true, 'invoice' => $remotes[$id]]]]);
            }
            if (str_contains($query, 'WaveApproveInvoice')) {
                return Http::response(['data' => ['invoiceApprove' => ['didSucceed' => true, 'invoice' => array_replace($remotes[$input['invoiceId']], ['status' => 'SAVED'])]]]);
            }
            throw new \RuntimeException('Unexpected synthetic request');
        });
        $extension->pay($first, '12.34');
        $this->assertTrue($overlapBlocked);
        $this->assertSame('open', GatewayPaymentAttempt::where('invoice_id', $second->id)->sole()->state);
        $extension->pay($second->fresh(), '12.34');
        $this->assertSame(1, $customerCreates);
        $this->assertSame(1, $lookups);
        $this->assertCount(2, $remotes);
        $this->assertSame(1, DB::table('gateway_customer_bindings')->count());
        $this->assertSame(['synthetic-customer', 'synthetic-customer'], GatewayPaymentAttempt::orderBy('id')->get()->map(fn ($a) => $a->provider_payload['customer_id'])->all());
        $this->assertSame(0, DB::table('invoice_transactions')->count());
    }
}
