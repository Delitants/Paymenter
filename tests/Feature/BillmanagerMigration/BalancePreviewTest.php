<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\BillmanagerRecord;
use App\Models\Credit;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\FinancialImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BalancePreviewTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function fixture(array $overrides = []): array
    {
        $tables = array_replace([
            'accounts' => [['id' => '10']],
            'users' => [['id' => '11', 'account' => '10', 'enabled' => 'on', 'level' => '16',
                'email' => 'balance@example.test', 'realname' => 'Synthetic Balance', 'last_login' => '2025-01-01 00:00:00']],
            'currencies' => [['id' => '1', 'iso' => 'USD']],
            'subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => '12.3450',
                'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on']],
        ], $overrides);
        $path = tempnam(sys_get_temp_dir(), 'balance-snapshot-');
        $this->files = array_merge($this->files, [$path, $path.'.sha256', $path.'.report']);
        file_put_contents($path, json_encode(['schema_version' => 1, 'source_host' => '192.0.2.10',
            'login_cutoff' => '2024-01-01 00:00:00', 'captured_at_utc' => '2026-01-01T00:00:00Z',
            'source_timezone' => 'UTC', 'tables' => $tables]));
        file_put_contents($path.'.sha256', hash_file('sha256', $path));
        $snapshot = Snapshot::load($path, '192.0.2.10', '2024-01-01 00:00:00');
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => $snapshot->checksum(), 'status' => 'prepared_partial']);
        $context = new ImportContext($id, '192.0.2.10');
        (new CustomerImporter)->import($snapshot, $context);
        (new FinancialImporter)->import($snapshot, $context);

        return [$path, $context];
    }

    private function preview(string $path, array $options = [])
    {
        return $this->artisan('billmanager:balances:preview', $options + ['snapshot' => $path,
            '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00', '--report' => $path.'.report']);
    }

    public function test_native_balance_preview_is_registered(): void
    {
        $this->assertArrayHasKey('billmanager:balances:preview', Artisan::all());
    }

    public function test_preview_preserves_exact_rounding_and_owner_without_writing_or_replaying_credits(): void
    {
        [$path, $context] = $this->fixture();
        $before = DB::table('billmanager_imports')->first();
        $this->preview($path)->assertSuccessful();
        $plan = json_decode(file_get_contents($path.'.report'), true);
        $this->assertFalse($plan['application_authorized']);
        $this->assertSame(hash_file('sha256', $path), $plan['snapshot_sha256']);
        $row = $plan['entries'][0];
        $this->assertSame('12.3450', $row['exact_amount']);
        $this->assertSame('12.35', $row['rounded_amount']);
        $this->assertSame('0.0050', $row['rounding_delta']);
        $this->assertSame('12.35', $row['proposed_credit']);
        $this->assertSame([], $row['review_reasons']);
        $this->assertSame((int) DB::table('billmanager_accounts')->value('owner_user_id'), $row['owner_user_id']);
        $this->assertEquals($before, DB::table('billmanager_imports')->first());
        $this->assertSame(0, Credit::count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('billmanager_holds')->whereNull('released_at')->count());
        $this->assertSame(0600, fileperms($path.'.report') & 0777);
        unlink($path.'.report');
        $this->preview($path)->assertSuccessful();
        $this->assertSame($plan, json_decode(file_get_contents($path.'.report'), true));
    }

    public function test_shared_credit_limits_and_unspent_provisional_money_require_review_and_are_not_added(): void
    {
        [$path] = $this->fixture([
            'users' => array_map(fn ($id) => ['id' => (string) $id, 'account' => '10', 'enabled' => 'on', 'level' => '16',
                'email' => 'balance'.$id.'@example.test', 'realname' => 'Synthetic Balance', 'last_login' => '2025-01-01 00:00:00'], [11, 12]),
            'subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => '12.3450',
                'creditlimit' => '100.0000', 'allowpostpaid' => 'off', 'active' => 'on']],
            'payments' => [['id' => '41', 'subaccount' => '31', 'status' => '3', 'subaccountamount' => '5.0000', 'usedamount' => '1.0000']],
        ]);
        $this->preview($path)->assertSuccessful();
        $row = json_decode(file_get_contents($path.'.report'), true)['entries'][0];
        $this->assertSame('12.35', $row['proposed_credit']);
        $this->assertEqualsCanonicalizing(['shared_account', 'credit_limit_policy', 'provisional_funds'], $row['review_reasons']);
        $this->assertSame(['41'], $row['provisional_payment_ids']);
        $this->assertSame(2, count($row['member_user_ids']));
        $this->assertSame(0, Credit::count());
    }

    public function test_negative_balance_remains_debt_even_when_rounding_to_zero(): void
    {
        [$path] = $this->fixture(['subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1',
            'balance' => '-0.0040', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on']]]);
        $this->preview($path)->assertSuccessful();
        $row = json_decode(file_get_contents($path.'.report'), true)['entries'][0];
        $this->assertSame('debt_review', $row['disposition']);
        $this->assertSame('-0.0040', $row['exact_amount']);
        $this->assertSame('0.00', $row['proposed_credit']);
        $this->assertContains('debt', $row['review_reasons']);
    }

    public function test_accounts_outside_a_newer_activation_cutoff_remain_excluded(): void
    {
        [$path] = $this->fixture();
        $this->preview($path, ['--activation-cutoff' => '2025-02-01 00:00:00'])->assertSuccessful();
        $row = json_decode(file_get_contents($path.'.report'), true)['entries'][0];
        $this->assertSame('excluded', $row['disposition']);
        $this->assertSame('0.00', $row['proposed_credit']);
        $this->assertSame('12.3450', $row['exact_amount']);
    }

    public function test_preview_refuses_ledger_drift_without_creating_a_report(): void
    {
        [$path] = $this->fixture();
        DB::table('billmanager_financial_records')->where('source_table', 'subaccounts')->update(['amount' => '13.3450']);
        $this->preview($path)->assertFailed();
        $this->assertFileDoesNotExist($path.'.report');
        $this->assertSame(0, Credit::count());
    }

    public function test_preview_refuses_an_owner_outside_verified_source_members(): void
    {
        [$path] = $this->fixture();
        DB::table('billmanager_members')->delete();
        $this->preview($path)->assertFailed();
        $this->assertFileDoesNotExist($path.'.report');
    }

    public function test_duplicate_account_currency_balances_require_explicit_reconciliation(): void
    {
        [$path] = $this->fixture(['subaccounts' => array_map(fn ($id) => ['id' => (string) $id, 'account' => '10', 'currency' => '1',
            'balance' => '12.3450', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on'], [31, 32])]);
        $this->preview($path)->assertFailed();
        $this->assertFileDoesNotExist($path.'.report');
    }

    public function test_native_currency_and_credit_storage_limits_are_flagged(): void
    {
        [$path] = $this->fixture([
            'currencies' => [['id' => '2', 'iso' => 'EUR']],
            'subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '2', 'balance' => '1000000000000000.0000',
                'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on']],
        ]);
        DB::table('currencies')->where('code', 'EUR')->delete();
        $this->preview($path)->assertSuccessful();
        $row = json_decode(file_get_contents($path.'.report'), true)['entries'][0];
        $this->assertContains('native_currency_unavailable', $row['review_reasons']);
        $this->assertContains('native_amount_out_of_range', $row['review_reasons']);
        $this->assertSame('1000000000000000.0000', $row['exact_amount']);
    }

    public function test_native_identity_drift_requires_reconciliation(): void
    {
        [$path] = $this->fixture();
        DB::table('users')->update(['email' => 'other-person@example.test']);
        $this->preview($path)->assertFailed();
        $this->assertFileDoesNotExist($path.'.report');
    }

    public function test_archived_customer_identity_drift_requires_reconciliation(): void
    {
        [$path] = $this->fixture();
        $record = BillmanagerRecord::where('source_table', 'users')->sole();
        $payload = $record->payload;
        $payload['email'] = 'source-collision@example.test';
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        DB::table('billmanager_records')->where('id', $record->id)->update([
            'payload' => Crypt::encryptString($json), 'payload_sha256' => hash('sha256', $json),
        ]);
        $this->preview($path)->assertFailed();
        $this->assertFileDoesNotExist($path.'.report');
    }

    public function test_framework_failures_do_not_print_mapped_private_identifiers(): void
    {
        [$path] = $this->fixture();
        DB::table('billmanager_mappings')->where('source_table', 'users')->update(['target_id' => 987654321]);
        $exit = Artisan::call('billmanager:balances:preview', [
            'snapshot' => $path, '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00',
        ]);
        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('987654321', Artisan::output());
        $this->assertStringNotContainsString('No query results for model', Artisan::output());
    }

    public function test_report_refuses_php_stream_wrappers(): void
    {
        [$path] = $this->fixture();
        ob_start();
        try {
            $exit = Artisan::call('billmanager:balances:preview', [
                'snapshot' => $path, '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00',
                '--report' => 'php://output',
            ]);
        } finally {
            $output = ob_get_clean();
        }
        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('exact_amount', $output);
        $this->assertFileDoesNotExist($path.'.report');
    }

    public function test_report_never_overwrites_an_existing_file_or_follows_a_symlink(): void
    {
        [$path] = $this->fixture();
        file_put_contents($path.'.report', 'preserve this evidence');
        $this->preview($path)->assertFailed();
        $this->assertSame('preserve this evidence', file_get_contents($path.'.report'));
    }
}
