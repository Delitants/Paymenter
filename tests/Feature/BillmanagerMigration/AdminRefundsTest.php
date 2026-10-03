<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Currency;
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
use App\Policies\InvoicePolicy;
use App\Services\Billing\InvoicePricing;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\Operations\GatewayOperations;
use App\Services\Gateways\Operations\ManualSettlements;
use App\Services\Gateways\Operations\OperationResult;
use App\Services\Gateways\Operations\ProviderOperations;
use App\Services\Gateways\Operations\RefundAllocation;
use App\Services\Gateways\Operations\Refunds;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

class AdminRefundsTest extends TestCase
{
    use UsesCommittedDatabase;

    protected function fixture(bool $native = false): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.tax_scope' => 'all']);
        TaxRate::create(['name' => 'Synthetic refund tax', 'rate' => '7.1250', 'country' => 'all']);
        $role = Role::create(['name' => 'Synthetic refund administrator', 'permissions' => ['*']]);
        $actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($actor);
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'pricing_tax_rate' => '7.1250']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '107.13', 'quantity' => 1, 'tax_amount' => '7.13']);
        $gateway = Gateway::create(['name' => 'Synthetic refund method', 'type' => 'gateway', 'extension' => 'Wave', 'enabled' => false]);
        $gateway->settings()->create(['key' => 'admin_payment_operations_enabled', 'value' => '1']);
        $invoice->items()->create(['description' => 'Synthetic fee', 'kind' => 'gateway_fee', 'gateway_id' => $gateway->id, 'price' => '2.75', 'quantity' => 1, 'tax_amount' => '0.00']);
        if ($native) {
            $transaction = $invoice->transactions()->create(['gateway_id' => $gateway->id, 'transaction_id' => 'synthetic-native-payment', 'amount' => '109.88', 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => false]);

            return [$actor, $invoice->fresh(), $gateway, $transaction];
        }
        $receipt = (new ManualSettlements)->record($actor, $invoice->fresh(), $gateway, '109.88', 'synthetic-original', 'Received externally', '2026-10-01T12:00:00Z', (string) Str::uuid());

        return [$actor, $invoice->fresh(), $gateway, $receipt->resultTransaction];
    }

    protected function external(User $actor, InvoiceTransaction $transaction, string $amount, bool $fee = true, ?string $key = null, string $reference = 'synthetic-refund'): PaymentOperation
    {
        return (new Refunds)->recordExternal($actor, $transaction, $amount, $fee, $reference, 'Refund issued externally', '2026-10-01T13:00:00Z', $key ?? (string) Str::uuid());
    }

    public function test_full_external_refund_preserves_received_funds_original_identity_and_paid_state(): void
    {
        [$actor, $invoice, , $transaction] = $this->fixture();
        $original = $transaction->getAttributes();
        $key = (string) Str::uuid();
        $operation = $this->external($actor, $transaction, '109.88', key: $key);
        $this->assertSame($operation->id, $this->external($actor, $transaction, '109.88', key: $key)->id);
        $this->assertSame(['net' => '100.00', 'tax' => '7.13', 'fee' => '2.75'], $operation->payload['allocation']);
        $this->assertSame('external_refund', $operation->kind);
        $this->assertSame('succeeded', $operation->state);
        $this->assertSame($actor->id, $operation->actor_id);
        $this->assertSame('synthetic-refund', $operation->payload['reference']);
        $this->assertSame($original, $transaction->fresh()->getAttributes());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame('109.88', $transaction->fresh()->refunded_amount);
        Http::assertNothingSent();
    }

    public function test_partial_product_refunds_use_remaining_original_tax_and_leave_fee_untaxed(): void
    {
        [$actor, , , $transaction] = $this->fixture();
        $first = $this->external($actor, $transaction, '53.57', false);
        $this->assertSame(['net' => '50.00', 'tax' => '3.57', 'fee' => '0.00'], $first->payload['allocation']);
        $second = $this->external($actor, $transaction, '53.56', false, reference: 'synthetic-second');
        $this->assertSame(['net' => '50.00', 'tax' => '3.56', 'fee' => '0.00'], $second->payload['allocation']);
        $fee = $this->external($actor, $transaction, '2.75', true, reference: 'synthetic-fee');
        $this->assertSame(['net' => '0.00', 'tax' => '0.00', 'fee' => '2.75'], $fee->payload['allocation']);
    }

    public function test_fee_exclusion_and_exhaustion_reject_overrefund_without_new_history(): void
    {
        [$actor, , , $transaction] = $this->fixture();
        foreach ([['109.88', false], ['109.89', true], ['0', true], ['1.001', true]] as [$amount, $fee]) {
            try {
                $this->external($actor, $transaction, $amount, $fee);
                $this->fail('Invalid refund was accepted');
            } catch (\RuntimeException) {
                $this->assertSame(1, PaymentOperation::count());
            }
        }
    }

    public function test_original_allocation_survives_later_invoice_price_changes(): void
    {
        [$actor, $invoice, , $transaction] = $this->fixture();
        $this->external($actor, $transaction, '53.57', false);
        $invoice->items()->where('kind', 'product')->sole()->update(['price' => '999.99', 'tax_amount' => '99.99']);
        $quote = (new RefundAllocation)->quote($transaction, '53.56', false);
        $this->assertSame(['net' => '50.00', 'tax' => '3.56', 'fee' => '0.00'], $quote['allocation']);
    }

    public function test_refund_requires_current_dedicated_permission_and_source_hold_clearance(): void
    {
        [$actor, $invoice, , $transaction] = $this->fixture();
        Role::findOrFail($actor->role_id)->update(['permissions' => ['admin.invoices.update']]);
        try {
            $this->external($actor, $transaction, '1.00');
            $this->fail('Customer ownership permission authorized a refund');
        } catch (AuthorizationException) {
            $this->assertSame(1, PaymentOperation::count());
        }
        Role::findOrFail($actor->role_id)->update(['permissions' => ['*']]);
        DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $invoice->id, 'reason' => 'Synthetic refund hold']);
        $this->expectException(MigrationHeldException::class);
        $this->external($actor, $transaction, '1.00');
    }

    public function test_request_drift_and_duplicate_external_reference_are_rejected(): void
    {
        [$actor, , , $transaction] = $this->fixture();
        $key = (string) Str::uuid();
        $this->external($actor, $transaction, '10.00', key: $key);
        foreach ([fn () => $this->external($actor, $transaction, '11.00', key: $key), fn () => $this->external($actor, $transaction, '10.00')] as $write) {
            try {
                $write();
                $this->fail('Changed request or duplicate refund reference accepted');
            } catch (\RuntimeException) {
                $this->assertSame(2, PaymentOperation::count());
            }
        }
    }

    protected function adapter(): object
    {
        $adapter = new class implements Adapter
        {
            public string $mode = 'pending';

            public bool $interruptQueued = false;

            public string $merchant = 'synthetic-merchant';

            public ?string $omitEvidence = null;

            public string $credentialVersion = 'synthetic-version-1';

            public bool $callbackDuringWrite = false;

            public bool $callbackBlocked = false;

            public int $writes = 0;

            public int $reads = 0;

            public function capabilities(): array
            {
                return ['refund' => true, 'capture' => true, 'reconcile' => true];
            }

            public function fingerprint(): string
            {
                if ($this->interruptQueued && PaymentOperation::where('state', 'queued')->exists()) {
                    throw new \RuntimeException('Synthetic interruption before execution');
                }

                return hash('sha256', $this->merchant . ':' . $this->credentialVersion);
            }

            public function prepare(Invoice $invoice, ?InvoiceTransaction $transaction, string $kind, string $providerReference, string $amount, string $currency): array
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Read-only preparation ran under locks');
                }
                $attempt = GatewayPaymentAttempt::where('invoice_id', $invoice->id)->where('state', 'open')->first();

                return ['authenticated' => true, 'merchant' => $this->merchant, 'environment' => 'synthetic', 'original_reference' => $providerReference,
                    'provider_object_type' => $transaction ? 'payment' : 'authorization', 'original_amount' => $transaction?->amount ?? $amount,
                    'amount' => $amount, 'currency' => $currency, 'invoice_id' => $invoice->id, 'gateway_id' => $transaction?->gateway_id ?? $attempt?->gateway_id,
                    'transaction_id' => $transaction?->id, 'already_refunded' => '0.00', 'attempt_id' => $attempt?->id, 'attempt_reference' => $attempt?->reference,
                    'merchant_fingerprint' => $attempt?->merchant_fingerprint];
            }

            public function execute(PaymentOperation $operation): OperationResult
            {
                if (DB::transactionLevel() !== 0 || PaymentOperation::findOrFail($operation->id)->state !== 'processing') {
                    throw new \LogicException('Write lacks durable claim');
                }
                $this->writes++;
                if ($this->callbackDuringWrite) {
                    $context = $operation->payload['provider_context'];
                    try {
                        (new PaymentAttempts)->settle($operation->gateway, $context['attempt_reference'], $context['merchant_fingerprint'], $operation->amount, $operation->currency_code, 'synthetic-provider-operation');
                    } catch (\RuntimeException $e) {
                        if (!str_contains($e->getMessage(), 'capture')) {
                            throw $e;
                        }
                        $this->callbackBlocked = true;
                    }
                }
                if ($this->mode === 'throw') {
                    throw new \RuntimeException('Synthetic lost provider response');
                }

                return new OperationResult('pending', 'synthetic-provider-operation');
            }

            public function reconcile(PaymentOperation $operation): OperationResult
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Readback ran under locks');
                }
                $this->reads++;
                $evidence = $operation->payload['provider_context'] + ['request_key' => $operation->request_key];
                $evidence['authenticated'] = true;
                $evidence['merchant'] = $this->merchant;
                $evidence['provider_reference'] = 'synthetic-provider-operation';
                $evidence['raw_provider_body'] = 'synthetic-private-provider-body';
                if ($this->omitEvidence !== null) {
                    unset($evidence[$this->omitEvidence]);
                }
                if ($this->mode === 'mismatch') {
                    $evidence['currency'] = 'EUR';
                }
                if ($this->mode === 'failed') {
                    $evidence['failure_proven'] = true;
                }

                return new OperationResult(in_array($this->mode, ['succeeded', 'mismatch'], true) ? 'succeeded' : ($this->mode === 'failed' ? 'failed' : 'pending'), 'synthetic-provider-operation', $evidence, 'synthetic_readback');
            }
        };
        $factory = new class($adapter) extends GatewayOperations
        {
            public function __construct(private Adapter $adapter) {}

            public function for(Gateway $gateway): Adapter
            {
                return $this->adapter;
            }
        };
        app()->instance(GatewayOperations::class, $factory);

        return $adapter;
    }

    protected function submit(User $actor, InvoiceTransaction $transaction, string $amount = '53.57', ?string $key = null): PaymentOperation
    {
        return (new Refunds)->submit($actor, $transaction, $amount, false, 'Provider refund requested', $key ?? (string) Str::uuid());
    }

    public function test_committed_queued_refund_recovers_by_identical_replay_once(): void
    {
        [$actor, , , $transaction] = $this->fixture(true);
        $adapter = $this->adapter();
        $adapter->interruptQueued = true;
        $key = (string) Str::uuid();
        try {
            $this->submit($actor, $transaction, key: $key);
            $this->fail('The pre-execution interruption was not reached');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic interruption before execution', $e->getMessage());
        }
        $claim = PaymentOperation::sole();
        $this->assertSame('queued', $claim->state);
        $this->assertSame(0, $adapter->writes);
        $identity = $claim->only(['actor_id', 'actor_snapshot', 'request_key', 'request_fingerprint', 'payload', 'amount', 'reason', 'effective_at']);
        $adapter->interruptQueued = false;
        $adapter->mode = 'succeeded';
        $result = $this->submit($actor, $transaction, key: $key);
        $this->assertSame('succeeded', $result->state);
        $this->assertSame($claim->id, $result->id);
        $this->assertEquals($identity, $result->only(array_keys($identity)));
        $this->submit($actor, $transaction, key: $key);
        $this->assertSame(1, $adapter->writes);
        $this->assertSame('53.57', $transaction->fresh()->refunded_amount);
    }

    public function test_queued_refund_waits_for_other_unknown_refund_on_original_payment(): void
    {
        [$actor, , , $transaction] = $this->fixture(true);
        $adapter = $this->adapter();
        $adapter->interruptQueued = true;
        $key = (string) Str::uuid();
        try {
            $this->submit($actor, $transaction, key: $key);
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic interruption before execution', $e->getMessage());
        }
        $queued = PaymentOperation::sole();
        $this->assertSame('queued', $queued->state);
        $adapter->interruptQueued = false;
        $adapter->mode = 'throw';
        $unknown = $this->submit($actor, $transaction, '1.00');
        $this->assertSame('uncertain', $unknown->state);
        $this->assertSame(1, $adapter->writes);
        $adapter->mode = 'succeeded';
        foreach (['processing', 'uncertain'] as $state) {
            DB::table('payment_operations')->where('id', $unknown->id)->update(['state' => $state]);
            $refused = false;
            try {
                $this->submit($actor, $transaction, key: $key);
            } catch (\RuntimeException $e) {
                $refused = true;
                $this->assertStringContainsString('reconciliation', $e->getMessage());
            }
            $this->assertTrue($refused, 'Queued refund ignored another unknown original-payment write');
            $this->assertSame('queued', $queued->fresh()->state);
            $this->assertSame(1, $adapter->writes);
        }
        $adapter->mode = 'failed';
        $this->assertSame('failed', (new ProviderOperations)->reconcile($actor, $unknown->fresh())->state);
        $adapter->mode = 'succeeded';
        $this->assertSame('succeeded', $this->submit($actor, $transaction, key: $key)->state);
        $this->assertSame(2, $adapter->writes);
        $this->assertSame('53.57', $transaction->fresh()->refunded_amount);
    }

    public function test_pending_refund_is_reserved_replay_never_resubmits_and_readback_completes_once(): void
    {
        [$actor, $invoice, , $transaction] = $this->fixture(true);
        $adapter = $this->adapter();
        $key = (string) Str::uuid();
        $operation = $this->submit($actor, $transaction, key: $key);
        $this->assertSame('pending', $operation->state);
        $this->assertSame($operation->id, $this->submit($actor, $transaction, key: $key)->id);
        $this->assertSame(1, $adapter->writes);
        $this->assertSame('53.56', (new RefundAllocation)->quote($transaction, '53.56', false)['remaining']);
        $adapter->mode = 'succeeded';
        $done = (new ProviderOperations)->reconcile($actor, $operation);
        $this->assertSame('succeeded', $done->state);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(1, $adapter->writes);
    }

    public function test_processor_deduction_refuses_manual_recorded_original_without_changing_allocation(): void
    {
        [, , , $transaction] = $this->fixture();
        $original = $transaction->fresh()->getAttributes();
        try {
            ExtensionHelper::addPaymentFee($transaction->transaction_id, '1.75');
            $this->fail('Manually recorded funds became original processor-payment evidence');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('native', strtolower($e->getMessage()));
        }
        $this->assertSame($original, $transaction->fresh()->getAttributes());
    }

    public function test_pending_provider_refund_retains_gateway_and_accepts_late_processor_deduction(): void
    {
        [$actor, $invoice, $gateway, $transaction] = $this->fixture(true);
        $adapter = $this->adapter();
        $operation = $this->submit($actor, $transaction);
        $identity = $operation->payload['original_identity'];
        try {
            $gateway->delete();
            $this->fail('Gateway deletion stranded a pending provider refund');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('payment', strtolower($e->getMessage()));
        }
        ExtensionHelper::addPaymentFee($transaction->transaction_id, '1.75');
        $this->assertSame('1.75', $transaction->fresh()->fee);
        $this->assertSame($identity, $operation->fresh()->payload['original_identity']);
        $adapter->mode = 'succeeded';
        $this->assertSame('succeeded', (new ProviderOperations)->reconcile($actor, $operation)->state);
        $this->assertSame(1, $adapter->writes);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(['net' => '100.00', 'tax' => '7.13', 'fee' => '2.75'], $transaction->fresh()->original_allocation);
    }

    public function test_unknown_write_and_mismatched_success_keep_reservations_until_proven_failure(): void
    {
        [$actor, , , $transaction] = $this->fixture(true);
        $adapter = $this->adapter();
        $adapter->mode = 'throw';
        $operation = $this->submit($actor, $transaction, '107.13');
        $this->assertSame('uncertain', $operation->state);
        $adapter->mode = 'mismatch';
        $this->assertSame('uncertain', (new ProviderOperations)->reconcile($actor, $operation)->state);
        try {
            $this->external($actor, $transaction, '1.00', false);
            $this->fail('Unknown provider write released reservation');
        } catch (\RuntimeException) {
            $this->assertSame(1, PaymentOperation::count());
        }
        $adapter->mode = 'failed';
        $this->assertSame('failed', (new ProviderOperations)->reconcile($actor, $operation->fresh())->state);
        $this->assertSame('107.13', (new RefundAllocation)->quote($transaction, '107.13', false)['remaining']);
        $this->assertSame(1, $adapter->writes);
    }

    public function test_merchant_drift_keeps_unknown_operation_reserved_without_write(): void
    {
        [$actor, , , $transaction] = $this->fixture(true);
        $adapter = $this->adapter();
        $operation = $this->submit($actor, $transaction);
        $adapter->merchant = 'different-synthetic-merchant';
        $this->assertSame('uncertain', (new ProviderOperations)->reconcile($actor, $operation)->state);
        $this->assertSame(1, $adapter->writes);
    }

    public function test_unsupported_gateway_refuses_provider_movement_but_allows_external_refund(): void
    {
        [$actor, , , $transaction] = $this->fixture(true);
        try {
            $this->submit($actor, $transaction);
            $this->fail('Unsupported Wave provider refund was submitted');
        } catch (\RuntimeException) {
            $this->assertSame(0, PaymentOperation::count());
        }
        $this->assertSame('succeeded', $this->external($actor, $transaction, '1.00')->state);
    }

    public function test_capture_without_existing_native_authorization_attempt_is_rejected(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $this->adapter();
        $this->expectException(\RuntimeException::class);
        (new ProviderOperations)->capture($actor, $invoice, $gateway, 'arbitrary-authorization', 'Capture authorization', (string) Str::uuid());
    }

    private function captureFixture(): array
    {
        [$actor, $paid, $gateway] = $this->fixture();
        $invoice = Invoice::factory()->create(['user_id' => $paid->user_id, 'status' => 'pending', 'pricing_tax_rate' => '7.1250']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '107.13', 'quantity' => 1, 'tax_amount' => '7.13']);
        $attempt = GatewayPaymentAttempt::create(['gateway_id' => $gateway->id, 'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id,
            'reference' => '123456789012345', 'merchant_fingerprint' => hash('sha256', 'synthetic-merchant'), 'amount' => '107.13', 'currency_code' => 'USD', 'state' => 'open',
            'provider_reference' => 'synthetic-authorization', 'pricing_fingerprint' => (new InvoicePricing)->fingerprint($invoice),
            'pricing_payload' => ['unpaid_net' => '100.00', 'unpaid_tax' => '7.13', 'gateway_fee' => '0.00', 'payable' => '107.13', 'currency' => 'USD']]);

        return [$actor, $invoice, $gateway, $attempt];
    }

    #[DataProvider('capturePermissions')]
    public function test_capture_binds_native_open_attempt_and_readback_marks_manual_capture_once(bool $onlyCapture): void
    {
        [$actor, $invoice, $gateway, $attempt] = $this->captureFixture();
        $adapter = $this->adapter();
        $adapter->mode = 'succeeded';
        if ($onlyCapture) {
            Role::findOrFail($actor->role_id)->update(['permissions' => ['admin.invoice_transactions.capture']]);
        }
        $key = (string) Str::uuid();
        $operation = (new ProviderOperations)->capture($actor, $invoice, $gateway, 'synthetic-authorization', 'Capture authorization', $key);
        $again = (new ProviderOperations)->capture($actor, $invoice->fresh(), $gateway, 'synthetic-authorization', 'Capture authorization', $key);
        $this->assertSame($operation->id, $again->id);
        $this->assertSame('succeeded', $operation->state);
        $this->assertSame('paid', $attempt->fresh()->state);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('manual_capture', $invoice->transactions()->sole()->settlement_origin);
        $this->assertSame('Manually settled — gateway verified', $invoice->transactions()->sole()->settlement_label);
        $this->assertSame(1, $adapter->writes);
        $this->assertSame(1, $invoice->transactions()->count());
    }

    public function test_new_native_partial_receipt_freezes_original_allocation_before_counting_payment(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $nativeInvoice = Invoice::factory()->create(['user_id' => $invoice->user_id, 'status' => 'pending', 'pricing_tax_rate' => '7.1250']);
        InvoiceItem::factory()->create(['invoice_id' => $nativeInvoice->id, 'price' => '107.13', 'quantity' => 1, 'tax_amount' => '7.13']);
        $native = $nativeInvoice->transactions()->create(['gateway_id' => $gateway->id, 'transaction_id' => 'synthetic-native-receipt', 'amount' => '53.57', 'status' => InvoiceTransactionStatus::Processing]);
        $this->assertNull($native->original_allocation);
        $native->update(['status' => InvoiceTransactionStatus::Succeeded]);
        $this->assertSame(['net' => '50.00', 'tax' => '3.57', 'fee' => '0.00'], $native->fresh()->original_allocation);
        $nativeInvoice->items()->sole()->update(['price' => '999.99', 'tax_amount' => '99.99']);
        $this->assertSame(['net' => '50.00', 'tax' => '3.57', 'fee' => '0.00'], (new RefundAllocation)->quote($native->fresh(), '53.57', false)['allocation']);
        $native->fresh()->update(['status' => InvoiceTransactionStatus::Succeeded]);
        $this->assertSame(['net' => '50.00', 'tax' => '3.57', 'fee' => '0.00'], $native->fresh()->original_allocation);
        try {
            $native->fresh()->update(['amount' => '53.58']);
            $this->fail('Frozen original received amount was edited');
        } catch (\RuntimeException) {
            $this->assertSame('53.57', $native->fresh()->amount);
        }
    }

    #[DataProvider('frozenInvoiceChanges')]
    public function test_partial_native_receipt_preserves_invoice_owner_currency_and_history(string $change): void
    {
        [$actor, $paid, $gateway] = $this->fixture(true);
        $invoice = Invoice::factory()->create(['user_id' => $paid->user_id, 'status' => 'pending', 'pricing_tax_rate' => '7.1250']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '107.13', 'tax_amount' => '7.13', 'quantity' => 1]);
        $transaction = $invoice->transactions()->create(['gateway_id' => $gateway->id, 'amount' => '53.57', 'transaction_id' => 'synthetic-partial-native',
            'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => false]);
        $original = $transaction->fresh()->getAttributes();
        $this->assertSame(0, PaymentOperation::where('invoice_id', $invoice->id)->count());
        $this->assertFalse(InvoicePaidProcessing::whereKey($invoice->id)->exists());
        if ($change === 'currency') {
            Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Synthetic euro', 'prefix' => 'EUR ', 'suffix' => '', 'format' => '1,000.00']);
        }
        try {
            if ($change === 'delete') {
                $invoice->delete();
            } else {
                $invoice->update($change === 'owner' ? ['user_id' => $actor->id] : ['currency_code' => 'EUR']);
            }
            $this->fail('A partial native payment lost its original invoice identity/history');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
            $this->assertSame($paid->user_id, $invoice->fresh()->user_id);
            $this->assertSame('USD', $invoice->fresh()->currency_code);
            $this->assertSame($original, $transaction->fresh()->getAttributes());
        }
        $this->assertFalse((new InvoicePolicy)->delete($actor, $invoice->fresh()));
    }

    public static function frozenInvoiceChanges(): array
    {
        return [['owner'], ['currency'], ['delete']];
    }

    public function test_old_receipt_missing_original_allocation_and_wallet_payment_are_not_refundable(): void
    {
        [$actor, , , $transaction] = $this->fixture();
        DB::table('payment_operations')->where('result_transaction_id', $transaction->id)->delete();
        DB::table('invoice_transactions')->where('id', $transaction->id)->update(['settlement_origin' => null, 'settlement_state' => null, 'original_allocation' => null]);
        $this->expectException(\RuntimeException::class);
        $this->external($actor, $transaction->fresh(), '1.00');
    }

    public function test_rotated_credentials_reconcile_same_merchant_without_repeating_write(): void
    {
        [$actor, , , $transaction] = $this->fixture(true);
        $adapter = $this->adapter();
        $operation = $this->submit($actor, $transaction);
        $adapter->credentialVersion = 'synthetic-version-2';
        $adapter->mode = 'succeeded';
        $done = (new ProviderOperations)->reconcile($actor, $operation);
        $this->assertSame('succeeded', $done->state);
        $this->assertSame(1, $adapter->writes);
        $this->assertSame('53.57', $transaction->fresh()->refunded_amount);
        $this->assertGreaterThanOrEqual(3, count($done->outcome_evidence));
    }

    public function test_native_callback_cannot_steal_active_capture_provenance_and_terminal_replay_is_harmless(): void
    {
        [$actor, $invoice, $gateway, $attempt] = $this->captureFixture();
        $gateway->update(['enabled' => true]);
        $gateway->settings()->create(['key' => 'collection_enabled', 'value' => '1']);
        $adapter = $this->adapter();
        $adapter->mode = 'succeeded';
        $adapter->callbackDuringWrite = true;
        $adapter->interruptQueued = true;
        try {
            (new ProviderOperations)->capture($actor, $invoice, $gateway, 'synthetic-authorization', 'Capture authorization', (string) Str::uuid());
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic interruption before execution', $e->getMessage());
        }
        $queued = PaymentOperation::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('queued', $queued->state);
        $this->assertSame(0, $adapter->writes);
        $callbackBlocked = false;
        try {
            (new PaymentAttempts)->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, $attempt->currency_code, 'synthetic-provider-operation');
        } catch (\RuntimeException $e) {
            $callbackBlocked = true;
            $this->assertStringContainsString('capture', $e->getMessage());
        }
        $this->assertTrue($callbackBlocked);
        $this->assertSame('open', $attempt->fresh()->state);
        $this->assertSame(0, $invoice->transactions()->count());
        $adapter->interruptQueued = false;
        $operation = (new ProviderOperations)->resume($actor, $queued);
        $this->assertSame('succeeded', $operation->state);
        // A competing caller holding the old queued model sees the winner's terminal result.
        $this->assertSame('succeeded', (new ProviderOperations)->resume($actor, $queued)->state);
        $this->assertSame('paid', $attempt->fresh()->state);
        $this->assertSame(1, $adapter->writes);
        $this->assertTrue($adapter->callbackBlocked);
        $this->assertSame('manual_capture', $invoice->transactions()->sole()->settlement_origin);
        (new PaymentAttempts)->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, $attempt->currency_code, 'synthetic-provider-operation');
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame('manual_capture', $invoice->transactions()->sole()->settlement_origin);
    }

    public function test_normal_native_callback_without_admin_capture_claim_stays_automatic(): void
    {
        [$actor, $invoice, $gateway, $attempt] = $this->captureFixture();
        $gateway->update(['enabled' => true]);
        $gateway->settings()->create(['key' => 'collection_enabled', 'value' => '1']);
        (new PaymentAttempts)->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, $attempt->currency_code, 'synthetic-automatic-payment');
        $this->assertNull($invoice->transactions()->sole()->settlement_origin);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('paid', $attempt->fresh()->state);
    }

    public function test_proven_failed_capture_allows_automatic_callback_without_relabelling_receipt(): void
    {
        [$actor, $invoice, $gateway, $attempt] = $this->captureFixture();
        $gateway->update(['enabled' => true]);
        $gateway->settings()->create(['key' => 'collection_enabled', 'value' => '1']);
        $adapter = $this->adapter();
        $adapter->mode = 'failed';
        $operation = (new ProviderOperations)->capture($actor, $invoice, $gateway, 'synthetic-authorization', 'Capture authorization', (string) Str::uuid());
        $this->assertSame('failed', $operation->state);
        (new PaymentAttempts)->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, $attempt->currency_code, 'synthetic-automatic-payment');
        $this->assertNull($invoice->transactions()->sole()->settlement_origin);
        $this->assertSame('paid', $attempt->fresh()->state);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('failed', $operation->fresh()->state);
    }

    public static function capturePermissions(): array
    {
        return [[false], [true]];
    }

    public function test_refund_only_permission_completes_immediate_readback_but_cannot_reconcile_later(): void
    {
        [$actor, , , $transaction] = $this->fixture(true);
        $adapter = $this->adapter();
        $adapter->mode = 'succeeded';
        Role::findOrFail($actor->role_id)->update(['permissions' => ['admin.invoice_transactions.refund']]);
        $operation = $this->submit($actor, $transaction);
        $this->assertSame('succeeded', $operation->state);
        $this->assertSame(1, $adapter->writes);
        $this->expectException(AuthorizationException::class);
        (new ProviderOperations)->reconcile($actor, $operation);
    }

    #[DataProvider('requiredReadbackEvidence')]
    public function test_missing_authenticated_readback_fact_remains_uncertain_and_reserved(string $field): void
    {
        [$actor, , , $transaction] = $this->fixture(true);
        $adapter = $this->adapter();
        $adapter->mode = 'succeeded';
        $adapter->omitEvidence = $field;
        $operation = $this->submit($actor, $transaction);
        $this->assertSame('uncertain', $operation->state);
        $this->assertSame('0.00', $transaction->fresh()->refunded_amount);
        $this->assertSame('53.56', (new RefundAllocation)->quote($transaction, '53.56', false)['remaining']);
        $this->assertSame(1, $adapter->writes);
        $this->assertStringNotContainsString('synthetic-private-provider-body', json_encode($operation->outcome_evidence));
    }

    public static function requiredReadbackEvidence(): array
    {
        return array_map(fn ($key) => [$key], ['authenticated', 'merchant', 'environment', 'original_reference', 'provider_object_type', 'original_amount',
            'amount', 'currency', 'invoice_id', 'gateway_id', 'transaction_id', 'attempt_id', 'attempt_reference', 'merchant_fingerprint', 'request_key', 'provider_reference']);
    }

    public function test_ordinary_model_creation_cannot_fabricate_queued_or_completed_claims(): void
    {
        [$actor, , , $transaction] = $this->fixture();
        $original = PaymentOperation::where('result_transaction_id', $transaction->id)->sole();
        foreach (['queued', 'succeeded'] as $state) {
            $attributes = $original->only(['kind', 'invoice_id', 'gateway_id', 'actor_id', 'actor_snapshot', 'amount', 'currency_code', 'reason', 'effective_at', 'request_fingerprint', 'payload']);
            $attributes['request_key'] = (string) Str::uuid();
            $attributes['state'] = $state;
            try {
                DB::transaction(fn () => PaymentOperation::create($attributes));
                $this->fail('An ordinary model create fabricated a financial operation claim');
            } catch (\RuntimeException) {
                $this->assertSame(1, PaymentOperation::count());
            }
        }
    }

    public function test_operation_outcome_cannot_be_forged_by_direct_model_update(): void
    {
        [$actor, , , $transaction] = $this->fixture(true);
        $this->adapter();
        $operation = $this->submit($actor, $transaction);
        $this->expectException(\RuntimeException::class);
        $operation->update(['state' => 'succeeded']);
    }
}
