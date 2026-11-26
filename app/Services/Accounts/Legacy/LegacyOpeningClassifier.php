<?php

namespace App\Services\Accounts\Legacy;

use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Models\LegacyReceiptConflict;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\OpeningBatchStore;
use App\Services\BillmanagerMigration\Opening\OpeningReceiptVerifier;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use App\Services\BillmanagerMigration\Opening\RuntimeProofFile;
use App\Services\BillmanagerMigration\Opening\SignedAttestation;
use App\Services\BillmanagerMigration\Opening\StrictProofJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Read-only evidence that a legacy effect was included in an accepted opening. */
final class LegacyOpeningClassifier
{
    private const PURPOSE = 'legacy-opening-membership';

    private const CONTROL_FIELDS = ['control_epoch_sha256', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint'];

    private const FILE_LIMITS = [
        'membership_path' => 4 * 1024 * 1024,
        'acceptance_path' => 65536,
        'signature_path' => 65536,
        'trust_path' => 262144,
    ];

    public function __construct(private readonly LegacyClock $clock) {}

    public function classify(string $effectKey): array
    {
        EffectIdentity::sha256($effectKey);
        $files = $this->readProofs();
        $trust = StrictProofJson::decode($files['trust_path']['bytes']);
        foreach (['revoked_keys', 'revoked_nonces'] as $field) {
            if (!is_array($trust[$field] ?? null) || !array_is_list($trust[$field])) {
                throw new RuntimeException('Legacy opening revocations require a list of identifiers.');
            }
            foreach ($trust[$field] as $identifier) {
                EffectIdentity::id($identifier);
            }
        }
        $acceptance = SignedAttestation::verifyBytes($files['acceptance_path']['bytes'],
            StrictProofJson::decode($files['signature_path']['bytes']), self::PURPOSE,
            $trust, $this->clock->now());
        if ($files['acceptance_path']['bytes'] !== CanonicalPolicy::bytes($acceptance) ||
            $files['membership_path']['sha256'] !== $acceptance['membership_sha256']) {
            throw new RuntimeException('Accepted opening membership bytes changed.');
        }
        $member = $this->findMember($files['membership_path']['bytes'], $effectKey);

        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('Legacy membership requires a fresh database snapshot.');
        }
        $classified = DB::transaction(function () use ($acceptance, $member, $effectKey) {
            $batch = AccountOpeningBatch::where('bundle_sha256', $acceptance['bundle_sha256'])->sole();
            $boundary = array_intersect_key($acceptance, array_flip(['bundle_sha256', 'source_identity', 'snapshot_sha256', 'scope_sha256', 'policy_sha256', 'verification_sha256']));
            if ($batch->state !== 'sealed' || $batch->sealed_at === null || $batch->only(array_keys($boundary)) !== $boundary) {
                throw new RuntimeException('Legacy membership requires its original sealed opening batch.');
            }
            $effect = LegacyEffect::where('effect_key', $effectKey)->sole();
            $identity = EffectIdentity::fromArray(StrictProofJson::decode($effect->identity_json));
            $facts = EconomicFacts::fromArray(StrictProofJson::decode($effect->economic_json));
            if ($identity->key() !== $effectKey || $facts->fingerprint() !== $effect->economic_sha256) {
                throw new RuntimeException('Stored legacy effect identity or economics changed.');
            }
            $result = ['classification' => 'ambiguous', 'effect_key' => $effectKey,
                'membership_sha256' => $acceptance['membership_sha256'], 'opening_receipt_sha256' => null];
            if ($member === null || $member['economic_sha256'] !== $effect->economic_sha256 ||
                $identity->data()['source_instance'] !== $acceptance['source_instance'] ||
                LegacyReceiptConflict::where('effect_id', $effect->id)->exists() ||
                !LegacyObservation::where('effect_id', $effect->id)->where('economic_sha256', $effect->economic_sha256)
                    ->where('disposition', 'pending_source_qualification')->exists()) {
                return $result;
            }
            $receipt = AccountOpeningReceipt::where('batch_id', $batch->id)
                ->where('receipt_sha256', $member['opening_receipt_sha256'])->sole();
            $this->assertSealedMembership($batch, $receipt);
            $this->assertOriginalOpening($receipt, $batch, $facts, $acceptance);

            return array_replace($result, ['classification' => 'included', 'opening_receipt_sha256' => $receipt->receipt_sha256]);
        }, 1);
        if ($this->readProofs() !== $files) {
            throw new RuntimeException('Accepted legacy opening membership changed during verification.');
        }
        $now = $this->clock->now();
        if ($now < ProofSchema::date($acceptance['not_before']) || $now >= ProofSchema::date($acceptance['expires_at'])) {
            throw new RuntimeException('Legacy opening membership expired during verification.');
        }

        return $classified;
    }

    private function readProofs(): array
    {
        $config = config('legacy-payments.opening_membership');
        if (!is_array($config) || !is_int($config['reader_gid'] ?? null)) {
            throw new RuntimeException('Configured legacy opening membership evidence is required.');
        }
        $files = [];
        foreach (self::FILE_LIMITS as $name => $limit) {
            if (!is_string($config[$name] ?? null)) {
                throw new RuntimeException('Configured legacy opening membership evidence is required.');
            }
            $files[$name] = RuntimeProofFile::read($config[$name], $config['reader_gid'], $limit);
        }

        return $files;
    }

    private function findMember(string $bytes, string $effectKey): ?array
    {
        $manifest = StrictProofJson::decode($bytes);
        ProofSchema::keys($manifest, ['schema_version', 'purpose', 'effects']);
        if ($manifest['schema_version'] !== 1 || $manifest['purpose'] !== 'legacy-opening-effects' ||
            !is_array($manifest['effects']) || !array_is_list($manifest['effects']) || CanonicalPolicy::bytes($manifest) !== $bytes) {
            throw new RuntimeException('Canonical legacy opening effect membership is required.');
        }
        $previous = null;
        $found = null;
        foreach ($manifest['effects'] as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('A complete opening effect membership entry is required.');
            }
            ProofSchema::keys($entry, ['effect_key', 'economic_sha256', 'opening_receipt_sha256']);
            foreach ($entry as $digest) {
                ProofSchema::hash($digest);
            }
            $key = $entry['effect_key'];
            if ($previous !== null && strcmp($previous, $key) >= 0) {
                throw new RuntimeException('Legacy opening effects must be unique and in bytewise order.');
            }
            $previous = $key;
            if ($key === $effectKey) {
                $found = $entry;
            }
        }

        return $found;
    }

    private function assertOriginalOpening(AccountOpeningReceipt $receipt, AccountOpeningBatch $batch, EconomicFacts $facts, array $acceptance): void
    {
        $economic = $facts->data();
        $identity = hash('sha256', json_encode(['billmanager:' . $batch->source_identity, $economic['source_account'], $economic['wallet_currency']], JSON_THROW_ON_ERROR));
        if ($receipt->source_account !== $economic['source_account'] || $receipt->currency !== $economic['wallet_currency'] ||
            $receipt->opening_identity !== $identity || OpeningBatchStore::receiptHash($receipt->getRawOriginal()) !== $receipt->receipt_sha256) {
            throw new RuntimeException('Legacy membership does not match the original opening receipt.');
        }
        $delta = StrictProofJson::decode($receipt->getRawOriginal('delta'));
        ProofSchema::keys($delta, ['wallet_row', 'credit_before', 'credit_after', 'fence_proof']);
        $proof = $delta['fence_proof'];
        if (!is_array($proof)) {
            throw new RuntimeException('Original v2 opening custody is required.');
        }
        ProofSchema::keys($proof, ['schema_version', 'payload_b64', 'signature', 'history']);
        $encoded = $proof['payload_b64'];
        $bytes = is_string($encoded) && strlen($encoded) <= 87384 ? base64_decode($encoded, true) : false;
        if ($proof['schema_version'] !== 2 || !is_array($proof['signature']) || !is_array($proof['history']) ||
            $bytes === false || strlen($bytes) > 65536 || base64_encode($bytes) !== $encoded) {
            throw new RuntimeException('Original v2 opening custody is required.');
        }
        $fence = StrictProofJson::decode($bytes);
        ProofSchema::attestation($fence, 'account-opening-fence');
        if ($fence['schema_version'] !== 2 || $fence['source_identity'] !== $batch->source_identity || $fence['freeze_id'] !== $batch->freeze_id ||
            $fence['target_baseline_sql_sha256'] !== $batch->baseline_sql_sha256 ||
            CanonicalPolicy::bytes($fence['target_identity']) !== CanonicalPolicy::bytes($batch->target_identity)) {
            throw new RuntimeException('Recorded custody belongs to a different original opening.');
        }
        foreach (self::CONTROL_FIELDS as $field) {
            if ($fence[$field] !== $acceptance[$field]) {
                throw new RuntimeException('Legacy membership does not match the original source boundary.');
            }
        }
    }

    private function assertSealedMembership(AccountOpeningBatch $batch, AccountOpeningReceipt $selected): void
    {
        $sealed = AccountOpeningReceipt::where('batch_id', $batch->id)->orderBy('id')
            ->get(['id', 'receipt_sha256', 'wallet_id'])
            ->map(fn (AccountOpeningReceipt $receipt) => [
                'receipt_id' => $receipt->id, 'receipt_sha256' => $receipt->receipt_sha256, 'wallet_id' => $receipt->wallet_id,
            ])->all();
        $selectedIdentity = ['receipt_id' => $selected->id, 'receipt_sha256' => $selected->receipt_sha256, 'wallet_id' => $selected->wallet_id];
        if (!in_array($selectedIdentity, $sealed, true) ||
            OpeningReceiptVerifier::verificationHash($batch->bundle_sha256, $batch->grant_sha256, $batch->scope_sha256, $sealed) !== $batch->verification_sha256) {
            throw new RuntimeException('Legacy membership does not match the terminal opening seal.');
        }
    }
}
