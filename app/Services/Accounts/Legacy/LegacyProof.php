<?php

namespace App\Services\Accounts\Legacy;

use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\RuntimeProofFile;
use App\Services\BillmanagerMigration\Opening\SignedAttestation;
use App\Services\BillmanagerMigration\Opening\StrictProofJson;
use DateTimeImmutable;
use RuntimeException;

final class LegacyProof
{
    public static function forEffect(string $effectKey, mixed $config): array
    {
        EffectIdentity::sha256($effectKey);
        if ($config === null || (is_array($config) && array_filter($config, fn ($item) => $item !== null) === [])) {
            return [];
        }
        if (!is_array($config) || !is_string($config['directory'] ?? null) ||
            !is_string($config['trust_path'] ?? null) || !is_int($config['reader_gid'] ?? null)) {
            throw new RuntimeException('Complete legacy effect proof configuration is required.');
        }
        $directory = $config['directory'];
        clearstatcache(true);
        if (!str_starts_with($directory, '/') || str_contains($directory, "\0") || realpath($directory) !== $directory || !is_dir($directory)) {
            throw new RuntimeException('An ordinary legacy proof directory is required.');
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

    /** @return array{array,string} */
    public static function verify(array $files, string $purpose, DateTimeImmutable $now): array
    {
        $trust = StrictProofJson::decode($files['trust_path']['bytes']);
        foreach (['revoked_keys', 'revoked_nonces'] as $field) {
            if (!is_array($trust[$field] ?? null) || !array_is_list($trust[$field])) {
                throw new RuntimeException('Legacy revocations require a list of identifiers.');
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
            throw new RuntimeException('Canonical accepted legacy evidence is required.');
        }

        return [$attestation, $signer];
    }
}
