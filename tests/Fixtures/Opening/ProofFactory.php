<?php

namespace Tests\Fixtures\Opening;

use RuntimeException;

final class ProofFactory
{
    public static array $directories = [];

    public static array $children = [];

    public static function signed(string $purpose, array $fields = [], ?string $parent = null): array
    {
        $parent ??= getenv('OPENING_FIXTURE_ROOT') ?: sys_get_temp_dir();
        $dir = $parent . '/proof-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        self::$directories[] = $dir;
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key) {
            throw new RuntimeException('Synthetic signing key unavailable.');
        }
        $payload = ['schema_version' => 1, 'purpose' => $purpose, 'issuer' => 'fixture-custodian',
            'not_before' => '2026-01-01T00:00:00Z', 'expires_at' => '2026-01-01T00:01:00Z', 'nonce' => 'fixture-nonce', ...$fields];
        $bytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        openssl_sign($bytes, $sig, $key, OPENSSL_ALGO_SHA256);
        self::write($dir . '/payload.json', $bytes);
        self::write($dir . '/signature.json', json_encode(['schema_version' => 1, 'key_id' => 'fixture', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($sig)], JSON_THROW_ON_ERROR));
        self::write($dir . '/trust.json', json_encode(['schema_version' => 1, 'purpose' => 'opening-trust', 'keys' => ['fixture' => ['issuer' => 'fixture-custodian', 'public_key' => openssl_pkey_get_details($key)['key'], 'purposes' => [$purpose]]], 'revoked_keys' => [], 'revoked_nonces' => []], JSON_THROW_ON_ERROR));

        return ['dir' => $dir, 'payload' => $dir . '/payload.json', 'signature' => $dir . '/signature.json', 'trust' => $dir . '/trust.json', 'value' => $payload, 'key' => $key];
    }

    public static function write(string $path, string $bytes): void
    {
        file_put_contents($path, $bytes);
        chmod($path, 0600);
    }

    public static function cleanup(): void
    {
        foreach (self::$children as $pid) {
            posix_kill($pid, SIGCONT);
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
        self::$children = [];
        foreach (self::$directories as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($dir);
        }
        self::$directories = [];
    }

    public static function fence(): array
    {
        return ['issued_at' => '2026-01-01T00:00:00Z', 'freeze_id' => 'fixture-freeze', 'sequence' => 1, 'status' => 'fenced',
            'source_identity' => 'source.example.invalid', 'source_population_fingerprint' => str_repeat('a', 64),
            'target_identity' => ['runtime_realpath' => '/fixture'], 'target_baseline_sql_sha256' => str_repeat('b', 64),
            'controlled_writers_fingerprint' => str_repeat('c', 64), 'incoming_payment_boundary_fingerprint' => str_repeat('d', 64)];
    }
}
