<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\PaidInvoiceJob;
use App\Livewire\Cart as CheckoutCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DebugLog;
use App\Models\ExtensionOperation;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\InvoicePricing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\SSLStore\SSLStoreOrders;
use RuntimeException;
use Tests\Fixtures\FeeGateway;
use Tests\TestCase;

class SSLStoreOrderTest extends TestCase
{
    use DatabaseMigrations;

    private const API = 'https://sandbox-wbapi.thesslstore.com';

    private const ENROLLMENT = 'https://enroll.example.test/certificates/start?token=synthetic-link-token';

    public function runDatabaseMigrations(): void
    {
        // Use real commits around the provider claim. The next test rebuilds its schema.
        $this->refreshTestDatabase();
        RefreshDatabaseState::$migrated = false;
    }

    private function fixture(bool $enabled = true, bool $paid = true): array
    {
        $server = Server::create(['name' => 'SSLStore fixture', 'extension' => 'SSLStore', 'type' => 'server', 'enabled' => true]);
        foreach ([
            'url' => self::API,
            'partner_code' => 'synthetic-partner',
            'auth_token' => 'synthetic-auth',
            'orders_enabled' => '1',
            'enrollment_host' => 'enroll.example.test',
        ] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct(['server_id' => $server->id, 'hidden' => false]);
        $product->plan->update(['billing_period' => 12]);
        $product->product->settings()->create(['key' => 'product_code', 'value' => 'synthetic_tls']);
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING, 'quantity' => 1, 'price' => '10.00',
        ]);
        [, $terms] = (new SSLStoreOrders)->quote($product->product, $product->plan, 'USD');
        $service->properties()->create([
            'key' => SSLStoreOrders::QUOTE,
            'value' => json_encode($terms + ['line_price' => '10.00', 'cart_item_id' => null], JSON_THROW_ON_ERROR),
        ]);
        if (!$enabled) {
            $server->settings()->where('key', 'orders_enabled')->update(['value' => '0']);
        }
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'due_at' => now()->addDay()]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id, 'reference_id' => $service->id, 'reference_type' => Service::class,
            'quantity' => 1, 'price' => '10.00',
        ]);
        if ($paid) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }
        Http::preventStrayRequests();

        return [$service, $item, $user];
    }

    private function reply(string $customId, string $link = self::ENROLLMENT, bool $enrolled = false): array
    {
        return [
            'AuthResponse' => ['isError' => false],
            'CustomOrderID' => $customId,
            'TheSSLStoreOrderID' => '9017123',
            'PartnerOrderID' => 'synthetic-partner-order',
            'Validity' => 12,
            'ServerCount' => 1,
            'ProductCode' => 'synthetic_tls',
            'TinyOrderLink' => $link,
            'OrderStatus' => ['MajorStatus' => $enrolled ? 'PENDING' : 'INITIAL', 'isTinyOrder' => true, 'isTinyOrderClaimed' => $enrolled],
        ];
    }

    public function test_paid_invoice_creates_one_invitation_and_an_owner_only_action(): void
    {
        [$service, $item, $user] = $this->fixture(paid: false);
        $customId = null;
        Http::fake(function (Request $request) use ($user, &$customId) {
            if ($request->url() === self::API . '/rest/order/info') {
                $this->assertSame('9017123', $request['TheSSLStoreOrderID']);

                return Http::response($this->reply($customId));
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('submitted', ExtensionOperation::where('kind', 'sslstore-invite')->sole()->status);
            $this->assertSame(self::API . '/rest/order/inviteorder', $request->url());
            $this->assertSame('synthetic_tls', $request['ProductCode']);
            $this->assertSame(12, $request['ValidityPeriod']);
            $this->assertSame(1, $request['ServerCount']);
            $this->assertSame(0, $request['ExtraSAN']);
            $this->assertFalse($request['PreferSendOrderEmails']);
            $this->assertFalse($request['PreferVendorLink']);
            $this->assertFalse($request['AddInstallationSupport']);
            $this->assertSame($user->email, $request['RequestorEmail']);
            $this->assertSame('synthetic-partner', $request['AuthRequest']['PartnerCode']);
            $customId = $request['CustomOrderID'];

            return Http::response($this->reply($request['CustomOrderID']));
        });
        $invoice = $item->invoice;
        $amount = (new InvoicePricing)->summary($invoice)->payable;
        ExtensionHelper::addPayment($invoice, null, $amount, transactionId: 'synthetic-certificate-payment');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $job = new PaidInvoiceJob($item->id);
        $expires = $service->fresh()->expires_at;
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertNotNull($expires);
        $job->handle();
        $this->assertEquals($expires, $service->fresh()->expires_at);
        Http::assertSentCount(1);

        $operation = ExtensionOperation::where('kind', 'sslstore-invite')->sole();
        $this->assertSame('complete', $operation->status);
        $this->assertStringNotContainsString('synthetic-link-token', $operation->getRawOriginal('payload'));
        $this->assertStringNotContainsString($user->email, $operation->getRawOriginal('payload'));

        $this->actingAs($user)->withSession($this->loginUser($user));
        $enroll = ExtensionHelper::callService($service->fresh(), 'openEnrollment');
        $this->assertSame(route('services.sslstore.enroll', $service), $enroll);
        $this->get($enroll)->assertRedirect(self::ENROLLMENT)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringNotContainsString('synthetic-link-token', json_encode(ExtensionHelper::getActions($service->fresh()), JSON_THROW_ON_ERROR));
        $this->assertFalse(ExtensionHelper::shouldScheduleRenewalInvoice($service->fresh()));
        $this->actingAs(User::factory()->create());
        $this->expectException(AuthorizationException::class);
        ExtensionHelper::callService($service->fresh(), 'openEnrollment');
    }

    public function test_lost_response_only_reconciles_the_existing_enrollment_order(): void
    {
        [$service, $item] = $this->fixture();
        config(['settings.debug' => true]);
        $customId = null;
        $calls = [];
        Http::fake(function (Request $request) use (&$customId, &$calls) {
            $calls[] = $request->url();
            if ($request->url() === self::API . '/rest/order/inviteorder') {
                $customId = $request['CustomOrderID'];

                return Http::failedConnection('private request details');
            }
            $this->assertSame(self::API . '/rest/order/info', $request->url());
            $this->assertSame($customId, $request['CustomOrderID']);

            $reply = $this->reply(count($calls) === 2 ? 'other-order' : $customId);
            if (count($calls) === 3) {
                $reply['Validity'] = 24;
            } elseif (count($calls) === 4) {
                $reply['OrderStatus']['MajorStatus'] = 'ACTIVE';
            }

            return Http::response($reply);
        });
        $job = new PaidInvoiceJob($item->id);
        for ($i = 0; $i < 4; $i++) {
            try {
                $job->handle();
                $this->fail('Unverified provider order was activated.');
            } catch (RuntimeException $error) {
                $this->assertStringNotContainsString('private request details', $error->getMessage());
                $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
                $this->assertSame('submitted', ExtensionOperation::where('kind', 'sslstore-invite')->sole()->status);
            }
        }
        $job->handle();
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame([self::API . '/rest/order/inviteorder', ...array_fill(0, 4, self::API . '/rest/order/info')], $calls);
        $this->assertFalse(DebugLog::where('type', 'http')->exists());
    }

    public function test_certificate_provider_payloads_are_excluded_from_http_debug_logs(): void
    {
        [, $item] = $this->fixture();
        config(['settings.debug' => true]);
        Http::fake([
            self::API . '/rest/order/inviteorder' => fn (Request $request) => Http::response($this->reply($request['CustomOrderID'])),
            'https://ordinary.example.test/debug' => Http::response(['status' => 'ok']),
        ]);
        (new PaidInvoiceJob($item->id))->handle();
        Http::get('https://ordinary.example.test/debug');
        $log = DebugLog::where('type', 'http')->get();
        $this->assertCount(1, $log);
        $this->assertSame('https://ordinary.example.test/debug', $log->first()->context['url']);
    }

    public function test_disabled_ordering_leaves_provider_untouched(): void
    {
        [$service, $item] = $this->fixture(enabled: false);
        Http::fake();
        try {
            (new PaidInvoiceJob($item->id))->handle();
            $this->fail('Certificate ordering bypassed the explicit configuration.');
        } catch (RuntimeException) {
            $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
            $this->assertFalse(ExtensionOperation::where('kind', 'sslstore-invite')->exists());
            Http::assertNothingSent();
        }
    }

    public function test_provider_order_with_an_unexpected_enrollment_origin_is_held(): void
    {
        [$service, $item] = $this->fixture();
        Http::fake(fn (Request $request) => Http::response($this->reply($request['CustomOrderID'], 'https://untrusted.example.test/')));
        try {
            (new PaidInvoiceJob($item->id))->handle();
            $this->fail('Unsafe enrollment link was accepted.');
        } catch (RuntimeException) {
            $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
            $this->assertSame('submitted', ExtensionOperation::where('kind', 'sslstore-invite')->sole()->status);
            Http::assertSentCount(1);
        }
    }

    public function test_cart_checks_the_current_supplier_term_and_the_paid_job_refuses_a_changed_quote(): void
    {
        [$service, $item, $user] = $this->fixture();
        $cart = new Cart(['currency_code' => 'USD']);
        $cart->setRelation('user', $user);
        $cartItem = new CartItem(['quantity' => 1, 'checkout_config' => [SSLStoreOrders::QUOTE => 'untrusted']]);
        $cartItem->setRelation('cart', $cart)->setRelation('product', $service->product)->setRelation('plan', $service->plan);
        $prices = [['NumberOfMonths' => 12]];
        Http::fake(function (Request $request) use (&$prices) {
            $this->assertSame(self::API . '/rest/product/query', $request->url());
            $this->assertSame('synthetic_tls', $request['ProductCode']);

            return Http::response([[
                'AuthResponse' => ['IsError' => false],
                'CurrencyCode' => 'USD',
                'ProductCode' => 'synthetic_tls',
                'PricingInfo' => $prices,
            ]]);
        });
        $server = $service->product->server;
        $quoted = ExtensionHelper::call($server, 'validateCartItem', [$cartItem]);
        $this->assertSame($service->properties()->where('key', SSLStoreOrders::QUOTE)->value('value'), $quoted[SSLStoreOrders::QUOTE]);
        $prices = [['NumberOfMonths' => 24]];
        try {
            ExtensionHelper::call($server, 'validateCartItem', [$cartItem]);
            $this->fail('A withdrawn term was offered.');
        } catch (DisplayException) {
            $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        }
        $service->product->settings()->where('key', 'product_code')->update(['value' => 'changed_tls']);
        try {
            (new PaidInvoiceJob($item->id))->handle();
            $this->fail('A different product was ordered.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('checkout quote', $error->getMessage());
            $this->assertFalse(ExtensionOperation::where('kind', 'sslstore-invite')->exists());
            Http::assertSentCount(2);
        }
    }

    public function test_only_the_authorized_customer_can_download_the_issued_order(): void
    {
        [$service, $item, $user] = $this->fixture();
        $zip = "PK\x03\x04synthetic certificate archive";
        $current = null;
        $status = 'ACTIVE';
        Http::fake(function (Request $request) use (&$current, &$status, $zip) {
            if ($request->url() === self::API . '/rest/order/inviteorder') {
                $current = $request['CustomOrderID'];

                return Http::response($this->reply($current));
            }
            $this->assertSame('9017123', $request['TheSSLStoreOrderID']);
            if ($request->url() === self::API . '/rest/order/info') {
                $this->assertArrayNotHasKey('CustomOrderID', $request->data());
                $reply = $this->reply($current, enrolled: true);
                $reply['OrderStatus']['MajorStatus'] = $status;

                return Http::response($reply);
            }
            $this->assertSame(self::API . '/rest/order/downloadaszip', $request->url());

            return Http::response([
                'AuthResponse' => ['isError' => false], 'Zip' => base64_encode($zip),
                'PartnerOrderID' => 'synthetic-partner-order', 'ValidationStatus' => 'Issued',
            ]);
        });
        (new PaidInvoiceJob($item->id))->handle();
        $this->actingAs($user)->withSession($this->loginUser($user));
        $this->assertTrue(ExtensionHelper::callService($service->fresh(), 'refreshCertificateStatus'));
        $this->assertContains('downloadCertificate', array_column(ExtensionHelper::getActions($service->fresh()), 'function'));
        $download = ExtensionHelper::callService($service->fresh(), 'downloadCertificate');
        $response = $this->get($download)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($zip, $response->streamedContent());
        $this->assertStringNotContainsString('synthetic-link-token', json_encode(ExtensionHelper::getActions($service->fresh()), JSON_THROW_ON_ERROR));
        Http::assertSentCount(4);
        $status = 'CANCELLED';
        $this->assertTrue(ExtensionHelper::callService($service->fresh(), 'refreshCertificateStatus'));
        $status = 'ACTIVE';
        $this->get($download)->assertStatus(409);
        Http::assertSentCount(5);
        $other = User::factory()->create();
        $this->actingAs($other)->withSession($this->loginUser($other))->get($download)->assertNotFound();
        Http::assertSentCount(5);
    }

    public function test_reopened_payment_blocks_enrollment_even_during_the_provider_lookup(): void
    {
        [$service, $item, $user] = $this->fixture();
        $customId = null;
        Http::fake(function (Request $request) use ($item, &$customId) {
            if ($request->url() === self::API . '/rest/order/inviteorder') {
                $customId = $request['CustomOrderID'];
            } else {
                DB::table('invoices')->where('id', $item->invoice_id)->update(['status' => Invoice::STATUS_PENDING]);
            }

            return Http::response($this->reply($customId));
        });
        (new PaidInvoiceJob($item->id))->handle();
        $this->actingAs($user)->withSession($this->loginUser($user));
        $this->get(route('services.sslstore.enroll', $service))->assertStatus(409);
        $this->assertSame(Invoice::STATUS_PENDING, $item->invoice()->value('status'));
        $this->assertNotContains('openEnrollment', array_column(ExtensionHelper::getActions($service->fresh()), 'function'));
        Http::assertSentCount(2);
    }

    public function test_checkout_builds_a_bound_invoice_and_payment_provisions_its_service(): void
    {
        [$existing, $existingItem, $user] = $this->fixture(paid: false);
        $cart = $this->createCertificateCart($existing, $user);
        Http::fake([
            self::API . '/rest/product/query' => Http::response($this->availableOffer()),
            self::API . '/rest/order/inviteorder' => fn (Request $request) => Http::response($this->reply($request['CustomOrderID'])),
        ]);
        $this->checkout($cart);
        $invoice = Invoice::where('id', '!=', $existingItem->invoice_id)->sole();
        $item = $invoice->items()->where('reference_type', Service::class)->sole();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertSame(Service::STATUS_PENDING, $item->reference->status);
        Http::assertSentCount(1);
        ExtensionHelper::addPayment($invoice, null, (new InvoicePricing)->summary($invoice)->payable, transactionId: 'synthetic-cart-payment');
        $this->assertSame(Service::STATUS_ACTIVE, $item->reference->fresh()->status);
        $this->assertSame($item->id, ExtensionOperation::where('kind', 'sslstore-invite')->sole()->invoice_item_id);
        Http::assertSentCount(2);
    }

    public function test_checkout_rolls_back_if_the_sku_changes_after_supplier_verification(): void
    {
        [$existing, , $user] = $this->fixture(paid: false);
        $cart = $this->createCertificateCart($existing, $user);
        Http::fake([
            self::API . '/rest/product/query' => function () use ($existing) {
                $existing->product->settings()->where('key', 'product_code')->update(['value' => 'different_tls']);

                return Http::response($this->availableOffer());
            },
        ]);
        $this->checkout($cart);

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('services', 1);
        Http::assertSentCount(1);
    }

    public function test_payment_stays_recorded_if_the_quoted_provider_was_detached(): void
    {
        [$service, $item] = $this->fixture(paid: false);
        $service->product()->update(['server_id' => null]);
        Http::fake();
        Queue::fake([PaidInvoiceJob::class]);
        $invoice = $item->invoice;
        ExtensionHelper::addPayment($invoice, null, (new InvoicePricing)->summary($invoice)->payable, transactionId: 'synthetic-detached-provider');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        Queue::assertPushed(PaidInvoiceJob::class, fn (PaidInvoiceJob $job) => $job->invoiceItemId === $item->id);
        try {
            (new PaidInvoiceJob($item->id))->handle();
            $this->fail('The detached certificate still entered the generic service lifecycle.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('checkout provider', $error->getMessage());
            $this->assertSame(1, $invoice->transactions()->count());
            Http::assertNothingSent();
        }
    }

    private function createCertificateCart(Service $service, User $user): Cart
    {
        $this->actingAs($user)->withSession($this->loginUser($user));
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $cart->items()->create([
            'product_id' => $service->product_id, 'plan_id' => $service->plan_id, 'quantity' => 1,
            'config_options' => [], 'checkout_config' => [],
        ]);

        return $cart;
    }

    private function checkout(Cart $cart): void
    {
        class_exists(FeeGateway::class);
        $gateway = Gateway::create(['name' => 'Certificate test payment', 'extension' => 'FeeGateway', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'collection_enabled', 'value' => '1']);
        Once::flush();
        Livewire::withCookies(['cart' => $cart->ulid])->test(CheckoutCart::class)
            ->set('gateway', $gateway->id)->set('tos', true)->call('checkout')->assertHasNoErrors();
    }

    private function availableOffer(): array
    {
        return [[
            'AuthResponse' => ['isError' => false], 'CurrencyCode' => 'USD', 'ProductCode' => 'synthetic_tls',
            'PricingInfo' => [['NumberOfMonths' => 12]],
        ]];
    }
}
