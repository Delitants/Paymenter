<?php

namespace App\Console\Commands;

use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\ImportReport;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportFromBillmanager extends Command
{
    protected $signature = 'billmanager:import {snapshot} {--source=} {--login-cutoff=} {--dry-run} {--apply} {--stage=all} {--report=}';

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
                if ($this->option('stage') !== 'customers') {
                    $this->error('Full import is not yet available; use an explicitly supported stage');

                    return self::FAILURE;
                }
                $report = DB::transaction(function () use ($snapshot) {
                    $row = DB::table('billmanager_imports')->where('snapshot_sha256', $snapshot->checksum())->lockForUpdate()->first();
                    if ($row && $row->source_host !== $snapshot->sourceHost()) {
                        throw new \RuntimeException('Snapshot source conflicts with existing import');
                    }
                    $id = $row?->id ?? DB::table('billmanager_imports')->insertGetId([
                        'source_host' => $snapshot->sourceHost(), 'snapshot_sha256' => $snapshot->checksum(),
                        'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $report = (new CustomerImporter)->import($snapshot, new ImportContext($id, $snapshot->sourceHost()));
                    DB::table('billmanager_imports')->where('id', $id)->update([
                        'status' => 'prepared_partial', 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);

                    return $report;
                });
            } else {
                $report = new ImportReport('validated', $snapshot->counts());
            }
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
