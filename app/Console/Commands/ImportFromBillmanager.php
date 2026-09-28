<?php

namespace App\Console\Commands;

use App\Services\BillmanagerMigration\ImportReport;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportFromBillmanager extends Command
{
    protected $signature = 'billmanager:import {snapshot} {--source=} {--login-cutoff=} {--dry-run} {--apply} {--report=}';

    protected $description = 'Validate a scoped BILLmanager snapshot before importing into an explicitly allowed database';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --apply or --dry-run');

            return self::FAILURE;
        }
        if (!$this->option('source') || !$this->option('login-cutoff')) {
            $this->error('An expected source identity and login cutoff are required');

            return self::FAILURE;
        }
        if ($this->option('apply') && !in_array(DB::connection()->getDatabaseName(), config('billmanager-migration.allowed_databases', []), true)) {
            $this->error('Database is not explicitly allowed for migration writes');

            return self::FAILURE;
        }
        try {
            $snapshot = Snapshot::load($this->argument('snapshot'), $this->option('source'), $this->option('login-cutoff'));
            if ($this->option('apply')) {
                $this->error('Native import stages are not yet available; no records were written');

                return self::FAILURE;
            }
            $report = new ImportReport('validated', $snapshot->counts());
            $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if ($path = $this->option('report')) {
                $oldMask = umask(0077);
                try {
                    if (file_put_contents($path, $json . PHP_EOL) === false) {
                        throw new \RuntimeException('Cannot write import report');
                    }
                } finally {
                    umask($oldMask);
                }
            }
            $this->line($json);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Snapshot validation failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
