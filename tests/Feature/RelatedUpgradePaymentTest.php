<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\FeeGateway;
use Tests\TestCase;

class RelatedUpgradePaymentTest extends TestCase
{
    use UsesCommittedDatabase;

    private function fixture(): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        class_exists(FeeGateway::class);
        config(['settings.tax_enabled' => false]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $old = $this->createProduct(['server_id' => null, 'stock' => null]);
        $new = $this->createProduct(['server_id' => null, 'stock' => null]);
        $old->plan->prices()->first()->update(['setup_fee' => '0.00']);
        $new->plan->prices()->first()->update(['price' => '20.00', 'setup_fee' => '0.00']);
        $service = Service::factory()->create(['status' => 'active', 'user_id' => $user->id, 'product_id' => $old->product->id, 'plan_id' => $old->plan->id, 'price' => '10.00', 'expires_at' => now()->addDays(10)]);
        $renewal = Invoice::factory()->create(['status' => 'pending', 'user_id' => $user->id]);
        $renewal->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        $invoice = Invoice::factory()->create(['status' => 'pending', 'user_id' => $user->id]);
        $upgrade = ServiceUpgrade::create(['status' => 'pending', 'service_id' => $service->id, 'product_id' => $new->product->id, 'plan_id' => $new->plan->id, 'invoice_id' => $invoice->id]);
        $invoice->items()->create(['description' => 'Upgrade', 'price' => '3.33', 'quantity' => 1, 'reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id]);
        $gateways = [];
        foreach (['Renewal method', 'Upgrade method'] as $name) {
            $g = Gateway::create(['name' => $name, 'extension' => 'FeeGateway', 'type' => 'gateway', 'enabled' => true]);
            $g->settings()->create(['key' => 'collection_enabled', 'value' => '1']);
            $gateways[] = $g;
        }

        return [$renewal, $invoice, $service, $upgrade, ...$gateways];
    }

    private function begin(Invoice $invoice, Gateway $gateway): GatewayPaymentAttempt
    {
        return (new PaymentAttempts)->begin($gateway, $invoice, hash('sha256', 'synthetic merchant'), 'USD');
    }

    public function test_upgrade_claim_is_refused_before_collection_when_renewal_is_claimed(): void
    {
        [$renewal, $invoice, $service, $upgrade, $g1, $g2] = $this->fixture();
        $this->begin($renewal, $g1);
        try {
            $this->begin($invoice, $g2);
            $this->fail('An upgrade could collect against a frozen renewal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('reconciliation', $e->getMessage());
        }
        $this->assertSame(1, GatewayPaymentAttempt::count());
        $this->assertSame('10.00', $renewal->items()->sole()->price);
        $this->assertSame('10.00', (string) $service->fresh()->getRawOriginal('price'));
        $this->assertSame('pending', $upgrade->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
        Http::assertNothingSent();
    }

    public function test_upgrade_claim_blocks_renewal_then_settles_once_and_reprices_it(): void
    {
        [$renewal, $invoice, $service, $upgrade, $g1, $g2] = $this->fixture();
        $a = $this->begin($invoice, $g2);
        try {
            $this->begin($renewal, $g1);
            $this->fail('A renewal could claim an amount awaiting a collected upgrade');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('reconciliation', $e->getMessage());
        }
        $ledger = new PaymentAttempts;
        $ledger->settle($g2, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-upgrade');
        $ledger->settle($g2, $a->reference, $a->merchant_fingerprint, $a->amount, 'USD', 'synthetic-upgrade');
        $this->assertSame('completed', $upgrade->fresh()->status);
        $this->assertSame('20.00', (string) $service->fresh()->getRawOriginal('price'));
        $this->assertSame('20.00', $renewal->items()->sole()->price);
        $this->assertSame(1, $invoice->transactions()->count());
        $renewalAttempt = $this->begin($renewal->fresh(), $g1);
        $this->assertSame('20.00', $renewalAttempt->amount);
        $ledger->settle($g1, $renewalAttempt->reference, $renewalAttempt->merchant_fingerprint, '20.00', 'USD', 'synthetic-renewal');
        $this->assertSame('paid', $renewal->fresh()->status);
        $this->assertSame(1, $renewal->transactions()->count());
    }

    public function test_related_claims_on_different_gateways_serialize_in_independent_processes(): void
    {
        [$renewal, $invoice,,, $g1, $g2] = $this->fixture();
        $c = config('database.connections.' . config('database.default'));
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/payment-write-worker.php'), DB::connection()->getDatabaseName(), 'gateway', (string) $invoice->id, (string) $g2->id], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => $c['database'], 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'],
            'DB_SOCKET' => $c['unix_socket'], 'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'], 'CACHE_STORE' => 'array',
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
            $this->begin($renewal, $g1);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('blocked', $process->getOutput());
            $this->assertSame(1, GatewayPaymentAttempt::count());
            $this->assertSame(0, $invoice->transactions()->count());
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}
