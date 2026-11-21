<?php

namespace App\Services\Accounts\Legacy;

use RuntimeException;

/** Purpose-specific transport policy. This is not protected-file or posting authority. */
final readonly class LegacyTransportTrust
{
    private function __construct(public array $data, public string $sha256) {}

    public static function fromBytes(string $bytes): self
    {
        $data = BoundedLegacyJson::decode($bytes, 262144, 8, 4096);
        EffectIdentity::closed($data, ['schema_version', 'purpose', 'keys', 'revoked_keys', 'revoked_nonces', 'retained_byte_limit', 'retained_observation_limit']);
        if ($data['schema_version'] !== 1 || $data['purpose'] !== 'legacy-receipt-trust' || !is_array($data['keys']) || !$data['keys'] || array_is_list($data['keys'])) {
            throw new RuntimeException('Explicit purpose-specific legacy transport trust is required.');
        }
        foreach (['retained_byte_limit', 'retained_observation_limit'] as $field) {
            if (!is_int($data[$field]) || $data[$field] < 1 || $data[$field] > 2147483647) {
                throw new RuntimeException('Positive bounded legacy retention quotas are required.');
            }
        }
        foreach ($data['keys'] as $keyId => $key) {
            self::keyId($keyId);
            if (!is_array($key)) {
                throw new RuntimeException('A closed legacy key record is required.');
            }
            EffectIdentity::closed($key, ['issuer', 'public_key', 'purposes', 'sources']);
            EffectIdentity::id($key['issuer'], 128);
            self::list($key['purposes']);
            foreach ($key['purposes'] as $purpose) {
                if ($purpose !== 'legacy-payment-observation') {
                    throw new RuntimeException('Unknown legacy key purpose.');
                }
            }
            self::list($key['sources']);
            foreach ($key['sources'] as $source) {
                if (!is_array($source)) {
                    throw new RuntimeException('Explicit legacy source grants are required.');
                }
                EffectIdentity::closed($source, ['source_instance', 'merchant_binding', 'effect_kinds']);
                foreach (['source_instance', 'merchant_binding'] as $field) {
                    EffectIdentity::id($source[$field]);
                    if ($source[$field] === '*') {
                        throw new RuntimeException('Wildcard legacy source grants are forbidden.');
                    }
                }
                self::list($source['effect_kinds']);
                if (count(array_unique($source['effect_kinds'], SORT_REGULAR)) !== count($source['effect_kinds'])) {
                    throw new RuntimeException('Duplicate legacy effect kinds are forbidden.');
                }
                foreach ($source['effect_kinds'] as $kind) {
                    if (!in_array($kind, EffectIdentity::KINDS, true)) {
                        throw new RuntimeException('Unsupported legacy source effect kind.');
                    }
                }
            }
            if (!is_string($key['public_key']) || !($public = @openssl_pkey_get_public($key['public_key']))) {
                throw new RuntimeException('A legacy RSA public key is required.');
            }
            $details = openssl_pkey_get_details($public);
            if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 3072) {
                throw new RuntimeException('Legacy RSA keys must have at least 3072 bits.');
            }
        }
        foreach (['revoked_keys', 'revoked_nonces'] as $field) {
            self::list($data[$field]);
            foreach ($data[$field] as $value) {
                if ($field === 'revoked_keys') {
                    self::keyId($value);
                } else {
                    EffectIdentity::id($value, 128);
                }
            }
        }

        return new self($data, hash('sha256', $bytes));
    }

    private static function list(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('A legacy trust list is required.');
        }
    }

    public static function keyId(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value)) {
            throw new RuntimeException('An unambiguous bounded legacy key ID is required.');
        }
    }
}
