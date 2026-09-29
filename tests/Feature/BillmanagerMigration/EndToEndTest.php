<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\BillmanagerFinancialRecord;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BillmanagerMigration\FinancialReconciler;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\LegacyCredentialVerifier;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EndToEndTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/migration-e2e-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['billmanager-migration.allowed_databases' => [DB::connection()->getDatabaseName()], 'queue.default' => 'database']);
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function fixture(): Snapshot
    {
        $tables = [
            'accounts' => [['id' => '10']],
            'users' => array_map(fn ($id) => ['id' => (string) $id, 'account' => '10', 'enabled' => 'on', 'level' => '16',
                'name' => 'legacy-' . $id, 'email' => 'client' . $id . '@example.test', 'realname' => 'Synthetic Member', 'last_login' => '2025-01-01 00:00:00'], [11, 12]),
            'currencies' => [['id' => '1', 'iso' => 'USD']],
            'profiles' => [['id' => '21', 'account' => '10', 'name' => 'Synthetic Payer']],
            'subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => '-12.3456', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off']],
            'payments' => [['id' => '41', 'subaccount' => '31', 'currency' => '1', 'status' => '4', 'number' => 'SYNTHETIC-41',
                'subaccountamount' => '19.9900', 'paymethodamount' => '19.9900', 'usedamount' => '0.0000', 'commissionamount' => '0.0000',
                'createdate' => '2020-01-02 00:00:00', 'paydate' => '2020-01-03 00:00:00']],
            'invoices' => [['id' => '51', 'customer' => '21', 'currency' => '1', 'invoice_status' => '1', 'number' => 'SYNTHETIC-51',
                'amount' => '3.4500', 'realamount' => '3.4500', 'cdate' => '2020-01-02']],
            'invoiceitems' => [['id' => '61', 'invoice' => '51', 'amount' => '3.4500', 'realamount' => '3.4500', 'taxamount' => '0.0000', 'name' => 'Historical service']],
            'pricelists' => [['id' => '20', 'name' => 'Synthetic Hosting', 'itemtype' => '30']],
            'items' => [['id' => '40', 'account' => '10', 'pricelist' => '20', 'processingmodule' => '5', 'status' => '2',
                'name' => 'host.example.test', 'period' => '1', 'costperiod' => '1', 'cost' => '5.0000', 'currency' => '1',
                'createdate' => '2020-01-01 00:00:00', 'expiredate' => '2026-10-01']],
            'tickets' => [['id' => '23', 'account_client' => '10', 'name' => 'Archived support — Привіт', 'status' => '10',
                'priority' => '0', 'date_start' => '2020-01-01 12:00:00', 'date_last' => '2021-02-03 14:00:00', 'item' => '40']],
            'ticket_messages' => [['id' => '32', 'ticket' => '23', 'user' => '11', 'message' => str_repeat('Привіт. ', 9000),
                'date_post' => '2021-02-03 14:00:00', 'date_delete' => null]],
        ];
        $path = $this->directory . '/snapshot.json';
        file_put_contents($path, json_encode(['schema_version' => 1, 'source_host' => '192.0.2.10', 'login_cutoff' => '2024-01-01 00:00:00',
            'captured_at_utc' => '2026-09-28T00:00:00Z', 'source_timezone' => 'UTC', 'tables' => $tables], JSON_THROW_ON_ERROR));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        file_put_contents($this->directory . '/credentials.enc', Crypt::encryptString(json_encode([
            'schema_version' => 1, 'source_host' => '192.0.2.10', 'credentials' => [
                ['source_user_id' => '11', 'hash' => crypt('synthetic-passphrase', '$5$testsalt$'), 'requires_mfa' => true, 'totp_secret' => 'JBSWY3DPEHPK3PXP'],
                ['source_user_id' => '12', 'hash' => crypt('synthetic-passphrase', '$1$testsalt$'), 'requires_mfa' => false],
            ],
        ], JSON_THROW_ON_ERROR)));

        return Snapshot::load($path, '192.0.2.10', '2024-01-01 00:00:00');
    }

    private function stage(string $stage, bool $succeeds = true): void
    {
        $command = $this->artisan('billmanager:import', [
            'snapshot' => $this->directory . '/snapshot.json', '--source' => '192.0.2.10', '--login-cutoff' => '2024-01-01 00:00:00',
            '--apply' => true, '--stage' => $stage, '--credential-bundle' => $this->directory . '/credentials.enc',
        ]);
        if ($succeeds) {
            $command->assertSuccessful();
        } else {
            $command->expectsOutputToContain('Injected financial-stage failure')->assertFailed();
        }
    }

    private function counts(): array
    {
        $counts = [];
        foreach (['users', 'orders', 'services', 'tickets', 'ticket_messages', 'billmanager_imports', 'billmanager_mappings',
            'billmanager_accounts', 'billmanager_members', 'billmanager_records', 'billmanager_holds',
            'billmanager_login_aliases', 'billmanager_legacy_credentials', 'billmanager_financial_records'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function test_staged_import_rolls_back_a_failed_stage_then_replays_one_snapshot_without_side_effects(): void
    {
        $snapshot = $this->fixture();
        $unrelated = User::factory()->create(['email' => 'existing@example.test']);
        $this->stage('customers');
        $this->stage('credentials');
        $this->stage('services');
        $this->stage('tickets');
        $beforeFailure = $this->counts();
        $inject = true;
        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inject, &$inserts) {
            if ($inject && str_starts_with(strtolower($query->sql), 'insert into `billmanager_financial_records`') && ++$inserts === 2) {
                throw new \RuntimeException('Injected financial-stage failure');
            }
        });
        $this->stage('financial', false);
        $inject = false;
        $this->assertSame(2, $inserts, 'Failure must occur after two actual financial writes');
        $this->assertSame($beforeFailure, $this->counts(), 'Failed stage must roll back its rows and archives, preserving earlier stages');
        $this->stage('financial');
        $first = $this->counts();
        foreach (['customers', 'credentials', 'services', 'tickets', 'financial'] as $stage) {
            $this->stage($stage);
        }
        $this->assertSame($first, $this->counts());
        $this->assertSame(3, User::count());
        $this->assertSame('existing@example.test', $unrelated->fresh()->email);
        $this->assertFalse(MigrationHold::isHeld($unrelated));
        $this->assertSame(1, DB::table('billmanager_imports')->count());
        $this->assertSame($snapshot->checksum(), DB::table('billmanager_imports')->value('snapshot_sha256'));
        $context = new ImportContext(DB::table('billmanager_imports')->value('id'), $snapshot->sourceHost());
        $owner = User::findOrFail($context->mappedId('users', '11'));
        $member = User::findOrFail($context->mappedId('users', '12'));
        $this->assertTrue(MigrationHold::isHeld($owner));
        $this->assertTrue(MigrationHold::isHeld($member));
        $this->assertSame('JBSWY3DPEHPK3PXP', $owner->tfa_secret);
        $this->assertNull($member->tfa_secret);
        $this->assertTrue((new LegacyCredentialVerifier)->verifyAndUpgrade($member, 'synthetic-passphrase'));
        $this->stage('credentials');
        $this->assertSame('', Crypt::decryptString(DB::table('billmanager_legacy_credentials')->where('user_id', $member->id)->value('credential')));
        $this->assertTrue(MigrationHold::isHeld(Service::sole()));
        $this->assertSame('5.00', Service::sole()->price);
        $ticket = Ticket::sole();
        $this->assertSame($owner->id, $ticket->user_id);
        $this->assertSame('closed', $ticket->status);
        $this->assertSame(str_repeat('Привіт. ', 9000), $ticket->messages()->sole()->message);
        $this->assertSame('2021-02-03 14:00:00', $ticket->messages()->sole()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, BillmanagerFinancialRecord::count());
        $this->assertSame('-12.3456', BillmanagerFinancialRecord::where('source_table', 'subaccounts')->value('amount'));
        $this->assertSame('19.9900', (new FinancialReconciler)->compare($snapshot, $context)->totals['payments']['USD']['paid']);
        foreach (['jobs', 'invoices', 'invoice_transactions', 'credits', 'gateway_payment_attempts'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table . ' must remain empty');
        }
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }
}
