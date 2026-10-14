<?php

namespace Tests\Fixtures\Opening;

/** Fictional structural records. These fixed dates never prove native chronology. */
final class CaptureProofFixture
{
    public static function runtimeIdentity(): array
    {
        return ['machine_id_sha256' => str_repeat('a', 64), 'runtime_realpath' => '/fixture/runtime', 'db_database' => 'fixture_database',
            'db_socket_realpath' => '/fixture/mysql.sock', 'db_server_uuid_or_fingerprint' => str_repeat('b', 64)];
    }

    public static function records(): array
    {
        $hash = str_repeat('c', 64);
        $common = ['schema_version' => 1, 'issuer' => 'fixture-custodian', 'not_before' => '2026-01-01T00:00:00Z', 'expires_at' => '2026-01-01T00:01:00Z', 'nonce' => 'fixture-nonce'];
        $captureFacts = ['freeze_id' => 'fixture-freeze', 'source_capture_id' => 'fixture-capture', 'source_identity' => 'source.example.invalid',
            'runtime_target_identity' => self::runtimeIdentity(), 'control_epoch_sha256' => $hash];
        $lineage = ['source_capture_id' => 'fixture-capture', 'control_epoch_sha256' => $hash, 'capture_fence_sha256' => $hash,
            'capture_acceptance_sha256' => $hash, 'preparation_history_sha256' => $hash];
        $target = [...self::runtimeIdentity(), 'network_namespace_inode' => 'net:[123]', 'operator_uid' => 0];
        $capture = [...$common, 'purpose' => 'account-opening-capture-fence', ...$captureFacts, 'issued_at' => $common['not_before'],
            'sequence' => 1, 'status' => 'fenced', 'controlled_writers_fingerprint' => $hash, 'incoming_payment_boundary_fingerprint' => $hash,
            'previous_lease_sha256' => null, 'issued_monotonic_ns' => '1000000000', 'expires_monotonic_ns' => '61000000000'];
        $ready = [...$common, 'schema_version' => 2, 'purpose' => 'account-opening-fence', 'issued_at' => $common['not_before'],
            'freeze_id' => 'fixture-freeze', 'sequence' => 2, 'status' => 'fenced', 'source_identity' => 'source.example.invalid',
            'source_population_fingerprint' => $hash, 'target_identity' => $target, 'target_baseline_sql_sha256' => $hash,
            'controlled_writers_fingerprint' => $hash, 'incoming_payment_boundary_fingerprint' => $hash, ...$lineage,
            'previous_lease_sha256' => $hash, 'issued_monotonic_ns' => '1100000000', 'expires_monotonic_ns' => '61100000000'];

        return [
            'epoch' => ['schema_version' => 1, 'purpose' => 'account-opening-control-epoch', 'issuer' => 'fixture-custodian',
                'freeze_id' => 'fixture-freeze', 'source_capture_id' => 'fixture-capture',
                'observer_identity' => ['machine_id_sha256' => $hash, 'boot_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'pid' => 1, 'start_ticks' => '0'],
                'source_identity' => 'source.example.invalid', 'source_boot_id' => '11111111-2222-3333-4444-555555555555',
                'runtime_target_identity' => self::runtimeIdentity(), 'control_profile_sha256' => $hash,
                'controlled_writers_fingerprint' => $hash, 'incoming_payment_boundary_fingerprint' => $hash],
            'collector' => ['schema_version' => 1, 'purpose' => 'account-opening-collector-receipt',
                'freeze_id' => 'fixture-freeze', 'source_capture_id' => 'fixture-capture', 'control_epoch_sha256' => $hash,
                'source_identity' => 'source.example.invalid', 'source_boot_id' => '11111111-2222-3333-4444-555555555555',
                'collector_identity_sha256' => $hash, 'collection_started_at' => '2026-01-01T00:00:01Z', 'collection_completed_at' => '2026-01-01T00:00:03Z',
                'collection_started_monotonic_ns' => '2000000000', 'collection_completed_monotonic_ns' => '4000000000',
                'source_started_at' => '2026-01-01T00:00:01Z', 'source_completed_at' => '2026-01-01T00:00:02Z',
                'source_started_monotonic_ns' => '900000000000', 'source_completed_monotonic_ns' => '901000000000',
                'snapshot_captured_at' => '2026-01-01T00:00:02Z', 'snapshot_sha256' => $hash, 'source_clock_skew_upper_bound_ms' => 2000],
            'capture' => $capture,
            'acceptance' => [...$common, 'purpose' => 'account-opening-capture-acceptance', ...$captureFacts, 'issued_at' => $common['not_before'],
                'status' => 'captured', 'capture_fence_sha256' => $hash, 'collector_receipt_sha256' => $hash, 'capture_history_sha256' => $hash,
                'snapshot_sha256' => $hash, 'source_population_fingerprint' => $hash, 'login_cutoff' => '2024-01-01 00:00:00',
                'activation_cutoff' => '2024-01-01 00:00:00', 'source_timezone' => 'UTC'],
            'ready' => $ready,
            'approval' => [...$common, 'schema_version' => 2, 'purpose' => 'billmanager-inactive-opening-approval', 'bundle_sha256' => $hash,
                'release_sha256' => $hash, 'target_identity' => $target, 'baseline_sql_sha256' => $hash, 'freeze_id' => 'fixture-freeze',
                'freeze_receipt_sha256' => $hash, 'login_cutoff' => '2024-01-01 00:00:00', 'activation_cutoff' => '2024-01-01 00:00:00',
                'policy_sha256' => $hash, 'scope_fingerprint' => $hash, 'accounts' => [], 'capabilities' => ['inactive_opening'],
                'status' => 'approved', 'human_decision_sha256' => $hash, ...$lineage],
        ];
    }
}
