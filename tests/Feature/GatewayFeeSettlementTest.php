<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Gateways\PaymentAttempts;
use App\Services\Gateways\PaymentWriteGuard;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\FeeGateway;
use Tests\TestCase;

class GatewayFeeSettlementTest extends TestCase
{
    use UsesCommittedDatabase;

    private function fixture(): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        class_exists(FeeGateway::class);
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.tax_scope' => 'all']);
        TaxRate::create(['name' => 'Synthetic tax', 'rate' => '7.1250', 'country' => 'all']);
        $user = User::factory()->create();
        $this->actingAs($user);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '107.13', 'quantity' => 1, 'tax_amount' => '7.13']);
        $g = $this->gateway();

        return [$invoice->fresh(), $g, $item];
    }

    private function gateway(): Gateway
    {
        $g = Gateway::create(['name' => 'Synthetic fee gateway ' . Gateway::count(), 'extension' => 'FeeGateway', 'type' => 'gateway', 'enabled' => true]);
        foreach (['collection_enabled' => '1', 'customer_fee_enabled' => '1', 'customer_fee_percent' => '2.5', 'customer_fee_fixed' => '0.25', 'customer_fee_currency' => 'USD'] as $key => $value) {
            $g->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $g;
    }

    private function begin(Invoice $i, Gateway $g): GatewayPaymentAttempt
    {
        return (new PaymentAttempts)->begin($g, $i, hash('sha256', 'synthetic merchant'), 'USD');
    }

    public function test_attempt_commits_one_untaxed_fee_and_replays_once(): void
    {
        [$i, $g, $item] = $this->fixture();
        $product = $this->createProduct();
        $service = Service::factory()->create(['status' => 'pending', 'user_id' => $i->user_id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'price' => '107.13']);
        $item->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        $a = $this->begin($i, $g);
        $this->assertSame('109.88', $a->amount);
        $this->assertSame(1, $i->items()->where('kind', 'gateway_fee')->count());
        $fee = $i->items()->where('kind', 'gateway_fee')->sole();
        $this->assertSame('0.00', $fee->tax_amount);
        $this->assertNull($fee->reference_id);
        $this->assertSame('2.75', $a->pricing_payload['gateway_fee']);
        $this->assertStringNotContainsString('109.88', DB::table('gateway_payment_attempts')->where('id', $a->id)->value('pricing_payload'));
        $ledger = new PaymentAttempts;
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, '109.88', 'USD', 'synthetic-payment-one');
        $due = $service->fresh()->expires_at->toDateString();
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, '109.88', 'USD', 'synthetic-payment-one');
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame(1, $i->transactions()->count());
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame($due, $service->fresh()->expires_at->toDateString());
        $this->assertSame('107.13', (string) $service->fresh()->getRawOriginal('price'));
        Http::assertNothingSent();
    }

    public function test_settings_change_reuses_frozen_attempt(): void
    {
        [$i, $g] = $this->fixture();
        $a = $this->begin($i, $g);
        $g->settings()->where('key', 'customer_fee_percent')->first()->update(['value' => '6.5']);
        $again = $this->begin($i->fresh(), $g->fresh());
        $this->assertSame($a->id, $again->id);
        $this->assertSame('109.88', $again->amount);
        $this->assertSame('2.5000', $again->pricing_payload['fee_policy']['percent']);
        $this->assertSame(1, $i->items()->where('kind', 'gateway_fee')->count());
    }

    public function test_new_legacy_claim_freezes_context_and_tax_without_changing_gross(): void
    {
        [$i, $g] = $this->fixture();
        $i->items()->delete();
        $i->items()->create(['description' => 'Legacy units', 'price' => '0.10', 'quantity' => 3]);
        DB::table('invoices')->where('id', $i->id)->update(['pricing_tax_rate' => null, 'pricing_tax_name' => null, 'pricing_tax_country' => null, 'pricing_tax_inclusive' => null]);
        DB::table('invoice_items')->where('invoice_id', $i->id)->update(['tax_amount' => null]);
        $a = $this->begin($i->fresh(), $g);
        $this->assertSame('7.1250', $i->fresh()->pricing_tax_rate);
        $this->assertFalse($i->fresh()->pricing_tax_inclusive);
        $this->assertSame('0.02', $i->items()->where('kind', 'product')->sole()->tax_amount);
        $this->assertSame('0.30', $a->pricing_payload['product_gross']);
        $this->assertSame('0.28', $a->pricing_payload['product_net']);
        TaxRate::where('country', 'all')->update(['rate' => '19.5000']);
        config(['settings.tax_enabled' => false, 'settings.tax_type' => 'inclusive']);
        $again = $this->begin($i->fresh(), $g);
        $this->assertSame($a->id, $again->id);
        $ledger = new PaymentAttempts;
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-legacy-frozen');
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-legacy-frozen');
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame(1, $i->transactions()->count());
    }

    public function test_exclusive_legacy_claim_settles_and_replays_with_snapshot_enabled(): void
    {
        [$i, $g] = $this->fixture();
        config(['settings.invoice_snapshot' => true]);
        DB::table('invoices')->where('id', $i->id)->update(['pricing_tax_rate' => null, 'pricing_tax_name' => null, 'pricing_tax_country' => null, 'pricing_tax_inclusive' => null]);
        DB::table('invoice_items')->where('invoice_id', $i->id)->update(['tax_amount' => null]);
        $a = $this->begin($i->fresh(), $g);
        $ledger = new PaymentAttempts;
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-legacy-replay');
        $ledger->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-legacy-replay');
        $this->assertSame(1, $i->transactions()->count());
        $this->assertSame('7.1250', $i->fresh()->snapshot->tax_rate);
    }

    public function test_stale_pricing_cannot_settle(): void
    {
        [$i, $g] = $this->fixture();
        $a = $this->begin($i, $g);
        DB::table('invoice_items')->where('invoice_id', $i->id)->where('kind', 'product')->update(['tax_amount' => '7.12']);
        $this->expectException(RuntimeException::class);
        (new PaymentAttempts)->settle($g, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-stale-payment');
    }

    public function test_claim_refuses_an_outer_transaction(): void
    {
        [$i, $g] = $this->fixture();
        DB::beginTransaction();
        try {
            $this->begin($i, $g);
            $this->fail('A provider claim could be erased by the outer transaction');
        } catch (RuntimeException) {
            $this->assertSame(0, GatewayPaymentAttempt::count());
            $this->assertSame(0, $i->items()->where('kind', 'gateway_fee')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_partial_credit_before_claim_reduces_the_fee_base(): void
    {
        [$i, $g] = $this->fixture();
        ExtensionHelper::addPayment($i, null, '57.13', isCreditTransaction: true);
        $a = $this->begin($i->fresh(), $g);
        $this->assertSame('51.42', $a->amount);
        $this->assertSame('1.42', $a->pricing_payload['gateway_fee']);
        $this->assertSame('46.67', $a->pricing_payload['unpaid_net']);
    }

    #[DataProvider('financialMutations')]
    public function test_claim_blocks_insert_delete_move_and_financial_edits(string $operation): void
    {
        [$i, $g, $item] = $this->fixture();
        $other = Invoice::factory()->create(['user_id' => $i->user_id]);
        $this->begin($i, $g);
        $this->expectException(RuntimeException::class);
        match ($operation) {
            'insert' => $i->items()->create(['description' => 'Inserted product', 'price' => '1.00', 'quantity' => 1]),
            'delete' => $item->delete(),
            'move' => $item->update(['invoice_id' => $other->id]),
            'price' => $item->update(['price' => '107.14']),
            'owner' => $i->update(['user_id' => User::factory()->create()->id]),
            'credit' => ExtensionHelper::addPayment($i, null, '1.00', isCreditTransaction: true),
        };
    }

    public static function financialMutations(): array
    {
        return array_map(fn ($v) => [$v], ['insert', 'delete', 'move', 'price', 'owner', 'credit']);
    }

    public function test_settlement_scope_cannot_allow_item_mutation_and_is_cleared_after_failure(): void
    {
        [$i, $g, $item] = $this->fixture();
        $a = $this->begin($i, $g);
        try {
            DB::transaction(fn () => (new PaymentWriteGuard)->duringSettlement($a, fn () => $item->update(['price' => '107.14'])));
            $this->fail('Settlement scope allowed item mutation');
        } catch (RuntimeException) {
            $this->assertSame('107.13', $item->fresh()->price);
        }
        $this->expectException(RuntimeException::class);
        $i->update(['status' => 'paid']);
    }

    public function test_legacy_initialized_attempt_never_acquires_a_retroactive_fee(): void
    {
        [$i, $g] = $this->fixture();
        $a = GatewayPaymentAttempt::create(['invoice_id' => $i->id, 'user_id' => $i->user_id, 'gateway_id' => $g->id, 'reference' => '123456789012345', 'merchant_fingerprint' => hash('sha256', 'synthetic merchant'), 'amount' => '107.13', 'currency_code' => 'USD', 'state' => 'initializing']);
        $this->assertSame($a->id, $this->begin($i, $g)->id);
        $this->assertSame(0, $i->items()->where('kind', 'gateway_fee')->count());
        $this->assertNull($a->fresh()->pricing_payload);
    }

    #[DataProvider('competingWrites')]
    public function test_cross_gateway_credit_and_item_mutations_serialize(string $operation): void
    {
        [$i, $g, $item] = $this->fixture();
        $second = $this->gateway();
        $credit = $i->user->credits()->create(['currency_code' => 'USD', 'amount' => '1.00']);
        $connection = config('database.connections.' . config('database.default'));
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/payment-write-worker.php'), DB::connection()->getDatabaseName(), $operation, (string) $i->id, (string) ($operation === 'gateway' ? $second->id : $item->id)], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => $connection['database'],
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_SOCKET' => $connection['unix_socket'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array',
        ]);
        $process->setTimeout(15);
        $started = false;
        DB::listen(function ($query) use (&$started, $process) {
            if (!$started && str_contains($query->sql, '`invoices`') && str_contains($query->sql, 'for update')) {
                $started = true;
                $process->start();
                $deadline = microtime(true) + 5;
                while (!str_contains($process->getOutput(), 'ready') && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $process->getOutput(), $process->getErrorOutput());
            }
        });
        try {
            $a = $this->begin($i, $g);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('blocked', $process->getOutput());
            $this->assertSame('109.88', $a->amount);
            $this->assertSame(1, GatewayPaymentAttempt::count());
            $this->assertSame('107.13', $item->fresh()->price);
            $this->assertSame('1.00', (string) $credit->fresh()->getRawOriginal('amount'));
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    public function test_cron_credit_attempt_never_debits_a_claimed_invoice(): void
    {
        [$i, $g] = $this->fixture();
        $credit = $i->user->credits()->create(['currency_code' => 'USD', 'amount' => '200.00']);
        $this->begin($i, $g);
        $method = new \ReflectionMethod(CronJob::class, 'payInvoiceWithCredits');
        $method->invoke(new CronJob, $i->fresh());
        $this->assertSame('200.00', (string) $credit->fresh()->getRawOriginal('amount'));
        $this->assertSame(0, $i->transactions()->count());
    }

    #[DataProvider('cronActions')]
    public function test_cron_preserves_service_and_invoice_with_external_attempt(string $status): void
    {
        [$i, $g, $item] = $this->fixture();
        Http::fake(['*' => Http::response([])]);
        $product = $this->createProduct();
        $service = Service::factory()->create(['status' => $status, 'user_id' => $i->user_id,
            'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'created_at' => now()->subDays(30), 'expires_at' => now()->subDays(30)]);
        $item->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        $this->begin($i, $g);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame($status, $service->fresh()->status);
        $this->assertSame('pending', $i->fresh()->status);
        Bus::assertNotDispatched(TerminateJob::class);
        Bus::assertNotDispatched(SuspendJob::class);
    }

    public function test_cron_preserves_upgrade_invoice_pricing_with_external_attempt(): void
    {
        [$i, $g, $item] = $this->fixture();
        Http::fake(['*' => Http::response([])]);
        $old = $this->createProduct();
        $new = $this->createProduct();
        $new->plan->prices()->first()->update(['price' => '25.00']);
        $service = Service::factory()->create(['status' => 'active', 'user_id' => $i->user_id,
            'product_id' => $old->product->id, 'plan_id' => $old->plan->id, 'expires_at' => now()->addDays(20)]);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'invoice_id' => $i->id,
            'product_id' => $new->product->id, 'plan_id' => $new->plan->id, 'status' => 'pending']);
        $item->update(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id]);
        $this->begin($i, $g);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame('107.13', $item->fresh()->price);
        $this->assertSame('2.75', $i->items()->where('kind', 'gateway_fee')->sole()->price);
    }

    public static function cronActions(): array
    {
        return [['pending'], ['active'], ['suspended']];
    }

    public static function competingWrites(): array
    {
        return [['edit'], ['credit'], ['gateway']];
    }
}
