<?php

namespace Tests\Unit\BillmanagerMigration;

use App\Services\BillmanagerMigration\Opening\ProofSchema;
use App\Services\BillmanagerMigration\Opening\SignedAttestation;
use App\Services\BillmanagerMigration\Opening\StrictProofJson;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\Opening\CaptureProofFixture;

class CaptureProofSchemaTest extends TestCase
{
    private function denied(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());

            return;
        }
        self::fail('Invalid capture/readiness schema was accepted.');
    }

    private function validate(string $name, array $value): void
    {
        match ($name) {
            'epoch' => ProofSchema::controlEpoch($value),
            'collector' => ProofSchema::collectorReceipt($value),
            default => ProofSchema::attestation($value, CaptureProofFixture::records()[$name]['purpose']),
        };
    }

    public function test_closed_records_require_each_key_and_reject_extras_and_versions(): void
    {
        self::assertTrue(method_exists(ProofSchema::class, 'controlEpoch'), 'Capture schemas are missing.');
        foreach (CaptureProofFixture::records() as $name => $value) {
            $this->validate($name, $value);
            foreach (array_keys($value) as $key) {
                $missing = $value;
                unset($missing[$key]);
                $this->denied(fn () => $this->validate($name, $missing));
            }
            $this->denied(fn () => $this->validate($name, [...$value, 'extra' => true]));
            foreach ([0, 3, '1', 1.0] as $version) {
                $this->denied(fn () => $this->validate($name, [...$value, 'schema_version' => $version]));
            }
            $this->denied(fn () => $this->validate($name, [...$value, 'schema_version' => $value['schema_version'] === 1 ? 2 : 1]));
        }
    }

    public function test_capture_uses_only_runtime_identity(): void
    {
        self::assertTrue(method_exists(ProofSchema::class, 'runtimeTargetIdentity'), 'Runtime identity schema is missing.');
        $p = CaptureProofFixture::records()['capture'];
        $this->validate('capture', $p);
        foreach (['target_identity' => $p['runtime_target_identity'], 'source_population_fingerprint' => str_repeat('a', 64), 'target_baseline_sql_sha256' => str_repeat('a', 64)] as $k => $v) {
            $this->denied(fn () => $this->validate('capture', [...$p, $k => $v]));
        }
        foreach (array_keys($p['runtime_target_identity']) as $key) {
            $missing = $p;
            unset($missing['runtime_target_identity'][$key]);
            $this->denied(fn () => $this->validate('capture', $missing));
        }
        $p['runtime_target_identity']['operator_uid'] = 0;
        $this->denied(fn () => $this->validate('capture', $p));
    }

    public function test_ready_requires_complete_operator_identity(): void
    {
        self::assertTrue(method_exists(ProofSchema::class, 'openingTargetIdentity'), 'Ready identity schema is missing.');
        $p = CaptureProofFixture::records()['ready'];
        $this->validate('ready', $p);
        foreach (array_keys($p['target_identity']) as $key) {
            $missing = $p;
            unset($missing['target_identity'][$key]);
            $this->denied(fn () => $this->validate('ready', $missing));
        }
        foreach (['operator_uid' => 1, 'network_namespace_inode' => 'net:[0]', 'machine_id_sha256' => 'invalid'] as $key => $value) {
            $p2 = $p;
            $p2['target_identity'][$key] = $value;
            $this->denied(fn () => $this->validate('ready', $p2));
        }
    }

    public function test_monotonic_is_canonical_and_overflow_safe(): void
    {
        self::assertTrue(method_exists(ProofSchema::class, 'monotonic'), 'Checked monotonic parser is missing.');
        self::assertSame(0, ProofSchema::monotonic('0'));
        self::assertSame(9223372036854775807, ProofSchema::monotonic('9223372036854775807'));
        foreach (['00', '01', '-1', '+1', '1.0', '1e3', '9223372036854775808', '99999999999999999999', 1, 1.0, null] as $bad) {
            $this->denied(fn () => ProofSchema::monotonic($bad));
        }
        $p = CaptureProofFixture::records()['capture'];
        $p['issued_monotonic_ns'] = '9223372036854775806';
        $p['expires_monotonic_ns'] = '9223372036854775807';
        $this->validate('capture', $p);
        self::addToAssertionCount(1);
        $p['expires_monotonic_ns'] = '9223372036854775808';
        $this->denied(fn () => $this->validate('capture', $p));
    }

    public function test_exact_lease_acceptance_and_scalar_bounds(): void
    {
        self::assertTrue(method_exists(ProofSchema::class, 'controlEpoch'), 'Capture schemas are missing.');
        $records = CaptureProofFixture::records();
        foreach (['capture', 'ready'] as $name) {
            $p = $records[$name];
            $this->validate($name, $p);
            foreach (['2026-01-01T00:00:00Z', '2026-01-01T00:01:01Z', '2025-12-31T23:59:59Z'] as $expiry) {
                $this->denied(fn () => $this->validate($name, [...$p, 'expires_at' => $expiry]));
            }
            foreach (['1000000000', '61000000001', '0'] as $expiry) {
                $q = $records['capture'];
                $q['expires_monotonic_ns'] = $expiry;
                $this->denied(fn () => $this->validate('capture', $q));
            }
        }
        $p = $records['acceptance'];
        $this->validate('acceptance', [...$p, 'expires_at' => '2026-01-01T06:00:00Z']);
        $this->denied(fn () => $this->validate('acceptance', [...$p, 'expires_at' => '2026-01-01T06:00:01Z']));
        foreach (['epoch', 'capture', 'acceptance', 'ready'] as $name) {
            foreach (['issuer', 'freeze_id', 'source_capture_id'] as $key) {
                $this->validate($name, [...$records[$name], $key => str_repeat('x', 128)]);
                $this->denied(fn () => $this->validate($name, [...$records[$name], $key => str_repeat('x', 129)]));
                $this->denied(fn () => $this->validate($name, [...$records[$name], $key => '']));
            }
            $this->validate($name, [...$records[$name], 'source_identity' => str_repeat('s', 45)]);
            $this->denied(fn () => $this->validate($name, [...$records[$name], 'source_identity' => str_repeat('s', 46)]));
        }
        $this->denied(fn () => $this->validate('epoch', [...$records['epoch'], 'source_boot_id' => 'INVALID']));
        foreach ([['sequence' => 0], ['sequence' => 2, 'previous_lease_sha256' => null], ['sequence' => 1, 'previous_lease_sha256' => str_repeat('a', 64)], ['previous_lease_sha256' => 'invalid'], ['not_before' => '2026-01-01T00:00:01Z'], ['status' => 'released']] as $bad) {
            $this->denied(fn () => $this->validate('capture', [...$records['capture'], ...$bad]));
        }
        foreach (['pid' => 0, 'start_ticks' => '01', 'boot_id' => 'INVALID'] as $key => $value) {
            $p = $records['epoch'];
            $p['observer_identity'][$key] = $value;
            $this->denied(fn () => $this->validate('epoch', $p));
        }
    }

    public function test_collector_clock_bounds_are_local_and_quantized(): void
    {
        self::assertTrue(method_exists(ProofSchema::class, 'collectorReceipt'), 'Collector schema is missing.');
        $p = CaptureProofFixture::records()['collector'];
        foreach ([0, 2000] as $skew) {
            ProofSchema::collectorReceipt([...$p, 'source_clock_skew_upper_bound_ms' => $skew]);
        }
        foreach ([-1, 2001, '0', 1.0, null] as $skew) {
            $this->denied(fn () => ProofSchema::collectorReceipt([...$p, 'source_clock_skew_upper_bound_ms' => $skew]));
        }
        // Same-second UTC observations can bracket a positive subsecond run.
        ProofSchema::collectorReceipt([...$p, 'collection_completed_at' => $p['collection_started_at'], 'collection_completed_monotonic_ns' => '2000000001']);
        foreach ([['collection_completed_monotonic_ns' => '2000000000'], ['collection_completed_at' => '2026-01-01T00:00:00Z'], ['collection_completed_monotonic_ns' => '6000000001'], ['snapshot_captured_at' => '2026-01-01T00:00:04Z']] as $bad) {
            $this->denied(fn () => ProofSchema::collectorReceipt([...$p, ...$bad]));
        }
    }

    public function test_history_syntax_count_and_references_are_closed(): void
    {
        self::assertTrue(method_exists(ProofSchema::class, 'leaseHistory'), 'History schema is missing.');
        $pair = ['payload' => ['path' => '/fixture/lease', 'sha256' => str_repeat('a', 64)], 'signature' => ['path' => '/fixture/signature', 'sha256' => str_repeat('b', 64)]];
        $p = ['schema_version' => 1, 'purpose' => 'account-opening-lease-history', 'leases' => [$pair]];
        ProofSchema::leaseHistory($p);
        $many = [];
        for ($i = 0; $i < 10000; $i++) {
            $q = $pair;
            $q['payload']['path'] .= $i;
            $q['signature']['path'] .= $i;
            $many[] = $q;
        }
        ProofSchema::leaseHistory([...$p, 'leases' => $many]);
        $this->denied(fn () => ProofSchema::leaseHistory([...$p, 'leases' => [...$many, $pair]]));
        $this->denied(fn () => ProofSchema::leaseHistory([...$p, 'leases' => []]));
        $this->denied(fn () => ProofSchema::leaseHistory([...$p, 'extra' => true]));
        $q = $pair;
        $q['payload']['extra'] = true;
        $this->denied(fn () => ProofSchema::leaseHistory([...$p, 'leases' => [$q]]));
        $q = $pair;
        $q['payload']['path'] = 'https://example.invalid/lease';
        $this->denied(fn () => ProofSchema::leaseHistory([...$p, 'leases' => [$q]]));
        self::addToAssertionCount(1);
    }

    public function test_capture_signature_needs_explicit_purpose_allowlisting(): void
    {
        self::assertTrue(method_exists(ProofSchema::class, 'controlEpoch'), 'Capture signature dispatch is missing.');
        $p = CaptureProofFixture::records()['capture'];
        $bytes = json_encode($p, JSON_THROW_ON_ERROR);
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_sign($bytes, $raw, $key, OPENSSL_ALGO_SHA256);
        $signature = ['schema_version' => 1, 'key_id' => 'fixture', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($raw)];
        $trust = ['schema_version' => 1, 'purpose' => 'opening-trust', 'keys' => ['fixture' => ['issuer' => 'fixture-custodian', 'public_key' => openssl_pkey_get_details($key)['key'], 'purposes' => ['account-opening-fence']]], 'revoked_keys' => [], 'revoked_nonces' => []];
        $now = new DateTimeImmutable('2026-01-01T00:00:01Z');
        $this->denied(fn () => SignedAttestation::verifyBytes($bytes, $signature, $p['purpose'], $trust, $now));
        $trust['keys']['fixture']['purposes'][] = $p['purpose'];
        self::assertSame('fenced', SignedAttestation::verifyBytes($bytes, $signature, $p['purpose'], $trust, $now)['status']);
        $this->denied(fn () => SignedAttestation::verifyBytes($bytes . ' ', $signature, $p['purpose'], $trust, $now));
        $this->denied(fn () => SignedAttestation::verifyBytes($bytes, $signature, $p['purpose'], $trust, new DateTimeImmutable($p['expires_at'])));
        foreach (['revoked_keys' => ['fixture'], 'revoked_nonces' => ['fixture-nonce']] as $key => $value) {
            $this->denied(fn () => SignedAttestation::verifyBytes($bytes, $signature, $p['purpose'], [...$trust, $key => $value], $now));
        }
        $weakKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_sign($bytes, $weakRaw, $weakKey, OPENSSL_ALGO_SHA256);
        $weakTrust = $trust;
        $weakTrust['keys']['fixture']['public_key'] = openssl_pkey_get_details($weakKey)['key'];
        $this->denied(fn () => SignedAttestation::verifyBytes($bytes, [...$signature, 'signature_b64' => base64_encode($weakRaw)], $p['purpose'], $weakTrust, $now));
        foreach (['{"schema_version":1,"schema_version":1}', '{"schema_version":1.0}', '{"issued_monotonic_ns":1e9}'] as $bad) {
            $this->denied(fn () => StrictProofJson::decode($bad));
        }
    }
}
