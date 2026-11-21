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

    public function test_shared_history_uses_the_latest_snapshot_for_each_owner_independently(): void
    {
        config(['settings.theme' => 'default']);
        Theme::set('default', 'default');
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $old = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('a', 64), 'status' => 'prepared_partial']);
        $current = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('b', 64), 'status' => 'prepared_partial']);
        $account = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 10, 'owner_user_id' => $owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $account, 'user_id' => $member->id, 'source_user_id' => 22]);
        $record = fn ($import, $user, $source) => DB::table('billmanager_financial_records')->insertGetId([
            'import_id' => $import, 'user_id' => $user->id, 'source_account_id' => $user->id,
            'source_table' => 'payments', 'source_id' => (string) $source, 'currency_code' => 'USD',
            'status' => 'paid', 'amount' => '12.3456', 'details' => Crypt::encryptString(json_encode(['description' => 'Fictional payment'])),
        ]);
        $superseded = $record($old, $owner, 100);
        $latest = $record($current, $owner, 100);
        $retained = $record($old, $member, 200);
        Auth::setUser($member);
        $records = (new LegacyHistory)->render()->getData()['records'];
        $this->assertSame(2, $records->total(), 'An older snapshot for one owner must not reintroduce another owner\'s superseded records');
        $this->assertEqualsCanonicalizing([$latest, $retained], $records->pluck('id')->all());
        $this->assertNotContains($superseded, $records->pluck('id')->all());
    }
}
