<?php

namespace Tests\Feature\Accounts;

use App\Jobs\Server\UpgradeJob;
use App\Livewire\Services\Upgrade;
use App\Models\AccountDowngradeReceipt;
use App\Models\AccountMovement;
use App\Models\AccountPostingIssue;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Accounts\DepositLifecycle;
use App\Services\Accounts\WalletLedger;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\UsesAccountWallet;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesVerifiedDeposit;
use Tests\Fixtures\Accounts\UpgradeProvider;
use Tests\TestCase;

class DowngradeCreditTest extends TestCase
{
    use UsesAccountWallet, UsesCommittedDatabase, UsesVerifiedDeposit;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(DepositLifecycle::class), 'Completed downgrade credit contract is missing');
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        UpgradeProvider::$after = null;
        UpgradeProvider::$calls = 0;
        config(['settings' => collect(config('settings'))->all()]);
        config(['settings.tax_enabled' => false, 'settings.credits_on_downgrade' => true]);
    }

    private function fixture(string $oldPrice = '50.00', string $newPrice = '20.00'): array
    {
        $owner = User::factory()->createQuietly();
        $wallet = $this->wallet($owner, '-40.0050', '100.00');
        $old = $this->createProduct(['server_id' => null, 'stock' => null]);
        $new = $this->createProduct(['server_id' => null, 'stock' => null]);
        $old->plan->prices()->first()->update(['price' => $oldPrice, 'setup_fee' => '0.00']);
        $new->plan->prices()->first()->update(['price' => $newPrice, 'setup_fee' => '0.00']);
        $service = Service::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'price' => $oldPrice,
            'product_id' => $old->product->id, 'plan_id' => $old->plan->id, 'quantity' => 1, 'status' => 'active', 'expires_at' => now()->addDays(30)]);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $new->product->id, 'plan_id' => $new->plan->id, 'status' => 'pending']);

        return [$owner, $wallet, $service, $upgrade, $new];
    }

    public function test_completed_native_downgrade_repays_debt_once(): void
    {
        [, $wallet, $service, $upgrade] = $this->fixture();
        (new ServiceUpgradeService)->handle($upgrade);
        self::assertSame('completed', $upgrade->fresh()->status);
        self::assertSame('-10.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame('30.0000', AccountMovement::sole()->delta);
        self::assertSame('downgrade', AccountMovement::sole()->kind);
        $receipt = (new DepositLifecycle)->creditCompletedDowngrade($upgrade->fresh());
        self::assertSame(AccountMovement::sole()->id, $receipt->id);
        (new ServiceUpgradeService)->handle($upgrade->fresh());
        self::assertSame(1, AccountMovement::count());
        self::assertSame('20.00', $service->fresh()->price);
        Http::assertNothingSent();
    }

    public function test_large_native_price_difference_remains_exact_to_one_cent(): void
    {
        [, $wallet,, $upgrade] = $this->fixture('999999999999999.99', '999999999999999.98');
        (new ServiceUpgradeService)->handle($upgrade);
        self::assertSame('-39.9950', $wallet->fresh()->balance);
        self::assertSame('0.0100', AccountMovement::sole()->delta);
    }

    public function test_native_client_downgrade_uses_the_ledger_without_a_second_cash_increment(): void
    {
        [$owner, $wallet, $service, $pending, $new] = $this->fixture();
        $pending->delete();
        $service->product->upgrades()->attach($new->product->id);
        $this->actingAs($owner);
        config(['app.version' => 'development']);
        try {
            Livewire::test(Upgrade::class, ['service' => $service->fresh()])->set('upgrade', $new->product->id)->call('doUpgrade')->assertHasNoErrors();
        } catch (RuntimeException $exception) {
            self::fail('Native client downgrade failed: ' . $exception->getMessage());
        }
        self::assertSame('-10.0050', $wallet->fresh()->balance);
        self::assertSame('0.00', Credit::sole()->amount);
        self::assertSame(1, AccountMovement::count());
        self::assertSame('completed', ServiceUpgrade::sole()->status);
        $receipt = AccountDowngradeReceipt::sole();
        $denied = false;
        try {
            $receipt->updateQuietly(['principal' => '99.00']);
        } catch (RuntimeException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Completion evidence must resist quiet edits');
    }

    public function test_native_client_upgrade_preserves_a_large_positive_one_cent_charge(): void
    {
        [$owner, $wallet, $service, $pending, $new] = $this->fixture('999999999999999.98', '999999999999999.99');
        $pending->delete();
        $service->product->upgrades()->attach($new->product->id);
        $this->actingAs($owner);
        config(['app.version' => 'development']);
        try {
            Livewire::test(Upgrade::class, ['service' => $service->fresh()])->set('upgrade', $new->product->id)->call('doUpgrade')->assertHasNoErrors();
        } catch (RuntimeException $exception) {
            self::fail('Native one-cent client upgrade failed: ' . $exception->getMessage());
        }
        self::assertSame(1, Invoice::count(), 'The positive upgrade must remain unpaid until its cent invoice is settled');
        self::assertSame('0.01', Invoice::sole()->items()->sole()->price);
        self::assertSame('pending', ServiceUpgrade::sole()->status);
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_unknown_provider_result_requires_reconciliation_before_another_request(): void
    {
        class_exists(UpgradeProvider::class);
        UpgradeProvider::$succeeds = null;
        UpgradeProvider::$calls = 0;
        [, $wallet, $service, $upgrade, $new] = $this->fixture();
        $server = Server::create(['name' => 'Synthetic unknown upgrade', 'extension' => 'AccountUpgradeFixture', 'type' => 'server']);
        $new->product->update(['server_id' => $server->id]);
        (new ServiceUpgradeService)->handle($upgrade);
        try {
            (new UpgradeJob($service->fresh(), false, $upgrade->id))->handle();
        } catch (RuntimeException) {
        }
        self::assertSame('uncertain', AccountDowngradeReceipt::sole()->provider_state);
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        UpgradeProvider::$succeeds = true;
        $denied = false;
        try {
            (new UpgradeJob($service->fresh(), false, $upgrade->id))->handle();
        } catch (RuntimeException) {
            $denied = true;
        }
        self::assertTrue($denied);
        self::assertSame(1, UpgradeProvider::$calls);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_confirmed_provider_result_is_retained_when_a_new_hold_blocks_account_posting(): void
    {
        UpgradeProvider::$succeeds = true;
        [$owner, $wallet, $service, $upgrade, $new] = $this->fixture();
        $server = Server::create(['name' => 'Synthetic held upgrade', 'extension' => 'AccountUpgradeFixture', 'type' => 'server']);
        $new->product->update(['server_id' => $server->id]);
        (new ServiceUpgradeService)->handle($upgrade);
        UpgradeProvider::$after = fn () => DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic new hold']);
        try {
            (new UpgradeJob($service->fresh(), false, $upgrade->id))->handle();
        } catch (RuntimeException) {
        }
        self::assertSame('confirmed', AccountDowngradeReceipt::sole()->provider_state);
        self::assertSame(1, UpgradeProvider::$calls);
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame(1, AccountPostingIssue::count());
        self::assertTrue((new WalletLedger)->quote($owner, 'USD')->blocked);
        // Fixture-only simulation of authorized hold resolution.
        DB::table('billmanager_holds')->where('model_type', User::class)->where('model_id', $owner->id)->update(['released_at' => now()]);
        UpgradeProvider::$after = null;
        (new UpgradeJob($service->fresh(), false, $upgrade->id))->handle();
        self::assertSame(1, UpgradeProvider::$calls);
        self::assertSame('-10.0050', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
        self::assertSame(AccountMovement::sole()->id, AccountPostingIssue::sole()->resolved_movement_id);
        (new UpgradeJob($service->fresh(), false, $upgrade->id))->handle();
        self::assertSame(1, UpgradeProvider::$calls);
        self::assertSame(1, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_paid_upgrade_invoice_cannot_be_reinterpreted_as_an_earned_downgrade(): void
    {
        [$owner, $wallet,, $upgrade] = $this->fixture();
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $upgrade->update(['invoice_id' => $invoice->id]);
        $invoice->items()->create(['description' => 'Synthetic previously quoted upgrade', 'price' => '30.00', 'quantity' => 1,
            'kind' => 'product', 'tax_amount' => '0.00', 'reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id]);
        $gateway = $this->depositGateway('0.00');
        $this->verifiedDeposit($invoice, $gateway, 'synthetic-paid-upgrade');
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertSame('completed', $upgrade->fresh()->status);
        self::assertSame('-40.0050', $wallet->fresh()->balance, 'A paid upgrade cannot create extra income from later catalog differences');
        self::assertSame(0, AccountMovement::count());
        self::assertSame(0, AccountDowngradeReceipt::count());
    }

    public function test_requested_downgrade_cannot_create_a_credit(): void
    {
        [, $wallet,, $upgrade] = $this->fixture();
        $denied = false;
        try {
            (new DepositLifecycle)->creditCompletedDowngrade($upgrade);
        } catch (RuntimeException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Requested downgrade was accepted as completed');
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_forged_completed_marker_is_not_a_completed_downgrade_receipt(): void
    {
        [, $wallet,, $upgrade] = $this->fixture();
        $upgrade->updateQuietly(['status' => 'completed']);
        $denied = false;
        try {
            (new DepositLifecycle)->creditCompletedDowngrade($upgrade);
        } catch (RuntimeException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Unbacked completed marker credited an account');
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_native_downgrade_and_account_credit_roll_back_together(): void
    {
        [, $wallet, $service, $upgrade] = $this->fixture();
        $oldProduct = $service->product_id;
        try {
            DB::transaction(function () use ($upgrade, $wallet) {
                (new ServiceUpgradeService)->handle($upgrade);
                self::assertSame('-10.0050', $wallet->fresh()->balance);
                throw new RuntimeException('synthetic rollback');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic rollback', $exception->getMessage());
        }
        self::assertSame('pending', $upgrade->fresh()->status);
        self::assertSame($oldProduct, $service->fresh()->product_id);
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
    }

    public function test_provider_downgrade_cannot_credit_before_confirmed_job_success(): void
    {
        class_exists(UpgradeProvider::class);
        UpgradeProvider::$succeeds = false;
        [, $wallet, $service, $upgrade, $new] = $this->fixture();
        $server = Server::create(['name' => 'Synthetic upgrade provider', 'extension' => 'AccountUpgradeFixture', 'type' => 'server']);
        $new->product->update(['server_id' => $server->id]);
        (new ServiceUpgradeService)->handle($upgrade);
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        try {
            (new UpgradeJob($service->fresh(), false, $upgrade->id))->handle();
        } catch (RuntimeException) {
            // A confirmed failure must never credit the account.
        }
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame(0, AccountMovement::count());
        UpgradeProvider::$succeeds = true;
        (new UpgradeJob($service->fresh(), false, $upgrade->id))->handle();
        self::assertSame('-10.0050', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
        (new DepositLifecycle)->creditCompletedDowngrade($upgrade->fresh());
        self::assertSame(1, AccountMovement::count());
        Http::assertNothingSent();
    }

    public function test_confirmed_downgrade_retains_original_managed_receipt_when_current_service_owner_has_no_wallet(): void
    {
        UpgradeProvider::$succeeds = true;
        [$owner, $wallet, $service, $upgrade, $new] = $this->fixture();
        $other = User::factory()->createQuietly();
        $server = Server::create(['name' => 'Synthetic transferred upgrade', 'extension' => 'AccountUpgradeFixture', 'type' => 'server']);
        $new->product->update(['server_id' => $server->id]);
        (new ServiceUpgradeService)->handle($upgrade);
        UpgradeProvider::$after = fn () => DB::table('services')->where('id', $service->id)->update(['user_id' => $other->id]);
        try {
            (new UpgradeJob($service->fresh(), false, $upgrade->id))->handle();
        } catch (RuntimeException) {
        }
        self::assertSame('confirmed', AccountDowngradeReceipt::sole()->provider_state);
        self::assertSame(1, UpgradeProvider::$calls);
        self::assertSame('-40.0050', $wallet->fresh()->balance);
        self::assertSame(1, AccountPostingIssue::count(), 'Original managed completion was discarded as an unmanaged current owner');
        self::assertSame($owner->id, AccountPostingIssue::sole()->user_id);
        self::assertTrue((new WalletLedger)->quote($owner, 'USD')->blocked);
        self::assertSame(0, Credit::where('user_id', $other->id)->count());
        DB::table('services')->where('id', $service->id)->update(['user_id' => $owner->id]);
        UpgradeProvider::$after = null;
        (new UpgradeJob($service->fresh(), false, $upgrade->id))->handle();
        self::assertSame(1, UpgradeProvider::$calls);
        self::assertSame('-10.0050', $wallet->fresh()->balance);
        self::assertSame(1, AccountMovement::count());
        self::assertSame(AccountMovement::sole()->id, AccountPostingIssue::sole()->resolved_movement_id);
        Http::assertNothingSent();
    }
}
