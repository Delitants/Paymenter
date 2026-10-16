<?php

namespace App\Console\Commands;

use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningPreparation;
use App\Services\BillmanagerMigration\Opening\OpeningProcessContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PrepareBillmanagerOpenings extends Command
{
    protected $signature = 'billmanager:openings:prepare {bundle} {--report=}';

    protected $description = 'Prepare an immutable inactive account opening report without financial writes';

    public function handle(): int
    {
        try {
            OpeningProcessContext::capture()->assertCurrent();
            $bundle = OpeningBundle::load($this->argument('bundle'));
            if ($bundle->executable()) {
                throw new RuntimeException('Preparation requires a non-executable input manifest.');
            }
            $report = DB::transaction(fn () => (new OpeningPreparation)->prepare($bundle));
            if (!is_string($path = $this->option('report')) || $path === '' || $path[0] !== '/' || str_contains($path, '://') || realpath(dirname($path)) !== dirname($path) || (fileperms(dirname($path)) & 0777) !== 0700 || fileowner(dirname($path)) !== 0) {
                throw new RuntimeException('A new private report destination is required.');
            }
            $cursor = '';
            foreach (explode('/', trim(dirname($path), '/')) as $part) {
                $cursor .= '/' . $part;
                $entry = lstat($cursor);
                if (!$entry || is_link($cursor) || $entry['uid'] !== 0 || ($entry['mode'] & 0170000) !== 0040000 ||
                    (($entry['mode'] & 0022) !== 0 && ($entry['mode'] & 01000) === 0)) {
                    throw new RuntimeException('Untrusted report ancestor.');
                }
            }
            $mask = umask(0077);
            try {
                $file = fopen($path, 'x');
            } finally {
                umask($mask);
            }
            if (!$file) {
                throw new RuntimeException('Report creation failed.');
            }
            try {
                $bytes = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || !fsync($file)) {
                    throw new RuntimeException('Incomplete private report.');
                }
            } finally {
                fclose($file);
            }
            $this->line(json_encode(['status' => 'prepared', 'application_authorized' => false, 'counts' => $report['counts']], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Opening preparation denied. Verify private evidence, exact import and isolated operator context.');

            return self::FAILURE;
        }
    }
}
