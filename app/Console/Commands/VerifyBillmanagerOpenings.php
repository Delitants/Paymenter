<?php

namespace App\Console\Commands;

use App\Models\AccountOpeningBatch;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningProcessContext;
use App\Services\BillmanagerMigration\Opening\OpeningReceiptVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningReportFile;
use Illuminate\Console\Command;
use RuntimeException;

final class VerifyBillmanagerOpenings extends Command
{
    protected $signature = 'billmanager:openings:verify {bundle} {--report=}';

    protected $description = 'Read-only verification of original inactive opening receipts';

    public function handle(): int
    {
        try {
            OpeningProcessContext::capture()->assertCurrent();
            $bundle = OpeningBundle::load($this->argument('bundle'));
            if (!is_string($path = $this->option('report')) || $path === '') {
                throw new RuntimeException('A new private verification report is required.');
            }
            $batch = AccountOpeningBatch::where('bundle_sha256', $bundle->digest())->sole();
            $result = (new OpeningReceiptVerifier)->verify($bundle, $batch);
            OpeningReportFile::write($path, ['schema_version' => 1, 'purpose' => 'inactive-opening-verification-export', 'bundle_sha256' => $bundle->digest(),
                'result' => $result, 'committed_rows' => OpeningReceiptVerifier::committedRows($bundle, $batch)]);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Opening verification failed. Original scope, target, inactive state or private export changed; no opening was attempted.');

            return self::FAILURE;
        }
    }
}
