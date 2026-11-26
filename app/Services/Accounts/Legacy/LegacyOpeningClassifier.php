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
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Classifies a legacy effect against its accepted opening without moving funds. */
final class LegacyOpeningClassifier
{
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
        $now = $this->clock->now();
        [$acceptance, $membershipKey] = $this->verifyAttestation($files, 'legacy-opening-membership', $now);
        if ($files['membership_path']['sha256'] !== $acceptance['membership_sha256']) {
            throw new RuntimeException('Accepted opening membership bytes changed.');
        }
        $member = $this->findMember($files['membership_path']['bytes'], $effectKey);
        $excludedEvidence = $this->readExclusion($effectKey, $acceptance, $membershipKey, $now, $member === null);
        [$exclusion, $exclusionKey, $exclusionFiles] = $excludedEvidence;
        $exclusionHash = $exclusionFiles['acceptance_path']['sha256'] ?? null;
        $sourceTrust = $exclusion !== null && $member === null ? LegacyOpeningExclusion::sourceTrust() : null;
        $approvalKeys = [$membershipKey, $exclusionKey];

        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('Legacy membership requires a fresh database snapshot.');
        }
        $classified = DB::transaction(function () use ($acceptance, $member, $exclusion, $exclusionHash, $effectKey, $sourceTrust, $approvalKeys) {
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
            $entry = $member ?? $exclusion;
            if ($entry === null || ($member !== null && $exclusion !== null) || $entry['economic_sha256'] !== $effect->economic_sha256 ||
                $identity->data()['source_instance'] !== $acceptance['source_instance'] ||
                LegacyReceiptConflict::where('effect_id', $effect->id)->exists()) {
                return $result;
            }
            if ($member !== null && !LegacyObservation::where('effect_id', $effect->id)->where('economic_sha256', $effect->economic_sha256)
                ->where('disposition', 'pending_source_qualification')->exists()) {
                return $result;
            }
            $receipt = AccountOpeningReceipt::where('batch_id', $batch->id)
                ->where('receipt_sha256', $entry['opening_receipt_sha256'])->sole();
            $this->assertSealedMembership($batch, $receipt);
            $this->assertOriginalOpening($receipt, $batch, $facts, $acceptance);
            if ($sourceTrust !== null) {
                $net = LegacyOpeningExclusion::netDelta($exclusion, $acceptance, $effect, $sourceTrust, $approvalKeys);

                return $net === null ? $result : array_replace($result, ['classification' => 'excluded',
                    'opening_receipt_sha256' => $receipt->receipt_sha256, 'exclusion_sha256' => $exclusionHash, 'net_delta' => $net]);
            }

            return array_replace($result, ['classification' => 'included', 'opening_receipt_sha256' => $receipt->receipt_sha256]);
        }, 1);
        if ($this->readProofs() !== $files ||
            $this->readExclusion($effectKey, $acceptance, $membershipKey, $now, $member === null) !== $excludedEvidence ||
            ($sourceTrust !== null && LegacyOpeningExclusion::sourceTrust()->sha256 !== $sourceTrust->sha256)) {
            throw new RuntimeException('Accepted legacy opening evidence changed during verification.');
        }
        $now = $this->clock->now();
        foreach ([$acceptance, $exclusion] as $attestation) {
            if ($attestation !== null && ($now < ProofSchema::date($attestation['not_before']) || $now >= ProofSchema::date($attestation['expires_at']))) {
                throw new RuntimeException('Legacy opening evidence expired during verification.');
            }
        }

        return $classified;
    }

    /** @return array{?array,?string,array} */
    private function readExclusion(string $effectKey, array $membership, string $membershipKey, DateTimeImmutable $now, bool $requireValid): array
    {
        try {
            $files = LegacyOpeningExclusion::proofFiles($effectKey);
            if ($files === []) {
                return [null, null, []];
            }
            [$exclusion, $signer] = $this->verifyAttestation($files, 'legacy-opening-exclusion', $now);
            if ($exclusion['effect_key'] !== $effectKey || $signer === $membershipKey) {
                throw new RuntimeException('Excluded payment requires the original effect and an independent approval key.');
            }
            foreach (['source_instance', 'bundle_sha256', 'membership_sha256', 'verification_sha256'] as $field) {
                if ($exclusion[$field] !== $membership[$field]) {
                    throw new RuntimeException('Excluded payment does not match its accepted opening.');
                }
            }

            return [$exclusion, $signer, $files];
        } catch (RuntimeException|DomainException $error) {
            if ($requireValid) {
                throw $error;
            }

            // A stale or invalid exclusion certificate is not evidence against a proved member.
            return [null, null, []];
        }
    }

    /** @return array{array,string} */
    private function verifyAttestation(array $files, string $purpose, DateTimeImmutable $now): array
    {
        $trust = StrictProofJson::decode($files['trust_path']['bytes']);
        foreach (['revoked_keys', 'revoked_nonces'] as $field) {
            if (!is_array($trust[$field] ?? null) || !array_is_list($trust[$field])) {
                throw new RuntimeException('Legacy opening revocations require a list of identifiers.');
            }
            foreach ($trust[$field] as $identifier) {
                EffectIdentity::id($identifier);
            }
        }
        $bytes = $files['acceptance_path']['bytes'];
        $signature = StrictProofJson::decode($files['signature_path']['bytes']);
        $keyId = $signature['key_id'] ?? null;
        $key = is_string($keyId) ? ($trust['keys'][$keyId]['public_key'] ?? null) : null;
        $signer = LegacyTransportTrust::keyFingerprint($key);
        $attestation = SignedAttestation::verifyBytes($bytes, $signature, $purpose, $trust, $now);
        if (CanonicalPolicy::bytes($attestation) !== $bytes) {
            throw new RuntimeException('Canonical accepted legacy opening evidence is required.');
        }

        return [$attestation, $signer];
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
