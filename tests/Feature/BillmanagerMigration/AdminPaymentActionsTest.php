<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Admin\Actions\PaymentActions;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\PaymentOperationsRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\User;
use App\Policies\InvoiceTransactionPolicy;
use App\Services\Billing\InvoicePricing;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\Operations\GatewayOperations;
use App\Services\Gateways\Operations\ManualSettlements;
use App\Services\Gateways\Operations\OperationResult;
use App\Services\Gateways\Operations\ProviderOperations;
use App\Services\Gateways\Operations\RefundAllocation;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

class AdminPaymentActionsTest extends TestCase
{
    use UsesCommittedDatabase;

    private function fixture(bool $ownedByActor = false): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $role = Role::create(['name' => 'Synthetic operator', 'permissions' => ['*']]);
        $actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = $ownedByActor ? $actor : User::factory()->createQuietly();
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '100.00', 'quantity' => 1]);
        $gateway = Gateway::create(['name' => 'Synthetic Wave', 'extension' => 'Wave', 'type' => 'gateway', 'enabled' => false]);
        $gateway->settings()->create(['key' => 'admin_payment_operations_enabled', 'value' => '1']);

        return [$actor, $invoice->fresh(), $gateway];
    }

    private function receipt($actor, $invoice, $gateway): PaymentOperation
    {
        return (new ManualSettlements)->record($actor, $invoice, $gateway, '100.00', 'synthetic-bank', 'Bank receipt confirmed', '2026-10-01T12:00:00Z', (string) Str::uuid());
    }

    public function test_native_relation_replaces_generic_create_with_audited_receipt(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $retired = Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class]);
        $retired->call('mountAction', 'create', [], ['table' => true])->call('callMountedAction');
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(0, PaymentOperation::count());
        $retired->assertTableActionDoesNotExist('create')
            ->callTableAction('manual_receipt', data: ['gateway_id' => $gateway->id, 'amount' => '40.00', 'reference' => 'synthetic-bank',
                'reason' => 'Bank receipt confirmed', 'effective_at' => '2026-10-01 12:00:00', 'request_key' => (string) Str::uuid()])
            ->assertHasNoTableActionErrors()->assertDispatched('admin-payment-operation-completed', invoiceId: $invoice->id);
        $this->assertSame('40.00', $invoice->transactions()->sole()->amount);
        $this->assertSame($actor->id, PaymentOperation::sole()->actor_id);
        Http::assertNothingSent();
    }

    public function test_preview_uses_server_currency_and_principal_first_allocation(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $this->receipt($actor, $invoice, $gateway);
        $transaction = $invoice->transactions()->sole();
        $quote = PaymentActions::refundPreview($actor, $transaction, 'partial', '30.00', true);
        $this->assertSame('USD', $quote['currency']);
        $this->assertSame(['net' => '30.00', 'tax' => '0.00', 'fee' => '0.00'], $quote['allocation']);
        $this->assertSame('100.00', PaymentActions::refundPreview($actor, $transaction, 'full', null, false)['amount']);
        $actor->role->update(['permissions' => []]);
        $this->expectException(AuthorizationException::class);
        PaymentActions::refundPreview($actor, $transaction, 'full', null, false);
    }

    public function test_native_refund_and_correction_actions_preserve_history_and_managed_deletion(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $this->receipt($actor, $invoice, $gateway);
        $transaction = $invoice->transactions()->sole();
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->assertTableActionHidden('delete', $transaction)
            ->callTableAction('external_refund', $transaction, data: ['refund_mode' => 'partial', 'amount' => '25.00', 'include_fee' => false,
                'reference' => 'synthetic-refund', 'reason' => 'External refund confirmed', 'effective_at' => '2026-10-01 13:00:00', 'request_key' => (string) Str::uuid()])
            ->assertHasNoTableActionErrors();
        $this->assertSame('25.00', $transaction->fresh()->refunded_amount);
        $this->assertSame('75.00', PaymentActions::refundPreview($actor, $transaction->fresh(), 'full', null, true)['amount']);
        Livewire::test(PaymentOperationsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->assertCanSeeTableRecords(PaymentOperation::all())
            ->assertTableColumnExists('reason')->assertTableColumnExists('actor.name')
            ->assertTableColumnDoesNotExist('payload')->assertTableColumnDoesNotExist('outcome_evidence');
        $this->assertSame('paid', $invoice->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_reconciliation_command_requires_explicit_actor_and_never_reexecutes_manual_receipt(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $operation = $this->receipt($actor, $invoice, $gateway);
        $this->artisan('payment-operation:reconcile', ['operation' => $operation->id])->assertFailed();
        $this->artisan('payment-operation:reconcile', ['operation' => $operation->id, '--actor' => $actor->id])->assertFailed();
        $this->assertSame(1, PaymentOperation::count());
        $this->assertSame(1, $invoice->transactions()->count());
        Http::assertNothingSent();
    }

    public function test_native_correction_preserves_reference_and_generic_creation_is_denied(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $this->receipt($actor, $invoice, $gateway);
        $transaction = $invoice->transactions()->sole();
        $reference = $transaction->transaction_id;
        $this->assertFalse((new InvoiceTransactionPolicy)->create($actor));
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->callTableAction('manual_unsettle', $transaction, data: ['reason' => 'Correct duplicate bank receipt', 'effective_at' => '2026-10-01 14:00:00', 'request_key' => (string) Str::uuid()])
            ->assertHasNoTableActionErrors()->assertSee('Unsettled');
        $this->assertSame('pending', $invoice->fresh()->status);
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->callTableAction('manual_restore', $transaction->fresh(), data: ['reason' => 'Bank receipt verified again', 'effective_at' => '2026-10-01 15:00:00', 'request_key' => (string) Str::uuid()])
            ->assertHasNoTableActionErrors();
        $this->assertSame($reference, $transaction->fresh()->transaction_id);
        $this->assertSame(3, PaymentOperation::count());
    }

    public function test_mount_binding_refuses_stale_reference_and_revoked_permission(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $this->receipt($actor, $invoice, $gateway);
        $transaction = $invoice->transactions()->sole();
        $component = Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class]);
        $binding = PaymentActions::binding($transaction);
        DB::table('invoice_transactions')->where('id', $transaction->id)->update(['transaction_id' => 'synthetic-concurrent-reference-change']);
        $component->callTableAction('external_refund', $transaction, data: ['payment_binding' => $binding, 'refund_mode' => 'full', 'include_fee' => false,
            'reference' => 'synthetic-stale-refund', 'reason' => 'External refund confirmed', 'effective_at' => '2026-10-01 13:00:00', 'request_key' => (string) Str::uuid()])
            ->assertHasTableActionErrors(['reason']);
        $this->assertSame(1, PaymentOperation::count());
        $this->assertSame('0.00', $transaction->fresh()->refunded_amount);
        $actor->role->update(['permissions' => ['admin.invoice_transactions.viewAny']]);
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->assertTableActionHidden('external_refund', $transaction)->assertTableActionHidden('manual_unsettle', $transaction);
    }

    public function test_full_and_partial_fee_choice_use_exact_original_allocation_and_zero_maximum(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.tax_scope' => 'all']);
        TaxRate::create(['name' => 'Synthetic native tax', 'rate' => '7.1250', 'country' => 'all']);
        [$actor, $invoice, $gateway] = $this->fixture();
        $invoice->items()->sole()->update(['price' => '107.13', 'tax_amount' => '7.13']);
        $invoice->items()->create(['description' => 'Synthetic fee', 'kind' => 'gateway_fee', 'gateway_id' => $gateway->id, 'price' => '2.75', 'tax_amount' => '0.00', 'quantity' => 1]);
        $receipt = (new ManualSettlements)->record($actor, $invoice->fresh(), $gateway, '109.88', 'synthetic-tax-bank', 'Bank receipt confirmed', '2026-10-01T12:00:00Z', (string) Str::uuid());
        $transaction = $receipt->resultTransaction;
        $this->assertSame('107.13', PaymentActions::refundPreview($actor, $transaction, 'full', null, false)['amount']);
        $this->assertSame(['net' => '50.00', 'tax' => '3.56', 'fee' => '0.00'], PaymentActions::refundPreview($actor, $transaction, 'partial', '53.56', true)['allocation']);
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->callTableAction('external_refund', $transaction, data: ['refund_mode' => 'full', 'include_fee' => true, 'reference' => 'synthetic-full-refund',
                'reason' => 'All money refunded externally', 'effective_at' => '2026-10-01 13:00:00', 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors();
        $refund = PaymentOperation::where('kind', 'external_refund')->sole();
        $this->assertSame(['net' => '100.00', 'tax' => '7.13', 'fee' => '2.75'], $refund->payload['allocation']);
        $this->assertSame('0.00', (new RefundAllocation)->available($transaction->fresh(), true));
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_disabled_and_held_preview_refuse_without_provider_access(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $this->receipt($actor, $invoice, $gateway);
        $transaction = $invoice->transactions()->sole();
        $gateway->settings()->where('key', 'admin_payment_operations_enabled')->update(['value' => '0']);
        try {
            PaymentActions::refundPreview($actor, $transaction, 'full', null, true);
            $this->fail('Disabled action preview was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }
        $gateway->settings()->where('key', 'admin_payment_operations_enabled')->update(['value' => '1']);
        DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $invoice->id, 'reason' => 'Synthetic hold']);
        try {
            PaymentActions::refundPreview($actor, $transaction, 'full', null, true);
            $this->fail('Held action preview was accepted');
        } catch (MigrationHeldException) {
            $this->assertSame(1, PaymentOperation::count());
        }
        Http::assertNothingSent();
    }

    private function captureFixture(): array
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $attempt = GatewayPaymentAttempt::create(['gateway_id' => $gateway->id, 'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id,
            'reference' => '123456789012345', 'merchant_fingerprint' => hash('sha256', 'synthetic-merchant'), 'amount' => '100.00', 'currency_code' => 'USD', 'state' => 'open',
            'provider_reference' => 'synthetic-authorization', 'pricing_fingerprint' => (new InvoicePricing)->fingerprint($invoice),
            'pricing_payload' => ['unpaid_net' => '100.00', 'unpaid_tax' => '0.00', 'gateway_fee' => '0.00', 'payable' => '100.00', 'currency' => 'USD']]);
        $adapter = new class implements Adapter
        {
            public int $writes = 0;

            public int $reads = 0;

            public bool $authenticated = true;

            public bool $interruptQueued = false;

            public string $version = 'original';

            public function capabilities(): array
            {
                return ['capture' => true, 'refund' => true, 'reconcile' => true];
            }

            public function fingerprint(): string
            {
                if ($this->interruptQueued && PaymentOperation::where('state', 'queued')->exists()) {
                    throw new \RuntimeException('Synthetic interruption before execution');
                }

                return hash('sha256', 'synthetic-operation-merchant:' . $this->version);
            }

            public function prepare(Invoice $invoice, ?InvoiceTransaction $transaction, string $kind, string $providerReference, string $amount, string $currency): array
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Preparation must be outside locks');
                }
                $this->reads++;
                $attempt = GatewayPaymentAttempt::where('invoice_id', $invoice->id)->where('state', 'open')->sole();

                return ['authenticated' => $this->authenticated, 'merchant' => 'synthetic-merchant', 'environment' => 'synthetic', 'original_reference' => $providerReference,
                    'provider_object_type' => 'authorization', 'original_amount' => $amount, 'amount' => $amount, 'currency' => $currency,
                    'invoice_id' => $invoice->id, 'gateway_id' => $attempt->gateway_id, 'transaction_id' => null, 'already_refunded' => '0.00',
                    'attempt_id' => $attempt->id, 'attempt_reference' => $attempt->reference, 'merchant_fingerprint' => $attempt->merchant_fingerprint];
            }

            public function execute(PaymentOperation $operation): OperationResult
            {
                $this->writes++;

                return new OperationResult('pending', 'synthetic-capture-result');
            }

            public function reconcile(PaymentOperation $operation): OperationResult
            {
                $this->reads++;

                return new OperationResult('pending', 'synthetic-capture-result', $operation->payload['provider_context'] + ['request_key' => $operation->request_key]);
            }
        };
        $registry = $this->mock(GatewayOperations::class);
        $registry->shouldReceive('for')->andReturn($adapter);

        return [$actor, $invoice, $gateway, $attempt, $adapter];
    }

    public function test_native_queued_recovery_rechecks_current_actor_holds_identity_and_configuration(): void
    {
        [$actor, $invoice, $gateway, $attempt, $adapter] = $this->captureFixture();
        $adapter->interruptQueued = true;
        try {
            (new ProviderOperations)->capture($actor, $invoice, $gateway, $attempt->provider_reference, 'Original capture reason', (string) Str::uuid());
            $this->fail('The pre-execution interruption was not reached');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic interruption before execution', $e->getMessage());
        }
        $operation = PaymentOperation::sole();
        $this->assertSame('queued', $operation->state);
        $identity = $operation->only(['actor_id', 'actor_snapshot', 'request_fingerprint', 'request_key', 'payload', 'reason', 'amount', 'effective_at']);
        $adapter->interruptQueued = false;
        $executor = User::factory()->create(['role_id' => Role::create(['name' => 'Synthetic recovery administrator', 'permissions' => ['*']])->id]);
        $this->actingAs($executor);
        $view = Livewire::test(PaymentOperationsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class]);
        $view->assertTableActionVisible('resume', $operation);
        foreach (['permission', 'hold', 'disabled', 'identity', 'configuration', 'eligibility'] as $change) {
            match ($change) {
                'permission' => $executor->role->update(['permissions' => []]),
                'hold' => DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $invoice->id, 'reason' => 'Synthetic recovery hold']),
                'disabled' => $gateway->settings()->where('key', 'admin_payment_operations_enabled')->first()->update(['value' => '0']),
                'identity' => DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => $executor->id]),
                'configuration' => $adapter->version = 'changed',
                'eligibility' => $attempt->update(['state' => 'expired']),
            };
            $refused = false;
            try {
                (new ProviderOperations)->resume($executor, $operation);
            } catch (\RuntimeException|AuthorizationException $e) {
                $refused = true;
                $this->assertSame('queued', $operation->fresh()->state);
                $this->assertSame(0, $adapter->writes);
            }
            $this->assertTrue($refused, 'Queued recovery ignored ' . $change);
            $executor->role->update(['permissions' => ['*']]);
            DB::table('billmanager_holds')->where('model_type', Invoice::class)->where('model_id', $invoice->id)->delete();
            $gateway->settings()->where('key', 'admin_payment_operations_enabled')->first()->update(['value' => '1']);
            DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => $invoice->user_id]);
            $adapter->version = 'original';
            $attempt->update(['state' => 'open']);
        }
        $view->callTableAction('resume', $operation)->assertHasNoTableActionErrors();
        $this->assertSame(1, $adapter->writes);
        $this->assertSame('pending', $operation->fresh()->state);
        $this->assertEquals($identity, $operation->fresh()->only(array_keys($identity)));
        $this->assertSame($executor->id, $operation->fresh()->outcome_evidence[0]['actor_id']);
        $this->assertSame('execution_started', $operation->fresh()->outcome_evidence[0]['outcome_code']);
        foreach (['processing', 'pending', 'uncertain'] as $state) {
            DB::table('payment_operations')->where('id', $operation->id)->update(['state' => $state]);
            (new ProviderOperations)->resume($executor, $operation->fresh());
            $this->assertSame(1, $adapter->writes);
        }
    }

    public function test_capture_preview_is_contextual_readonly_and_uncertain_claim_is_reserved(): void
    {
        [$actor, $invoice, $gateway, $attempt, $adapter] = $this->captureFixture();
        $preview = (new ProviderOperations)->capturePreview($actor, $invoice);
        $this->assertSame(['gateway_id' => $gateway->id, 'reference' => 'synthetic-authorization', 'amount' => '100.00', 'currency' => 'USD'], $preview);
        $this->assertSame(0, $adapter->writes);
        $this->assertSame(0, PaymentOperation::count());
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class])
            ->callTableAction('capture_authorization', data: ['reason' => 'Capture original authorization', 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors()
            ->assertDispatched('admin-payment-operation-completed', invoiceId: $invoice->id);
        $operation = PaymentOperation::sole();
        $this->assertSame('pending', $operation->state);
        $this->assertSame(1, $adapter->writes);
        $this->artisan('payment-operation:reconcile', ['operation' => $operation->id, '--actor' => (string) $actor->id])->assertFailed();
        $this->assertSame(1, $adapter->writes);
        $actor->role->update(['permissions' => ['admin.invoice_transactions.capture']]);
        $reads = $adapter->reads;
        $this->artisan('payment-operation:reconcile', ['operation' => $operation->id, '--actor' => (string) $actor->id])->assertFailed();
        $this->assertSame($reads, $adapter->reads);
        Http::assertNothingSent();
    }

    public function test_capture_preview_rejects_unverified_provider_context_and_arbitrary_reference(): void
    {
        [$actor, $invoice, $gateway, $attempt, $adapter] = $this->captureFixture();
        $adapter->authenticated = false;
        try {
            (new ProviderOperations)->capturePreview($actor, $invoice);
            $this->fail('Unverified authorization preview was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Authenticated', $e->getMessage());
        }
        $attempt->update(['provider_reference' => null]);
        try {
            (new ProviderOperations)->capturePreview($actor, $invoice);
            $this->fail('Missing native authorization reference was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('original provider reference', $e->getMessage());
        }
        $this->assertSame(0, $adapter->writes);
        $this->assertSame(0, PaymentOperation::count());
    }

    public function test_preserved_legacy_tax_without_rate_renders_and_manual_receipt_settles(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture();
        $invoice->items()->sole()->update(['price' => '107.13', 'tax_amount' => '7.13']);
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->callTableAction('manual_receipt', data: ['gateway_id' => $gateway->id, 'amount' => '107.13', 'reference' => 'synthetic-legacy-tax',
                'reason' => 'Received preserved legacy tax amount', 'effective_at' => '2026-10-01 12:00:00', 'request_key' => (string) Str::uuid()])
            ->assertHasNoTableActionErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(['net' => '100.00', 'tax' => '7.13', 'fee' => '0.00'], PaymentOperation::sole()->payload['allocation']);
        $html = view('pdf.invoice', ['invoice' => $invoice->fresh()])->render();
        $this->assertStringContainsString('<td class="label">Tax</td>', $html);
        $this->assertStringContainsString($invoice->fresh()->formattedTotal->format('7.13'), $html);
        $this->assertStringNotContainsString('Tax (0', $html);
        Http::assertNothingSent();
    }

    public function test_capture_confirmation_rechecks_previously_verified_authorization(): void
    {
        [$actor, $invoice, $gateway, $attempt, $adapter] = $this->captureFixture();
        $component = Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class]);
        $component->mountTableAction('capture_authorization');
        $this->assertSame(1, $adapter->reads);
        $adapter->authenticated = false;
        $component->set('mountedActions.0.data.reason', 'Capture the verified original authorization')
            ->set('mountedActions.0.data.request_key', (string) Str::uuid())->call('callMountedAction')->assertHasTableActionErrors(['reason'])
            ->assertNotDispatched('admin-payment-operation-completed');
        $this->assertSame(0, $adapter->writes);
        $this->assertSame(0, PaymentOperation::count());
        Http::assertNothingSent();
    }

    public function test_parent_payment_event_refreshes_status_and_preserves_unrelated_edits(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture(ownedByActor: true);
        $invoice->update(['due_at' => '2026-10-10']);
        $component = Livewire::test(EditInvoice::class, ['record' => (string) $invoice->id])
            ->set('data.due_at', '2026-10-12')->assertSet('data.status', 'pending');
        $this->receipt($actor, $invoice, $gateway);
        $component->dispatch('admin-payment-operation-completed', invoiceId: $invoice->id + 1000)->assertSet('data.status', 'pending');
        $component->dispatch('admin-payment-operation-completed', invoiceId: $invoice->id)
            ->assertSet('data.status', 'paid')->assertSet('data.due_at', '2026-10-12')->assertSet('paymentStatusBaseline', 'paid');
        $component->call('save')->assertHasNoFormErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-10-12', $invoice->fresh()->due_at->toDateString());
        $this->assertSame(1, PaymentOperation::count());
        Http::assertNothingSent();
    }

    public function test_stale_parent_save_refuses_before_relationship_financial_writes(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture(ownedByActor: true);
        $invoice->update(['due_at' => '2026-10-10']);
        $component = Livewire::test(EditInvoice::class, ['record' => (string) $invoice->id]);
        $data = $component->get('data');
        $itemKey = array_key_first($data['items']);
        $component->set('data.items.' . $itemKey . '.description', 'Unsaved replacement description')->set('data.due_at', '2026-10-12');
        $original = $invoice->items()->sole()->only(['description', 'price', 'quantity', 'tax_amount']);
        $this->receipt($actor, $invoice, $gateway);
        $component->call('save')->assertHasFormErrors(['status']);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-10-10', $invoice->fresh()->due_at->toDateString());
        $this->assertSame($original, $invoice->items()->sole()->only(array_keys($original)));
        $this->assertSame(1, PaymentOperation::count());
        Http::assertNothingSent();
    }

    public function test_stale_partial_native_save_is_guarded_and_intentional_status_edit_is_retained(): void
    {
        [$actor, $invoice, $gateway] = $this->fixture(ownedByActor: true);
        $invoice->update(['due_at' => '2026-10-10']);
        $component = Livewire::test(EditInvoice::class, ['record' => (string) $invoice->id]);
        $this->receipt($actor, $invoice, $gateway);
        $page = $component->instance();
        $field = $page->getSchema('form')->getComponentByStatePath('status');
        $this->assertNotNull($field);
        try {
            $page->saveFormComponentOnly($field);
            $this->fail('Stale partial native save was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.status', $e->errors());
        }
        $this->assertSame('paid', $invoice->fresh()->status);
        $fresh = Livewire::test(EditInvoice::class, ['record' => (string) $invoice->id]);
        $fresh->set('data.status', 'pending')->call('save')->assertHasNoFormErrors();
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(1, PaymentOperation::count());
        $this->assertSame(1, $invoice->transactions()->count());
        Http::assertNothingSent();
    }
}
