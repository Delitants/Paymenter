<?php

namespace App\Services\BillmanagerMigration\Opening;

use App\Services\Accounts\OpeningEvidence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The journal proves bounded allocations only; database receipts prove commits. */
final class OpeningAttemptJournal
{
    public function __construct(private string $grantHash) {}

    public static function directory(): string
    {
        $path = config('account-opening.journal_directory');
        if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, '://') || realpath($path) !== $path) {
            throw new RuntimeException('A private root-owned journal directory is required.');
        }
        $stat = lstat($path);
        if (!$stat || $stat['uid'] !== 0 || ($stat['mode'] & 0777) !== 0700) {
            throw new RuntimeException('A private root-owned journal directory is required.');
        }
        $cursor = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            $cursor .= '/' . $part;
            $entry = lstat($cursor);
            if (!$entry || $entry['uid'] !== 0 || is_link($cursor) || ($entry['mode'] & 0170000) !== 0040000 || (($entry['mode'] & 0022) !== 0 && ($entry['mode'] & 01000) === 0)) {
                throw new RuntimeException('Untrusted journal ancestor.');
            }
        }

        return $path;
    }

    public function path(OpeningBundle $bundle): string
    {
        return self::directory() . '/opening-' . $bundle->digest() . '.journal';
    }

    public function initialize(OpeningBundle $bundle): array
    {
        OpeningBatchOperator::assertMutex($bundle);
        $path = $this->path($bundle);
        if (!file_exists($path)) {
            $header = ['schema_version' => 1, 'purpose' => 'account-opening-attempt-header', 'bundle_sha256' => $bundle->digest(), 'grant_sha256' => $this->grantHash, 'nonce' => bin2hex(random_bytes(32))];
            $mask = umask(0077);
            try {
                $file = fopen($path, 'x');
            } finally {
                umask($mask);
            }
            if (!$file) {
                throw new RuntimeException('Journal creation failed.');
            }
            try {
                self::write($file, CanonicalPolicy::bytes($header) . "\n");
            } finally {
                fclose($file);
            }
            self::syncDirectory(dirname($path));
        }
        $file = PrivateProofFile::read($path, 0);
        $records = self::decodeRecords($file['bytes'], $bundle->digest(), $this->grantHash);

        return ['path' => $path, 'device' => $file['device'], 'inode' => $file['inode'], 'header_sha256' => $records[0]['sha256']];
    }

    public function begin(OpeningBundle $bundle, OpeningEvidence $evidence): string
    {
        OpeningBatchOperator::assertMutex($bundle);
        $before = PrivateProofFile::read($this->path($bundle), 0);
        $records = self::decodeRecords($before['bytes'], $bundle->digest(), $this->grantHash);
        $allocators = [];
        foreach (['account_wallets', 'credits', 'account_opening_receipts'] as $table) {
            $allocators[$table] = self::allocator($table);
        }
        $record = ['schema_version' => 1, 'purpose' => 'account-opening-attempt', 'bundle_sha256' => $bundle->digest(), 'grant_sha256' => $this->grantHash,
            'previous_sha256' => end($records)['sha256'], 'sequence' => count($records), 'source_account' => $evidence->sourceAccount, 'owner_id' => $evidence->ownerId, 'currency' => $evidence->currency,
            'allocators' => $allocators, 'budgets' => ['account_wallets' => 1, 'credits' => DB::table('credits')->where('user_id', $evidence->ownerId)->where('currency_code', $evidence->currency)->exists() ? 0 : 1, 'account_opening_receipts' => 1],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z')];

        return $this->append($bundle, $before, $record);
    }

    public function beginBatch(OpeningBundle $bundle): string
    {
        OpeningBatchOperator::assertMutex($bundle);
        $before = PrivateProofFile::read($this->path($bundle), 0);
        $records = self::decodeRecords($before['bytes'], $bundle->digest(), $this->grantHash);
        $record = ['schema_version' => 1, 'purpose' => 'account-opening-batch-attempt', 'bundle_sha256' => $bundle->digest(), 'grant_sha256' => $this->grantHash,
            'previous_sha256' => end($records)['sha256'], 'sequence' => count($records), 'allocators' => ['account_opening_batches' => self::allocator('account_opening_batches')],
            'budgets' => ['account_opening_batches' => 1], 'created_at' => gmdate('Y-m-d\TH:i:s\Z')];

        return $this->append($bundle, $before, $record);
    }

    private function append(OpeningBundle $bundle, array $before, array $record): string
    {
        $bytes = CanonicalPolicy::bytes($record);
        $file = fopen($this->path($bundle), 'r+b');
        if (!$file) {
            throw new RuntimeException('Journal append failed.');
        }
        try {
            $stat = fstat($file);
            if ($stat['dev'] !== $before['device'] || $stat['ino'] !== $before['inode'] || stream_get_contents($file) !== $before['bytes']) {
                throw new RuntimeException('Journal identity changed before append.');
            }
            self::write($file, $bytes . "\n");
        } finally {
            fclose($file);
        }
        PrivateProofFile::read($this->path($bundle), 0);

        return hash('sha256', $bytes);
    }

    public function attempts(OpeningBundle $bundle): array
    {
        $file = PrivateProofFile::read($this->path($bundle), 0);

        return array_values(array_filter(self::decodeRecords($file['bytes'], $bundle->digest(), $this->grantHash), fn ($row) => $row['purpose'] === 'account-opening-attempt'));
    }

    public function batchAttempts(OpeningBundle $bundle): array
    {
        $file = PrivateProofFile::read($this->path($bundle), 0);

        return array_values(array_filter(self::decodeRecords($file['bytes'], $bundle->digest(), $this->grantHash), fn ($row) => $row['purpose'] === 'account-opening-batch-attempt'));
    }

    public static function decodeRecords(string $bytes, string $bundleHash, string $grantHash): array
    {
        if (!str_ends_with($bytes, "\n") || str_contains($bytes, "\r")) {
            throw new RuntimeException('Incomplete opening attempt journal.');
        }
        $records = [];
        foreach (explode("\n", substr($bytes, 0, -1)) as $index => $line) {
            $row = StrictProofJson::decode($line);
            $header = ['schema_version', 'purpose', 'bundle_sha256', 'grant_sha256', 'nonce'];
            $attempt = ['schema_version', 'purpose', 'bundle_sha256', 'grant_sha256', 'previous_sha256', 'sequence', 'source_account', 'owner_id', 'currency', 'allocators', 'budgets', 'created_at'];
            $batchAttempt = ['schema_version', 'purpose', 'bundle_sha256', 'grant_sha256', 'previous_sha256', 'sequence', 'allocators', 'budgets', 'created_at'];
            $batch = $index > 0 && ($row['purpose'] ?? null) === 'account-opening-batch-attempt';
            ProofSchema::keys($row, $index === 0 ? $header : ($batch ? $batchAttempt : $attempt));
            if ($row['schema_version'] !== 1 || $row['bundle_sha256'] !== $bundleHash || $row['grant_sha256'] !== $grantHash ||
                $row['purpose'] !== ($index === 0 ? 'account-opening-attempt-header' : ($batch ? 'account-opening-batch-attempt' : 'account-opening-attempt')) || $line !== CanonicalPolicy::bytes($row)) {
                throw new RuntimeException('Opening attempt journal lineage changed.');
            }
            if ($index === 0) {
                ProofSchema::hash($row['nonce']);
            } else {
                if ($row['sequence'] !== $index || $row['previous_sha256'] !== $records[$index - 1]['sha256'] || (!$batch && (!is_string($row['source_account']) || $row['source_account'] === '' ||
                    !is_int($row['owner_id']) || $row['owner_id'] < 1 || !is_string($row['currency']) || !preg_match('/^[A-Z]{3}$/D', $row['currency'])))) {
                    throw new RuntimeException('Opening attempt journal chain changed.');
                }
                ProofSchema::date($row['created_at']);
                foreach (['allocators', 'budgets'] as $key) {
                    ProofSchema::keys($row[$key], $batch ? ['account_opening_batches'] : ['account_wallets', 'credits', 'account_opening_receipts']);
                    foreach ($row[$key] as $table => $value) {
                        if (!is_int($value) || ($key === 'allocators' ? $value < 1 : !in_array($value, $table === 'credits' ? [0, 1] : [1], true))) {
                            throw new RuntimeException('Unbounded opening allocator allowance.');
                        }
                    }
                }
            }
            $records[] = [...$row, 'sha256' => hash('sha256', $line)];
        }

        return $records;
    }

    public static function allocator(string $table): int
    {
        $row = DB::selectOne('SELECT AUTO_INCREMENT AS allocator FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        if (!$row || $row->allocator === null || (int) $row->allocator < 1) {
            throw new RuntimeException('Opening allocator identity is missing.');
        }

        return (int) $row->allocator;
    }

    private static function write($file, string $bytes): void
    {
        if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || !fsync($file)) {
            throw new RuntimeException('Incomplete fsynced opening journal.');
        }
    }

    private static function syncDirectory(string $path): void
    {
        $file = fopen($path, 'r');
        if (!$file) {
            throw new RuntimeException('Journal directory durability failed.');
        }
        try {
            if (!fsync($file)) {
                throw new RuntimeException('Journal directory durability failed.');
            }
        } finally {
            fclose($file);
        }
    }
}
