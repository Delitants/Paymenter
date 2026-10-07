<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningHistoryMutationTest extends TestCase
{
    use UsesCommittedDatabase;

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
