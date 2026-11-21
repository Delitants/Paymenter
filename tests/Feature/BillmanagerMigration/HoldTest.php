<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Service\RenewServiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HoldTest extends TestCase
{
    use RefreshDatabase;

    private function heldService(): Service
    {
        Event::fake();
        Bus::fake();
        Mail::fake();
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::create([
            'user_id' => $user->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'price' => '10.00', 'currency_code' => 'USD',
            'status' => 'active', 'expires_at' => '2026-01-01',
        ]);
        DB::table('billmanager_holds')->insert([
            'model_type' => Service::class, 'model_id' => $service->id, 'reason' => 'migration',
        ]);

        return $service;
    }

    public function test_all_lifecycle_helpers_and_jobs_stop_before_side_effects(): void
    {
        $service = $this->heldService();
        $operations = ['createServer', 'suspendServer', 'unsuspendServer', 'terminateServer', 'upgradeServer'];
        foreach ($operations as $method) {
            try {
                ExtensionHelper::$method($service);
                $this->fail($method . ' did not enforce the hold');
            } catch (MigrationHeldException $e) {
                $this->assertStringContainsString('migration', $e->getMessage());
            }
        }
        foreach (['Create', 'Suspend', 'Unsuspend', 'Terminate', 'Upgrade'] as $name) {
            $class = 'App\\Jobs\\Server\\' . $name . 'Job';
            try {
                (new $class($service))->handle();
                $this->fail($name . ' job did not enforce the hold');
            } catch (MigrationHeldException $e) {
                $this->assertStringContainsString('migration', $e->getMessage());
            }
        }
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_renewal_does_not_advance_an_imported_service(): void
    {
        $service = $this->heldService();
        try {
            (new RenewServiceService)->handle($service);
            $this->fail('Renewal bypassed the hold');
        } catch (MigrationHeldException) {
            $this->assertSame('2026-01-01', $service->fresh()->expires_at->format('Y-m-d'));
        }
    }

    public function test_custom_service_action_stops_before_extension_resolution(): void
    {
        $service = $this->heldService();
        $this->expectException(MigrationHeldException::class);
        ExtensionHelper::callService($service, 'restart');
    }

    public function test_generic_extension_call_cannot_swallow_a_hold(): void
    {
        $service = $this->heldService();
        $this->expectException(MigrationHeldException::class);
        ExtensionHelper::call(null, 'restart', [$service], true);
    }

    public function test_held_invoice_rejects_payment_before_transaction_creation(): void
    {
        $service = $this->heldService();
        $invoice = Invoice::create(['user_id' => $service->user_id, 'currency_code' => 'USD', 'status' => 'pending']);
        DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $invoice->id, 'reason' => 'migration']);
        try {
            ExtensionHelper::addPayment($invoice->id, null, '10.00', transactionId: 'original-receipt');
            $this->fail('Held invoice accepted a payment');
        } catch (MigrationHeldException) {
            $this->assertSame(0, $invoice->transactions()->count());
        }
    }

    public function test_account_hold_applies_to_new_invoice_and_unheld_users_stay_usable(): void
    {
        $service = $this->heldService();
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $service->user_id, 'reason' => 'migration']);
        $invoice = new Invoice(['user_id' => $service->user_id, 'currency_code' => 'USD']);
        $this->assertTrue(MigrationHold::isHeld($invoice));
        $this->assertFalse(MigrationHold::isHeld(User::factory()->create()));
    }

    public function test_daily_cron_skips_held_services(): void
    {
        $service = $this->heldService();
        $this->artisan('app:cron-job')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(0, Invoice::count());
        Bus::assertNothingDispatched();
    }
}
