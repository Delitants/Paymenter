<?php

namespace App\Services\BillmanagerMigration\Opening;

use RuntimeException;

/** Static historical facts and preparation bridge, never live financial authority. */
final class CaptureLineageVerifier
{
    private const RUNTIME_FIELDS = ['machine_id_sha256', 'runtime_realpath', 'db_database', 'db_socket_realpath', 'db_server_uuid_or_fingerprint'];

    /** @return array{epoch:array,collector:array,acceptance:array,capture_history:array,preparation_history:array,first_ready:array} */
    public static function verify(OpeningBundle $bundle, ControlProofGraph $graph, string $trustPath): array
    {
        $data = $bundle->data();
        if (!$bundle->executable() || $data['schema_version'] !== 2) {
            throw new RuntimeException('Static capture lineage requires an executable v2 bundle.');
        }
        $read = static fn (string $name, string $kind = 'control') => $graph->read($bundle->referenceRecord($name), $kind);
        $trust = StrictProofJson::decode(PrivateProofFile::read($trustPath, 0)['bytes']);
        $epochFile = $read('control_epoch');
        $epoch = StrictProofJson::decode($epochFile['bytes']);
        ProofSchema::controlEpoch($epoch);
        $collectorFile = $read('collector_receipt');
        $collector = StrictProofJson::decode($collectorFile['bytes']);
        ProofSchema::collectorReceipt($collector);
        $acceptanceFile = $read('capture_acceptance');
        $acceptance = StrictProofJson::decode($acceptanceFile['bytes']);
        $acceptanceSignature = StrictProofJson::decode($read('capture_acceptance_signature')['bytes']);
        $acceptance = SignedAttestation::verifyBytes($acceptanceFile['bytes'], $acceptanceSignature, 'account-opening-capture-acceptance', $trust, ProofSchema::date($acceptance['issued_at'] ?? null));
        if ($data['control_epoch_sha256'] !== $epochFile['sha256'] || $data['capture_acceptance_sha256'] !== $acceptanceFile['sha256'] ||
            $acceptance['control_epoch_sha256'] !== $epochFile['sha256'] || $acceptance['collector_receipt_sha256'] !== $collectorFile['sha256'] || $collector['control_epoch_sha256'] !== $epochFile['sha256']) {
            throw new RuntimeException('Capture epoch, collector or acceptance original bytes changed.');
        }
        foreach (['freeze_id', 'source_capture_id', 'source_identity'] as $key) {
            if ($epoch[$key] !== $data[$key] || $collector[$key] !== $epoch[$key] || $acceptance[$key] !== $epoch[$key]) {
                throw new RuntimeException('Capture scope or source identity changed.');
            }
        }
        if ($acceptance['issuer'] !== $epoch['issuer'] || $collector['source_boot_id'] !== $epoch['source_boot_id'] ||
            CanonicalPolicy::bytes($acceptance['runtime_target_identity']) !== CanonicalPolicy::bytes($epoch['runtime_target_identity']) ||
            CanonicalPolicy::bytes($epoch['runtime_target_identity']) !== CanonicalPolicy::bytes(OpeningProcessContext::runtimeTargetIdentity())) {
            throw new RuntimeException('Source boot or authenticated capture runtime changed.');
        }
        $capture = OpeningFenceHistoryVerifier::verify($bundle->referenceRecord('capture_history'), $graph, $trustPath);
        $preparation = OpeningFenceHistoryVerifier::verify($bundle->referenceRecord('preparation_history'), $graph, $trustPath);
        OpeningFenceHistoryVerifier::assertPrefix($capture, $preparation);
        if ($capture['reference']['sha256'] !== $acceptance['capture_history_sha256'] || $preparation['reference']['sha256'] !== $data['preparation_history_sha256']) {
            throw new RuntimeException('Capture or preparation history checksum changed.');
        }
        $original = $capture['entries'][0];
        if ($bundle->referenceRecord('capture_fence') !== $original['payload_ref'] || $bundle->referenceRecord('capture_fence_signature') !== $original['signature_ref'] ||
            $data['capture_fence_sha256'] !== $original['payload_ref']['sha256'] || $acceptance['capture_fence_sha256'] !== $original['payload_ref']['sha256']) {
            throw new RuntimeException('Original capture pair changed.');
        }
        $acceptedAt = ProofSchema::date($acceptance['issued_at']);
        if ($acceptedAt < ProofSchema::date($collector['collection_completed_at'])) {
            throw new RuntimeException('Capture acceptance precedes completed collection.');
        }
        foreach ($capture['entries'] as $entry) {
            if (ProofSchema::date($entry['payload']['issued_at']) > $acceptedAt) {
                throw new RuntimeException('Capture acceptance contains a future lease generation.');
            }
        }
        foreach ($preparation['entries'] as $entry) {
            $lease = $entry['payload'];
            if ($lease['purpose'] !== 'account-opening-capture-fence') {
                throw new RuntimeException('Preparation bridge must contain capture-stage leases only.');
            }
            foreach (['issuer', 'freeze_id', 'source_capture_id', 'source_identity', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint'] as $key) {
                if ($lease[$key] !== $epoch[$key]) {
                    throw new RuntimeException('Preparation control epoch facts changed.');
                }
            }
            if ($lease['control_epoch_sha256'] !== $epochFile['sha256'] || CanonicalPolicy::bytes($lease['runtime_target_identity']) !== CanonicalPolicy::bytes($epoch['runtime_target_identity'])) {
                throw new RuntimeException('Preparation capture runtime or epoch changed.');
            }
        }
        OpeningFenceHistoryVerifier::assertCoverage($capture, $collector['collection_started_at'], $collector['collection_completed_at'], $collector['collection_started_monotonic_ns'], $collector['collection_completed_monotonic_ns']);
        $snapshot = $bundle->snapshot();
        $population = $bundle->referenceRecord('population_review');
        $facts = (new OpeningPreparation)->sourceFacts($bundle);
        if ($collector['snapshot_sha256'] !== $snapshot->checksum() || $acceptance['snapshot_sha256'] !== $snapshot->checksum() || $collector['snapshot_captured_at'] !== $snapshot->capturedAt() ||
            $acceptance['source_population_fingerprint'] !== $population['sha256'] || $facts['population_fingerprint'] !== $population['sha256'] || $facts['snapshot_sha256'] !== $snapshot->checksum() || $facts['import_id'] !== $data['import_id']) {
            throw new RuntimeException('Completed capture does not match the exact native import and population.');
        }
        foreach (['login_cutoff', 'activation_cutoff', 'source_timezone'] as $key) {
            if ($acceptance[$key] !== $data[$key]) {
                throw new RuntimeException('Capture cutoffs or source timezone changed.');
            }
        }
        $bundle->context();
        $readyFile = $read('freeze_receipt');
        $readySignature = StrictProofJson::decode($read('freeze_signature')['bytes']);
        $ready = StrictProofJson::decode($readyFile['bytes']);
        $ready = SignedAttestation::verifyBytes($readyFile['bytes'], $readySignature, 'account-opening-fence', $trust, ProofSchema::date($ready['issued_at'] ?? null));
        if ($ready['schema_version'] !== 2 || $readyFile['sha256'] !== $data['freeze_receipt_sha256']) {
            throw new RuntimeException('Original v2 ready proof is required.');
        }
        foreach (['issuer', 'freeze_id', 'source_capture_id', 'source_identity', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint'] as $key) {
            if ($ready[$key] !== $epoch[$key]) {
                throw new RuntimeException('Opening readiness changed the capture controls.');
            }
        }
        foreach (['source_capture_id', 'control_epoch_sha256', 'capture_fence_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256'] as $key) {
            if ($ready[$key] !== $data[$key]) {
                throw new RuntimeException('Opening readiness changed the approved lineage.');
            }
        }
        $lastCapture = $preparation['entries'][array_key_last($preparation['entries'])];
        $readyAt = ProofSchema::date($ready['issued_at']);
        if ($ready['sequence'] !== count($preparation['entries']) + 1 || $ready['previous_lease_sha256'] !== $lastCapture['payload_ref']['sha256'] ||
            $readyAt < $acceptedAt || $readyAt >= ProofSchema::date($acceptance['expires_at']) || $readyAt < ProofSchema::date($lastCapture['payload']['issued_at']) ||
            $readyAt > ProofSchema::date($lastCapture['payload']['expires_at']) ||
            ProofSchema::monotonic($ready['issued_monotonic_ns']) <= ProofSchema::monotonic($lastCapture['payload']['issued_monotonic_ns']) ||
            ProofSchema::monotonic($ready['issued_monotonic_ns']) > ProofSchema::monotonic($lastCapture['payload']['expires_monotonic_ns'])) {
            throw new RuntimeException('Opening readiness is outside its original capture acceptance or predecessor.');
        }
        OpeningFenceHistoryVerifier::assertCoverage($preparation, $collector['collection_started_at'], $ready['issued_at'], $collector['collection_started_monotonic_ns'], $ready['issued_monotonic_ns']);
        if (CanonicalPolicy::bytes($ready['target_identity']) !== CanonicalPolicy::bytes($bundle->target()) ||
            CanonicalPolicy::bytes(array_intersect_key($ready['target_identity'], array_flip(self::RUNTIME_FIELDS))) !== CanonicalPolicy::bytes($epoch['runtime_target_identity']) ||
            $ready['source_population_fingerprint'] !== $acceptance['source_population_fingerprint'] || $ready['target_baseline_sql_sha256'] !== $data['baseline_sql_sha256']) {
            throw new RuntimeException('Opening readiness changed the exact population, baseline or target.');
        }
        // Historical bridge checks never replace the existing current-time
        // release, cold-restore and full SQL gates at financial application.
        $releaseFile = $read('release');
        $release = StrictProofJson::decode($releaseFile['bytes']);
        $release = SignedAttestation::verifyBytes($releaseFile['bytes'], StrictProofJson::decode($read('release_signature')['bytes']), 'account-funding-release', $trust, ProofSchema::date($release['not_before'] ?? null));
        $backupFile = $read('backup_acceptance');
        $backup = StrictProofJson::decode($backupFile['bytes']);
        $backupAcceptedAt = ProofSchema::date($backup['accepted_at'] ?? null);
        $backupValidFrom = ProofSchema::date($backup['not_before'] ?? null);
        $backupRecordedAt = $backupAcceptedAt > $backupValidFrom ? $backupAcceptedAt : $backupValidFrom;
        $backup = SignedAttestation::verifyBytes($backupFile['bytes'], StrictProofJson::decode($read('backup_signature')['bytes']), 'opening-backup-acceptance', $trust, $backupRecordedAt);
        if ($readyAt < $backupRecordedAt || $readyAt < ProofSchema::date($release['not_before']) ||
            $releaseFile['sha256'] !== $data['release_sha256'] || $backup['release_sha256'] !== $data['release_sha256'] ||
            $backup['baseline_sql_sha256'] !== $data['baseline_sql_sha256'] || $backup['restored_sql_sha256'] !== $data['baseline_sql_sha256'] ||
            $bundle->referenceRecord('target_sql')['sha256'] !== $data['baseline_sql_sha256'] || $backup['dump_options'] !== OpeningBackupVerifier::OPTIONS ||
            CanonicalPolicy::bytes($backup['target_identity']) !== CanonicalPolicy::bytes($bundle->target()) || CanonicalPolicy::bytes($release['target_identity']) !== CanonicalPolicy::bytes($bundle->target()) ||
            $backup['configuration_sha256'] !== $release['configuration_sha256']) {
            throw new RuntimeException('Opening readiness precedes or substitutes completed backup/release acceptance.');
        }
        $bundle->reference('target_sql');

        return ['epoch' => $epoch, 'collector' => $collector, 'acceptance' => $acceptance, 'capture_history' => $capture, 'preparation_history' => $preparation,
            'first_ready' => ['payload' => $ready, 'signature' => $readySignature, 'payload_ref' => $bundle->referenceRecord('freeze_receipt'), 'signature_ref' => $bundle->referenceRecord('freeze_signature'), 'payload_bytes' => $readyFile['bytes']]];
    }
}
