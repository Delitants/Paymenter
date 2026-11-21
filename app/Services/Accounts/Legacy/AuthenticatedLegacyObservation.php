<?php

namespace App\Services\Accounts\Legacy;

use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use DateTimeImmutable;
use RuntimeException;

/** Transport authentication only: no native commit, inclusion or money authority. */
final readonly class AuthenticatedLegacyObservation
{
    private function __construct(
        public EffectIdentity $identity,
        public EconomicFacts $economic,
        public array $observation,
        public string $payloadBytes,
        public string $signatureBytes,
        public string $payloadSha256,
        public string $signatureSha256,
        public string $issuer,
        public string $keyId,
        public string $nonce,
        public string $policySha256,
    ) {}

    public static function verify(string $payloadBytes, string $signatureBytes, LegacyTransportTrust $trust, DateTimeImmutable $now): self
    {
        $envelope = LegacyEnvelope::parse($payloadBytes);
        $signature = BoundedLegacyJson::decode($signatureBytes, 65536);
        EffectIdentity::closed($signature, ['schema_version', 'key_id', 'algorithm', 'signature_b64']);
        if ($signature['schema_version'] !== 1 || $signature['algorithm'] !== 'rsa-sha256' || !is_string($signature['signature_b64'])) {
            throw new RuntimeException('Unsupported legacy signature.');
        }
        if (CanonicalPolicy::bytes($signature) !== $signatureBytes) {
            throw new RuntimeException('Legacy signature JSON must be byte-canonical.');
        }
        LegacyTransportTrust::keyId($signature['key_id']);
        $policy = $trust->data;
        $keyId = $signature['key_id'];
        $key = $policy['keys'][$keyId] ?? null;
        $data = $envelope->data;
        if (!$key || in_array($keyId, $policy['revoked_keys'], true) || in_array($data['nonce'], $policy['revoked_nonces'], true) ||
            $key['issuer'] !== $data['issuer'] || !in_array($data['purpose'], $key['purposes'], true) ||
            $now < ProofSchema::date($data['not_before']) || $now >= ProofSchema::date($data['expires_at'])) {
            throw new RuntimeException('Legacy issuer, key, purpose, revocation or validity denied.');
        }
        $identity = $envelope->identity->data();
        $granted = false;
        foreach ($key['sources'] as $source) {
            if ($source['source_instance'] === $identity['source_instance'] && $source['merchant_binding'] === $identity['merchant_binding'] && in_array($identity['effect_kind'], $source['effect_kinds'], true)) {
                $granted = true;
                break;
            }
        }
        $signatureRaw = base64_decode($signature['signature_b64'], true);
        if (!$granted || $signatureRaw === false || base64_encode($signatureRaw) !== $signature['signature_b64'] ||
            openssl_verify($payloadBytes, $signatureRaw, $key['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Legacy source grant or original-byte signature denied.');
        }

        return new self($envelope->identity, $envelope->economic, $envelope->observation, $payloadBytes, $signatureBytes,
            hash('sha256', $payloadBytes), hash('sha256', $signatureBytes), $data['issuer'], $keyId, $data['nonce'], $trust->sha256);
    }
}
