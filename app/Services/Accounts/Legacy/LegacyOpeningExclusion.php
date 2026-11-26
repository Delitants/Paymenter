<?php

namespace App\Services\Accounts\Legacy;

use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Services\Accounts\AccountAmount;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use App\Services\BillmanagerMigration\Opening\RuntimeProofFile;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class LegacyOpeningExclusion
{
    public static function proofFiles(string $effectKey): array
    {
        EffectIdentity::sha256($effectKey);
        $config = config('legacy-payments.opening_exclusion');
        if ($config === null || (is_array($config) && array_filter($config, fn ($item) => $item !== null) === [])) {
            return [];
        }
        if (!is_array($config) || !is_string($config['directory'] ?? null) ||
            !is_string($config['trust_path'] ?? null) || !is_int($config['reader_gid'] ?? null)) {
            throw new RuntimeException('Complete legacy exclusion evidence configuration is required.');
        }
        $directory = $config['directory'];
        clearstatcache(true);
        if (!str_starts_with($directory, '/') || str_contains($directory, "\0") || realpath($directory) !== $directory || !is_dir($directory)) {
            throw new RuntimeException('An ordinary legacy exclusion proof directory is required.');
        }
        $payload = $directory . '/' . $effectKey . '.json';
        $signature = $directory . '/' . $effectKey . '.sig.json';
        if (@lstat($payload) === false && @lstat($signature) === false) {
            return [];
        }

        return [
            'acceptance_path' => RuntimeProofFile::read($payload, $config['reader_gid'], 65536),
            'signature_path' => RuntimeProofFile::read($signature, $config['reader_gid'], 65536),
            'trust_path' => RuntimeProofFile::read($config['trust_path'], $config['reader_gid'], 262144),
        ];
    }

    public static function sourceTrust(): LegacyTransportTrust
    {
        $path = config('legacy-payments.trust_path');
        $group = config('legacy-payments.trust_reader_gid');
        if (!is_string($path) || !is_int($group)) {
            throw new RuntimeException('The original legacy transport trust is required.');
        }

        return LegacyTrustReader::read($path, $group);
    }

    /** Verify the original, independently accepted source record without authorizing a movement. */
    public static function netDelta(array $exclusion, array $opening, LegacyEffect $effect, LegacyTransportTrust $sourceTrust, array $approvalKeys): ?string
    {
        $proof = $exclusion['source_commit'];
        ProofSchema::keys($proof, ['schema_version', 'purpose', 'effect_key', 'economic_sha256', 'control_epoch_sha256',
            'journal_id', 'source_transaction_ref', 'wallet_before', 'wallet_after', 'crossing_allocations']);
        EffectIdentity::id($proof['source_transaction_ref']);
        EffectIdentity::id($proof['journal_id']);
        if ($proof['schema_version'] !== 1 || $proof['purpose'] !== 'legacy-source-wallet-commit' ||
            $proof['effect_key'] !== $effect->effect_key || $proof['economic_sha256'] !== $effect->economic_sha256 ||
            $proof['control_epoch_sha256'] !== $opening['control_epoch_sha256'] ||
            !is_array($proof['crossing_allocations']) || !array_is_list($proof['crossing_allocations'])) {
            throw new RuntimeException('Original legacy commit evidence does not match the accepted source boundary.');
        }
        if ($proof['crossing_allocations'] !== []) {
            return null;
        }

        $retained = LegacyObservation::where('effect_id', $effect->id)->orderBy('id')->firstOrFail();
        $payloadHash = hash('sha256', $retained->payload_bytes);
        $signatureHash = hash('sha256', $retained->signature_bytes);
        $observationKey = hash('sha256', "legacy-observation-v1\0" . $payloadHash . $signatureHash);
        if ($payloadHash !== $retained->payload_sha256 || $signatureHash !== $retained->signature_sha256 ||
            $observationKey !== $retained->observation_key || $observationKey !== $exclusion['observation_key'] ||
            $retained->policy_sha256 !== $exclusion['observation_policy_sha256'] || $retained->policy_sha256 !== $sourceTrust->sha256 ||
            $retained->disposition !== 'pending_source_qualification' || $retained->economic_sha256 !== $effect->economic_sha256) {
            throw new RuntimeException('Accepted exclusion does not match the original retained observation.');
        }
        $admitted = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $retained->created_at, new DateTimeZone('UTC'));
        if (!$admitted || $admitted->format('Y-m-d H:i:s') !== $retained->created_at) {
            throw new RuntimeException('The original legacy admission time is invalid.');
        }
        $event = AuthenticatedLegacyObservation::verify($retained->payload_bytes, $retained->signature_bytes, $sourceTrust, $admitted);
        if (in_array(LegacyTransportTrust::keyFingerprint($sourceTrust->data['keys'][$event->keyId]['public_key']), $approvalKeys, true)) {
            throw new RuntimeException('Source and approval keys must be distinct.');
        }
        if ($event->identity->key() !== $effect->effect_key || $event->economic->fingerprint() !== $effect->economic_sha256 ||
            $event->observation['journal_id'] !== $proof['journal_id'] ||
            $event->observation['commit_binding_ref'] !== 'sha256:' . hash('sha256', CanonicalPolicy::bytes($proof))) {
            throw new RuntimeException('Retained source evidence does not bind the accepted commit.');
        }
        $before = AccountAmount::parse($proof['wallet_before']);
        $after = AccountAmount::parse($proof['wallet_after']);
        $net = $after->subtract($before)->exact();
        if ($before->exact() !== $proof['wallet_before'] || $after->exact() !== $proof['wallet_after'] || $net !== $event->economic->delta()) {
            throw new RuntimeException('Accepted source wallet transition must conserve the exact economic effect.');
        }

        return $net;
    }
}
