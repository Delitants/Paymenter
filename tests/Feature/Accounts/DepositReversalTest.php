<?php

namespace Tests\Feature\Accounts;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\PaymentOperationsRelationManager;
use App\Models\AccountMovement;
use App\Models\AccountPostingIssue;
use App\Models\AccountReversalReservation;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\DepositLifecycle;
use App\Services\Accounts\InvoiceFunding;
use App\Services\Accounts\WalletLedger;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\Operations\GatewayOperations;
use App\Services\Gateways\Operations\ManualSettlements;
use App\Services\Gateways\Operations\OperationResult;
use App\Services\Gateways\Operations\ProviderOperations;
use App\Services\Gateways\Operations\Refunds;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\UsesAccountWallet;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesVerifiedDeposit;
use Tests\TestCase;

class DepositReversalTest extends TestCase
{
    use UsesAccountWallet,UsesCommittedDatabase,UsesVerifiedDeposit;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.tax_enabled' => false]);
        self::assertTrue(method_exists(DepositLifecycle::class, 'reserveRefund'), 'Missing durable deposit refund lifecycle');
    }

    private function fixture(bool $manual = false, string $opening = '0', string $limit = '10'): array
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, $opening, $limit);
        $gateway = $this->depositGateway();
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $invoice->items()->create(['description' => 'Synthetic principal', 'price' => '50.00', 'quantity' => 1, 'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        if ($manual) {
            $this->manualDeposit($invoice, $gateway, '50.00', 'synthetic-manual-deposit');
        } else {
            $this->verifiedDeposit($invoice, $gateway, 'synthetic-reversible-deposit');
        }
        $role = Role::create(['name' => 'Synthetic account refund staff', 'permissions' => ['admin.invoice_transactions.refund', 'admin.invoice_transactions.reconcile', 'admin.invoice_transactions.manual_settle', 'admin.invoice_transactions.manual_unsettle']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);

        return [$actor, $owner, $wallet, $invoice->fresh(), $invoice->transactions()->sole()];
    }

    private function refund(User $actor, InvoiceTransaction $transaction, string $amount = '20.00', bool $fee = false, ?string $key = null): PaymentOperation
    {
        try {
            return (new Refunds)->submit($actor, $transaction, $amount, $fee, 'Synthetic requested refund', $key ?? (string) Str::uuid());
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'interruption') || str_contains($e->getMessage(), 'refund exceeds')) {
                throw $e;
            } self::fail('Native refund failed: ' . $e->getMessage());
        }
    }

    private function reservation(): AccountReversalReservation
    {
        self::assertSame(1, AccountReversalReservation::count(), 'Missing principal reservation');

        return AccountReversalReservation::sole();
    }

    private function reconcile(User $actor, PaymentOperation $op): PaymentOperation
    {
        try {
            return (new ProviderOperations)->reconcile($actor, $op);
        } catch (\RuntimeException $e) {
            self::fail('Native reconciliation failed: ' . $e->getMessage());
        }
    }

    public function test_pending_and_unknown_refunds_reserve_only_credited_principal_and_reduce_cash(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $reservation = $this->reservation();
        self::assertSame('pending', $op->state);
        self::assertSame('reserved', $reservation->state);
        self::assertSame('20.0000', $reservation->principal);
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame('30.00', Credit::sole()->amount);
        self::assertSame('40.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
        $adapter->mode = 'mismatch';
        $this->reconcile($actor, $op);
        self::assertSame('reserved', $reservation->fresh()->state);
        self::assertSame('30.00', Credit::sole()->amount);
        Http::assertNothingSent();
    }

    public function test_verified_failure_releases_reservation_without_balance_movement(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $adapter->mode = 'failed';
        $done = $this->reconcile($actor, $op);
        self::assertSame('failed', $done->state);
        self::assertSame('released', $this->reservation()->state);
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame('50.00', Credit::sole()->amount);
        self::assertSame('60.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
        self::assertSame(1, AccountMovement::count());
    }

    public function test_success_consumes_once_and_gateway_fee_never_reverses_principal(): void
    {
        [$actor,,$wallet,$invoice,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $adapter->mode = 'succeeded';
        $key = (string) Str::uuid();
        $op = $this->refund($actor, $tx, '52.00', true, $key);
        $reservation = $this->reservation();
        self::assertSame('succeeded', $op->state);
        self::assertSame('50.0000', $reservation->principal);
        self::assertSame('consumed', $reservation->state);
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame('-50.0000', AccountMovement::where('kind', 'deposit_refund')->sole()->delta);
        $this->refund($actor, $tx, '52.00', true, $key);
        $this->reconcile($actor, $op);
        self::assertSame(2, AccountMovement::count());
        self::assertSame(1, $adapter->writes);
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame('paid', $invoice->fresh()->status);
    }

    public function test_refunding_spent_deposit_retains_excess_debt_and_denies_new_spending(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $bill = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $bill->items()->create(['description' => 'Synthetic usage', 'price' => '60.00', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);
        $this->actingAs($owner);
        (new InvoiceFunding)->fund($owner, $bill, '60.00', (string) Str::uuid());
        $this->actingAs($actor);
        $adapter->mode = 'succeeded';
        $this->refund($actor, $tx, '50.00');
        $quote = (new WalletLedger)->quote($owner, 'USD');
        self::assertSame('-60.0000', $wallet->fresh()->balance);
        self::assertSame('50.0000', $quote->excessDebt);
        self::assertSame('0.00', $quote->fundingAvailable);
        self::assertSame('0.00', Credit::sole()->amount);
    }

    public function test_new_hold_after_verified_success_retains_evidence_until_exact_native_retry(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $adapter->mode = 'succeeded';
        $adapter->afterRead = function () use ($owner) {
            DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic after-read hold']);
        };
        $done = $this->reconcile($actor, $op);
        self::assertSame('succeeded', $done->state);
        self::assertTrue($wallet->fresh()->reconciliation_required);
        self::assertSame('reserved', $this->reservation()->state);
        self::assertSame(0, AccountMovement::where('kind', 'deposit_refund')->count());
        DB::table('billmanager_holds')->where('model_type', User::class)->where('model_id', $owner->id)->delete();
        $adapter->afterRead = null;
        $this->reconcile($actor, $done);
        $this->reconcile($actor, $done);
        self::assertFalse($wallet->fresh()->reconciliation_required);
        self::assertSame('30.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::where('kind', 'deposit_refund')->count());
        self::assertSame(1, $adapter->writes);
        self::assertSame(2, $adapter->reads);
    }

    public function test_manual_unsettle_and_restore_invert_principal_without_replaying_fulfillment(): void
    {
        [$actor,,$wallet,$invoice,$tx] = $this->fixture(true);
        $manual = new ManualSettlements;
        $effective = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $key = (string) Str::uuid();
        $op = $manual->unsettle($actor, $tx, 'Synthetic unsettlement', $effective, $key);
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame('pending', $invoice->fresh()->status);
        $manual->unsettle($actor, $tx->fresh(), 'Synthetic unsettlement', $effective, $key);
        $restore = (string) Str::uuid();
        $manual->restore($actor, $tx->fresh(), 'Synthetic restore', $effective, $restore);
        $manual->restore($actor, $tx->fresh(), 'Synthetic restore', $effective, $restore);
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame(3, AccountMovement::count());
        self::assertSame(1, InvoicePaidProcessing::count());
        $manual->unsettle($actor, $tx->fresh(), 'Synthetic second unsettlement', $effective, (string) Str::uuid());
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame(4, AccountMovement::count());
    }

    public function test_external_verified_refund_posts_original_principal_and_refunded_receipt_cannot_restore(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture(true);
        $key = (string) Str::uuid();
        $refunds = new Refunds;
        $effective = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $refunds->recordExternal($actor, $tx, '50.00', false, 'synthetic-external-refund', 'Synthetic verified refund', $effective, $key);
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame(2, AccountMovement::count());
        $refunds->recordExternal($actor, $tx, '50.00', false, 'synthetic-external-refund', 'Synthetic verified refund', $effective, $key);
        self::assertSame(2, AccountMovement::count());
        $denied = false;
        try {
            (new ManualSettlements)->unsettle($actor, $tx, 'Synthetic already refunded', $effective, (string) Str::uuid());
        } catch (\RuntimeException|AuthorizationException) {
            $denied = true;
        }
        self::assertTrue($denied);
        Http::assertNothingSent();
    }

    public function test_queued_claim_reserves_before_network_and_unknown_result_never_reexecutes(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $adapter->interruptQueued = true;
        $key = (string) Str::uuid();
        try {
            $this->refund($actor, $tx, '20.00', false, $key);
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic interruption before execution', $e->getMessage());
        }
        self::assertSame('queued', PaymentOperation::sole()->state);
        self::assertSame('20.0000', $this->reservation()->principal);
        self::assertSame('30.00', Credit::sole()->amount);
        self::assertSame(0, $adapter->writes);
        $adapter->interruptQueued = false;
        $adapter->mode = 'throw';
        $op = $this->refund($actor, $tx, '20.00', false, $key);
        self::assertSame('uncertain', $op->state);
        $this->refund($actor, $tx, '20.00', false, $key);
        self::assertSame(1, $adapter->writes);
        self::assertSame('reserved', $this->reservation()->state);
    }

    public function test_reservation_and_payment_operation_outcome_are_immutable_without_events(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $reservation = $this->reservation();
        foreach ([fn () => $reservation->updateQuietly(['principal' => '0.0000']), fn () => $reservation->deleteQuietly(),
            fn () => $op->updateQuietly(['state' => 'succeeded']), fn () => PaymentOperation::withoutEvents(fn () => $op->update(['amount' => '1.00']))] as $write) {
            $denied = false;
            try {
                $write();
            } catch (\RuntimeException|AuthorizationException) {
                $denied = true;
            }
            self::assertTrue($denied, 'Protected refund evidence changed through quiet persistence');
        }
        self::assertSame('pending', $op->fresh()->state);
        self::assertSame('20.0000', $reservation->fresh()->principal);
    }

    public function test_contradictory_terminal_readback_cannot_release_consumed_principal(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $adapter->mode = 'succeeded';
        $op = $this->refund($actor, $tx);
        $identity = $op->getAttributes();
        $adapter->mode = 'failed';
        $done = $this->reconcile($actor, $op);
        self::assertSame($identity, $done->getAttributes());
        self::assertSame('30.0000', $wallet->fresh()->balance);
        self::assertSame('consumed', $this->reservation()->state);
        self::assertSame(2, AccountMovement::count());
    }

    public function test_two_reserved_refunds_cannot_exceed_original_slice_and_release_restores_projection(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $first = $this->refund($actor, $tx, '30.00');
        $second = $this->refund($actor, $tx, '20.00');
        self::assertSame('50.0000', (new WalletLedger)->quote($owner, 'USD')->reservedPrincipal);
        self::assertSame('0.00', Credit::sole()->amount);
        $denied = false;
        try {
            $this->refund($actor, $tx, '0.01');
        } catch (\RuntimeException|AuthorizationException) {
            $denied = true;
        }
        self::assertTrue($denied);
        self::assertSame(2, AccountReversalReservation::count());
        $adapter->mode = 'failed';
        $this->reconcile($actor, $first);
        self::assertSame('30.00', Credit::sole()->amount);
        self::assertSame('20.0000', (new WalletLedger)->quote($owner, 'USD')->reservedPrincipal);
    }

    public function test_multi_receipt_refund_uses_only_its_original_principal_slice(): void
    {
        [$actor,$owner,$wallet,$invoice,$tx] = $this->fixture(true);
        $refunds = new Refunds;
        $effective = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $other = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $gateway = $this->depositGateway('0.00');
        $other->items()->create(['description' => 'Synthetic split deposit', 'price' => '50.00', 'quantity' => 1, 'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        $this->manualDeposit($other, $gateway, '20.00', 'synthetic-split-one');
        $this->manualDeposit($other, $gateway, '30.00', 'synthetic-split-two');
        $this->actingAs($actor);
        $first = $other->transactions()->orderBy('id')->firstOrFail();
        $second = $other->transactions()->orderByDesc('id')->firstOrFail();
        $refunds->recordExternal($actor, $first, '20.00', false, 'synthetic-split-refund', 'Synthetic original slice', $effective, (string) Str::uuid());
        self::assertSame('80.0000', $wallet->fresh()->balance);
        $refunds->recordExternal($actor, $second, '30.00', false, 'synthetic-split-refund-two', 'Synthetic second slice', $effective, (string) Str::uuid());
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame(2, AccountMovement::where('kind', 'deposit_refund')->count());
    }

    public function test_fee_only_verified_refund_has_no_principal_debit_or_new_journal_movement(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $adapter->mode = 'succeeded';
        $this->refund($actor, $tx, '50.00');
        self::assertSame('0.0000', $wallet->fresh()->balance);
        $fee = $this->refund($actor, $tx->fresh(), '2.00', true);
        self::assertSame('succeeded', $fee->state);
        self::assertSame(2, AccountMovement::count());
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame('0.0000', AccountReversalReservation::where('payment_operation_id', $fee->id)->sole()->principal);
    }

    public function test_identity_conflict_after_verified_refund_retains_original_wallet_reservation(): void
    {
        [$actor,$owner,$wallet,$invoice,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $other = User::factory()->createQuietly();
        $adapter->mode = 'succeeded';
        $adapter->afterRead = function () use ($invoice, $other) {
            DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => $other->id]);
        };
        $done = $this->reconcile($actor, $op);
        self::assertSame('succeeded', $done->state);
        self::assertTrue($wallet->fresh()->reconciliation_required);
        self::assertSame('reserved', $this->reservation()->state);
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::where('kind', 'deposit_refund')->count());
        DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => $owner->id]);
        $adapter->afterRead = null;
        $this->reconcile($actor, $done);
        self::assertSame('30.0000', $wallet->fresh()->balance);
        self::assertSame(1, $adapter->writes);
        self::assertSame(2, $adapter->reads);
    }

    public function test_verified_refund_overflow_retains_original_result_and_blocks_native_spending(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture(false, '-999999999999990.0000', '999999999999999.9999');
        $adapter = $this->adapter();
        $bill = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $bill->items()->create(['description' => 'Synthetic near-bound usage', 'price' => '59.99', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);
        $this->actingAs($owner);
        (new InvoiceFunding)->fund($owner, $bill, '59.99', (string) Str::uuid());
        $this->actingAs($actor);
        self::assertSame('-999999999999999.9900', $wallet->fresh()->balance);
        $adapter->mode = 'succeeded';
        $op = $this->refund($actor, $tx, '50.00');
        self::assertSame('succeeded', $op->state);
        self::assertTrue($wallet->fresh()->reconciliation_required);
        self::assertSame('reserved', $this->reservation()->state);
        self::assertSame('-999999999999999.9900', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::where('kind', 'deposit_refund')->count());
        self::assertSame('0.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
        self::assertSame('0.00', Credit::sole()->amount);
    }

    public function test_actual_payment_operation_cannot_forge_quiet_terminal_evidence(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $denied = false;
        try {
            $op->updateQuietly(['state' => 'succeeded']);
        } catch (\RuntimeException|AuthorizationException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Quiet provider outcome forgery was accepted');
        self::assertSame('pending', $op->fresh()->state);
    }

    public function test_verified_refund_and_incoming_issue_can_recover_without_mutual_blocking(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $other = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $gateway = $this->depositGateway('0.00');
        $other->items()->create(['description' => 'Synthetic second principal', 'price' => '10.00', 'quantity' => 1, 'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        // Fixture-only activation transition yields a real verified incoming issue.
        // Both operations were already durably prepared while the wallet was active.
        DB::table('account_wallets')->where('id', $wallet->id)->update(['active' => false]);
        $this->verifiedDeposit($other, $gateway, 'synthetic second paid deposit');
        DB::table('account_wallets')->where('id', $wallet->id)->update(['active' => true]);
        $this->actingAs($actor);
        $adapter->mode = 'succeeded';
        $adapter->afterRead = function () use ($owner) {
            DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic shared hold']);
        };
        $done = $this->reconcile($actor, $op);
        self::assertSame('succeeded', $done->state);
        self::assertSame(1, AccountPostingIssue::whereNull('resolved_movement_id')->count());
        self::assertTrue($wallet->fresh()->reconciliation_required);
        self::assertSame('0.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
        DB::table('billmanager_holds')->where('model_type', User::class)->where('model_id', $owner->id)->delete();
        $adapter->afterRead = null;
        $this->reconcile($actor, $done);
        self::assertSame('consumed', $this->reservation()->state, 'Verified outgoing recovery is permanently blocked by the independent incoming issue');
        self::assertSame('0.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable, 'Pending incoming evidence must still deny spending');
        (new DepositLifecycle)->creditPaidInvoice($other->fresh());
        self::assertSame('40.0000', $wallet->fresh()->balance);
        self::assertFalse($wallet->fresh()->reconciliation_required);
        self::assertSame(0, AccountPostingIssue::whereNull('resolved_movement_id')->count());
        self::assertSame(1, $adapter->writes);
        self::assertSame(2, $adapter->reads);
    }

    public function test_native_retry_requires_current_dedicated_reconcile_permission(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $adapter->mode = 'succeeded';
        $adapter->afterRead = function () use ($owner) {
            DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic permission hold']);
        };
        $done = $this->reconcile($actor, $op);
        DB::table('billmanager_holds')->where('model_type', User::class)->where('model_id', $owner->id)->delete();
        $adapter->afterRead = null;
        $actor->role->update(['permissions' => ['admin.invoice_transactions.update', 'admin.invoice_transactions.refund']]);
        $denied = false;
        try {
            (new ProviderOperations)->reconcile($actor, $done);
        } catch (\RuntimeException|AuthorizationException) {
            $denied = true;
        }
        self::assertTrue($denied, 'General edit/refund permission granted native posting recovery');
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertTrue($wallet->fresh()->reconciliation_required);
        self::assertSame('reserved', $this->reservation()->state);
        self::assertSame(1, $adapter->writes);
        self::assertSame(2, $adapter->reads);
        $actor->role->update(['permissions' => ['admin.invoice_transactions.reconcile']]);
        $this->reconcile($actor, $done);
        self::assertSame('30.0000', $wallet->fresh()->balance);
        self::assertSame(1, $adapter->writes);
        self::assertSame(2, $adapter->reads);
    }

    public function test_native_admin_exposes_terminal_principal_recovery_without_provider_replay(): void
    {
        [$actor,$owner,$wallet,$invoice,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $adapter->mode = 'succeeded';
        $adapter->afterRead = function () use ($owner) {
            DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic admin retry hold']);
        };
        $done = $this->reconcile($actor, $op);
        DB::table('billmanager_holds')->where('model_type', User::class)->where('model_id', $owner->id)->delete();
        $adapter->afterRead = null;
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $view = Livewire::test(PaymentOperationsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class]);
        $view->assertTableActionVisible('reconcile', $done)->callTableAction('reconcile', $done)->assertHasNoTableActionErrors();
        self::assertSame('30.0000', $wallet->fresh()->balance);
        self::assertFalse($wallet->fresh()->reconciliation_required);
        self::assertSame(1, $adapter->writes);
        self::assertSame(2, $adapter->reads);
    }

    public function test_two_verified_posting_blockers_recover_each_original_reservation(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $first = $this->refund($actor, $tx, '20.00');
        $second = $this->refund($actor, $tx, '10.00');
        $adapter->mode = 'succeeded';
        $adapter->afterRead = function () use ($owner) {
            DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic independent blocker']);
        };
        $first = $this->reconcile($actor, $first);
        DB::table('billmanager_holds')->where('model_type', User::class)->where('model_id', $owner->id)->delete();
        $second = $this->reconcile($actor, $second);
        self::assertSame(2, AccountReversalReservation::where('posting_required', true)->count());
        DB::table('billmanager_holds')->where('model_type', User::class)->where('model_id', $owner->id)->delete();
        $adapter->afterRead = null;
        $this->reconcile($actor, $first);
        self::assertSame('consumed', AccountReversalReservation::where('payment_operation_id', $first->id)->sole()->state, 'Independent verified refunds permanently block each other');
        self::assertTrue($wallet->fresh()->reconciliation_required);
        self::assertSame('0.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
        $this->reconcile($actor, $second);
        self::assertSame('20.0000', $wallet->fresh()->balance);
        self::assertFalse($wallet->fresh()->reconciliation_required);
        self::assertSame(2, $adapter->writes);
        self::assertSame(4, $adapter->reads);
    }

    public function test_public_verified_external_refund_retry_is_native_only_and_permission_bound(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture(true);
        $refunds = new Refunds;
        $op = $refunds->recordExternal($actor, $tx, '20.00', false, 'synthetic-public-retry', 'Synthetic verified refund', now()->utc()->format('Y-m-d\TH:i:s\Z'), (string) Str::uuid());
        try {
            (new DepositLifecycle)->finalizeRefund($op);
        } catch (\RuntimeException $e) {
            self::fail('Public native retry lacks a safe dependency frame: ' . $e->getMessage());
        }
        self::assertSame('30.0000', $wallet->fresh()->balance);
        self::assertSame(2, AccountMovement::count());
        $actor->role->update(['permissions' => ['admin.invoice_transactions.update']]);
        $denied = false;
        try {
            (new DepositLifecycle)->finalizeRefund($op);
        } catch (\RuntimeException|AuthorizationException) {
            $denied = true;
        }
        self::assertTrue($denied);
        self::assertSame('30.0000', $wallet->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_verified_incoming_deposit_repays_debt_while_original_refund_requires_posting(): void
    {
        [$actor,$owner,$wallet,,$tx] = $this->fixture(false, '-999999999999990.0000', '999999999999999.9999');
        $adapter = $this->adapter();
        $bill = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $bill->items()->create(['description' => 'Synthetic near-bound usage', 'price' => '59.99', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);
        $this->actingAs($owner);
        (new InvoiceFunding)->fund($owner, $bill, '59.99', (string) Str::uuid());
        $this->actingAs($actor);
        $adapter->mode = 'succeeded';
        $op = $this->refund($actor, $tx, '50.00');
        self::assertTrue($wallet->fresh()->reconciliation_required);
        $incoming = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $gateway = $this->depositGateway('0.00');
        $incoming->items()->create(['description' => 'Synthetic debt repayment', 'price' => '100.00', 'quantity' => 1, 'kind' => 'credit_allocation', 'tax_amount' => '0.00', 'reference_type' => Credit::class]);
        $this->verifiedDeposit($incoming, $gateway, 'synthetic-overflow-recovery-income');
        $this->actingAs($actor);
        self::assertSame('-999999999999899.9900', $wallet->fresh()->balance, 'A verified deposit cannot repay debt while a known outgoing posting blocker exists');
        self::assertTrue($wallet->fresh()->reconciliation_required);
        self::assertSame('0.00', (new WalletLedger)->quote($owner, 'USD')->fundingAvailable);
        $this->reconcile($actor, $op);
        self::assertSame('-999999999999949.9900', $wallet->fresh()->balance);
        self::assertFalse($wallet->fresh()->reconciliation_required);
        self::assertSame(1, $adapter->writes);
        self::assertSame(1, $adapter->reads);
    }

    public function test_reserved_posting_schema_cannot_remove_retained_principal_history(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture();
        $this->adapter();
        $this->refund($actor, $tx);
        $migration = require database_path('migrations/2026_10_06_000003_add_reserved_posting_state.php');
        $denied = false;
        try {
            $migration->down();
        } catch (\RuntimeException) {
            $denied = true;
        }
        self::assertTrue($denied);
        self::assertTrue(Schema::hasColumn('account_reversal_reservations', 'posting_required'));
        self::assertSame(1, AccountReversalReservation::count());
        self::assertSame('50.0000', $wallet->fresh()->balance);
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

            public ?\Closure $afterRead = null;

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
                    'transaction_id' => $transaction?->id, 'already_refunded' => $transaction?->refunded_amount ?? '0.00', 'attempt_id' => $attempt?->id, 'attempt_reference' => $attempt?->reference,
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
                if ($this->afterRead) {
                    ($this->afterRead)();
                }
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

    public function test_verified_refund_truth_survives_role_revocation_during_readback_without_money_or_provider_replay(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $role = $actor->role;
        $adapter->mode = 'succeeded';
        $adapter->afterRead = fn () => $role->update(['permissions' => []]);
        try {
            $this->reconcile($actor, $op);
        } catch (AuthorizationException|\RuntimeException $e) {
        }
        self::assertSame('succeeded', $op->fresh()->state, 'Authenticated terminal truth was lost after current authority changed');
        self::assertSame('reserved', $this->reservation()->state);
        self::assertTrue($this->reservation()->posting_required);
        self::assertTrue($wallet->fresh()->reconciliation_required);
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::where('kind', 'deposit_refund')->count());
        $role->update(['permissions' => ['admin.invoice_transactions.reconcile']]);
        $adapter->afterRead = null;
        $this->reconcile($actor, $op->fresh());
        self::assertSame('consumed', $this->reservation()->state);
        self::assertSame('30.0000', $wallet->fresh()->balance);
        self::assertSame(1, $adapter->writes);
        self::assertSame(2, $adapter->reads);
        Http::assertNothingSent();
    }

    public function test_verified_refund_truth_survives_gateway_admin_disable_during_readback_without_money_or_provider_replay(): void
    {
        [$actor,,$wallet,,$tx] = $this->fixture();
        $adapter = $this->adapter();
        $op = $this->refund($actor, $tx);
        $setting = $tx->gateway->settings()->where('key', 'admin_payment_operations_enabled')->sole();
        $adapter->mode = 'succeeded';
        $adapter->afterRead = fn () => $setting->update(['value' => '0']);
        try {
            $this->reconcile($actor, $op);
        } catch (AuthorizationException|\RuntimeException $e) {
        }
        self::assertSame('succeeded', $op->fresh()->state, 'Authenticated terminal truth was lost after gateway operation authority changed');
        self::assertSame('reserved', $this->reservation()->state);
        self::assertTrue($this->reservation()->posting_required);
        self::assertSame('50.0000', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::where('kind', 'deposit_refund')->count());
        $setting->update(['value' => '1']);
        $adapter->afterRead = null;
        $this->reconcile($actor, $op->fresh());
        self::assertSame('consumed', $this->reservation()->state);
        self::assertSame('30.0000', $wallet->fresh()->balance);
        self::assertSame(1, $adapter->writes);
        self::assertSame(2, $adapter->reads);
        Http::assertNothingSent();
    }
}
