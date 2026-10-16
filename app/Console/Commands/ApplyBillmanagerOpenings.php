<?php

namespace App\Console\Commands;

use App\Models\AccountOpeningBatch;
use App\Services\BillmanagerMigration\Opening\OpeningBatchOperator;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningReceiptVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningReportFile;
use Illuminate\Console\Command;
use RuntimeException;

final class ApplyBillmanagerOpenings extends Command
{
    protected $signature = 'billmanager:openings:apply {bundle} {--approval=} {--signature=} {--report=}';

    protected $description = 'Apply externally approved inactive openings with atomic durable receipts';

    public function handle(): int
    {
        try {
            foreach (['approval', 'signature', 'report'] as $option) {
                if (!is_string($this->option($option)) || $this->option($option) === '') {
                    throw new RuntimeException('Complete private operator paths are required.');
                }
            }
            $bundle = OpeningBundle::load($this->argument('bundle'));
            $result = (new OpeningBatchOperator)->apply($bundle, $this->option('approval'), $this->option('signature'));
            $batch = AccountOpeningBatch::where('bundle_sha256', $bundle->digest())->sole();
            OpeningReportFile::write($this->option('report'), ['schema_version' => 1, 'purpose' => 'inactive-opening-receipt-export', 'bundle_sha256' => $bundle->digest(),
                'result' => $result, 'committed_rows' => OpeningReceiptVerifier::committedRows($bundle, $batch)]);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Opening application stopped or private export failed. Durable inactive receipts may remain; verify them before resuming.');

            return self::FAILURE;
        }
    }
}
