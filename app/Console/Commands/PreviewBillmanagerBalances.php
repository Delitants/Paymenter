<?php

namespace App\Console\Commands;

use App\Services\BillmanagerMigration\BalancePreview;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\Snapshot;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PreviewBillmanagerBalances extends Command
{
    protected $signature = 'billmanager:balances:preview {snapshot} {--source=} {--login-cutoff=} {--activation-cutoff=} {--report=}';

    protected $description = 'Preview held BILLmanager balances without creating spendable credits';

    public function handle(): int
    {
        try {
            if (! $this->option('source') || ! $this->option('login-cutoff')) {
                throw new RuntimeException('Expected source identity and login cutoff are required');
            }
            $cutoff = $this->option('activation-cutoff') ?: $this->option('login-cutoff');
            foreach ([$this->option('login-cutoff'), $cutoff] as $date) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date);
                if (! $parsed || $parsed->format('Y-m-d H:i:s') !== $date) {
                    throw new RuntimeException('Cutoffs must be valid source-local dates in Y-m-d H:i:s format');
                }
            }
            if ($cutoff < $this->option('login-cutoff')) {
                throw new RuntimeException('Activation cutoff cannot expand the snapshot cohort');
            }
            $snapshot = Snapshot::load($this->argument('snapshot'), $this->option('source'), $this->option('login-cutoff'));
            $plan = DB::transaction(function () use ($snapshot, $cutoff) {
                $row = DB::table('billmanager_imports')->where(['source_host' => $snapshot->sourceHost(), 'snapshot_sha256' => $snapshot->checksum()])->sole();

                return (new BalancePreview)->prepare($snapshot, new ImportContext($row->id, $snapshot->sourceHost()), $cutoff);
            });
            if ($path = $this->option('report')) {
                if (str_contains($path, '://') || ! stream_is_local($path) || ! is_dir(dirname($path))) {
                    throw new RuntimeException('Report destination must be an ordinary local file');
                }
                $json = json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
                $mask = umask(0077);
                try {
                    $file = @fopen($path, 'x');
                } finally {
                    umask($mask);
                }
                if ($file === false) {
                    throw new RuntimeException('Report must be a new file in an existing private directory');
                }
                try {
                    if (fwrite($file, $json) !== strlen($json) || ! fflush($file)) {
                        throw new RuntimeException('Cannot write complete balance report');
                    }
                } finally {
                    fclose($file);
                }
            }
            $this->line(json_encode(['status' => $plan['status'], 'application_authorized' => false, 'counts' => $plan['counts']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Balance preview failed. Verify the snapshot, held customer mappings and a new local report destination.');

            return self::FAILURE;
        }
    }
}
