<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice\Paid;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePaidProcessing;
use App\Models\PaymentOperation;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\TaxRate;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Policies\InvoiceTransactionPolicy;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\Operations\ManualSettlements;
use App\Services\Gateways\PaymentAttempts;
use App\Services\Invoice\ProcessPaidInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\FeeGateway;
use Tests\TestCase;

class AdminPaymentOperationsTest extends TestCase
{
    use UsesCommittedDatabase;

    private function fixture(string $extension = 'Wave'): array
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $role = Role::create(['name' => 'Synthetic finance administrator', 'permissions' => ['*']]);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($admin);
        $owner = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '100.00', 'quantity' => 1]);
        $gateway = Gateway::create(['name' => 'Synthetic ' . $extension, 'type' => 'gateway', 'extension' => $extension, 'enabled' => false]);
        $gateway->settings()->create(['key' => 'admin_payment_operations_enabled', 'value' => '1']);

        return [$admin, $invoice->fresh(), $gateway];
    }

    private function record(User $admin, Invoice $invoice, Gateway $gateway, string $amount = '100.00', ?string $key = null): PaymentOperation
    {
        return (new ManualSettlements)->record($admin, $invoice, $gateway, $amount, 'synthetic-bank-receipt', 'Received outside the gateway', '2026-10-01T12:00:00Z', $key ?? (string) Str::uuid());
    }

    public function test_manual_received_payment_is_labelled_and_replay_does_not_duplicate_it(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $key = (string) Str::uuid();
        $operation = $this->record($admin, $invoice, $gateway, key: $key);
        $again = $this->record($admin, $invoice->fresh(), $gateway, key: $key);
        $transaction = $invoice->transactions()->sole();
        $this->assertSame($operation->id, $again->id);
        $this->assertSame('succeeded', $operation->state);
        $this->assertSame($admin->id, $operation->actor_id);
        $this->assertSame('manual_record', $transaction->settlement_origin);
        $this->assertSame('settled', $transaction->settlement_state);
        $this->assertSame('Manually settled — admin recorded', $transaction->settlement_label);
        $this->assertSame('100.00', $transaction->amount);
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, PaymentOperation::count());
        Http::assertNothingSent();
    }

    public function test_unsettle_and_restore_keep_original_payment_and_credit_wallet_only_once(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $wallet = Credit::create(['user_id' => $invoice->user_id, 'currency_code' => 'USD', 'amount' => '0.00']);
        $invoice->items()->sole()->update(['reference_type' => Credit::class, 'reference_id' => $wallet->id]);
        $receipt = $this->record($admin, $invoice->fresh(), $gateway);
        $transaction = $invoice->transactions()->sole();
        $identity = $transaction->only(['id', 'amount', 'status', 'transaction_id', 'created_at']);
        $this->assertSame('100.00', (string) $wallet->fresh()->getRawOriginal('amount'));
        $manual = new ManualSettlements;
        $key = (string) Str::uuid();
        $reversal = $manual->unsettle($admin, $transaction, 'Receipt was entered in error', '2026-10-01T13:00:00Z', $key);
        $this->assertSame($reversal->id, $manual->unsettle($admin, $transaction->fresh(), 'Receipt was entered in error', '2026-10-01T13:00:00Z', $key)->id);
        $this->assertSame('Unsettled', $transaction->fresh()->settlement_label);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(100.0, $invoice->fresh()->remaining);
        $manual->restore($admin, $transaction->fresh(), 'Bank receipt verified', '2026-10-01T14:00:00Z', (string) Str::uuid());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEquals($identity, $transaction->fresh()->only(array_keys($identity)));
        $this->assertSame('100.00', (string) $wallet->fresh()->getRawOriginal('amount'));
        $this->assertSame(1, DB::table('invoice_paid_processings')->where('invoice_id', $invoice->id)->count());
        $this->assertSame(3, PaymentOperation::count());
        $this->assertSame($transaction->id, $receipt->result_transaction_id);
    }

    public function test_processing_payment_never_counts_as_received_and_history_is_not_relabelled_manual(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $old = $invoice->transactions()->create(['gateway_id' => $gateway->id, 'amount' => '100.00', 'status' => InvoiceTransactionStatus::Processing, 'transaction_id' => 'synthetic-processing']);
        $this->assertSame('Unsettled', $old->settlement_label);
        $this->assertNull($old->settlement_origin);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(100.0, $invoice->fresh()->remaining);
        $refused = false;
        try {
            $this->record($admin, $invoice, $gateway, '40.00');
        } catch (\RuntimeException $e) {
            $refused = true;
            $this->assertStringContainsString('reconciliation', $e->getMessage());
        }
        $this->assertTrue($refused, 'Native processing funds allowed an overlapping manual receipt');
        $this->assertSame(0, PaymentOperation::count());
        $this->assertSame(100.0, $invoice->fresh()->remaining);
        $this->assertNull($old->fresh()->settlement_origin);
    }

    #[DataProvider('processingCorrections')]
    public function test_native_processing_blocks_manual_correction_until_failed(string $action): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $receipt = $this->record($admin, $invoice, $gateway, '40.00')->resultTransaction;
        $manual = new ManualSettlements;
        if ($action === 'restore') {
            $manual->unsettle($admin, $receipt, 'Correct bank receipt', '2026-10-01T13:00:00Z', (string) Str::uuid());
        }
        $native = $invoice->transactions()->create(['gateway_id' => $gateway->id, 'amount' => '60.00', 'status' => InvoiceTransactionStatus::Processing, 'transaction_id' => 'synthetic-pending-correction']);
        $count = PaymentOperation::count();
        $refused = false;
        try {
            $manual->$action($admin, $receipt->fresh(), 'Reconcile bank receipt', '2026-10-01T14:00:00Z', (string) Str::uuid());
        } catch (\RuntimeException $e) {
            $refused = true;
            $this->assertStringContainsString('reconciliation', $e->getMessage());
        }
        $this->assertTrue($refused, 'Native processing allowed manual correction');
        $this->assertSame($count, PaymentOperation::count());
        ExtensionHelper::addFailedPayment($invoice->id, $gateway, '60.00', null, $native->transaction_id);
        $manual->$action($admin, $receipt->fresh(), 'Reconcile bank receipt', '2026-10-01T14:00:00Z', (string) Str::uuid());
        $this->assertSame($action === 'restore' ? 'settled' : 'unsettled', $receipt->fresh()->settlement_state);
        $this->assertNull($native->fresh()->settlement_origin);
    }

    public static function processingCorrections(): array
    {
        return [['restore'], ['unsettle']];
    }

    public function test_owner_cannot_use_admin_settlement_permission(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $this->actingAs($invoice->user);
        $this->expectException(AuthorizationException::class);
        $this->record($invoice->user, $invoice, $gateway);
    }

    public function test_disabled_operations_and_migration_hold_refuse_before_any_native_payment(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $gateway->settings()->where('key', 'admin_payment_operations_enabled')->first()->update(['value' => '0']);
        try {
            $this->record($admin, $invoice, $gateway);
            $this->fail('Disabled admin operations were accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }
        $gateway->settings()->where('key', 'admin_payment_operations_enabled')->first()->update(['value' => '1']);
        DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $invoice->id, 'reason' => 'Synthetic migration hold']);
        try {
            $this->record($admin, $invoice, $gateway);
            $this->fail('Held invoice was settled');
        } catch (MigrationHeldException $e) {
            $this->assertStringContainsString('migration hold', $e->getMessage());
        }
        $this->assertSame(0, PaymentOperation::count());
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_changed_request_and_duplicate_external_receipt_cannot_add_more_money(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $key = (string) Str::uuid();
        $this->record($admin, $invoice, $gateway, '40.00', $key);
        foreach ([$key, (string) Str::uuid()] as $requestKey) {
            try {
                $this->record($admin, $invoice->fresh(), $gateway, '30.00', $requestKey);
                $this->fail('A changed or repeated receipt was accepted');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(60.0, $invoice->fresh()->remaining);
    }

    public function test_manual_metadata_and_managed_payment_cannot_be_edited_or_deleted_directly(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $this->record($admin, $invoice, $gateway);
        $transaction = $invoice->transactions()->sole();
        foreach (['change_amount', 'change_state', 'delete'] as $mutation) {
            try {
                $t = $transaction->fresh();
                match ($mutation) {
                    'change_amount' => $t->update(['amount' => '99.00']),
                    'change_state' => $t->update(['settlement_state' => 'unsettled']),
                    'delete' => $t->delete(),
                };
                $this->fail('Managed payment mutation was accepted');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame('100.00', $transaction->fresh()->amount);
        $this->assertSame('settled', $transaction->fresh()->settlement_state);
    }

    public function test_finance_role_requires_the_specific_action_permission(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $admin->role->update(['permissions' => ['admin.invoice_transactions.create', 'admin.invoices.update']]);
        $admin->unsetRelation('role');
        try {
            $this->record($admin, $invoice, $gateway);
            $this->fail('Generic finance permission authorized a manual receipt');
        } catch (AuthorizationException) {
            $this->assertSame(0, PaymentOperation::count());
        }
        $admin->role->update(['permissions' => ['admin.invoice_transactions.manual_settle']]);
        $admin->unsetRelation('role');
        $this->record($admin, $invoice, $gateway);
        $this->expectException(AuthorizationException::class);
        (new ManualSettlements)->unsettle($admin, $invoice->transactions()->sole(), 'Correct the bank receipt', '2026-10-01T13:00:00Z', (string) Str::uuid());
    }

    public function test_invalid_money_uuid_or_calendar_dates_do_not_create_a_receipt(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        foreach (['0', '-1', '1.001', '1e2', '01.00', '100.01'] as $amount) {
            try {
                $this->record($admin, $invoice, $gateway, $amount);
                $this->fail('Invalid or excessive amount was accepted: ' . $amount);
            } catch (\RuntimeException) {
                $this->assertSame(0, PaymentOperation::count());
            }
        }
        foreach ([['not-a-uuid', '2026-10-01T12:00:00Z'], [(string) Str::uuid(), '2026-02-30T12:00:00Z'], [(string) Str::uuid(), '2026-10-01T25:00:00Z']] as [$key, $date]) {
            try {
                (new ManualSettlements)->record($admin, $invoice, $gateway, '1.00', 'synthetic-date', 'Received via bank', $date, $key);
                $this->fail('Invalid UUID or effective date was accepted');
            } catch (\RuntimeException) {
                $this->assertSame(0, PaymentOperation::count());
            }
        }
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_service_renewal_and_paid_event_are_not_replayed_after_manual_restore(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $product = $this->createProduct();
        $product->plan->update(['type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
        $service = Service::factory()->create(['user_id' => $invoice->user_id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'expires_at' => '2026-12-01']);
        $invoice->items()->sole()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        $paidEvents = 0;
        Event::listen(Paid::class, function () use (&$paidEvents) {
            $paidEvents++;
        });
        $this->record($admin, $invoice, $gateway);
        $this->assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());
        $transaction = $invoice->transactions()->sole();
        $manual = new ManualSettlements;
        $manual->unsettle($admin, $transaction, 'Correct bank reconciliation', '2026-10-01T13:00:00Z', (string) Str::uuid());
        $manual->restore($admin, $transaction->fresh(), 'Bank reconciliation confirmed', '2026-10-01T14:00:00Z', (string) Str::uuid());
        $this->assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());
        $this->assertSame(1, $paidEvents);
        $this->assertFalse((new ProcessPaidInvoiceService)->handle($invoice->fresh()));
    }

    public function test_legacy_paid_marker_prevents_historical_wallet_effects(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $wallet = Credit::create(['user_id' => $invoice->user_id, 'currency_code' => 'USD', 'amount' => '100.00']);
        $invoice->items()->sole()->update(['reference_type' => Credit::class, 'reference_id' => $wallet->id]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
        // Exercise the actual additive migration against a pre-existing paid invoice.
        // This fixture has no operations; rollback recreates only the new empty schema.
        $migration = require database_path('migrations/2026_10_01_000001_create_admin_payment_operations.php');
        $proofs = require database_path('migrations/2026_10_01_000002_create_gateway_operation_proofs.php');
        $accountFunding = require database_path('migrations/2026_10_06_000001_create_account_funding_tables.php');
        $incomingReceipts = require database_path('migrations/2026_10_06_000002_create_account_incoming_receipts.php');
        $reservedPosting = require database_path('migrations/2026_10_06_000003_add_reserved_posting_state.php');
        $cycleCompleted = false;
        try {
            $reservedPosting->down();
            $incomingReceipts->down();
            $accountFunding->down(); // This fixture has no managed accounts or receipts.
            $proofs->down(); // Roll back the empty dependent FK schema first.
            $migration->down();
            $migration->up();
            $proofs->up();
            $accountFunding->up();
            $incomingReceipts->up();
            $reservedPosting->up();
            $cycleCompleted = true;
        } catch (QueryException $exception) {
            $this->assertSame(1451, $exception->errorInfo[1], 'Unexpected schema failure in legacy-paid migration fixture');
        }
        $this->assertTrue($cycleCompleted, 'Legacy-paid fixture must reverse all empty dependent schemas before recreating payment operations');
        $this->assertTrue(Schema::hasColumn('account_reversal_reservations', 'posting_required'), 'Legacy-paid schema cycle must restore the current reserved-posting evidence');
        $this->assertSame('legacy', InvoicePaidProcessing::findOrFail($invoice->id)->origin);
        $this->assertFalse((new ProcessPaidInvoiceService)->handle($invoice->fresh()));
        $this->assertSame('100.00', (string) $wallet->fresh()->getRawOriginal('amount'));
        $this->assertFalse((new InvoicePolicy)->delete($admin, $invoice->fresh()));
    }

    public function test_managed_invoice_and_journal_identity_cannot_be_changed(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $operation = $this->record($admin, $invoice, $gateway, '40.00');
        foreach ([fn () => $operation->fresh()->update(['reason' => 'Rewrite financial history']), fn () => $operation->fresh()->delete(),
            fn () => $invoice->fresh()->update(['user_id' => $admin->id]), fn () => $invoice->fresh()->update(['currency_code' => 'EUR']),
            fn () => $invoice->transactions()->sole()->update(['transaction_id' => 'replacement-provider-identity'])] as $write) {
            try {
                $write();
                $this->fail('Managed invoice or journal identity was changed');
            } catch (\RuntimeException) {
                $this->assertSame($invoice->user_id, $invoice->fresh()->user_id);
            }
        }
        $policy = new InvoiceTransactionPolicy;
        $this->assertFalse($policy->delete($admin, $invoice->transactions()->sole()));
        $this->assertFalse($policy->update($admin, $invoice->transactions()->sole()));
    }

    public function test_partial_manual_receipt_allows_remaining_checkout_with_untaxed_fee_and_blocks_overlap(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture('FeeGateway');
        class_exists(FeeGateway::class);
        $gateway->update(['enabled' => true]);
        foreach (['collection_enabled' => '1', 'customer_fee_enabled' => '1', 'customer_fee_percent' => '2.5', 'customer_fee_fixed' => '0.25', 'customer_fee_currency' => 'USD'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        $operation = $this->record($admin, $invoice, $gateway, '40.00');
        $this->assertSame(['net' => '40.00', 'tax' => '0.00', 'fee' => '0.00'], $operation->payload['allocation']);
        $attempts = new PaymentAttempts;
        $this->actingAs($invoice->user);
        $attempt = $attempts->begin($gateway, $invoice->fresh(), hash('sha256', 'synthetic operation merchant'), 'USD');
        $this->assertSame('61.75', $attempt->amount);
        $this->assertSame('0.00', $invoice->items()->where('kind', 'gateway_fee')->sole()->tax_amount);
        $this->actingAs($admin);
        try {
            (new ManualSettlements)->record($admin, $invoice->fresh(), $gateway, '10.00', 'other-synthetic-receipt', 'Received separately', '2026-10-01T13:00:00Z', (string) Str::uuid());
            $this->fail('Overlapping provider claim allowed another manual payment');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reconciliation', $e->getMessage());
        }
        $attempts->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, 'USD', 'synthetic-remainder-payment');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(2, $invoice->transactions()->count());
        $originalAllocation = $attempt->fresh()->pricing_payload;
        (new ManualSettlements)->unsettle($admin, $operation->resultTransaction, 'Correct partial bank receipt', '2026-10-01T14:00:00Z', (string) Str::uuid());
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(40.0, $invoice->fresh()->remaining);
        $this->assertSame($originalAllocation, $attempt->fresh()->pricing_payload);
        try {
            $attempts->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, 'USD', 'synthetic-remainder-payment');
            $this->fail('A stale provider callback ignored the manual correction');
        } catch (\RuntimeException) {
            $this->assertSame('unsettled', $operation->resultTransaction->fresh()->settlement_state);
        }
        Http::assertNothingSent();
    }

    public function test_held_referenced_service_blocks_manual_receipt_before_any_native_payment(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $invoice->user_id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active']);
        $invoice->items()->sole()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        DB::table('billmanager_holds')->insert(['model_type' => Service::class, 'model_id' => $service->id, 'reason' => 'Synthetic source hold']);
        try {
            $this->record($admin, $invoice, $gateway);
            $this->fail('Source-held service was manually paid');
        } catch (MigrationHeldException) {
            $this->assertSame(0, PaymentOperation::count());
            $this->assertSame(0, $invoice->transactions()->count());
        }
    }

    public function test_revoked_role_is_rechecked_before_receipt(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $admin->load('role');
        Role::findOrFail($admin->role_id)->update(['permissions' => []]);
        try {
            $this->record($admin, $invoice, $gateway);
            $this->fail('Cached revoked permissions were accepted');
        } catch (AuthorizationException) {
            $this->assertSame(0, PaymentOperation::count());
        }
    }

    #[DataProvider('foreignReferences')]
    public function test_foreign_owned_service_is_rejected_before_receipt(string $kind): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $admin->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active']);
        $reference = match ($kind) {
            'credit' => Credit::create(['user_id' => $admin->id, 'currency_code' => 'USD', 'amount' => '0.00']),
            'upgrade' => ServiceUpgrade::create(['status' => 'pending', 'service_id' => $service->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'invoice_id' => $invoice->id]),
            default => $service,
        };
        $invoice->items()->sole()->update(['reference_type' => $reference::class, 'reference_id' => $reference->id]);
        try {
            $this->record($admin, $invoice, $gateway);
            $this->fail('Receipt processed a reference belonging to a different account');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ownership', $e->getMessage());
            $this->assertSame(0, $invoice->transactions()->count());
        }
    }

    public static function foreignReferences(): array
    {
        return [['service'], ['upgrade'], ['credit']];
    }

    public function test_callback_replay_cannot_restore_manually_reversed_funds(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $this->record($admin, $invoice, $gateway);
        $transaction = $invoice->transactions()->sole();
        (new ManualSettlements)->unsettle($admin, $transaction, 'Correct bank receipt', '2026-10-01T13:00:00Z', (string) Str::uuid());
        ExtensionHelper::addPayment($invoice->fresh(), $gateway, '100.00', transactionId: $transaction->transaction_id);
        $this->assertSame('unsettled', $transaction->fresh()->settlement_state);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(100.0, $invoice->fresh()->remaining);
        $this->assertSame(1, $invoice->transactions()->count());
    }

    public function test_manual_allocation_preserves_original_tax_and_zero_tax_fee(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.tax_scope' => 'all']);
        TaxRate::create(['name' => 'Synthetic operation tax', 'rate' => '7.1250', 'country' => 'all']);
        [$admin, $invoice, $gateway] = $this->fixture();
        $invoice->items()->sole()->update(['price' => '107.13', 'tax_amount' => '7.13']);
        $invoice->items()->create(['description' => 'Synthetic customer fee', 'kind' => 'gateway_fee', 'gateway_id' => $gateway->id, 'price' => '2.75', 'tax_amount' => '0.00', 'quantity' => 1]);
        $lines = $invoice->items()->get()->map->only(['id', 'price', 'tax_amount', 'kind'])->all();
        $operation = $this->record($admin, $invoice->fresh(), $gateway, '109.88');
        $this->assertSame(['net' => '100.00', 'tax' => '7.13', 'fee' => '2.75'], $operation->payload['allocation']);
        $this->assertSame($lines, $invoice->items()->get()->map->only(['id', 'price', 'tax_amount', 'kind'])->all());
        $this->assertSame('USD', $operation->currency_code);
        $this->assertSame('2026-10-01 12:00:00', $operation->effective_at->format('Y-m-d H:i:s'));
        $this->assertStringNotContainsString('synthetic-bank-receipt', DB::table('payment_operations')->where('id', $operation->id)->value('payload'));
    }

    public function test_paid_processing_evidence_cannot_be_removed_to_replay_a_wallet_credit(): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $this->record($admin, $invoice, $gateway);
        foreach ([fn () => InvoicePaidProcessing::findOrFail($invoice->id)->delete(),
            fn () => InvoicePaidProcessing::findOrFail($invoice->id)->update(['origin' => 'legacy'])] as $write) {
            try {
                $write();
                $this->fail('Durable paid processing evidence was removed or changed');
            } catch (\RuntimeException) {
                $this->assertSame(1, InvoicePaidProcessing::whereKey($invoice->id)->count());
            }
        }
    }

    #[DataProvider('changedInvoiceIdentities')]
    public function test_stale_invoice_identity_is_rejected_before_receipt_and_fresh_identity_replays(string $field): void
    {
        [$admin, $invoice, $gateway] = $this->fixture();
        $identity = $invoice->only(['user_id', 'currency_code']);
        $changes = $field === 'currency_code' ? ['currency_code' => 'EUR'] : ['user_id' => User::factory()->create()->id];
        if ($field === 'currency_code') {
            Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Synthetic euro', 'prefix' => 'EUR ', 'suffix' => '', 'format' => '1,000.00']);
        }
        $invoice->fresh()->update($changes);
        $this->assertSame($identity, $invoice->only(['user_id', 'currency_code']));
        $key = (string) Str::uuid();
        try {
            $this->record($admin, $invoice, $gateway, '40.00', $key);
            $this->fail('A stale invoice identity was silently accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('invoice identity changed', $e->getMessage());
        }
        $this->assertSame(0, PaymentOperation::count());
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame($changes[$field], $invoice->fresh()->getAttribute($field));
        $operation = $this->record($admin, $invoice->fresh(), $gateway, '40.00', $key);
        $replay = $this->record($admin, $invoice->fresh(), $gateway, '40.00', $key);
        $this->assertSame($operation->id, $replay->id);
        $this->assertSame($invoice->fresh()->currency_code, $operation->currency_code);
        $this->assertSame(1, PaymentOperation::count());
        $this->assertSame(1, $invoice->transactions()->count());
        Http::assertNothingSent();
    }

    public static function changedInvoiceIdentities(): array
    {
        return [['currency_code'], ['user_id']];
    }
}
