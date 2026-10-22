<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Models\User;
use App\Services\BillmanagerMigration\Opening\OpeningBackupVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\CaptureProofFixture;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningHistoryMutationTest extends TestCase
{
    use UsesCommittedDatabase;

    public function test_v2_does_not_exempt_session_support_or_unrelated_rows(): void
    {
        $argv = $_SERVER['argv'];
        $_SERVER['argv'] = [base_path('artisan'), 'billmanager:openings:apply'];
        try {
            $owner = User::factory()->createQuietly();
            $session = DB::table('user_sessions')->insertGetId(['ulid' => '01J00000000000000000000000', 'user_id' => $owner->id, 'last_activity' => '2026-01-01 00:00:00', 'user_agent' => 'original fixture']);
            $ticket = DB::table('tickets')->insertGetId(['user_id' => $owner->id, 'subject' => 'original support', 'status' => 'closed', 'priority' => 'normal']);
            $setting = DB::table('settings')->insertGetId(['key' => 'fixture-unrelated-setting', 'value' => 'original']);
            $p = CaptureProofFixture::ready();
            (new OpeningBackupVerifier)->assertReady($p['bundle']);
            foreach ([['user_sessions', $session, 'user_agent', 'changed session'], ['tickets', $ticket, 'subject', 'changed support'], ['settings', $setting, 'value', 'changed unrelated']] as [$table,$id,$field,$changed]) {
                $original = DB::table($table)->where('id', $id)->value($field);
                DB::table($table)->where('id', $id)->update([$field => $changed]);
                $before = (new OpeningTargetState)->capture();
                try {
                    (new OpeningBackupVerifier)->assertReady($p['bundle']);
                    self::fail('V2 exempted original state in ' . $table);
                } catch (RuntimeException $e) {
                    self::assertNotSame('', $e->getMessage());
                    self::assertSame($before, (new OpeningTargetState)->capture());
                } finally {
                    DB::table($table)->where('id', $id)->update([$field => $original]);
                }
            }
            self::assertSame(0, DB::table('account_opening_receipts')->count());
        } finally {
            $_SERVER['argv'] = $argv;
            ProofFactory::cleanup();
        }
    }

    public static function bulk(): array
    {
        $cases = [];
        foreach ([AccountOpeningBatch::class, AccountOpeningReceipt::class] as $model) {
            foreach (['update', 'delete', 'insert', 'upsert', 'increment', 'decrement', 'truncate', 'fillAndInsert'] as $action) {
                $cases[$model . ':' . $action] = [$model, $action];
            }
        }

        return $cases;
    }

    public static function historyModels(): array
    {
        return [[AccountOpeningBatch::class], [AccountOpeningReceipt::class]];
    }

    #[DataProvider('historyModels')]
    public function test_denied_history_delete_cannot_dispatch_model_events(string $class): void
    {
        $before = (new OpeningTargetState)->capture();
        $events = [];
        $class::deleting(function ($row) use (&$events) {
            $events[] = $row->id;
        });
        $row = new $class;
        $row->setRawAttributes(['id' => 0], true);
        $row->exists = true;
        try {
            $row->delete();
            self::fail('Direct history deletion was accepted.');
        } catch (RuntimeException $e) {
            self::assertSame('Opening history is writable only by the live batch store.', $e->getMessage());
        }
        self::assertSame([], $events, 'Denied deletion dispatched model events before its guard.');
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    #[DataProvider('bulk')]
    public function test_bulk_history_mutations_are_denied_before_any_sql(string $model, string $action): void
    {
        $before = (new OpeningTargetState)->capture();
        $writes = [];
        DB::listen(function ($event) use (&$writes) {
            if (preg_match('/^(?:update|delete|insert|truncate)\b/i', ltrim($event->sql))) {
                $writes[] = $event->sql;
            }
        });
        $query = $model::whereKey(0);
        try {
            match ($action) {
                'update' => $query->update(['created_at' => '2026-01-01 00:00:00']),
                'delete' => $query->delete(),
                'insert' => $query->insert([]),
                'upsert' => $query->upsert([], ['id']),
                'increment' => $query->increment('id'),
                'decrement' => $query->decrement('id'),
                'truncate' => $query->truncate(),
                'fillAndInsert' => $query->fillAndInsert([]),
            };
        } catch (RuntimeException $e) {
            self::assertSame('Opening history is writable only by the live batch store.', $e->getMessage());
            self::assertSame([], $writes);
            self::assertSame($before, (new OpeningTargetState)->capture());

            return;
        }
        self::fail('Bulk opening history mutation was accepted.');
    }
}
