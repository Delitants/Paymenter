<?php

namespace App\Services\BillmanagerMigration\Opening;

use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Models\AccountWallet;
use App\Services\Accounts\AccountAmount;
use App\Services\Accounts\AccountWriteContext;
use App\Services\Accounts\OpeningEvidence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OpeningReceiptVerifier
{
    public static function evidence(OpeningBundle $bundle, AccountOpeningBatch $batch, array $account): OpeningEvidence
    {
        return new OpeningEvidence($account['source_system'], $account['source_account'], $account['owner_id'], $account['currency'], $account['opening'], $account['limit_exact'],
            $bundle->snapshot()->checksum(), $bundle->data()['policy_sha256'], $batch->grant_sha256, false);
    }

    public static function assertBatch(OpeningBundle $bundle, AccountOpeningBatch $batch): void
    {
        $data = $bundle->data();
        $expected = ['bundle_sha256' => $bundle->digest(), 'source_identity' => $data['source_identity'], 'import_id' => $data['import_id'], 'target_identity' => $bundle->target(),
            'release_sha256' => $data['release_sha256'], 'baseline_sql_sha256' => $data['baseline_sql_sha256'], 'policy_sha256' => $data['policy_sha256'], 'scope_sha256' => $data['scope_fingerprint'],
            'snapshot_sha256' => $bundle->snapshot()->checksum(), 'freeze_receipt_sha256' => $data['freeze_receipt_sha256'], 'freeze_id' => $data['freeze_id']];
        if (!$batch->exists || $batch->only(array_keys($expected)) !== $expected || !in_array($batch->state, ['opening', 'sealed'], true) ||
            ($batch->state === 'opening' && ($batch->sealed_at !== null || $batch->verification_sha256 !== null)) ||
            ($batch->state === 'sealed' && ($batch->sealed_at === null || $batch->verification_sha256 === null))) {
            throw new RuntimeException('Original opening batch identity changed.');
        }
        ProofSchema::hash($batch->grant_sha256);
        if ((array) DB::table('account_opening_batches')->where('id', $batch->id)->sole() !== $batch->getRawOriginal()) {
            throw new RuntimeException('Opening batch readback changed.');
        }
        $journal = new OpeningAttemptJournal($batch->grant_sha256);
        if ($journal->path($bundle) !== $batch->journal_path) {
            throw new RuntimeException('Original journal path changed.');
        }
        $file = PrivateProofFile::read($batch->journal_path, 0);
        $rows = OpeningAttemptJournal::decodeRecords($file['bytes'], $bundle->digest(), $batch->grant_sha256);
        if ($file['device'] !== $batch->journal_device || $file['inode'] !== $batch->journal_inode || $rows[0]['sha256'] !== $batch->journal_header_sha256) {
            throw new RuntimeException('Original journal identity changed.');
        }
    }

    public static function committedRows(OpeningBundle $bundle, AccountOpeningBatch $batch): array
    {
        self::assertBatch($bundle, $batch);
        $accounts = (new OpeningPreparation)->sourceFacts($bundle)['accounts'];
        $attempts = (new OpeningAttemptJournal($batch->grant_sha256))->attempts($bundle);
        $attemptIndex = [];
        foreach ($attempts as $attempt) {
            $matches = array_values(array_filter($accounts, fn ($row) => $row['source_account'] === $attempt['source_account'] && $row['currency'] === $attempt['currency'] && $row['owner_id'] === $attempt['owner_id']));
            if (count($matches) !== 1 || $matches[0]['disposition'] !== 'candidate') {
                throw new RuntimeException('Journal attempted an unapproved account.');
            }
            $attemptIndex[$attempt['sha256']] = $attempt;
        }
        $batchRaw = (array) DB::table('account_opening_batches')->where('id', $batch->id)->sole();
        $rows = [['batch_row' => $batchRaw, 'receipt_row' => null, 'wallet_row' => null, 'credit_before' => null, 'credit_after' => null, 'attempts' => $attempts, 'batch_attempts' => (new OpeningAttemptJournal($batch->grant_sha256))->batchAttempts($bundle)]];
        $usedAttempts = [];
        foreach (AccountOpeningReceipt::where('batch_id', $batch->id)->orderBy('id')->get() as $receipt) {
            $raw = (array) DB::table('account_opening_receipts')->where('id', $receipt->id)->sole();
            if ($raw !== $receipt->getRawOriginal() || OpeningBatchStore::receiptHash($raw) !== $receipt->receipt_sha256) {
                throw new RuntimeException('Durable opening receipt bytes changed.');
            }
            $delta = StrictProofJson::decode($raw['delta']);
            ProofSchema::keys($delta, ['wallet_row', 'credit_before', 'credit_after', 'fence_proof']);
            $matches = array_values(array_filter($accounts, fn ($row) => $row['source_account'] === $receipt->source_account && $row['currency'] === $receipt->currency));
            if (count($matches) !== 1 || $matches[0]['disposition'] !== 'candidate') {
                throw new RuntimeException('Durable receipt is outside the original scope.');
            }
            $evidence = self::evidence($bundle, $batch, $matches[0]);
            $expected = AccountWriteContext::openingAttributesFrom($evidence);
            $wallet = AccountWallet::findOrFail($receipt->wallet_id);
            $cash = DB::table('credits')->where('user_id', $evidence->ownerId)->where('currency_code', $evidence->currency)->get();
            $balance = AccountAmount::parse($expected['balance']);
            $expectedCash = $balance->compare(AccountAmount::parse('0')) > 0 ? $balance->floorCents() : '0.00';
            if ($receipt->owner_id !== $evidence->ownerId || $receipt->opening_identity !== $expected['opening_identity'] || $wallet->only(array_keys($expected)) !== $expected ||
                $receipt->evidence_sha256 !== hash('sha256', CanonicalPolicy::bytes(get_object_vars($evidence))) ||
                $delta['wallet_row'] !== (array) DB::table('account_wallets')->where('id', $wallet->id)->sole() || $cash->count() !== 1 || $delta['credit_after'] !== (array) $cash->first() ||
                $delta['credit_after']['amount'] !== $expectedCash || $wallet->movements()->exists() || $wallet->allocations()->exists() || $wallet->reservations()->exists() ||
                DB::table('account_posting_issues')->where('wallet_id', $wallet->id)->exists()) {
                throw new RuntimeException('Opening wallet or cash projection facts changed.');
            }
            if ($delta['credit_before'] !== null) {
                $before = $delta['credit_before'];
                if (!is_array($before) || AccountAmount::parse($before['amount'])->exact() !== '0.0000' || $before['user_id'] !== $evidence->ownerId || $before['currency_code'] !== $evidence->currency ||
                    array_diff_key($before, ['amount' => true, 'updated_at' => true]) !== array_diff_key($delta['credit_after'], ['amount' => true, 'updated_at' => true])) {
                    throw new RuntimeException('Original zero cash row was not preserved.');
                }
            }
            $attempt = $attemptIndex[$receipt->attempt_sha256] ?? null;
            if (!$attempt || isset($usedAttempts[$receipt->attempt_sha256]) || $attempt['source_account'] !== $evidence->sourceAccount || $attempt['currency'] !== $evidence->currency || $attempt['owner_id'] !== $evidence->ownerId ||
                $attempt['budgets']['credits'] !== ($delta['credit_before'] === null ? 1 : 0)) {
                throw new RuntimeException('Receipt lacks its unique original bounded attempt.');
            }
            $usedAttempts[$receipt->attempt_sha256] = true;
            foreach (['account_wallets' => $wallet->id, 'credits' => $delta['credit_before'] === null ? $delta['credit_after']['id'] : null, 'account_opening_receipts' => $receipt->id] as $table => $id) {
                if ($id !== null && ($id < $attempt['allocators'][$table] || $id >= $attempt['allocators'][$table] + $attempt['budgets'][$table])) {
                    throw new RuntimeException('Opening allocation exceeded its durable attempt.');
                }
            }
            $fence = self::historicalFence($bundle, $delta['fence_proof']);
            $rows[] = ['batch_row' => $batchRaw, 'receipt_row' => $raw, 'wallet_row' => $delta['wallet_row'], 'credit_before' => $delta['credit_before'], 'credit_after' => $delta['credit_after'], 'attempts' => $attempts, 'fence' => $fence];
        }

        return $rows;
    }

    private static function historicalFence(OpeningBundle $bundle, array $proof): array
    {
        ProofSchema::keys($proof, ['payload_b64', 'signature']);
        $bytes = is_string($proof['payload_b64']) ? base64_decode($proof['payload_b64'], true) : false;
        if ($bytes === false || base64_encode($bytes) !== $proof['payload_b64']) {
            throw new RuntimeException('Recorded fence bytes are invalid.');
        }
        $payload = StrictProofJson::decode($bytes);
        $trustPath = config('account-opening.trust_path');
        if (!is_string($trustPath) || $trustPath === '') {
            throw new RuntimeException('Original fence verification trust is required.');
        }
        $trust = StrictProofJson::decode(PrivateProofFile::read($trustPath, 0)['bytes']);
        $fence = SignedAttestation::verifyBytes($bytes, $proof['signature'], 'account-opening-fence', $trust, ProofSchema::date($payload['issued_at']));
        $initial = StrictProofJson::decode(PrivateProofFile::read($bundle->reference('freeze_receipt'), 0)['bytes']);
        foreach (['issuer', 'freeze_id', 'source_identity', 'source_population_fingerprint', 'target_identity', 'target_baseline_sql_sha256', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint'] as $key) {
            if ($fence[$key] !== $initial[$key]) {
                throw new RuntimeException('Recorded fence lineage changed.');
            }
        }
        if ($fence['sequence'] < $initial['sequence'] || ProofSchema::date($fence['issued_at']) < ProofSchema::date($initial['issued_at'])) {
            throw new RuntimeException('Recorded fence regressed.');
        }

        return $fence;
    }

    public function verify(OpeningBundle $bundle, AccountOpeningBatch $batch): array
    {
        $process = OpeningProcessContext::capture();
        if ($process->targetIdentity() !== $bundle->target()) {
            throw new RuntimeException('Verification target changed.');
        }
        $rows = self::committedRows($bundle, $batch);
        $baseline = StrictProofJson::decode(PrivateProofFile::read($bundle->reference('target_inventory'), 0)['bytes']);
        (new OpeningTargetState)->assertExpected($baseline, $rows);
        $identities = array_map(fn ($row) => ['receipt_id' => $row['receipt_row']['id'], 'receipt_sha256' => $row['receipt_row']['receipt_sha256'], 'wallet_id' => $row['wallet_row']['id']], array_slice($rows, 1));
        $hash = hash('sha256', CanonicalPolicy::bytes(['bundle_sha256' => $bundle->digest(), 'grant_sha256' => $batch->grant_sha256, 'scope_sha256' => $batch->scope_sha256, 'receipts' => $identities]));
        $expected = count($bundle->accounts());
        $committed = count($identities);
        if ($committed > $expected || ($batch->state === 'sealed' && ($committed !== $expected || $batch->verification_sha256 !== $hash))) {
            throw new RuntimeException('Terminal opening verification changed.');
        }
        $process->assertCurrent();

        return ['status' => $batch->state === 'sealed' ? 'sealed' : ($committed === $expected ? 'complete_inactive' : 'partial_inactive'), 'expected' => $expected, 'committed' => $committed,
            'sealed' => $batch->state === 'sealed', 'verification_sha256' => $hash];
    }
}
