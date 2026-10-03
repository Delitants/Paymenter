<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\User;
use App\Policies\GatewayPolicy;
use App\Policies\UserPolicy;
use App\Services\Gateways\Operations\ManualSettlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

class AdminPaymentRetentionTest extends TestCase
{
    use UsesCommittedDatabase;

    private function fixture(bool $received = true, bool $quietUsers = false): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.tax_scope' => 'all']);
        TaxRate::create(['name' => 'Synthetic retention tax', 'rate' => '7.1250', 'country' => 'all']);
        $actorAttributes = ['role_id' => Role::create(['name' => 'Synthetic retention administrator', 'permissions' => ['*']])->id];
        $actor = $quietUsers ? User::factory()->createQuietly($actorAttributes) : User::factory()->create($actorAttributes);
        $this->actingAs($actor);
        $owner = $quietUsers ? User::factory()->createQuietly() : User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'status' => 'pending', 'pricing_tax_rate' => '7.1250']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '107.13', 'tax_amount' => '7.13', 'quantity' => 1]);
        $gateway = Gateway::create(['name' => 'Synthetic retained gateway', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
        $gateway->settings()->create(['key' => 'admin_payment_operations_enabled', 'value' => '0']);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'synthetic-api-key']);
        $gateway->settings()->create(['key' => 'stripe_webhook_secret', 'value' => 'synthetic-webhook-secret']);
        $transaction = $received ? $this->receipt($invoice, $gateway) : null;

        return [$actor, $owner, $invoice, $gateway, $transaction];
    }

    private function receipt(Invoice $invoice, Gateway $gateway): InvoiceTransaction
    {
        return $invoice->transactions()->create(['gateway_id' => $gateway->id, 'transaction_id' => 'pi_synthetic_retained', 'amount' => '53.57',
            'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => false]);
    }

    #[DataProvider('parentDeletes')]
    public function test_partial_native_receipt_survives_direct_parent_deletion(string $parent): void
    {
        [$actor, $owner, $invoice, $gateway, $transaction] = $this->fixture();
        $original = $transaction->fresh()->getAttributes();
        $this->assertSame(['net' => '50.00', 'tax' => '3.57', 'fee' => '0.00'], $transaction->original_allocation);
        $this->assertSame(0, PaymentOperation::count());
        $this->assertSame(0, GatewayPaymentAttempt::count());
        $this->assertFalse(InvoicePaidProcessing::whereKey($invoice->id)->exists());
        try {
            $record = $parent === 'user' ? $owner : (str_starts_with($parent, 'extension') ? Extension::findOrFail($gateway->id) : $gateway);
            if (str_contains($parent, 'mutated')) {
                $record->type = 'server';
            }
            str_ends_with($parent, 'force') ? $record->forceDelete() : $record->delete();
            $this->fail('Parent deletion discarded immutable native payment ancestry');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payment', strtolower($e->getMessage()));
        }
        $this->assertNotNull($owner->fresh());
        $this->assertNotNull($invoice->fresh());
        $this->assertNotNull($gateway->fresh());
        $this->assertSame($original, $transaction->fresh()->getAttributes());
        $this->assertFalse((new UserPolicy)->delete($actor, $owner));
        $this->assertFalse((new GatewayPolicy)->delete($actor, $gateway));
        Http::assertNothingSent();
    }

    public static function parentDeletes(): array
    {
        return [['user'], ['gateway-soft'], ['gateway-force'], ['extension-soft'], ['extension-force'], ['extension-mutated-soft'], ['extension-mutated-force']];
    }

    public function test_unprotected_user_and_gateway_can_still_be_deleted(): void
    {
        [$actor, $owner, $invoice, $gateway] = $this->fixture(false);
        $this->assertTrue((new UserPolicy)->delete($actor, $owner));
        $this->assertTrue((new GatewayPolicy)->delete($actor, $gateway));
        $owner->delete();
        $gateway->delete();
        $this->assertNull($owner->fresh());
        $this->assertNull($invoice->fresh());
        $this->assertTrue(Gateway::withTrashed()->findOrFail($gateway->id)->trashed());
        $gateway->forceDelete();
        $this->assertNull(Gateway::withTrashed()->find($gateway->id));
    }

    #[DataProvider('racingParents')]
    public function test_independent_parent_delete_waits_for_native_receipt_and_preserves_evidence(string $parent): void
    {
        [, $owner, $invoice, $gateway] = $this->fixture(false);
        $worker = tempnam(sys_get_temp_dir(), 'paymenter-parent-delete-');
        file_put_contents($worker, '<?php
require $argv[1]."/vendor/autoload.php";
$app=require $argv[1]."/bootstrap/app.php";
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (!app()->environment("testing") || \\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName() !== $argv[2] || !str_ends_with($argv[2], "_test") || config("mail.default") !== "array") { throw new RuntimeException("Unsafe synthetic parent worker"); }
\\Illuminate\\Support\\Facades\\Http::preventStrayRequests();
$record=$argv[3] === "user" ? \\App\\Models\\User::findOrFail($argv[4]) : \\App\\Models\\Gateway::findOrFail($argv[4]);
echo "ready\\n"; flush();
try { $argv[3] === "gateway-force" ? $record->forceDelete() : $record->delete(); echo "deleted\\n"; }
catch (RuntimeException $e) { if (!str_contains(strtolower($e->getMessage()), "payment")) { throw $e; } echo "blocked\\n"; }
');
        $connection = config('database.connections.' . config('database.default'));
        $process = new Process([PHP_BINARY, $worker, base_path(), DB::connection()->getDatabaseName(), $parent, (string) ($parent === 'user' ? $owner->id : $gateway->id)], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_SOCKET' => $connection['unix_socket'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array',
        ]);
        $process->setTimeout(20);
        try {
            $transaction = DB::transaction(function () use ($invoice, $gateway, $process) {
                Gateway::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
                Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $deadline = microtime(true) + 5;
                while (!str_contains($process->getOutput(), 'ready') && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $process->getOutput(), $process->getErrorOutput());
                usleep(150000);
                $this->assertTrue($process->isRunning(), 'Parent delete did not wait for the held native payment locks');
                $this->assertStringNotContainsString('deleted', $process->getOutput());

                return $this->receipt($invoice, $gateway);
            });
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('blocked', $process->getOutput());
            $this->assertStringNotContainsString('deleted', $process->getOutput());
            $this->assertSame(['net' => '50.00', 'tax' => '3.57', 'fee' => '0.00'], $transaction->fresh()->original_allocation);
            $this->assertSame($gateway->id, $transaction->fresh()->gateway_id);
            $this->assertNotNull($owner->fresh());
            $this->assertNotNull($invoice->fresh());
            $this->assertNotNull($gateway->fresh());
        } finally {
            $process->stop();
            unlink($worker);
        }
    }

    public static function racingParents(): array
    {
        return [['user'], ['gateway-soft'], ['gateway-force']];
    }

    public function test_allocation_only_receipt_refuses_migration_down_before_schema_or_evidence_changes(): void
    {
        [, , $invoice, , $transaction] = $this->fixture();
        $original = $transaction->fresh()->getAttributes();
        $this->assertSame(0, PaymentOperation::count());
        $this->assertSame(0, InvoicePaidProcessing::count());
        $this->assertSame(0, GatewayPaymentAttempt::count());
        $migration = require database_path('migrations/2026_10_01_000001_create_admin_payment_operations.php');
        try {
            $migration->down();
            $this->fail('Rollback destroyed allocation-only received-payment evidence');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('history', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('payment_operations'));
        $this->assertTrue(Schema::hasTable('invoice_paid_processings'));
        $this->assertTrue(Schema::hasColumns('invoice_transactions', ['original_allocation', 'settlement_origin', 'settlement_state']));
        $this->assertSame($original, $transaction->fresh()->getAttributes());
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    #[DataProvider('deductionIdentityChanges')]
    public function test_processor_deduction_refuses_changed_explicit_original_identity(string $field): void
    {
        [, , , , $transaction] = $this->fixture();
        $transaction->forceFill([$field => $field === 'amount' ? '53.58' : 'different-provider-payment']);
        try {
            ExtensionHelper::addPaymentFee('pi_synthetic_retained', '1.75', $transaction);
            $this->fail('Processor deduction accepted changed original identity');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('identity', strtolower($e->getMessage()));
        }
        $this->assertNull($transaction->fresh()->fee);
    }

    public static function deductionIdentityChanges(): array
    {
        return [['amount'], ['transaction_id']];
    }

    public function test_processor_deduction_refuses_ambiguous_provider_reference(): void
    {
        [, $owner, , $gateway, $transaction] = $this->fixture();
        $other = Invoice::factory()->create(['user_id' => $owner->id, 'status' => 'pending', 'pricing_tax_rate' => '7.1250']);
        InvoiceItem::factory()->create(['invoice_id' => $other->id, 'price' => '107.13', 'tax_amount' => '7.13', 'quantity' => 1]);
        $second = $this->receipt($other, $gateway);
        try {
            ExtensionHelper::addPaymentFee('pi_synthetic_retained', '1.75');
            $this->fail('Ambiguous original provider reference accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ambiguous', strtolower($e->getMessage()));
        }
        $this->assertNull($transaction->fresh()->fee);
        $this->assertNull($second->fresh()->fee);
    }

    #[DataProvider('processingOutcomes')]
    public function test_signed_stripe_processing_blocks_manual_money_until_terminal_callback(bool $success): void
    {
        [$actor, , $invoice, $gateway] = $this->fixture(false);
        $gateway->settings()->where('key', 'admin_payment_operations_enabled')->first()->update(['value' => '1']);
        $stripe = (new Stripe(['stripe_webhook_secret' => 'synthetic-webhook-secret', 'stripe_secret_key' => 'synthetic-api-key']))->bindRecord($gateway);
        $object = ['id' => 'pi_synthetic_processing', 'amount' => 10713, 'currency' => 'usd', 'metadata' => ['invoice_id' => $invoice->id]];
        $this->webhook($stripe, 'payment_intent.processing', $object);
        $native = $invoice->transactions()->sole();
        $this->assertSame(InvoiceTransactionStatus::Processing, $native->status);
        $this->assertSame(0, GatewayPaymentAttempt::count());
        $refused = false;
        try {
            (new ManualSettlements)->record($actor, $invoice->fresh(), $gateway, '40.00', 'synthetic-bank-processing', 'Bank receipt review', '2026-10-01T12:00:00Z', (string) Str::uuid());
        } catch (RuntimeException $e) {
            $refused = true;
            $this->assertStringContainsString('reconciliation', $e->getMessage());
        }
        $this->assertTrue($refused, 'Signed native processing allowed overlapping manual money');
        $this->assertSame(0, PaymentOperation::count());
        $this->assertSame(0, InvoicePaidProcessing::count());
        $this->webhook($stripe, $success ? 'payment_intent.succeeded' : 'payment_intent.payment_failed', $object);
        $this->assertSame($native->id, $invoice->transactions()->sole()->id);
        $this->assertSame($success ? InvoiceTransactionStatus::Succeeded : InvoiceTransactionStatus::Failed, $native->fresh()->status);
        $this->assertNull($native->fresh()->settlement_origin);
        if (!$success) {
            (new ManualSettlements)->record($actor, $invoice->fresh(), $gateway, '107.13', 'synthetic-bank-processing', 'Bank receipt verified', '2026-10-01T12:00:00Z', (string) Str::uuid());
        }
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, InvoicePaidProcessing::count());
        Http::assertNothingSent();
    }

    public static function processingOutcomes(): array
    {
        return [[true], [false]];
    }

    private function webhook(Stripe $stripe, string $type, array $object): mixed
    {
        $body = json_encode(['type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $timestamp = 'synthetic-timestamp';
        $request = Request::create('/synthetic-stripe-webhook', 'POST', server: ['HTTP_STRIPE_SIGNATURE' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, 'synthetic-webhook-secret')], content: $body);

        return $stripe->webhook($request);
    }

    #[DataProvider('deletedGateways')]
    public function test_signed_stripe_receipt_refuses_deleted_authenticated_gateway_without_counting_funds(bool $force): void
    {
        [, , $invoice, $gateway] = $this->fixture(false);
        $stripe = (new Stripe(['stripe_webhook_secret' => 'synthetic-webhook-secret', 'stripe_secret_key' => 'synthetic-api-key']))->bindRecord($gateway);
        $force ? $gateway->forceDelete() : $gateway->delete();
        try {
            $this->webhook($stripe, 'payment_intent.succeeded', ['id' => 'pi_synthetic_retained', 'amount' => 5357, 'currency' => 'usd', 'metadata' => ['invoice_id' => $invoice->id]]);
            $this->fail('Signed native receipt lost its deleted authenticated gateway identity');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('identity', strtolower($e->getMessage()));
        }
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertFalse(InvoicePaidProcessing::whereKey($invoice->id)->exists());
        $this->assertSame(0, PaymentOperation::count());
        Http::assertNothingSent();
    }

    public static function deletedGateways(): array
    {
        return [[false], [true]];
    }

    public function test_signed_stripe_late_processor_fee_and_replay_preserve_native_original_with_admin_disabled(): void
    {
        [, , $invoice, $gateway] = $this->fixture(false);
        $stripe = (new Stripe(['stripe_webhook_secret' => 'synthetic-webhook-secret', 'stripe_secret_key' => 'synthetic-api-key']))->bindRecord($gateway);
        $this->webhook($stripe, 'payment_intent.succeeded', ['id' => 'pi_synthetic_retained', 'amount' => 5357, 'currency' => 'usd', 'metadata' => ['invoice_id' => $invoice->id]]);
        $transaction = $invoice->transactions()->sole();
        $original = $transaction->getAttributes();
        $this->assertSame(['net' => '50.00', 'tax' => '3.57', 'fee' => '0.00'], $transaction->original_allocation);
        $this->assertSame('0', $gateway->settings()->where('key', 'admin_payment_operations_enabled')->value('value'));
        Http::fake(['https://api.stripe.com/v1/balance_transactions/txn_synthetic_fee' => Http::response(['id' => 'txn_synthetic_fee', 'source' => 'ch_synthetic_fee', 'amount' => 5357, 'currency' => 'usd', 'fee' => 175])]);
        $charge = ['id' => 'ch_synthetic_fee', 'payment_intent' => 'pi_synthetic_retained', 'amount' => 5357, 'currency' => 'usd', 'balance_transaction' => 'txn_synthetic_fee'];
        $this->webhook($stripe, 'charge.updated', $charge);
        $this->webhook($stripe, 'charge.updated', $charge);
        $fresh = $transaction->fresh();
        $this->assertSame('1.75', $fresh->fee);
        foreach (['invoice_id', 'gateway_id', 'transaction_id', 'amount', 'status', 'is_credit_transaction', 'settlement_origin', 'settlement_state', 'original_allocation'] as $key) {
            $this->assertSame($original[$key], $fresh->getAttributes()[$key]);
        }
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(0, PaymentOperation::count());
        Http::assertSentCount(2);
        try {
            $fresh->update(['fee' => '2.00']);
            $this->fail('Ordinary writes bypassed immutable original metadata');
        } catch (RuntimeException) {
            $this->assertSame('1.75', $transaction->fresh()->fee);
        }
    }

    public function test_authenticated_fx_processor_metadata_is_acknowledged_explicitly_without_relabelling_or_changing_prior_fee(): void
    {
        [, , $invoice, $gateway, $transaction] = $this->fixture(quietUsers: true);
        ExtensionHelper::addPaymentFee($transaction->transaction_id, '1.75');
        $original = $transaction->fresh()->getAttributes();
        $stripe = (new Stripe(['stripe_webhook_secret' => 'synthetic-webhook-secret', 'stripe_secret_key' => 'synthetic-api-key']))->bindRecord($gateway);
        Log::spy();
        Http::fake(['https://api.stripe.com/v1/balance_transactions/txn_synthetic_fx' => Http::response([
            'id' => 'txn_synthetic_fx', 'source' => 'ch_synthetic_fx', 'amount' => 5000, 'currency' => 'eur', 'fee' => 150, 'exchange_rate' => 0.933358223,
        ])]);
        $charge = ['id' => 'ch_synthetic_fx', 'payment_intent' => $transaction->transaction_id, 'amount' => 5357, 'currency' => 'usd', 'balance_transaction' => 'txn_synthetic_fx'];
        foreach ([1, 2] as $replay) {
            $response = $this->webhook($stripe, 'charge.updated', $charge);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['received' => true, 'outcome_code' => 'unsupported_processor_fee_currency'], $response->getData(true));
            $this->assertSame($original, $transaction->fresh()->getAttributes());
        }
        Log::shouldHaveReceived('notice')->twice()->with('Stripe processor fee metadata is unsupported.', [
            'outcome_code' => 'unsupported_processor_fee_currency', 'original_transaction_id' => $transaction->id, 'gateway_id' => $gateway->id,
        ]);
        $this->assertSame('1.75', $transaction->fresh()->fee);
        $this->assertSame(['net' => '50.00', 'tax' => '3.57', 'fee' => '0.00'], $transaction->fresh()->original_allocation);
        $this->assertSame('0', $gateway->settings()->where('key', 'admin_payment_operations_enabled')->value('value'));
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, PaymentOperation::count());
        Http::assertSentCount(2);
    }

    #[DataProvider('fxLinkageChanges')]
    public function test_fx_processor_metadata_still_refuses_unlinked_authenticated_balance(string $field): void
    {
        [, , , $gateway, $transaction] = $this->fixture(quietUsers: true);
        $original = $transaction->fresh()->getAttributes();
        $stripe = (new Stripe(['stripe_webhook_secret' => 'synthetic-webhook-secret', 'stripe_secret_key' => 'synthetic-api-key']))->bindRecord($gateway);
        $balance = ['id' => 'txn_synthetic_fx', 'source' => 'ch_synthetic_fx', 'amount' => 5000, 'currency' => 'eur', 'fee' => 150, 'exchange_rate' => 0.933358223];
        $balance[$field] = 'different-synthetic-link';
        Http::fake(['https://api.stripe.com/v1/balance_transactions/txn_synthetic_fx' => Http::response($balance)]);
        $refused = false;
        try {
            $this->webhook($stripe, 'charge.updated', ['id' => 'ch_synthetic_fx', 'payment_intent' => $transaction->transaction_id, 'amount' => 5357, 'currency' => 'usd', 'balance_transaction' => 'txn_synthetic_fx']);
        } catch (RuntimeException $e) {
            $refused = true;
            $this->assertStringContainsString('identity', strtolower($e->getMessage()));
        }
        $this->assertTrue($refused, 'FX handling waived original balance linkage verification');
        $this->assertSame($original, $transaction->fresh()->getAttributes());
    }

    public static function fxLinkageChanges(): array
    {
        return [['id'], ['source']];
    }

    #[DataProvider('chargeIdentityChanges')]
    public function test_signed_stripe_deduction_refuses_mismatched_charge_before_http(string $field): void
    {
        [, , $invoice, $gateway, $transaction] = $this->fixture();
        $stripe = (new Stripe(['stripe_webhook_secret' => 'synthetic-webhook-secret', 'stripe_secret_key' => 'synthetic-api-key']))->bindRecord($gateway);
        $charge = ['id' => 'ch_synthetic_fee', 'payment_intent' => $transaction->transaction_id, 'amount' => 5357, 'currency' => 'usd', 'balance_transaction' => 'txn_synthetic_fee'];
        if ($field === 'gateway') {
            $other = Gateway::create(['name' => 'Different synthetic Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
            $stripe->bindRecord($other);
        } else {
            $charge[$field] = $field === 'amount' ? 5358 : 'eur';
        }
        Http::fake(['https://api.stripe.com/v1/balance_transactions/txn_synthetic_fee' => Http::response(['fee' => 175])]);
        try {
            $this->webhook($stripe, 'charge.updated', $charge);
            $this->fail('Stripe fee accepted mismatched original payment identity');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('identity', strtolower($e->getMessage()));
        }
        $this->assertNull($transaction->fresh()->fee);
        $this->assertSame('pending', $invoice->fresh()->status);
        Http::assertNothingSent();
    }

    public static function chargeIdentityChanges(): array
    {
        return [['amount'], ['currency'], ['gateway']];
    }
}
