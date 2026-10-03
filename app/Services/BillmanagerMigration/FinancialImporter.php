<?php

namespace App\Services\BillmanagerMigration;

use App\Models\BillmanagerFinancialRecord;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FinancialImporter
{
    public function import(Snapshot $snapshot, ImportContext $context): ImportReport
    {
        if ($snapshot->sourceHost() !== $context->sourceHost) {
            throw new RuntimeException('Snapshot source does not match import context');
        }

        return DB::transaction(function () use ($snapshot, $context) {
            foreach (FinancialRows::archives($snapshot) as [$table, $id, $account, $row]) {
                if ($account !== null && !$context->mappedId('accounts', (string) $account)) {
                    throw new RuntimeException('Financial archive account was not imported');
                }
                $context->archive($table, $id, $row, $account);
            }
            foreach (FinancialRows::records($snapshot) as $record) {
                $owner = DB::table('billmanager_accounts')->where('id', $context->mappedId('accounts', (string) $record['source_account_id']))->value('owner_user_id');
                if (!$owner) {
                    throw new RuntimeException('Financial record account has no imported owner');
                }
                $identity = ['import_id' => $context->importId, 'source_table' => $record['source_table'], 'source_id' => $record['source_id']];
                if (!BillmanagerFinancialRecord::where($identity)->exists()) {
                    $record['details'] = Crypt::encryptString(json_encode($record['details'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    DB::table('billmanager_financial_records')->insert($identity + $record + ['user_id' => $owner]);
                }
            }
            $native = (new HistoricalInvoiceImporter)->import($snapshot, $context);
            $report = (new FinancialReconciler)->compare($snapshot, $context);

            return new ImportReport($report->status, $report->counts + $native, totals: $report->totals);
        });
    }
}
