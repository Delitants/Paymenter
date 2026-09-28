<?php

namespace App\Console\Commands;

use App\Services\BillmanagerMigration\AttachmentImporter;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\ImportReport;
use App\Services\BillmanagerMigration\Snapshot;
use App\Services\BillmanagerMigration\TicketImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportFromBillmanager extends Command
{
    protected $signature = 'billmanager:import {snapshot} {--source=} {--login-cutoff=} {--dry-run} {--apply} {--stage=all} {--report=} {--attachment-manifest=} {--attachment-directory=}';

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
                if (!in_array($this->option('stage'), ['customers', 'tickets', 'attachments'], true)) {
                    $this->error('Full import is not yet available; use an explicitly supported stage');

                    return self::FAILURE;
                }
                if ($this->option('stage') === 'attachments') {
                    $row = DB::table('billmanager_imports')->where(['snapshot_sha256' => $snapshot->checksum(), 'source_host' => $snapshot->sourceHost()])->first();
                    if (!$row || !$this->option('attachment-manifest') || !$this->option('attachment-directory')) {
                        throw new \RuntimeException('Attachments require an existing snapshot import, manifest and source directory');
                    }
                    $manifest = json_decode(file_get_contents($this->option('attachment-manifest')), true, flags: JSON_THROW_ON_ERROR);
                    $expected = array_column($snapshot->rows('ticket_attachments'), null, 'id');
                    if (count($manifest) !== count($expected)) {
                        throw new \RuntimeException('Attachment manifest does not match snapshot');
                    }
                    foreach ($manifest as $entry) {
                        if (($entry['original'] ?? null) !== ($expected[$entry['id']] ?? null)) {
                            throw new \RuntimeException('Attachment source metadata does not match snapshot');
                        }
                        if ((string) $entry['ticket_message'] !== (string) $entry['original']['ticket_message']) {
                            throw new \RuntimeException('Attachment message does not match snapshot');
                        }
                    }
                    // The importer owns this transaction so copied files are cleaned up on failure.
                    $report = (new AttachmentImporter)->import($this->option('attachment-manifest'), $this->option('attachment-directory'), new ImportContext($row->id, $snapshot->sourceHost()));
                } else {
                    $report = DB::transaction(function () use ($snapshot) {
                        $row = DB::table('billmanager_imports')->where('snapshot_sha256', $snapshot->checksum())->lockForUpdate()->first();
                        if ($row && $row->source_host !== $snapshot->sourceHost()) {
                            throw new \RuntimeException('Snapshot source conflicts with existing import');
                        }
                        $id = $row?->id ?? DB::table('billmanager_imports')->insertGetId([
                            'source_host' => $snapshot->sourceHost(), 'snapshot_sha256' => $snapshot->checksum(),
                            'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $importer = $this->option('stage') === 'customers' ? new CustomerImporter : new TicketImporter;
                        $report = $importer->import($snapshot, new ImportContext($id, $snapshot->sourceHost()));
                        DB::table('billmanager_imports')->where('id', $id)->update([
                            'status' => 'prepared_partial', 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                        ]);

                        return $report;
                    });
                }
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
