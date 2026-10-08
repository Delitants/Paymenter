<?php

namespace App\Services\BillmanagerMigration\Opening;

use DateTimeImmutable;
use RuntimeException;

final class SignedAttestation
{
    public static function verify(string $payloadPath, string $signaturePath, string $purpose, string $trustPath, DateTimeImmutable $now): array
    {
        $payload = PrivateProofFile::read($payloadPath, 0);
        $signature = StrictProofJson::decode(PrivateProofFile::read($signaturePath, 0)['bytes']);
        $trust = StrictProofJson::decode(PrivateProofFile::read($trustPath, 0)['bytes']);

        return self::verifyBytes($payload['bytes'], $signature, $purpose, $trust, $now);
    }

    public static function verifyRuntime(string $payloadPath, string $signaturePath, string $trustPath, DateTimeImmutable $now, int $readerGid): array
    {
        $payload = RuntimeProofFile::read($payloadPath, $readerGid);
        $signature = StrictProofJson::decode(RuntimeProofFile::read($signaturePath, $readerGid)['bytes']);
        $trust = StrictProofJson::decode(RuntimeProofFile::read($trustPath, $readerGid)['bytes']);

        return self::verifyBytes($payload['bytes'], $signature, 'account-funding-runtime-acceptance', $trust, $now);
    }

    public static function verifyBytes(string $bytes, array $signature, string $purpose, array $trust, DateTimeImmutable $now): array
    {
        ProofSchema::keys($signature, ['schema_version', 'key_id', 'algorithm', 'signature_b64']);
        ProofSchema::keys($trust, ['schema_version', 'purpose', 'keys', 'revoked_keys', 'revoked_nonces']);
        if ($signature['schema_version'] !== 1 || $signature['algorithm'] !== 'rsa-sha256' || !is_string($signature['key_id']) || !is_string($signature['signature_b64']) ||
            $trust['schema_version'] !== 1 || $trust['purpose'] !== 'opening-trust' || !is_array($trust['keys']) || !array_is_list($trust['revoked_keys']) || !array_is_list($trust['revoked_nonces'])) {
            throw new RuntimeException('Invalid attestation trust or signature schema.');
        }
        $key = $trust['keys'][$signature['key_id']] ?? null;
        if (!is_array($key) || in_array($signature['key_id'], $trust['revoked_keys'], true)) {
            throw new RuntimeException('Missing or revoked attestation issuer.');
        }
        ProofSchema::keys($key, ['issuer', 'public_key', 'purposes']);
        $value = StrictProofJson::decode($bytes);
        ProofSchema::attestation($value, $purpose);
        if ($key['issuer'] !== $value['issuer'] || !is_array($key['purposes']) || !in_array($purpose, $key['purposes'], true) || in_array($value['nonce'], $trust['revoked_nonces'], true) ||
            $now < ProofSchema::date($value['not_before']) || $now >= ProofSchema::date($value['expires_at'])) {
            throw new RuntimeException('Attestation purpose, revocation or execution window denied.');
        }
        $public = is_string($key['public_key']) ? openssl_pkey_get_public($key['public_key']) : false;
        $details = $public ? openssl_pkey_get_details($public) : false;
        $sig = base64_decode($signature['signature_b64'], true);
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 3072 || $sig === false || base64_encode($sig) !== $signature['signature_b64'] ||
            openssl_verify($bytes, $sig, $public, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Attestation signature is invalid.');
        }

        return $value;
    }
}
