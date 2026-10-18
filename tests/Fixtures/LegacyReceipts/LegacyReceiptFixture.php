<?php

namespace Tests\Fixtures\LegacyReceipts;

use App\Services\Accounts\Legacy\LegacyClock;
use App\Services\Accounts\Legacy\LegacyReceiptInbox;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use DateTimeImmutable;
use Tests\Fixtures\Opening\RuntimeFixture;

final class LegacyReceiptFixture
{
    private static ?array $ingress = null;

    public static function withProtectedIngress(\Closure $test): mixed
    {
        if (self::$ingress !== null) {
            throw new \RuntimeException('Nested ingress fixture refused.');
        }
        $keys = self::keyPair();
        $original = config('legacy-payments');
        $clockWasBound = app()->bound(LegacyClock::class);
        $originalClock = $clockWasBound ? app(LegacyClock::class) : null;
        try {
            return self::withProtectedTrust(self::trust($keys), function ($path, $gid) use ($test, $keys) {
                $clock = new class implements LegacyClock
                {
                    public DateTimeImmutable $value;

                    public function __construct()
                    {
                        $this->value = new DateTimeImmutable('2026-01-01T00:01:00Z');
                    }

                    public function now(): DateTimeImmutable
                    {
                        return $this->value;
                    }
                };
                app()->instance(LegacyClock::class, $clock);
                config(['legacy-payments.receipt_ingress_enabled' => true, 'legacy-payments.trust_path' => $path, 'legacy-payments.trust_reader_gid' => $gid]);
                self::$ingress = compact('keys', 'path', 'clock');
                [$payload, $signature] = self::delivery();

                return $test(new LegacyReceiptInbox($clock), $payload, $signature);
            });
        } finally {
            self::$ingress = null; // Fictional private key never leaves memory.
            config(['legacy-payments' => $original]);
            app()->forgetInstance(LegacyClock::class);
            if ($clockWasBound) {
                app()->instance(LegacyClock::class, $originalClock);
            }
        }
    }

    public static function delivery(array $overrides = []): array
    {
        if (self::$ingress === null) {
            throw new \RuntimeException('Active fictional ingress fixture required.');
        }
        $payload = self::payload($overrides);

        return [$payload, self::signature($payload, self::$ingress['keys'])];
    }

    public static function replaceTrust(array $overrides): void
    {
        file_put_contents(self::$ingress['path'], CanonicalPolicy::bytes(self::trust(self::$ingress['keys'], $overrides)));
    }

    public static function advanceClock(string $time): void
    {
        self::$ingress['clock']->value = new DateTimeImmutable($time);
    }

    public static function payload(array $rootOverrides = []): string
    {
        return CanonicalPolicy::bytes([...[
            'schema_version' => 1, 'purpose' => 'legacy-payment-observation', 'issuer' => 'fictional-source',
            'not_before' => '2026-01-01T00:00:00Z', 'expires_at' => '2026-01-02T00:00:00Z', 'nonce' => 'delivery-one',
            'identity' => GoldenVectors::identity(), 'economic' => GoldenVectors::economic(),
            'observation' => ['custody_id' => 'custody-one', 'journal_id' => 'journal-one', 'raw_sha256' => str_repeat('b', 64),
                'validation_ref' => 'validation-one', 'validator_sha256' => str_repeat('c', 64), 'credential_version' => 'credential-one', 'commit_binding_ref' => 'unproven-commit-reference'],
        ], ...$rootOverrides]);
    }

    public static function keyPair(int $bits = 3072): array
    {
        $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key || !openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('Fictional in-memory key generation failed.');
        }

        return ['private_pem' => $private, 'public_pem' => openssl_pkey_get_details($key)['key']];
    }

    public static function signature(string $payload, array $keyPair): string
    {
        if (!openssl_sign($payload, $signature, $keyPair['private_pem'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Fictional signature generation failed.');
        }

        return CanonicalPolicy::bytes(['schema_version' => 1, 'key_id' => 'fixture-key', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($signature)]);
    }

    public static function trust(array $keyPair, array $rootOverrides = []): array
    {
        return [...[
            'schema_version' => 1, 'purpose' => 'legacy-receipt-trust',
            'keys' => ['fixture-key' => ['issuer' => 'fictional-source', 'public_key' => $keyPair['public_pem'],
                'purposes' => ['legacy-payment-observation'], 'sources' => [[
                    'source_instance' => 'fictional-source', 'merchant_binding' => 'merchant-a',
                    'effect_kinds' => ['capture', 'refund', 'reversal', 'chargeback', 'manual_settlement', 'manual_unsettlement', 'manual_restore'],
                ]]]],
            'revoked_keys' => [], 'revoked_nonces' => [], 'retained_byte_limit' => 1048576, 'retained_observation_limit' => 100,
        ], ...$rootOverrides];
    }

    public static function withProtectedTrust(array $trust, \Closure $test): mixed
    {
        $root = RuntimeFixture::root();
        $gid = RuntimeFixture::gid();
        $directory = $root . '/legacy-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0750) || !chgrp($directory, $gid)) {
            throw new \RuntimeException('Owned protected legacy fixture directory unavailable.');
        }
        $path = $directory . '/trust.json';
        try {
            file_put_contents($path, CanonicalPolicy::bytes($trust));
            chmod($path, 0640);
            chgrp($path, $gid);

            return $test($path, $gid);
        } finally {
            foreach (glob($directory . '/*') as $owned) {
                if (!is_dir($owned) || is_link($owned)) {
                    unlink($owned);
                }
            }
            rmdir($directory);
        }
    }
}
