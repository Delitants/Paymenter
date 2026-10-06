<?php

namespace Tests\Feature\Accounts;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Accounts\AccountFundingReversals;
use App\Services\Accounts\InvoiceFunding;
use App\Services\Accounts\WalletLedger;
use App\Services\Billing\InvoicePricing;
use App\Services\Gateways\PaymentAttempts;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\UsesAccountWallet;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesVerifiedDeposit;
use Tests\TestCase;

class InternalReversalTest extends TestCase
{
    use UsesAccountWallet,UsesCommittedDatabase,UsesVerifiedDeposit;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        self::assertTrue(class_exists(AccountFundingReversals::class), 'Missing native allocation reversal contract');
    }

    private function fixture(string $opening = '10.00', bool $renewal = false): array
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, $opening, '100.00');
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '0.0000']);
        $service = null;
        if ($renewal) {
            $product = $this->createProduct();
            $product->plan->update(['type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
            $service = Service::factory()->create(['user_id' => $owner->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'currency_code' => 'USD', 'expires_at' => '2026-12-01']);
        }
        $invoice->items()->create(['description' => 'Synthetic funded product', 'price' => '15.00', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00', 'reference_type' => $service ? Service::class : null, 'reference_id' => $service?->id]);
        $this->actingAs($owner);
        $fundingKey = (string) Str::uuid();
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', $fundingKey);
        $role = Role::create(['name' => 'Synthetic internal reversal staff', 'permissions' => ['admin.invoice_transactions.account_reverse']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);

        return [$actor, $owner, $wallet, $invoice->fresh(), $allocation, $fundingKey, $service];
    }

    private function reverse(User $actor, AccountFundingAllocation $allocation, string $amount = '5.00', ?string $key = null, string $reason = 'Synthetic correction'): AccountMovement
    {
        try {
            return (new AccountFundingReversals)->reverse($actor, $allocation, $amount, $reason, $key ?? (string) Str::uuid());
        } catch (\RuntimeException $e) {
            self::fail('Native internal reversal failed: ' . $e->getMessage());
        }
    }

    private function denied(callable $write): void
    {
        $denied = false;
        try {
            $write();
        } catch (\RuntimeException|AuthorizationException|\DomainException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Unapproved internal reversal was accepted');
    }

    public function test_partial_reversal_replays_one_linked_receipt_and_reopens_only_reversed_principal(): void
    {
        [$actor,,$wallet,$invoice,$allocation] = $this->fixture();
        $key = (string) Str::uuid();
        $original = $allocation->transaction->getAttributes();
        $first = $this->reverse($actor, $allocation, '5.00', $key);
        $again = $this->reverse($actor, $allocation, '5.00', $key);
        self::assertSame($first->id, $again->id);
        self::assertSame('internal_reversal', $first->kind);
        self::assertSame($allocation->movement_id, $first->linked_reversal_id);
        self::assertSame('5.0000', $first->delta);
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame('5.00', $allocation->fresh()->reversed_amount);
        self::assertSame($original, $allocation->transaction->fresh()->getAttributes());
        self::assertSame('pending', $invoice->fresh()->status);
        self::assertSame('5.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame(2, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_full_reversal_restores_same_signed_principal_and_preserves_native_history(): void
    {
        [$actor,,$wallet,$invoice,$allocation] = $this->fixture();
        $this->reverse($actor, $allocation, '15.00');
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame('10.00', Credit::sole()->amount);
        self::assertSame('15.00', $allocation->fresh()->reversed_amount);
        self::assertSame('15.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        self::assertSame(1, $invoice->transactions()->count());
        self::assertSame(1, InvoicePaidProcessing::count());
        Http::assertNothingSent();
    }

    public function test_multiple_partial_reversals_cannot_exceed_original_allocation(): void
    {
        [$actor,,$wallet,,$allocation] = $this->fixture();
        $this->reverse($actor, $allocation, '5.00');
        $this->reverse($actor, $allocation, '10.00');
        $this->denied(fn () => (new AccountFundingReversals)->reverse($actor, $allocation, '0.01', 'Synthetic excessive correction', (string) Str::uuid()));
        self::assertSame('15.00', $allocation->fresh()->reversed_amount);
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame(3, AccountMovement::count());
    }

    public function test_changed_amount_reason_or_actor_cannot_reuse_original_request(): void
    {
        [$actor,,$wallet,,$allocation] = $this->fixture();
        $key = (string) Str::uuid();
        $this->reverse($actor, $allocation, '5.00', $key);
        $other = User::factory()->createQuietly(['role_id' => $actor->role_id]);
        foreach ([[$actor, '6.00', 'Synthetic correction'], [$actor, '5.00', 'Different correction'], [$other, '5.00', 'Synthetic correction']] as [$staff,$amount,$reason]) {
            $this->actingAs($staff);
            $this->denied(fn () => (new AccountFundingReversals)->reverse($staff, $allocation, $amount, $reason, $key));
        }
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame(2, AccountMovement::count());
    }

    public function test_original_funding_replay_and_new_collection_never_repeat_fulfillment(): void
    {
        [$actor,$owner,$wallet,$invoice,$allocation,$fundingKey] = $this->fixture();
        $this->reverse($actor, $allocation);
        $this->actingAs($owner);
        $again = (new InvoiceFunding)->fund($owner, $invoice->fresh(), '15.00', $fundingKey);
        self::assertSame($allocation->id, $again->id);
        self::assertSame('0.0000', $wallet->fresh()->balance);
        (new InvoiceFunding)->fund($owner, $invoice->fresh(), '5.00', (string) Str::uuid());
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame('0.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame(2, $invoice->transactions()->count());
    }

    public function test_internal_reversal_and_recollection_do_not_renew_service_twice(): void
    {
        [$actor,$owner,$wallet,$invoice,$allocation,,$service] = $this->fixture(renewal: true);
        self::assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());
        $this->reverse($actor, $allocation);
        $this->actingAs($owner);
        (new InvoiceFunding)->fund($owner, $invoice->fresh(), '5.00', (string) Str::uuid());
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());
        self::assertSame(1, InvoicePaidProcessing::count());
        Http::assertNothingSent();
    }

    public function test_cancelled_invoice_keeps_cancellation_after_internal_refund(): void
    {
        [$actor,,$wallet,$invoice,$allocation] = $this->fixture();
        $invoice->update(['status' => 'cancelled']);
        $this->reverse($actor, $allocation, '15.00');
        self::assertSame('10.0000', $wallet->fresh()->balance);
        self::assertSame('cancelled', $invoice->fresh()->status);
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame(2, AccountMovement::count());
    }

    public function test_reversal_reduces_current_fractional_debt_before_creating_cash(): void
    {
        [$actor,$owner,$wallet,,$allocation] = $this->fixture('-40.0050');
        $this->reverse($actor, $allocation);
        self::assertSame('-50.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame('50.0050', (new WalletLedger)->quote($owner, 'USD')->debt);
    }

    public function test_native_admin_action_exposes_only_dedicated_internal_reversal(): void
    {
        [$actor,,$wallet,$invoice,$allocation] = $this->fixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $view = Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class]);
        try {
            $view->assertTableActionVisible('account_reverse', $allocation->transaction)->assertTableActionHidden('provider_refund', $allocation->transaction)
                ->callTableAction('account_reverse', $allocation->transaction, data: ['amount' => '5.00', 'reason' => 'Synthetic correction', 'request_key' => (string) Str::uuid()])->assertHasNoTableActionErrors();
        } catch (ActionNotResolvableException $e) {
            self::fail('Missing native internal reversal action: ' . $e->getMessage());
        }
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame('5.00', $allocation->fresh()->reversed_amount);
    }

    public function test_general_edit_refund_and_unrelated_reader_cannot_reverse(): void
    {
        [$actor,,$wallet,,$allocation] = $this->fixture();
        $actor->role->update(['permissions' => ['admin.invoice_transactions.update', 'admin.invoice_transactions.refund']]);
        $this->denied(fn () => (new AccountFundingReversals)->reverse($actor, $allocation, '5.00', 'Synthetic unapproved', (string) Str::uuid()));
        $unrelated = User::factory()->createQuietly();
        $this->actingAs($unrelated);
        $this->denied(fn () => (new AccountFundingReversals)->reverse($unrelated, $allocation, '5.00', 'Synthetic unrelated', (string) Str::uuid()));
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame('0.00', $allocation->fresh()->reversed_amount);
    }

    public function test_hold_disabled_facility_or_inactive_wallet_denies_new_reversal(): void
    {
        [$actor,$owner,$wallet,,$allocation] = $this->fixture();
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic reversal hold']);
        $this->denied(fn () => (new AccountFundingReversals)->reverse($actor, $allocation, '5.00', 'Synthetic held', (string) Str::uuid()));
        DB::table('billmanager_holds')->where('model_type', User::class)->where('model_id', $owner->id)->delete();
        config(['account-funding.enabled' => false]);
        $this->denied(fn () => (new AccountFundingReversals)->reverse($actor, $allocation, '5.00', 'Synthetic disabled', (string) Str::uuid()));
        config(['account-funding.enabled' => true]);
        DB::table('account_wallets')->where('id', $wallet->id)->update(['active' => false]);
        $this->denied(fn () => (new AccountFundingReversals)->reverse($actor, $allocation, '5.00', 'Synthetic inactive', (string) Str::uuid()));
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
    }

    public function test_changed_native_financial_owner_does_not_redirect_reversal(): void
    {
        [$actor,,$wallet,$invoice,$allocation] = $this->fixture();
        $other = User::factory()->createQuietly();
        DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => $other->id]);
        $this->denied(fn () => (new AccountFundingReversals)->reverse($actor, $allocation, '5.00', 'Synthetic wrong owner', (string) Str::uuid()));
        self::assertSame('-5.0000', $wallet->fresh()->balance);
        self::assertSame('0.00', $allocation->fresh()->reversed_amount);
        self::assertSame(1, AccountMovement::count());
    }

    public function test_mixed_invoice_recollects_reversed_internal_principal_with_new_fee_and_preserves_old_gateway_receipt(): void
    {
        config(['settings.tax_enabled' => false]);
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '0.0000']);
        $invoice->items()->create(['description' => 'Synthetic mixed collection', 'price' => '50.00', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);
        $this->actingAs($owner);
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', (string) Str::uuid());
        $gateway = $this->depositGateway();
        $attempts = new PaymentAttempts;
        $first = $attempts->begin($gateway, $invoice->fresh(), hash('sha256', 'synthetic mixed merchant'), 'USD');
        self::assertSame('37.00', $first->amount);
        $attempts->settle($gateway, $first->reference, $first->merchant_fingerprint, $first->amount, 'USD', 'synthetic-original-mixed-payment');
        self::assertSame('paid', $invoice->fresh()->status);
        $oldAttempt = $first->fresh()->getAttributes();
        $oldTransaction = $invoice->transactions()->where('gateway_id', $gateway->id)->sole()->getAttributes();
        $oldFee = $invoice->items()->where('kind', 'gateway_fee')->sole()->getAttributes();
        $role = Role::create(['name' => 'Synthetic mixed reversal staff', 'permissions' => ['admin.invoice_transactions.account_reverse']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);
        $this->reverse($actor, $allocation, '5.00');
        self::assertSame('5.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        $attempts->settle($gateway, $first->reference, $first->merchant_fingerprint, $first->amount, 'USD', 'synthetic-original-mixed-payment');
        self::assertSame('pending', $invoice->fresh()->status);
        $this->actingAs($owner);
        try {
            $second = $attempts->begin($gateway, $invoice->fresh(), $first->merchant_fingerprint, 'USD');
        } catch (\RuntimeException $e) {
            self::fail('Reversed mixed principal is not collectible: ' . $e->getMessage());
        }
        self::assertNotSame($first->id, $second->id);
        self::assertSame('7.00', $second->amount);
        $attempts->settle($gateway, $second->reference, $second->merchant_fingerprint, $second->amount, 'USD', 'synthetic-new-mixed-payment');
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame('0.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        self::assertSame('54.00', (new InvoicePricing)->summary($invoice->fresh())->total);
        self::assertSame(['net' => '5.00', 'tax' => '0.00', 'fee' => '2.00'], $invoice->transactions()->where('transaction_id', 'gateway:' . $gateway->id . ':synthetic-new-mixed-payment')->sole()->original_allocation);
        $attempts->settle($gateway, $first->reference, $first->merchant_fingerprint, $first->amount, 'USD', 'synthetic-original-mixed-payment');
        $attempts->settle($gateway, $second->reference, $second->merchant_fingerprint, $second->amount, 'USD', 'synthetic-new-mixed-payment');
        self::assertSame($oldAttempt, $first->fresh()->getAttributes());
        self::assertSame($oldTransaction, InvoiceTransaction::findOrFail($oldTransaction['id'])->getAttributes());
        self::assertSame($oldFee, InvoiceItem::findOrFail($oldFee['id'])->getAttributes());
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame('0.0000', $wallet->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_manually_settled_mixed_invoice_recollects_reversed_principal_without_repricing_original_fee(): void
    {
        config(['settings.tax_enabled' => false]);
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $gateway = $this->depositGateway();
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '0.0000']);
        $invoice->items()->create(['description' => 'Synthetic manually collected product', 'price' => '50.00', 'quantity' => 1, 'kind' => 'product', 'tax_amount' => '0.00']);
        $this->actingAs($owner);
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', (string) Str::uuid());
        $invoice->items()->create(['description' => 'Synthetic original collected fee', 'price' => '2.00', 'quantity' => 1, 'kind' => 'gateway_fee', 'tax_amount' => '0.00', 'gateway_id' => $gateway->id]);
        $this->manualDeposit($invoice, $gateway, '37.00', 'synthetic-original-manual-mixed');
        self::assertSame('paid', $invoice->fresh()->status);
        $oldTransaction = $invoice->transactions()->where('settlement_origin', 'manual_record')->sole()->getAttributes();
        $oldFee = $invoice->items()->where('kind', 'gateway_fee')->sole()->getAttributes();
        $role = Role::create(['name' => 'Synthetic manually collected reversal staff', 'permissions' => ['admin.invoice_transactions.account_reverse']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);
        $this->reverse($actor, $allocation, '5.00');
        self::assertSame('5.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        $this->actingAs($owner);
        $attempts = new PaymentAttempts;
        try {
            $attempt = $attempts->begin($gateway, $invoice->fresh(), hash('sha256', 'synthetic manual recollection merchant'), 'USD');
        } catch (\RuntimeException $e) {
            self::fail('Manually collected reversed principal is not collectible: ' . $e->getMessage());
        }
        self::assertSame('7.00', $attempt->amount);
        $attempts->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, 'USD', 'synthetic-manual-recollection-payment');
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame('0.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        self::assertSame('54.00', (new InvoicePricing)->summary($invoice->fresh())->total);
        self::assertSame($oldTransaction, InvoiceTransaction::findOrFail($oldTransaction['id'])->getAttributes());
        self::assertSame($oldFee, InvoiceItem::findOrFail($oldFee['id'])->getAttributes());
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame('0.0000', $wallet->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_final_review_never_paid_partial_reversal_can_complete_first_external_payment_once(): void
    {
        config(['settings.tax_enabled' => false]);
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '10.00', '100.00');
        $product = $this->createProduct();
        $product->plan->update(['type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
        $service = Service::factory()->create(['user_id' => $owner->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'currency_code' => 'USD', 'expires_at' => '2026-12-01']);
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'pricing_tax_rate' => '0.0000']);
        $invoice->items()->create(['description' => 'Synthetic initially unpaid renewal', 'price' => '50.00', 'quantity' => 1,
            'kind' => 'product', 'tax_amount' => '0.00', 'reference_type' => Service::class, 'reference_id' => $service->id]);
        $this->actingAs($owner);
        $allocation = (new InvoiceFunding)->fund($owner, $invoice, '15.00', (string) Str::uuid());
        $role = Role::create(['name' => 'Synthetic unpaid reversal staff', 'permissions' => ['admin.invoice_transactions.account_reverse']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);
        $this->reverse($actor, $allocation, '5.00');
        self::assertSame(0, InvoicePaidProcessing::count());
        self::assertSame('2026-12-01', $service->fresh()->expires_at->toDateString());
        self::assertSame('40.00', (new InvoicePricing)->summary($invoice->fresh())->payable);
        $this->actingAs($owner);
        $gateway = $this->depositGateway();
        $attempts = new PaymentAttempts;
        try {
            $attempt = $attempts->begin($gateway, $invoice->fresh(), hash('sha256', 'synthetic first collection after reversal'), 'USD');
        } catch (\RuntimeException $exception) {
            self::fail('Initially unpaid reversed principal cannot be collected: ' . $exception->getMessage());
        }
        self::assertSame('42.00', $attempt->amount);
        $attempts->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, 'USD', 'synthetic-first-reversed-payment');
        $attempts->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, 'USD', 'synthetic-first-reversed-payment');
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());
        self::assertSame(1, InvoicePaidProcessing::count());
        self::assertSame('0.0000', $wallet->fresh()->balance);
        self::assertSame('5.00', $allocation->fresh()->reversed_amount);
        self::assertSame(['net' => '40.00', 'tax' => '0.00', 'fee' => '2.00'], $invoice->transactions()->where('gateway_id', $gateway->id)->sole()->original_allocation);
        Http::assertNothingSent();
    }
}
