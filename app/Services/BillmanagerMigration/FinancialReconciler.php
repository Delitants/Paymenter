<?php

namespace App\Services\BillmanagerMigration;

use App\Models\BillmanagerFinancialRecord;
use App\Models\BillmanagerRecord;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use RuntimeException;

final class FinancialReconciler
{
    private const READ_BATCH_SIZE = 128;

    public function compare(Snapshot $snapshot, ImportContext $context): ImportReport
    {
        if ($snapshot->sourceHost() !== $context->sourceHost) {
            throw new RuntimeException('Snapshot source does not match import context');
        }
        $counts = [];
        $totals = [];
        $owners = [];
        $records = BillmanagerFinancialRecord::where('import_id', $context->importId);
        $sourceRecords = static function () use ($snapshot) {
            foreach (FinancialRows::records($snapshot) as $record) {
                yield [$record['source_table'], $record['source_id'], $record];
            }
        };
        foreach ($this->pairedRecords($records, $sourceRecords) as [[$table, , $expected], $native]) {
            foreach ($expected as $key => $value) {
                if ($value !== $native->$key) {
                    throw new RuntimeException('Financial record mismatch: ' . $key);
                }
            }
            $account = $expected['source_account_id'];
            $owner = $owners[$account] ??= DB::table('billmanager_accounts')->where('id', $context->mappedId('accounts', (string) $account))->value('owner_user_id');
            if ((int) $native->user_id !== (int) $owner) {
                throw new RuntimeException('Financial record mismatch: owner');
            }
            $currency = $expected['currency_code'];
            $status = $expected['status'];
            $counts[$table] = ($counts[$table] ?? 0) + 1;
            $totals[$table][$currency][$status] = (string) BigDecimal::of($totals[$table][$currency][$status] ?? '0')->plus($expected['amount']);
        }
        if ($records->count() !== array_sum($counts)) {
            throw new RuntimeException('Unexpected financial records');
        }
        $archived = 0;
        $archives = BillmanagerRecord::where('import_id', $context->importId);
        $decoder = $archives->getModel();
        $columns = ['source_id', 'source_account_id', 'payload'];
        foreach ($this->pairedRecords($archives, static fn () => FinancialRows::archives($snapshot), $columns) as [[, , $account, $row], $record]) {
            $payload = $record->payload === null ? null : $decoder->fromJson($decoder->fromEncryptedString($record->payload));
            if ($payload !== $row || $record->source_account_id != $account) {
                throw new RuntimeException('Financial archive mismatch');
            }
            $archived++;
        }

        return new ImportReport('verified', $counts + ['archive_rows' => $archived], totals: $totals);
    }

    /** Keep source order and the normal casts for callers that need models. */
    private function pairedRecords(Builder $query, Closure $source, ?array $rawColumns = null): iterable
    {
        foreach (LazyCollection::make($source)->chunk(self::READ_BATCH_SIZE) as $page) {
            $entries = $page->all();
            $requested = [];
            foreach ($entries as [$table, $id]) {
                $requested[$table][] = $id;
            }
            $native = [];
            foreach ($requested as $table => $ids) {
                $batch = (clone $query)->where('source_table', $table)->whereIn('source_id', $ids);
                $rows = $rawColumns === null ? $batch->get() : $batch->toBase()->get($rawColumns);
                $native[$table] = $rows->groupBy('source_id');
            }
            foreach ($entries as $entry) {
                [$table, $id] = $entry;
                $matches = $native[$table]->get($id);
                if ($matches?->count() === 1) {
                    $record = $matches->first();
                } else {
                    $model = (clone $query)->where(['source_table' => $table, 'source_id' => $id])->sole();
                    $record = $rawColumns === null ? $model : (object) $model->getRawOriginal();
                }
                yield [$entry, $record];
            }
        }
    }
}
