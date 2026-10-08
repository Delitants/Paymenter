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
