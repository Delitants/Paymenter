<?php

namespace App\Services\BillmanagerMigration\Opening;

use App\Models\AccountOpeningBatch;
use App\Services\Accounts\OpeningEvidence;
use App\Services\Accounts\WalletLedger;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OpeningBatchOperator
{
    private static ?self $active = null;

    private ?OpeningBundle $bundle = null;

    private ?InactiveOpeningAuthority $authority = null;

    private ?AccountOpeningBatch $batch = null;

    private ?OpeningEvidence $account = null;

    private ?string $attempt = null;

    private ?Connection $connection = null;

    private ?\PDO $pdo = null;

    private $mutex = null;

    private ?string $mutexPath = null;

    private ?array $mutexIdentity = null;

    private ?string $databaseMutex = null;

    public function apply(OpeningBundle $bundle, string $approvalPath, string $signaturePath): array
    {
        $process = OpeningProcessContext::capture();
        if (!$bundle->executable() || $process->targetIdentity() !== $bundle->target() || !$bundle->accounts() || DB::transactionLevel() !== 0) {
            throw new RuntimeException('A genuine isolated batch operator and complete executable scope are required.');
        }
        $this->bundle = $bundle;
        $this->authority = new InactiveOpeningAuthority($bundle, $approvalPath, $signaturePath, $process);
        $this->lock();
        try {
            $this->batch = AccountOpeningBatch::where('bundle_sha256', $bundle->digest())->first();
            if ($this->batch && $this->batch->state === 'sealed') {
                throw new RuntimeException('Sealed opening batches cannot initialize.');
            }
            $first = $bundle->accounts()[0];
            $firstEvidence = new OpeningEvidence($first['source_system'], $first['source_account'], $first['owner_id'], $first['currency'], $first['opening'], $first['limit_exact'],
                $bundle->snapshot()->checksum(), $bundle->data()['policy_sha256'], $this->authority->grantHash(), false);
            $this->authority->assertApproved($firstEvidence);
            $journal = new OpeningAttemptJournal($this->authority->grantHash());
            $baseline = StrictProofJson::decode(PrivateProofFile::read($bundle->reference('target_inventory'), 0)['bytes']);
            $anchor = $journal->initialize($bundle);
            $batchAttempts = $journal->batchAttempts($bundle);
            if (!$this->batch) {
                (new OpeningTargetState)->assertInitial($baseline, $batchAttempts);
                (new OpeningBackupVerifier)->assertReady($bundle, $batchAttempts);
            } else {
                OpeningReceiptVerifier::assertBatch($bundle, $this->batch);
                if ($this->batch->grant_sha256 !== $this->authority->grantHash()) {
                    throw new RuntimeException('A resumed batch requires its original external approval.');
                }
            }
            $store = new OpeningBatchStore($bundle, $this->authority, $anchor);
            if (!$this->batch) {
                $journal->beginBatch($bundle);
                $this->batch = DB::transaction(function () use ($store, $bundle, $baseline) {
                    $batch = $store->open($bundle, $this->authority->grantHash());
                    (new OpeningTargetState)->assertExpected($baseline, $store->committed($batch));
                    $this->authority->assertFenceCurrent();

                    return $batch;
                });
            }
            $verified = (new OpeningReceiptVerifier)->verify($bundle, $this->batch);
            $replayed = $verified['committed'];
            foreach (OpeningReceiptVerifier::committedRows($bundle, $this->batch) as $row) {
                if (isset($row['fence'])) {
                    $this->authority->assertFenceNotBefore($row['fence']);
                }
            }
            foreach ($bundle->accounts() as $account) {
                $rows = $store->committed($this->batch);
                (new OpeningTargetState)->assertExpected($baseline, $rows);
                $this->account = OpeningReceiptVerifier::evidence($bundle, $this->batch, $account);
                $this->authority->assertApproved($this->account);
                $existing = array_filter(array_slice($rows, 1), fn ($row) => $row['receipt_row']['source_account'] === $account['source_account'] && $row['receipt_row']['currency'] === $account['currency']);
                if ($existing) {
                    $this->account = null;

                    continue;
                }
                $this->attempt = $journal->begin($bundle, $this->account);
                try {
                    $this->authority->duringAccount($this->account, function ($permit) use ($store, $baseline) {
                        $query = DB::table('credits')->where('user_id', $this->account->ownerId)->where('currency_code', $this->account->currency);
                        // The ledger takes the credit lock after owner/currency,
                        // native receipts and wallet. This read is not an earlier
                        // credit lock; exact baseline comparison binds its bytes.
                        $credit = $query->first();
                        $before = $credit ? (array) $credit : null;
                        $wallet = (new WalletLedger)->initializeHeldInactive($this->account, $this->authority, $permit);
                        $store->append($this->batch, $this->account, $wallet, $before);
                        (new OpeningTargetState)->assertExpected($baseline, $store->committed($this->batch));
                        $this->authority->assertFenceCurrent();
                    });
                } finally {
                    $this->account = null;
                    $this->attempt = null;
                }
            }
            $report = (new OpeningReceiptVerifier)->verify($bundle, $this->batch);
            DB::transaction(function () use ($store, $report) {
                $this->batch = AccountOpeningBatch::whereKey($this->batch->id)->lockForUpdate()->firstOrFail();
                $this->authority->assertAcceptedRelease();
                $store->seal($this->batch, $report['verification_sha256']);
            });
            $report = (new OpeningReceiptVerifier)->verify($bundle, $this->batch->refresh());

            return ['status' => $report['status'], 'expected' => $report['expected'], 'committed' => $report['committed'], 'replayed' => $replayed, 'sealed' => $report['sealed']];
        } finally {
            $this->unlock();
        }
    }

    private function lock(): void
    {
        if (self::$active !== null) {
            throw new RuntimeException('An opening batch operator is already running.');
        }
        $name = hash('sha256', CanonicalPolicy::bytes($this->bundle->target()));
        $this->mutexPath = OpeningAttemptJournal::directory() . '/target-' . $name . '.lock';
        $mask = umask(0077);
        try {
            if (!file_exists($this->mutexPath)) {
                $file = fopen($this->mutexPath, 'x');
                if (!$file) {
                    throw new RuntimeException('Operator mutex creation failed.');
                }
                fclose($file);
            }
        } finally {
            umask($mask);
        }
        $this->mutexIdentity = PrivateProofFile::read($this->mutexPath, 0);
        $this->mutex = fopen($this->mutexPath, 'r+b');
        if (!$this->mutex || !flock($this->mutex, LOCK_EX | LOCK_NB)) {
            $this->unlock();
            throw new RuntimeException('Opening filesystem mutex is unavailable.');
        }
        $stat = fstat($this->mutex);
        if ($stat['dev'] !== $this->mutexIdentity['device'] || $stat['ino'] !== $this->mutexIdentity['inode']) {
            $this->unlock();
            throw new RuntimeException('Operator mutex identity changed.');
        }
        $this->connection = DB::connection();
        $this->pdo = $this->connection->getPdo();
        try {
            $lock = 'opening:' . substr($name, 0, 56);
            if ((int) $this->connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired !== 1) {
                throw new RuntimeException('Opening database mutex is unavailable.');
            }
            $this->databaseMutex = $lock;
            self::$active = $this;
        } catch (\Throwable $e) {
            $this->unlock();
            throw $e;
        }
    }

    private function unlock(): void
    {
        try {
            if ($this->databaseMutex !== null && $this->connection?->getPdo() === $this->pdo) {
                if ((int) $this->connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->databaseMutex])->released !== 1) {
                    throw new RuntimeException('Owned opening database mutex was lost.');
                }
            }
        } finally {
            if (self::$active === $this) {
                self::$active = null;
            }
            if (is_resource($this->mutex)) {
                flock($this->mutex, LOCK_UN);
                fclose($this->mutex);
            }
            $this->mutex = null;
            $this->databaseMutex = null;
            $this->connection = null;
            $this->pdo = null;
            $this->account = null;
            $this->attempt = null;
        }
    }

    public static function assertMutex(OpeningBundle $bundle, ?InactiveOpeningAuthority $authority = null): void
    {
        $scope = self::$active;
        if (!$scope || $scope->bundle !== $bundle || ($authority !== null && $scope->authority !== $authority) || !is_resource($scope->mutex) ||
            $scope->connection !== DB::connection() || $scope->pdo !== DB::connection()->getPdo() || $scope->databaseMutex === null) {
            throw new RuntimeException('A live exact batch operator mutex is required.');
        }
        $named = PrivateProofFile::read($scope->mutexPath, 0);
        if ($named['device'] !== $scope->mutexIdentity['device'] || $named['inode'] !== $scope->mutexIdentity['inode'] ||
            (int) $scope->connection->selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned', [$scope->databaseMutex])->owned !== 1) {
            throw new RuntimeException('Owned opening mutex identity changed.');
        }
    }

    public static function assertAccountScope(InactiveOpeningAuthority $authority, OpeningEvidence $evidence): void
    {
        $scope = self::$active;
        if (!$scope || $scope->authority !== $authority || $scope->account !== $evidence || $scope->batch?->state !== 'opening' || $scope->attempt === null || DB::transactionLevel() < 1) {
            throw new RuntimeException('A held opening requires its live atomic batch receipt scope.');
        }
        self::assertMutex($scope->bundle, $authority);
    }

    public static function assertAccountCommitted(InactiveOpeningAuthority $authority, OpeningEvidence $evidence): void
    {
        self::assertAccountScope($authority, $evidence);
        $rows = OpeningReceiptVerifier::committedRows(self::$active->bundle, self::$active->batch);
        $matches = array_filter(array_slice($rows, 1), fn ($row) => $row['receipt_row']['source_account'] === $evidence->sourceAccount && $row['receipt_row']['currency'] === $evidence->currency && $row['receipt_row']['attempt_sha256'] === self::$active->attempt);
        if (count($matches) !== 1) {
            throw new RuntimeException('An opening must commit with its exact durable receipt.');
        }
    }

    public static function attemptHash(): string
    {
        if (!self::$active || self::$active->attempt === null) {
            throw new RuntimeException('No current durable opening attempt.');
        }

        return self::$active->attempt;
    }
}
