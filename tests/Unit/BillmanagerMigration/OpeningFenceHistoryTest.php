<?php

namespace Tests\Unit\BillmanagerMigration;

use App\Services\BillmanagerMigration\Opening\ControlProofGraph;
use App\Services\BillmanagerMigration\Opening\OpeningFenceHistoryVerifier;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\Opening\CaptureProofFixture;
use Tests\Fixtures\Opening\ProofFactory;

#[Group('opening-native')]
class OpeningFenceHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(OpeningFenceHistoryVerifier::class), 'Continuous signed history verification is missing.');
    }

    protected function tearDown(): void
    {
        ProofFactory::cleanup();
        parent::tearDown();
    }

    private function denied(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());

            return;
        }
        self::fail('Invalid custody history was accepted.');
    }

    private function verify(array $p, ?array $ref = null): array
    {
        return OpeningFenceHistoryVerifier::verify($ref ?? $p['history_ref'], new ControlProofGraph, $p['trust_path']);
    }

    private function alternative(array $p, array $leases): array
    {
        return ($p['records']['write'])('alternative-' . bin2hex(random_bytes(4)), ['schema_version' => 1, 'purpose' => 'account-opening-lease-history', 'leases' => $leases]);
    }

    public function test_complete_capture_to_ready_chain_preserves_exact_original_pairs(): void
    {
        $p = CaptureProofFixture::history();
        $history = $this->verify($p);
        self::assertSame([1, 2, 3], array_column(array_column($history['entries'], 'payload'), 'sequence'));
        self::assertNull($history['entries'][0]['payload']['previous_lease_sha256']);
        self::assertSame($p['records']['leases'][1]['payload']['sha256'], $history['entries'][2]['payload']['previous_lease_sha256']);
        self::assertSame(file_get_contents($p['records']['leases'][2]['payload']['path']), $history['entries'][2]['payload_bytes']);
        $prefix = $this->verify($p, $p['records']['capture_history_ref']);
        OpeningFenceHistoryVerifier::assertPrefix($prefix, $history);
        OpeningFenceHistoryVerifier::assertCoverage($history, '2026-01-01T00:00:01Z', '2026-01-01T00:00:20Z', '2000000000', '21000000000');
    }

    public function test_missing_duplicate_skipped_reordered_or_replaced_ancestor_denies(): void
    {
        $p = CaptureProofFixture::history();
        $pairs = $p['records']['leases'];
        foreach ([[$pairs[1], $pairs[2]], [$pairs[0], $pairs[2]], [$pairs[0], $pairs[0], $pairs[1], $pairs[2]], [$pairs[1], $pairs[0], $pairs[2]]] as $bad) {
            $ref = $this->alternative($p, $bad);
            $this->denied(fn () => $this->verify($p, $ref));
        }
        foreach ([['previous_lease_sha256' => str_repeat('f', 64)], ['sequence' => 4], ['issuer' => 'changed-issuer'], ['source_capture_id' => 'changed-capture'], ['control_epoch_sha256' => str_repeat('f', 64)], ['controlled_writers_fingerprint' => str_repeat('f', 64)], ['incoming_payment_boundary_fingerprint' => str_repeat('f', 64)]] as $change) {
            $p = CaptureProofFixture::history(['lease_changes' => [2 => $change]]);
            $this->denied(fn () => $this->verify($p));
        }
    }

    public function test_utc_and_observer_monotonic_intervals_both_cover_without_gaps(): void
    {
        $p = CaptureProofFixture::history(['lease_changes' => [1 => ['expires_at' => '2026-01-01T00:00:10Z', 'expires_monotonic_ns' => '11000000000']]]);
        self::assertCount(3, $this->verify($p)['entries']);
        $p = CaptureProofFixture::history(['lease_changes' => [2 => ['issued_at' => '2026-01-01T00:00:00Z', 'not_before' => '2026-01-01T00:00:00Z', 'expires_at' => '2026-01-01T00:01:00Z', 'issued_monotonic_ns' => '1000000001', 'expires_monotonic_ns' => '61000000001']]]);
        self::assertCount(3, $this->verify($p)['entries']);
        foreach ([['expires_at' => '2026-01-01T00:00:09Z'], ['expires_monotonic_ns' => '10999999999']] as $change) {
            $p = CaptureProofFixture::history(['lease_changes' => [1 => $change]]);
            $this->denied(fn () => $this->verify($p));
        }
        foreach ([['issued_monotonic_ns' => '1000000000', 'expires_monotonic_ns' => '61000000000'], ['issued_at' => '2025-12-31T23:59:59Z', 'not_before' => '2025-12-31T23:59:59Z', 'expires_at' => '2026-01-01T00:00:59Z']] as $change) {
            $p = CaptureProofFixture::history(['lease_changes' => [2 => $change]]);
            $this->denied(fn () => $this->verify($p));
        }
        $p = CaptureProofFixture::history();
        $h = $this->verify($p);
        $this->denied(fn () => OpeningFenceHistoryVerifier::assertCoverage($h, '2025-12-31T23:59:59Z', '2026-01-01T00:00:20Z'));
        $this->denied(fn () => OpeningFenceHistoryVerifier::assertCoverage($h, '2026-01-01T00:00:01Z', '2026-01-01T00:01:21Z'));
        $this->denied(fn () => OpeningFenceHistoryVerifier::assertCoverage($h, '2026-01-01T00:00:01Z', '2026-01-01T00:00:20Z', '0', '21000000000'));
        $this->denied(fn () => OpeningFenceHistoryVerifier::assertCoverage($h, '2026-01-01T00:00:01Z', '2026-01-01T00:00:20Z', '2000000000', null));
    }

    public function test_ready_facts_cannot_change_or_return_to_capture(): void
    {
        $p = CaptureProofFixture::history();
        $pairs = $p['records']['leases'];
        $value = json_decode(file_get_contents($pairs[2]['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        foreach (['target_baseline_sql_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256', 'source_population_fingerprint'] as $field) {
            $v = [...$value, 'sequence' => 4, 'nonce' => 'fixture-ready-4', 'issued_at' => '2026-01-01T00:00:30Z', 'not_before' => '2026-01-01T00:00:30Z', 'expires_at' => '2026-01-01T00:01:30Z', 'issued_monotonic_ns' => '31000000000', 'expires_monotonic_ns' => '91000000000', 'previous_lease_sha256' => $pairs[2]['payload']['sha256'], $field => str_repeat('f', 64)];
            $pair = ($p['records']['sign'])('changed-' . $field, $v);
            $ref = $this->alternative($p, [...$pairs, $pair]);
            $this->denied(fn () => $this->verify($p, $ref));
        }
        $v = json_decode(file_get_contents($pairs[1]['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        $v = array_replace($v, ['sequence' => 4, 'nonce' => 'fixture-capture-4', 'issued_at' => '2026-01-01T00:00:30Z', 'not_before' => '2026-01-01T00:00:30Z', 'expires_at' => '2026-01-01T00:01:30Z', 'issued_monotonic_ns' => '31000000000', 'expires_monotonic_ns' => '91000000000', 'previous_lease_sha256' => $pairs[2]['payload']['sha256']]);
        $pair = ($p['records']['sign'])('late-capture', $v);
        $ref = $this->alternative($p, [...$pairs, $pair]);
        $this->denied(fn () => $this->verify($p, $ref));
        $p = CaptureProofFixture::history(['lease_changes' => [3 => ['capture_fence_sha256' => str_repeat('f', 64)]]]);
        $this->denied(fn () => $this->verify($p));
        $runtime = CaptureProofFixture::runtimeIdentity();
        $runtime['db_database'] = 'changed_database';
        $p = CaptureProofFixture::history(['lease_changes' => [2 => ['runtime_target_identity' => $runtime]]]);
        $this->denied(fn () => $this->verify($p));
    }

    public function test_prefix_requires_original_pair_references_and_bytes(): void
    {
        $p = CaptureProofFixture::history();
        $whole = $this->verify($p);
        $pairs = $p['records']['leases'];
        $copy = $p['records']['directory'] . '/copied-payload.json';
        copy($pairs[0]['payload']['path'], $copy);
        chmod($copy, 0600);
        $pairs[0]['payload']['path'] = $copy;
        $prefix = $this->verify($p, $this->alternative($p, array_slice($pairs, 0, 2)));
        $this->denied(fn () => OpeningFenceHistoryVerifier::assertPrefix($prefix, $whole));
        $other = CaptureProofFixture::history(['lease_changes' => [1 => ['nonce' => 'same-sequence-different-original']]]);
        $this->denied(fn () => OpeningFenceHistoryVerifier::assertPrefix($this->verify($other, $other['records']['capture_history_ref']), $whole));
    }

    public function test_all_ancestors_use_current_trust_and_revocation(): void
    {
        foreach (['revoked_keys' => ['fixture'], 'revoked_nonces' => ['fixture-capture-1']] as $field => $value) {
            $p = CaptureProofFixture::history();
            self::assertCount(3, $this->verify($p)['entries']);
            $trust = json_decode(file_get_contents($p['trust_path']), true, flags: JSON_THROW_ON_ERROR);
            $trust[$field] = $value;
            ProofFactory::write($p['trust_path'], json_encode($trust, JSON_THROW_ON_ERROR));
            $this->denied(fn () => $this->verify($p));
        }
        $p = CaptureProofFixture::history();
        $sig = $p['records']['leases'][0]['signature'];
        $v = json_decode(file_get_contents($sig['path']), true, flags: JSON_THROW_ON_ERROR);
        $v['signature_b64'] = base64_encode('invalid');
        ProofFactory::write($sig['path'], json_encode($v));
        $pairs = $p['records']['leases'];
        $pairs[0]['signature']['sha256'] = hash_file('sha256', $sig['path']);
        $ref = $this->alternative($p, $pairs);
        $this->denied(fn () => $this->verify($p, $ref));
    }

    public function test_history_count_bound(): void
    {
        $p = CaptureProofFixture::history();
        $pair = $p['records']['leases'][0];
        $pairs = [];
        for ($i = 0; $i < 10000; $i++) {
            $q = $pair;
            $q['payload']['path'] .= '-' . $i;
            $q['signature']['path'] .= '-' . $i;
            $pairs[] = $q;
        }
        $value = ['schema_version' => 1, 'purpose' => 'account-opening-lease-history', 'leases' => $pairs];
        ProofSchema::leaseHistory($value);
        $this->denied(fn () => ProofSchema::leaseHistory([...$value, 'leases' => [...$pairs, $pair]]));
        self::assertCount(3, $this->verify($p)['entries']);
    }

    public function test_history_traversal_uses_callers_remaining_graph_budget(): void
    {
        $p = CaptureProofFixture::history();
        $graph = new ControlProofGraph;
        for ($i = 0; $i < 8; $i++) {
            $path = $p['records']['directory'] . '/budget-' . $i;
            ProofFactory::write($path, str_repeat('a', 4194304));
            $graph->read(['path' => $path, 'sha256' => hash_file('sha256', $path)], 'history');
        }
        $this->denied(fn () => OpeningFenceHistoryVerifier::verify($p['history_ref'],$graph,$p['trust_path']));
        self::assertSame(['bytes' => 33554432, 'documents' => 8],$graph->usage());
    }
}
