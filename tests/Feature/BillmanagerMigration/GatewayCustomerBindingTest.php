<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CustomerBindings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

class GatewayCustomerBindingTest extends TestCase
{
    use UsesCommittedDatabase;

    public function test_overlapping_checkouts_share_one_durable_customer_claim(): void
    {
        $user = User::factory()->create();
        $bindings = new CustomerBindings;
        $merchant = hash('sha256', 'synthetic-business');
        $calls = 0;
        $id = $bindings->resolve('Wave', $merchant, $user, function () use ($bindings, $merchant, $user, &$calls) {
            $calls++;
            try {
                $bindings->resolve('Wave', $merchant, $user, function () use (&$calls) {
                    $calls++;

                    return 'duplicate-customer';
                });
                $this->fail('An overlapping checkout took the customer claim');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('reconciliation', $e->getMessage());
            }

            return 'synthetic-customer';
        });
        $this->assertSame('synthetic-customer', $id);
        $this->assertSame($id, $bindings->resolve('Wave', $merchant, $user, function () use (&$calls) {
            $calls++;

            return 'duplicate-customer';
        }));
        $this->assertSame(1, $calls);
        $row = DB::table('gateway_customer_bindings')->sole();
        $this->assertSame('ready', $row->state);
        $this->assertStringNotContainsString('synthetic-customer', $row->provider_reference);
        $this->assertSame('synthetic-customer', Crypt::decryptString($row->provider_reference));
    }

    public function test_unknown_customer_creation_remains_claimed_across_later_invoices(): void
    {
        $user = User::factory()->create();
        $merchant = hash('sha256', 'synthetic-business');
        $calls = 0;
        for ($i = 0; $i < 2; $i++) {
            $rejected = false;
            try {
                (new CustomerBindings)->resolve('Wave', $merchant, $user, function () use (&$calls) {
                    $calls++;
                    throw new \RuntimeException('Uncertain synthetic response');
                });
            } catch (\RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Uncertain customer creation was accepted');
            $this->assertSame(1, $calls);
        }
        $this->assertSame('initializing', DB::table('gateway_customer_bindings')->sole()->state);
    }

    public function test_customer_binding_is_merchant_scoped_and_email_drift_requires_reconciliation(): void
    {
        $user = User::factory()->create();
        $bindings = new CustomerBindings;
        $a = hash('sha256', 'business-a');
        $b = hash('sha256', 'business-b');
        $this->assertSame('customer-a', $bindings->resolve('Wave', $a, $user, fn () => 'customer-a'));
        $this->assertSame('customer-b', $bindings->resolve('Wave', $b, $user, fn () => 'customer-b'));
        $user->email = 'changed@example.test';
        $user->save();
        $called = false;
        try {
            $bindings->resolve('Wave', $a, $user, function () use (&$called) {
                $called = true;

                return 'new-customer';
            });
            $this->fail('Changed email silently created a new provider identity');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reconciliation', $e->getMessage());
        }
        $this->assertFalse($called);
        $this->assertSame(2, DB::table('gateway_customer_bindings')->count());
    }

    public function test_held_customer_is_rejected_before_claim_or_provider_callback(): void
    {
        $user = User::factory()->create();
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $user->id, 'reason' => 'Synthetic migration']);
        $called = false;
        try {
            (new CustomerBindings)->resolve('Wave', hash('sha256', 'synthetic-business'), $user, function () use (&$called) {
                $called = true;

                return 'customer';
            });
            $this->fail('Held customer reached provider creation');
        } catch (MigrationHeldException) {
            $this->assertFalse($called);
            $this->assertSame(0, DB::table('gateway_customer_bindings')->count());
        }
    }

    public function test_outer_transaction_cannot_roll_back_a_claim_after_a_provider_write(): void
    {
        $user = User::factory()->create();
        $called = false;
        DB::beginTransaction();
        try {
            (new CustomerBindings)->resolve('Wave', hash('sha256', 'synthetic-business'), $user, function () use (&$called) {
                $called = true;

                return 'customer';
            });
            $this->fail('Provider call ran inside a rollbackable claim transaction');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('autocommit', $e->getMessage());
            $this->assertFalse($called);
            $this->assertSame(0, DB::table('gateway_customer_bindings')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_provider_customer_cannot_be_reassigned_to_another_native_user(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $bindings = new CustomerBindings;
        $merchant = hash('sha256', 'synthetic-business');
        $bindings->resolve('Wave', $merchant, $first, fn () => 'same-provider-customer');
        try {
            $bindings->resolve('Wave', $merchant, $second, fn () => 'same-provider-customer');
            $this->fail('Provider customer was assigned to two native users');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reconciliation', $e->getMessage());
        }
        $this->assertSame(1, DB::table('gateway_customer_bindings')->where('state', 'ready')->count());
        $this->assertSame(1, DB::table('gateway_customer_bindings')->where('user_id', $second->id)->where('state', 'initializing')->count());
    }
}
