<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Livewire\Billing\LegacyHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Qirolab\Theme\Theme;
use Tests\TestCase;

class LegacyHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_history_keeps_earlier_only_payments_and_shows_each_current_version_once(): void
    {
        config(['settings.theme' => 'default']);
        Theme::set('default', 'default');
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $old = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('a', 64), 'status' => 'prepared_partial']);
        $current = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('b', 64), 'status' => 'prepared_partial']);
        $otherSource = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.20', 'snapshot_sha256' => str_repeat('c', 64), 'status' => 'prepared_partial']);
        $account = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 10, 'owner_user_id' => $owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $account, 'user_id' => $member->id, 'source_user_id' => 22]);
        $record = fn ($import, $user, $source, $status = 'paid') => DB::table('billmanager_financial_records')->insertGetId([
            'import_id' => $import, 'user_id' => $user->id, 'source_account_id' => $user->id,
            'source_table' => 'payments', 'source_id' => (string) $source, 'currency_code' => 'USD',
            'status' => $status, 'amount' => '12.3456', 'details' => Crypt::encryptString(json_encode(['description' => 'Fictional payment'])),
        ]);
        $superseded = $record($old, $owner, 100, 'pending');
        $latest = $record($current, $owner, 100);
        $earlierOnly = $record($old, $owner, 101, 'new');
        $retained = $record($old, $member, 200);
        $sameIdFromAnotherSource = $record($otherSource, $owner, 100);
        $unrelated = $record($current, User::factory()->create(), 300);
        Auth::setUser($member);
        $history = new LegacyHistory;
        $history->kind = 'payments';
        $records = $history->render()->getData()['records'];
        $this->assertContains($earlierOnly, $records->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$latest, $earlierOnly, $retained, $sameIdFromAnotherSource], $records->pluck('id')->all());
        $this->assertNotContains($superseded, $records->pluck('id')->all());
        $this->assertNotContains($unrelated, $records->pluck('id')->all());
    }

    public function test_account_snapshot_and_preliminary_invoice_visibility_are_preserved(): void
    {
        config(['settings.theme' => 'default']);
        Theme::set('default', 'default');
        $owner = User::factory()->create();
        $old = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('a', 64), 'status' => 'prepared_partial']);
        $current = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('b', 64), 'status' => 'prepared_partial']);
        $record = fn ($import, $table, $source, $status) => DB::table('billmanager_financial_records')->insertGetId([
            'import_id' => $import, 'user_id' => $owner->id, 'source_account_id' => 10,
            'source_table' => $table, 'source_id' => (string) $source, 'currency_code' => 'USD',
            'status' => $status, 'amount' => '10.0000', 'details' => Crypt::encryptString('[]'),
        ]);
        $record($old, 'subaccounts', 31, 'held');
        $currentBalance = $record($current, 'subaccounts', 32, 'held');
        $record($old, 'invoices', 51, 'created');
        $record($current, 'invoices', 51, 'preliminary');
        $retainedInvoice = $record($old, 'invoices', 52, 'created');

        Auth::setUser($owner);
        $records = (new LegacyHistory)->render()->getData()['records'];
        $this->assertEqualsCanonicalizing([$currentBalance, $retainedInvoice], $records->pluck('id')->all());
    }
}
