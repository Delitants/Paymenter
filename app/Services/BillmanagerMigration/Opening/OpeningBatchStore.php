<?php

namespace App\Services\BillmanagerMigration\Opening;

use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Models\AccountWallet;
use App\Services\Accounts\OpeningEvidence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OpeningBatchStore
{
    private static ?self $writing = null;

    private ?Model $pending = null;

    private ?array $attributes = null;

    private ?string $action = null;

    private ?Builder $pendingBuilder = null;

    public function __construct(private OpeningBundle $bundle, private InactiveOpeningAuthority $authority, private array $journal)
    {
        OpeningBatchOperator::assertMutex($bundle, $authority);
    }

    public function open(OpeningBundle $bundle, string $grantHash): AccountOpeningBatch
    {
        $this->assertScope();
        if ($bundle !== $this->bundle || $grantHash !== $this->authority->grantHash()) {
            throw new RuntimeException('Batch evidence differs from the live operator.');
        }
        $row = AccountOpeningBatch::where('bundle_sha256', $bundle->digest())->lockForUpdate()->first();
        if ($row) {
            OpeningReceiptVerifier::assertBatch($bundle, $row);
            if ($row->grant_sha256 !== $grantHash || $row->state !== 'opening') {
                throw new RuntimeException('Original opening grant or batch state changed.');
            }

            return $row;
        }
        $data = $bundle->data();
        $row = new AccountOpeningBatch(['bundle_sha256' => $bundle->digest(), 'grant_sha256' => $grantHash, 'source_identity' => $data['source_identity'], 'import_id' => $data['import_id'],
            'target_identity' => $bundle->target(), 'release_sha256' => $data['release_sha256'], 'baseline_sql_sha256' => $data['baseline_sql_sha256'], 'policy_sha256' => $data['policy_sha256'],
            'scope_sha256' => $data['scope_fingerprint'], 'snapshot_sha256' => $bundle->snapshot()->checksum(), 'freeze_receipt_sha256' => $data['freeze_receipt_sha256'], 'freeze_id' => $data['freeze_id'],
            'journal_path' => $this->journal['path'], 'journal_device' => $this->journal['device'], 'journal_inode' => $this->journal['inode'], 'journal_header_sha256' => $this->journal['header_sha256'],
            'state' => 'opening', 'created_at' => gmdate('Y-m-d H:i:s'), 'sealed_at' => null, 'verification_sha256' => null]);
        $this->save($row, 'create');

        return $row->refresh();
    }

    public function append(AccountOpeningBatch $batch, OpeningEvidence $evidence, AccountWallet $wallet, ?array $creditBefore): AccountOpeningReceipt
    {
        $this->assertScope();
        OpeningBatchOperator::assertAccountScope($this->authority, $evidence);
        $this->authority->assertApproved($evidence);
        OpeningReceiptVerifier::assertBatch($this->bundle, $batch);
        if ($batch->state !== 'opening') {
            throw new RuntimeException('Sealed opening batches cannot initialize.');
        }
        $existing = AccountOpeningReceipt::where('batch_id', $batch->id)->where('source_account', $evidence->sourceAccount)->where('currency', $evidence->currency)->lockForUpdate()->first();
        if ($existing) {
            OpeningReceiptVerifier::committedRows($this->bundle, $batch);

            return $existing;
        }
        $cash = DB::table('credits')->where('user_id', $evidence->ownerId)->where('currency_code', $evidence->currency)->sole();
        $delta = ['wallet_row' => (array) DB::table('account_wallets')->where('id', $wallet->id)->sole(), 'credit_before' => $creditBefore, 'credit_after' => (array) $cash,
            'fence_proof' => $this->authority->fenceProof()];
        $row = new AccountOpeningReceipt(['batch_id' => $batch->id, 'wallet_id' => $wallet->id, 'opening_identity' => $wallet->opening_identity,
            'source_account' => $evidence->sourceAccount, 'currency' => $evidence->currency, 'owner_id' => $evidence->ownerId,
            'evidence_sha256' => hash('sha256', CanonicalPolicy::bytes(get_object_vars($evidence))), 'attempt_sha256' => OpeningBatchOperator::attemptHash(), 'delta' => $delta,
            'created_at' => gmdate('Y-m-d H:i:s')]);
        $row->receipt_sha256 = self::receiptHash($row->getAttributes());
        $this->save($row, 'create');
        OpeningReceiptVerifier::committedRows($this->bundle, $batch);

        return $row->refresh();
    }

    public function committed(AccountOpeningBatch $batch): array
    {
        return OpeningReceiptVerifier::committedRows($this->bundle, $batch);
    }

    public function seal(AccountOpeningBatch $batch, string $verificationHash): void
    {
        $this->assertScope();
        OpeningReceiptVerifier::assertBatch($this->bundle, $batch);
        $first = $this->bundle->accounts()[0];
        $evidence = OpeningReceiptVerifier::evidence($this->bundle, $batch, $first);
        $this->authority->assertApproved($evidence);
        $report = (new OpeningReceiptVerifier)->verify($this->bundle, $batch);
        if ($batch->state !== 'opening' || $report['committed'] !== $report['expected'] || $verificationHash !== $report['verification_sha256']) {
            throw new RuntimeException('Only an exactly verified complete inactive batch can seal.');
        }
        $this->authority->assertFenceCurrent();
        $batch->fill(['state' => 'sealed', 'sealed_at' => gmdate('Y-m-d H:i:s'), 'verification_sha256' => $verificationHash]);
        $this->save($batch, 'update');
        (new OpeningReceiptVerifier)->verify($this->bundle, $batch->refresh());
        $this->authority->assertApproved($evidence);
        $this->authority->assertFenceCurrent();
    }

    public static function receiptHash(array $row): string
    {
        unset($row['id'], $row['receipt_sha256']);

        return hash('sha256', CanonicalPolicy::bytes($row));
    }

    private function assertScope(): void
    {
        OpeningBatchOperator::assertMutex($this->bundle, $this->authority);
        if (DB::transactionLevel() < 1 || !DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('Opening history requires the original live transaction.');
        }
    }

    private function save(Model $row, string $action): void
    {
        if (self::$writing !== null) {
            throw new RuntimeException('Nested opening history mutation is denied.');
        }
        $this->assertScope();
        self::$writing = $this;
        $this->pending = $row;
        $this->attributes = $row->getAttributes();
        $this->action = $action;
        try {
            $row->saveOrFail();
        } finally {
            self::$writing = null;
            $this->pending = null;
            $this->attributes = null;
            $this->action = null;
            $this->pendingBuilder = null;
        }
    }

    public static function assertMutation(Model $row, string $action, ?Builder $query = null): void
    {
        $scope = self::$writing;
        if (!$scope || $scope->pending !== $row || $scope->action !== $action || $scope->attributes !== $row->getAttributes() || $action === 'delete') {
            throw new RuntimeException('Opening history is writable only by the live batch store.');
        }
        $scope->assertScope();
        if ($query !== null) {
            if ($scope->pendingBuilder !== null && $scope->pendingBuilder !== $query) {
                throw new RuntimeException('Opening history is writable only by the live batch store.');
            }
            $scope->pendingBuilder = $query;
        }
        if ($action === 'update' && (!$row instanceof AccountOpeningBatch || $row->getRawOriginal('state') !== 'opening' || $row->state !== 'sealed' || array_diff(array_keys($row->getDirty()), ['state', 'sealed_at', 'verification_sha256']))) {
            throw new RuntimeException('Opening history has only one terminal seal transition.');
        }
    }

    public static function assertBuilderMutation(Builder $query, string $action, array $values): void
    {
        $scope = self::$writing;
        if (!$scope || $scope->pendingBuilder !== $query || $scope->action !== $action || !$scope->pending ||
            $values !== ($action === 'create' ? $scope->pending->getAttributes() : $scope->pending->getDirty())) {
            throw new RuntimeException('Opening history is writable only by the live batch store.');
        }
        $scope->assertScope();
    }
}
