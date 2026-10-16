<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHeldException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DirectWriteHoldTest extends TestCase
{
    use RefreshDatabase;

    private function hold(User $user): void
    {
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $user->id, 'reason' => 'migration']);
    }

    public function test_direct_invoice_creation_cannot_notify_or_create_receivable_for_held_user(): void
    {
        $user = User::factory()->create();
        $this->hold($user);
        Bus::fake();
        try {
            Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'pending']);
            $this->fail('Direct invoice write bypassed the hold');
        } catch (MigrationHeldException) {
            $this->assertSame(0, Invoice::where('user_id', $user->id)->count());
            Bus::assertNothingDispatched();
        }
    }

    public function test_direct_transaction_creation_stops_before_any_financial_effect(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::withoutEvents(fn () => Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'pending']));
        $this->hold($user);
        try {
            $invoice->transactions()->create(['amount' => '10.00', 'status' => 'succeeded']);
            $this->fail('Direct payment bypassed the hold');
        } catch (MigrationHeldException) {
            $this->assertSame(0, $invoice->transactions()->count());
            $this->assertSame('pending', $invoice->fresh()->status);
        }
    }

    public function test_credit_cannot_be_spent_before_payment_helper_runs(): void
    {
        $user = User::factory()->create();
        $credit = Credit::create(['user_id' => $user->id, 'currency_code' => 'USD', 'amount' => '10.00']);
        $this->hold($user);
        try {
            $credit->amount = '0.00';
            $credit->save();
            $this->fail('Credit debit bypassed the hold');
        } catch (MigrationHeldException) {
            $this->assertEquals('10.00', $credit->fresh()->amount);
        }
    }
}
