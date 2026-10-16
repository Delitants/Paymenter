<?php

namespace App\Services\BillmanagerMigration\Opening;

use DateTimeImmutable;
use RuntimeException;

final class ProofSchema
{
    public static function keys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new RuntimeException('Incomplete or unknown proof fields.');
        }
    }

    public static function hash(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new RuntimeException('An exact SHA256 proof is required.');
        }

        return $value;
    }

    public static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new RuntimeException('An exact UTC timestamp is required.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('An exact UTC timestamp is required.');
        }

        return $date;
    }

    public static function attestation(array $value, string $purpose): void
    {
        $version = $value['schema_version'] ?? null;
        if ($version === 1 && in_array($purpose, ['account-opening-fence', 'account-funding-release', 'account-funding-runtime-acceptance', 'opening-backup-acceptance', 'billmanager-inactive-opening-approval'], true)) {
            self::legacyAttestation($value, $purpose);

            return;
        }
        $capture = ['issued_at', 'freeze_id', 'source_capture_id', 'sequence', 'status', 'source_identity', 'runtime_target_identity', 'control_epoch_sha256', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint', 'previous_lease_sha256', 'issued_monotonic_ns', 'expires_monotonic_ns'];
        $lineage = ['source_capture_id', 'control_epoch_sha256', 'capture_fence_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256'];
        $extra = match ([$purpose, $version]) {
            ['account-opening-capture-fence', 1] => $capture,
            ['account-opening-capture-acceptance', 1] => ['issued_at', 'freeze_id', 'source_capture_id', 'status', 'source_identity', 'runtime_target_identity', 'control_epoch_sha256', 'capture_fence_sha256', 'collector_receipt_sha256', 'capture_history_sha256', 'snapshot_sha256', 'source_population_fingerprint', 'login_cutoff', 'activation_cutoff', 'source_timezone'],
            ['account-opening-fence', 2] => ['issued_at', 'freeze_id', 'sequence', 'status', 'source_identity', 'source_population_fingerprint', 'target_identity', 'target_baseline_sql_sha256', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint', ...$lineage, 'previous_lease_sha256', 'issued_monotonic_ns', 'expires_monotonic_ns'],
            ['billmanager-inactive-opening-approval', 2] => ['bundle_sha256', 'release_sha256', 'target_identity', 'baseline_sql_sha256', 'freeze_id', 'freeze_receipt_sha256', 'login_cutoff', 'activation_cutoff', 'policy_sha256', 'scope_fingerprint', 'accounts', 'capabilities', 'status', 'human_decision_sha256', ...$lineage],
            default => throw new RuntimeException('Unsupported attestation purpose or version.'),
        };
        self::keys($value, ['schema_version', 'purpose', 'issuer', 'not_before', 'expires_at', 'nonce', ...$extra]);
        if ($value['purpose'] !== $purpose) {
            throw new RuntimeException('Attestation purpose conflicts.');
        }
        self::identities($value);
        self::bounded($value['nonce'], PHP_INT_MAX);
        $before = self::date($value['not_before']);
        $expires = self::date($value['expires_at']);
        if ($expires <= $before) {
            throw new RuntimeException('Positive attestation interval is required.');
        }
        self::hashFields($value);
        if (str_starts_with($purpose, 'account-opening-capture-')) {
            if (!is_array($value['runtime_target_identity'])) {
                throw new RuntimeException('Capture runtime identity is required.');
            }
            self::runtimeTargetIdentity($value['runtime_target_identity']);
        } else {
            if (!is_array($value['target_identity'])) {
                throw new RuntimeException('Opening operator identity is required.');
            }
            self::openingTargetIdentity($value['target_identity']);
        }
        if ($purpose === 'billmanager-inactive-opening-approval') {
            if ($value['status'] !== 'approved' || !is_array($value['accounts']) || !array_is_list($value['accounts']) || $value['capabilities'] !== ['inactive_opening']) {
                throw new RuntimeException('An inactive-only human approval is required.');
            }
            self::cutoffs($value);

            return;
        }
        if (self::date($value['issued_at']) != $before) {
            throw new RuntimeException('Issue time must equal the acceptance start.');
        }
        if ($purpose === 'account-opening-capture-acceptance') {
            if ($value['status'] !== 'captured' || $expires->getTimestamp() - $before->getTimestamp() > 21600) {
                throw new RuntimeException('Bounded completed capture acceptance is required.');
            }
            self::cutoffs($value);

            return;
        }
        if ($value['status'] !== 'fenced' || !is_int($value['sequence']) || $value['sequence'] < 1 || ($version === 2 && $value['sequence'] < 2) || $expires->getTimestamp() - $before->getTimestamp() > 60) {
            throw new RuntimeException('Bounded sequenced capture or ready fence is required.');
        }
        if ($purpose === 'account-opening-capture-fence' && $value['sequence'] === 1) {
            if ($value['previous_lease_sha256'] !== null) {
                throw new RuntimeException('Initial capture cannot have a predecessor.');
            }
        } else {
            self::hash($value['previous_lease_sha256']);
        }
        $issuedNs = self::monotonic($value['issued_monotonic_ns']);
        $expiresNs = self::monotonic($value['expires_monotonic_ns']);
        if ($expiresNs <= $issuedNs || $expiresNs - $issuedNs > 60000000000) {
            throw new RuntimeException('Bounded positive monotonic lease is required.');
        }
    }

    public static function monotonic(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) || strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0) || PHP_INT_SIZE < 8) {
            throw new RuntimeException('Canonical bounded monotonic nanoseconds are required.');
        }

        return (int) $value;
    }

    public static function runtimeTargetIdentity(array $value): void
    {
        self::keys($value, ['machine_id_sha256', 'runtime_realpath', 'db_database', 'db_socket_realpath', 'db_server_uuid_or_fingerprint']);
        self::hash($value['machine_id_sha256']);
        self::hash($value['db_server_uuid_or_fingerprint']);
        self::path($value['runtime_realpath']);
        self::path($value['db_socket_realpath']);
        self::bounded($value['db_database'], PHP_INT_MAX);
    }

    public static function openingTargetIdentity(array $value): void
    {
        self::keys($value, ['machine_id_sha256', 'runtime_realpath', 'db_database', 'db_socket_realpath', 'db_server_uuid_or_fingerprint', 'network_namespace_inode', 'operator_uid']);
        $runtime = $value;
        unset($runtime['network_namespace_inode'], $runtime['operator_uid']);
        self::runtimeTargetIdentity($runtime);
        if ($value['operator_uid'] !== 0 || !is_string($value['network_namespace_inode']) || !preg_match('/^net:\[[1-9][0-9]*\]$/D', $value['network_namespace_inode'])) {
            throw new RuntimeException('Exact root operator namespace is required.');
        }
    }

    public static function controlEpoch(array $value): void
    {
        self::keys($value, ['schema_version', 'purpose', 'issuer', 'freeze_id', 'source_capture_id', 'observer_identity', 'source_identity', 'source_boot_id', 'runtime_target_identity', 'control_profile_sha256', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint']);
        if ($value['schema_version'] !== 1 || $value['purpose'] !== 'account-opening-control-epoch' || !is_array($value['observer_identity']) || !is_array($value['runtime_target_identity'])) {
            throw new RuntimeException('Invalid capture control epoch.');
        }
        self::identities($value);
        self::hashFields($value);
        self::uuid($value['source_boot_id']);
        self::runtimeTargetIdentity($value['runtime_target_identity']);
        $observer = $value['observer_identity'];
        self::keys($observer, ['machine_id_sha256', 'boot_id', 'pid', 'start_ticks']);
        self::hash($observer['machine_id_sha256']);
        self::uuid($observer['boot_id']);
        if (!is_int($observer['pid']) || $observer['pid'] < 1 || !is_string($observer['start_ticks']) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $observer['start_ticks'])) {
            throw new RuntimeException('Exact observer process identity is required.');
        }
    }

    public static function collectorReceipt(array $value): void
    {
        self::keys($value, ['schema_version', 'purpose', 'freeze_id', 'source_capture_id', 'control_epoch_sha256', 'source_identity', 'source_boot_id', 'collector_identity_sha256', 'collection_started_at', 'collection_completed_at', 'collection_started_monotonic_ns', 'collection_completed_monotonic_ns', 'source_started_at', 'source_completed_at', 'source_started_monotonic_ns', 'source_completed_monotonic_ns', 'snapshot_captured_at', 'snapshot_sha256', 'source_clock_skew_upper_bound_ms']);
        if ($value['schema_version'] !== 1 || $value['purpose'] !== 'account-opening-collector-receipt') {
            throw new RuntimeException('Invalid collector receipt.');
        }
        self::identities($value);
        self::hashFields($value);
        self::uuid($value['source_boot_id']);
        $skew = $value['source_clock_skew_upper_bound_ms'];
        if (!is_int($skew) || $skew < 0 || $skew > 2000) {
            throw new RuntimeException('Conservatively bounded source clock skew is required.');
        }
        foreach (['collection', 'source'] as $host) {
            $start = self::date($value[$host . '_started_at']);
            $end = self::date($value[$host . '_completed_at']);
            $startNs = self::monotonic($value[$host . '_started_monotonic_ns']);
            $endNs = self::monotonic($value[$host . '_completed_monotonic_ns']);
            if ($end < $start || $endNs <= $startNs) {
                throw new RuntimeException('Positive own-clock collector brackets are required.');
            }
            $duration = $endNs - $startNs;
            $wholeDifference = intdiv($duration, 1000000000) - ($end->getTimestamp() - $start->getTimestamp());
            if ($wholeDifference < -2 || $wholeDifference > 2 || ($wholeDifference === 2 && $duration % 1000000000 !== 0)) {
                throw new RuntimeException('Collector wall and own monotonic duration disagree.');
            }
        }
        $captured = self::date($value['snapshot_captured_at']);
        if ($captured < self::date($value['source_started_at']) || $captured > self::date($value['source_completed_at'])) {
            throw new RuntimeException('Snapshot is outside the source-local collector bracket.');
        }
    }

    public static function leaseHistory(array $value): void
    {
        self::keys($value, ['schema_version', 'purpose', 'leases']);
        if ($value['schema_version'] !== 1 || $value['purpose'] !== 'account-opening-lease-history' || !is_array($value['leases']) || !array_is_list($value['leases']) || !$value['leases'] || count($value['leases']) > 10000) {
            throw new RuntimeException('A nonempty bounded original lease history is required.');
        }
        foreach ($value['leases'] as $pair) {
            if (!is_array($pair)) {
                throw new RuntimeException('Incomplete history pair.');
            }
            self::keys($pair, ['payload', 'signature']);
            foreach ($pair as $ref) {
                if (!is_array($ref)) {
                    throw new RuntimeException('Incomplete history reference.');
                }
                self::keys($ref, ['path', 'sha256']);
                self::path($ref['path']);
                self::hash($ref['sha256']);
            }
        }
    }

    private static function identities(array $value): void
    {
        foreach (['issuer', 'freeze_id', 'source_capture_id'] as $field) {
            if (array_key_exists($field, $value)) {
                self::bounded($value[$field], 128);
            }
        }
        if (array_key_exists('source_identity', $value)) {
            self::bounded($value['source_identity'], 45);
        }
    }

    private static function bounded(mixed $value, int $max): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max || str_contains($value, "\0")) {
            throw new RuntimeException('A nonempty bounded proof identity is required.');
        }
    }

    private static function path(mixed $value): void
    {
        if (!is_string($value) || $value === '' || $value[0] !== '/' || str_contains($value, '://') || str_contains($value, "\0") || str_contains($value, '/../') || str_contains($value, '/./')) {
            throw new RuntimeException('An absolute ordinary proof identity path is required.');
        }
    }

    private static function uuid(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value)) {
            throw new RuntimeException('A canonical proof boot UUID is required.');
        }
    }

    private static function hashFields(array $value): void
    {
        foreach ($value as $key => $item) {
            if ((str_ends_with($key, 'sha256') || str_ends_with($key, 'fingerprint')) && !($key === 'previous_lease_sha256' && $item === null)) {
                self::hash($item);
            }
        }
    }

    private static function cutoffs(array $value): void
    {
        foreach (['login_cutoff', 'activation_cutoff'] as $key) {
            $date = is_string($value[$key]) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value[$key]) : false;
            if (!$date || $date->format('Y-m-d H:i:s') !== $value[$key]) {
                throw new RuntimeException('Exact source-local proof cutoff is required.');
            }
        }
        if ($value['activation_cutoff'] < $value['login_cutoff']) {
            throw new RuntimeException('Activation cannot expand retained population.');
        }
        if (array_key_exists('source_timezone', $value)) {
            try {
                if (!is_string($value['source_timezone'])) {
                    throw new RuntimeException;
                }
                new \DateTimeZone($value['source_timezone']);
            } catch (\Throwable $e) {
                throw new RuntimeException('Valid source timezone is required.', previous: $e);
            }
        }
    }

    private static function legacyAttestation(array $value, string $purpose): void
    {
        $extra = match ($purpose) {
            'account-opening-fence' => ['issued_at', 'freeze_id', 'sequence', 'status', 'source_identity', 'source_population_fingerprint', 'target_identity', 'target_baseline_sql_sha256', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint'],
            'account-funding-release', 'account-funding-runtime-acceptance' => ['manifest_path', 'manifest_sha256', 'target_identity', 'configuration_sha256', 'decision_ref_sha256'],
            'opening-backup-acceptance' => ['baseline_sql_sha256', 'target_identity', 'configuration_sha256', 'release_sha256', 'restored_sql_sha256', 'accepted_at', 'dump_binary_sha256', 'dump_options'],
            'billmanager-inactive-opening-approval' => ['bundle_sha256', 'release_sha256', 'target_identity', 'baseline_sql_sha256', 'freeze_id', 'freeze_receipt_sha256', 'login_cutoff', 'activation_cutoff', 'policy_sha256', 'scope_fingerprint', 'accounts', 'capabilities', 'status', 'human_decision_sha256'],
            default => throw new RuntimeException('Unknown attestation purpose.'),
        };
        self::keys($value, ['schema_version', 'purpose', 'issuer', 'not_before', 'expires_at', 'nonce', ...$extra]);
        if ($value['schema_version'] !== 1 || $value['purpose'] !== $purpose || !is_string($value['issuer']) || $value['issuer'] === '' || !is_string($value['nonce']) || $value['nonce'] === '') {
            throw new RuntimeException('Invalid attestation identity.');
        }
        foreach ($extra as $key) {
            if (str_ends_with($key, 'sha256') || str_ends_with($key, 'fingerprint')) {
                self::hash($value[$key]);
            }
        }
        if (!is_array($value['target_identity']) || !$value['target_identity']) {
            throw new RuntimeException('Target identity is required.');
        }
        if ($purpose === 'account-opening-fence') {
            $issued = self::date($value['issued_at']);
            $expires = self::date($value['expires_at']);
            if ($value['status'] !== 'fenced' || !is_int($value['sequence']) || $value['sequence'] < 1 || !is_string($value['freeze_id']) || $value['freeze_id'] === '' ||
                $expires->getTimestamp() - $issued->getTimestamp() > 60 || $expires <= $issued || self::date($value['not_before'])->getTimestamp() !== $issued->getTimestamp()) {
                throw new RuntimeException('A current bounded writer fence is required.');
            }
        }
    }
}
