<?php

namespace App\Services\BillmanagerMigration;

use App\Models\BillmanagerFinancialRecord;
use App\Models\BillmanagerRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FinancialReconciler
{
    public function compare(Snapshot $snapshot, ImportContext $context): ImportReport
    {
        if ($snapshot->sourceHost() !== $context->sourceHost) {
            throw new RuntimeException('Snapshot source does not match import context');
        }
        $counts = [];
        $totals = [];
        foreach (FinancialRows::records($snapshot) as $expected) {
            $native = BillmanagerFinancialRecord::where(['import_id' => $context->importId, 'source_table' => $expected['source_table'], 'source_id' => $expected['source_id']])->sole();
            foreach ($expected as $key => $value) {
                if ($value !== $native->$key) {
                    throw new RuntimeException('Financial record mismatch: ' . $key);
                }
            }
            $owner = DB::table('billmanager_accounts')->where('id', $context->mappedId('accounts', (string) $expected['source_account_id']))->value('owner_user_id');
            if ((int) $native->user_id !== (int) $owner) {
                throw new RuntimeException('Financial record mismatch: owner');
            }
            $table = $expected['source_table'];
            $currency = $expected['currency_code'];
            $status = $expected['status'];
            $counts[$table] = ($counts[$table] ?? 0) + 1;
            $totals[$table][$currency][$status] = (string) BigDecimal::of($totals[$table][$currency][$status] ?? '0')->plus($expected['amount']);
        }
        if (BillmanagerFinancialRecord::where('import_id', $context->importId)->count() !== array_sum($counts)) {
            throw new RuntimeException('Unexpected financial records');
        }
        $archived = 0;
        foreach (FinancialRows::archives($snapshot) as [$table, $id, $account, $row]) {
            $record = BillmanagerRecord::where(['import_id' => $context->importId, 'source_table' => $table, 'source_id' => $id])->sole();
            if ($record->payload !== $row || $record->source_account_id != $account) {
                throw new RuntimeException('Financial archive mismatch');
            }
            $archived++;
        }

        return new ImportReport('verified', $counts + ['archive_rows' => $archived], totals: $totals);
    }
}
