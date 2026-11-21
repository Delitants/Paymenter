<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Livewire\Billing\LegacyHistory;
use App\Models\BillmanagerFinancialRecord;
use App\Models\BillmanagerRecord;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\FinancialImporter;
use App\Services\BillmanagerMigration\FinancialReconciler;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(array $overrides = []): array
    {
        $tables = array_replace([
            'accounts' => [['id' => '10']],
            'users' => array_map(fn ($id) => ['id' => (string) $id, 'account' => '10', 'enabled' => 'on', 'level' => '16',
                'email' => 'client' . $id . '@example.test', 'realname' => 'Synthetic Client', 'last_login' => '2025-01-01 00:00:00'], [11, 12]),
            'profiles' => [['id' => '21', 'account' => '10', 'name' => 'Synthetic Payer']],
            'currencies' => [['id' => '1', 'iso' => 'USD'], ['id' => '2', 'iso' => 'EUR']],
            'subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => '-1450.8693', 'creditlimit' => '100.0000', 'allowpostpaid' => 'on']],
            'payments' => array_map(fn ($id, $status) => ['id' => (string) $id, 'subaccount' => '31', 'currency' => '1', 'status' => (string) $status,
                'subaccountamount' => '1.2345', 'paymethodamount' => '1.2345', 'usedamount' => '0.0000', 'commissionamount' => '0.0100',
                'taxamount' => null, 'number' => 'R-' . $id, 'externalid' => 'provider-' . $id, 'createdate' => '2020-01-02 00:00:00', 'paydate' => null,
            ], [41, 42, 43, 44], [3, 4, 7, 9]),
            'invoices' => [['id' => '51', 'customer' => '21', 'currency' => '2', 'invoice_status' => '1', 'number' => 'I-51', 'amount' => '3.4500', 'realamount' => '3.4500', 'cdate' => '2020-01-02']],
            'invoiceitems' => [['id' => '61', 'invoice' => '51', 'amount' => '3.4500', 'realamount' => '3.4500', 'taxamount' => '0.0000', 'name' => 'Synthetic service']],
            'invoiceitem_payments' => [['invoiceitem' => '61', 'payment' => '42', 'amount' => '1.2345']],
            'payment_history' => [['reference' => '42', 'status' => '1', 'status_new' => '4', 'changedate' => '2020-01-03 00:00:00']],
        ], $overrides);
        $path = tempnam(sys_get_temp_dir(), 'financial-snapshot-');
        file_put_contents($path, json_encode(['schema_version' => 1, 'source_host' => '192.0.2.10', 'login_cutoff' => '2024-01-01 00:00:00',
            'captured_at_utc' => '2026-01-01T00:00:00Z', 'source_timezone' => 'UTC', 'tables' => $tables]));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        try {
            $snapshot = Snapshot::load($path, '192.0.2.10', '2024-01-01 00:00:00');
        } finally {
            unlink($path);
            unlink($path . '.sha256');
        }
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => $snapshot->checksum(), 'status' => 'running']);
        $context = new ImportContext($id, '192.0.2.10');
        (new CustomerImporter)->import($snapshot, $context);

        return [$snapshot, $context];
    }

    public function test_exact_history_and_source_states_survive_replay_without_spendable_credit_or_invented_payments(): void
    {
        [$snapshot, $context] = $this->fixture();
        for ($i = 0; $i < 2; $i++) {
            (new FinancialImporter)->import($snapshot, $context);
        }
        $this->assertSame(6, BillmanagerFinancialRecord::count());
        $records = BillmanagerFinancialRecord::where('source_table', 'payments')->orderBy('source_id')->get();
        $this->assertSame(['provisionally_credited', 'paid', 'fraudulent', 'cancelled'], $records->pluck('status')->all());
        $this->assertSame(['1.2345', '1.2345', '1.2345', '1.2345'], $records->pluck('amount')->all());
        $this->assertSame('provider-42', $records[1]->details['externalid']);
        $balance = BillmanagerFinancialRecord::where('source_table', 'subaccounts')->sole();
        $this->assertSame('-1450.8693', $balance->amount);
        $this->assertSame('-0.0007', $balance->details['rounding_delta']);
        $invoice = BillmanagerFinancialRecord::where('source_table', 'invoices')->sole();
        $this->assertSame('created', $invoice->status);
        $this->assertSame('EUR', $invoice->currency_code);
        $this->assertSame('I-51', $invoice->number);
        $this->assertSame(0, Credit::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(1, BillmanagerRecord::where('source_table', 'payment_history')->count());
        $report = (new FinancialReconciler)->compare($snapshot, $context);
        $this->assertSame('verified', $report->status);
        $this->assertSame('1.2345', $report->totals['payments']['USD']['paid']);
    }

    public function test_shared_members_can_read_only_their_financial_history(): void
    {
        [$snapshot, $context] = $this->fixture();
        (new FinancialImporter)->import($snapshot, $context);
        $record = BillmanagerFinancialRecord::firstOrFail();
        foreach (User::all() as $member) {
            $this->assertTrue(Gate::forUser($member)->allows('view', $record));
        }
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('view', $record));
        $this->assertArrayNotHasKey('details', $record->toArray());
    }

    public function test_reconciliation_detects_a_modified_amount(): void
    {
        [$snapshot, $context] = $this->fixture();
        (new FinancialImporter)->import($snapshot, $context);
        DB::table('billmanager_financial_records')->where('source_table', 'payments')->where('source_id', '42')->update(['amount' => '2.2345']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Financial record mismatch');
        (new FinancialReconciler)->compare($snapshot, $context);
    }

    public function test_customer_history_renders_exact_amounts_and_denies_unrelated_readers(): void
    {
        [$snapshot, $context] = $this->fixture();
        (new FinancialImporter)->import($snapshot, $context);
        $member = User::where('email', 'client12@example.test')->sole();
        $record = BillmanagerFinancialRecord::where('source_table', 'payments')->where('source_id', '42')->sole();
        Livewire::actingAs($member)->test(LegacyHistory::class)
            ->assertSee('R-42')->assertSee('1.2345')->assertSee('-1450.8693');
        Livewire::actingAs($member)->test(LegacyHistory::class, ['record' => $record])
            ->assertSee('provider-42')->assertSee('0.0100');
        Livewire::actingAs(User::factory()->create())->test(LegacyHistory::class, ['record' => $record])->assertNotFound();
    }

    public function test_proven_same_currency_allocations_create_one_native_paid_invoice_without_rebilling(): void
    {
        [$snapshot, $context] = $this->fixture([
            'company_profiles' => [['id' => '1', 'name' => 'Historical Seller', 'address_legal' => 'Synthetic address']],
            'payments' => array_map(fn ($id, $amount) => ['id' => (string) $id, 'subaccount' => '31', 'currency' => '1', 'status' => '4',
                'subaccountamount' => $amount, 'number' => 'R-' . $id, 'externalid' => 'provider-' . $id, 'createdate' => '2020-01-02 00:00:00', 'paydate' => '2020-01-03 00:00:00'], [41, 42], ['1.2500', '2.2000']),
            'invoices' => [['id' => '51', 'company' => '1', 'customer' => '21', 'currency' => '1', 'invoice_status' => '1', 'number' => 'I-51', 'amount' => '3.4500', 'realamount' => '3.4500', 'cdate' => '2020-01-02']],
            'invoiceitem_payments' => [['invoiceitem' => '61', 'payment' => '41', 'amount' => '1.2500'], ['invoiceitem' => '61', 'payment' => '42', 'amount' => '2.2000']],
        ]);
        for ($i = 0; $i < 2; $i++) {
            (new FinancialImporter)->import($snapshot, $context);
        }
        $invoice = Invoice::sole();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('I-51', $invoice->number);
        $this->assertSame(3.45, $invoice->total);
        $this->assertEquals(0, $invoice->remaining);
        $this->assertSame(['provider-41', 'provider-42'], $invoice->transactions()->orderBy('id')->pluck('transaction_id')->all());
        $this->assertStringContainsString('Historical Seller', $invoice->bill_to);
        $this->assertTrue(MigrationHold::isHeld($invoice));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, Credit::count());
    }

    public function test_refund_relationship_and_amount_survive_without_issuing_a_new_refund(): void
    {
        [$snapshot, $context] = $this->fixture([
            'payments' => [
                ['id' => '41', 'subaccount' => '31', 'currency' => '1', 'status' => '4', 'subaccountamount' => '5.0000', 'number' => 'R-41'],
                ['id' => '42', 'subaccount' => '31', 'currency' => '1', 'status' => '6', 'subaccountamount' => '1.2345', 'number' => 'R-42'],
            ],
            'payment_refunds' => [['payment_base' => '41', 'payment_refund' => '42', 'amount' => '1.2345']],
        ]);
        $report = (new FinancialImporter)->import($snapshot, $context);
        $this->assertSame('1.2345', $report->totals['payments']['USD']['refunded']);
        $this->assertSame(['payment_base' => '41', 'payment_refund' => '42', 'amount' => '1.2345'], BillmanagerRecord::where('source_table', 'payment_refunds')->sole()->payload);
        $this->assertSame(0, DB::table('invoice_transactions')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }
}
