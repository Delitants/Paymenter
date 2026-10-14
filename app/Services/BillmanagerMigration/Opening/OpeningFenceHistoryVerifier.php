<?php

namespace App\Services\BillmanagerMigration\Opening;

use RuntimeException;

/** Validates historical original bytes. Only live authority can permit a commit. */
final class OpeningFenceHistoryVerifier
{
    private const RUNTIME_FIELDS = ['machine_id_sha256', 'runtime_realpath', 'db_database', 'db_socket_realpath', 'db_server_uuid_or_fingerprint'];

    /** @return array{reference:array{path:string,sha256:string},entries:list<array{payload:array,signature:array,payload_ref:array,signature_ref:array,payload_bytes:string}>} */
    public static function verify(array $historyRef, ControlProofGraph $graph, string $trustPath): array
    {
        $index = StrictProofJson::decode($graph->read($historyRef, 'history')['bytes']);
        ProofSchema::leaseHistory($index);
        $trust = StrictProofJson::decode(PrivateProofFile::read($trustPath, 0)['bytes']);
        $entries = [];
        $seen = [];
        $ready = null;
        foreach ($index['leases'] as $pair) {
            $file = $graph->read($pair['payload'], 'control', [$historyRef['path']]);
            $signature = StrictProofJson::decode($graph->read($pair['signature'], 'control', [$historyRef['path']])['bytes']);
            $value = StrictProofJson::decode($file['bytes']);
            $capture = ($value['purpose'] ?? null) === 'account-opening-capture-fence' && ($value['schema_version'] ?? null) === 1;
            $opening = ($value['purpose'] ?? null) === 'account-opening-fence' && ($value['schema_version'] ?? null) === 2;
            if (!$capture && !$opening) {
                throw new RuntimeException('History contains a non-custody purpose or version.');
            }
            $value = SignedAttestation::verifyBytes($file['bytes'], $signature, $value['purpose'], $trust, ProofSchema::date($value['issued_at'] ?? null));
            if (isset($seen[$file['sha256']]) || $value['sequence'] !== count($entries) + 1) {
                throw new RuntimeException('Duplicate, missing or reordered custody lease.');
            }
            if (!$entries) {
                if (!$capture || $value['previous_lease_sha256'] !== null) {
                    throw new RuntimeException('History must start with original capture sequence one.');
                }
            } else {
                $previous = $entries[array_key_last($entries)];
                $prior = $previous['payload'];
                if ($value['previous_lease_sha256'] !== $previous['payload_ref']['sha256']) {
                    throw new RuntimeException('Custody predecessor bytes changed.');
                }
                foreach (['issuer', 'freeze_id', 'source_capture_id', 'source_identity', 'control_epoch_sha256', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint'] as $key) {
                    if ($value[$key] !== $prior[$key]) {
                        throw new RuntimeException('Custody epoch or controlled facts changed.');
                    }
                }
                $runtime = $capture ? $value['runtime_target_identity'] : array_intersect_key($value['target_identity'], array_flip(self::RUNTIME_FIELDS));
                $priorRuntime = isset($prior['runtime_target_identity']) ? $prior['runtime_target_identity'] : array_intersect_key($prior['target_identity'], array_flip(self::RUNTIME_FIELDS));
                if (CanonicalPolicy::bytes($runtime) !== CanonicalPolicy::bytes($priorRuntime)) {
                    throw new RuntimeException('Custody runtime target changed.');
                }
                $issued = ProofSchema::date($value['issued_at']);
                $priorIssued = ProofSchema::date($prior['issued_at']);
                $issuedNs = ProofSchema::monotonic($value['issued_monotonic_ns']);
                if ($issued < $priorIssued || $issuedNs <= ProofSchema::monotonic($prior['issued_monotonic_ns']) ||
                    $issued > ProofSchema::date($prior['expires_at']) || $issuedNs > ProofSchema::monotonic($prior['expires_monotonic_ns'])) {
                    throw new RuntimeException('Custody clock regressed or history has an uncovered interval.');
                }
            }
            if ($capture && $ready !== null) {
                throw new RuntimeException('Capture cannot follow opening readiness.');
            }
            if ($opening) {
                if ($value['capture_fence_sha256'] !== $entries[0]['payload_ref']['sha256']) {
                    throw new RuntimeException('Ready lease does not identify the original capture.');
                }
                if ($ready !== null) {
                    foreach (['source_population_fingerprint', 'target_identity', 'target_baseline_sql_sha256', 'capture_fence_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256'] as $key) {
                        if (CanonicalPolicy::bytes([$value[$key]]) !== CanonicalPolicy::bytes([$ready[$key]])) {
                            throw new RuntimeException('Ready renewal changed the original approved facts.');
                        }
                    }
                } else {
                    $ready = $value;
                }
            }
            $seen[$file['sha256']] = true;
            $entries[] = ['payload' => $value, 'signature' => $signature, 'payload_ref' => $pair['payload'], 'signature_ref' => $pair['signature'], 'payload_bytes' => $file['bytes']];
        }

        return ['reference' => $historyRef, 'entries' => $entries];
    }

    public static function assertPrefix(array $prefix, array $extension): void
    {
        if (!$prefix['entries'] || count($prefix['entries']) > count($extension['entries'])) {
            throw new RuntimeException('Original custody history is not a complete prefix.');
        }
        foreach ($prefix['entries'] as $index => $entry) {
            $other = $extension['entries'][$index];
            foreach (['payload_ref', 'signature_ref', 'payload_bytes', 'signature'] as $key) {
                if ($entry[$key] !== $other[$key]) {
                    throw new RuntimeException('Original custody history prefix changed.');
                }
            }
        }
    }

    public static function assertCoverage(array $history, string $startedAt, string $completedAt, ?string $startedMonotonicNs = null, ?string $completedMonotonicNs = null): void
    {
        $start = ProofSchema::date($startedAt);
        $end = ProofSchema::date($completedAt);
        if (!$history['entries'] || $end < $start || (($startedMonotonicNs === null) !== ($completedMonotonicNs === null))) {
            throw new RuntimeException('Complete custody interval is required.');
        }
        $first = $history['entries'][0]['payload'];
        $latestEnd = ProofSchema::date($first['expires_at']);
        $latestNs = ProofSchema::monotonic($first['expires_monotonic_ns']);
        foreach ($history['entries'] as $entry) {
            $expiry = ProofSchema::date($entry['payload']['expires_at']);
            if ($expiry > $latestEnd) {
                $latestEnd = $expiry;
            }
            $latestNs = max($latestNs, ProofSchema::monotonic($entry['payload']['expires_monotonic_ns']));
        }
        if ($start < ProofSchema::date($first['issued_at']) || $end > $latestEnd) {
            throw new RuntimeException('Custody does not cover the complete UTC interval.');
        }
        if ($startedMonotonicNs !== null) {
            $startNs = ProofSchema::monotonic($startedMonotonicNs);
            $endNs = ProofSchema::monotonic($completedMonotonicNs);
            if ($endNs < $startNs || $startNs < ProofSchema::monotonic($first['issued_monotonic_ns']) || $endNs > $latestNs) {
                throw new RuntimeException('Custody does not cover the complete observer-clock interval.');
            }
        }
    }
}
