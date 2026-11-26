<?php

namespace App\Console\Commands;

use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Models\LegacyPostingResult;
use App\Services\Accounts\Legacy\AuthenticatedLegacyObservation;
use App\Services\Accounts\Legacy\LegacyEnvelope;
use App\Services\Accounts\Legacy\LegacyReceiptInbox;
use App\Services\Accounts\Legacy\LegacyReceiptWriteGuard;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ReceiveBillmanagerLegacyPayment extends Command
{
    private const DOCUMENT_LIMIT = 65536;

    private const HEADER = 'PAYMENTER-LEGACY-V1';

    private const STATUS_HEADER = 'PAYMENTER-LEGACY-STATUS-V1';

    protected $signature = 'billmanager:legacy-payment:receive';

    protected $description = 'Retain or check one signed BILLmanager receipt from standard input';

    public function handle(LegacyReceiptInbox $inbox): int
    {
        try {
            [$payload, $signature, $statusOnly] = $this->readFrame();
            $envelope = LegacyEnvelope::parse($payload);
            $observationKey = hash('sha256', "legacy-observation-v1\0" . hash('sha256', $payload) . hash('sha256', $signature));
            $configuration = LegacyReceiptWriteGuard::configuration();
            if (!$statusOnly) {
                $inbox->retain($payload, $signature);
            }
            $observation = LegacyObservation::query()->useWritePdo()->where('observation_key', $observationKey)->sole();
            if ($observation->payload_bytes !== $payload || $observation->signature_bytes !== $signature) {
                throw new RuntimeException('The retained observation differs from its delivery.');
            }
            $ack = $this->acknowledge($envelope, $observation);
            if ($statusOnly) {
                // The existing receipt supplies the admission time. Current
                // trust, including revocation, still controls disclosure.
                $admitted = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $observation->created_at, new DateTimeZone('UTC'));
                $trust = LegacyReceiptWriteGuard::configuredTrust();
                if (!$admitted || $admitted->format('Y-m-d H:i:s') !== $observation->created_at ||
                    $observation->policy_sha256 !== $trust->sha256 || LegacyReceiptWriteGuard::configuration() !== $configuration) {
                    throw new RuntimeException('The original admission is no longer verifiable.');
                }
                AuthenticatedLegacyObservation::verify($payload, $signature, $trust, $admitted);
            }
            $this->output->writeln(CanonicalPolicy::bytes($ack), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (Throwable) {
            // Error details can contain original observations or SQL values.
            $this->output->writeln('{"accepted":false}', OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
    }

    private function acknowledge(LegacyEnvelope $envelope, LegacyObservation $observation): array
    {
        $effect = LegacyEffect::query()->useWritePdo()->whereKey($observation->effect_id)->sole();
        $economicHash = $envelope->economic->fingerprint();
        $conflict = $effect->economic_sha256 !== $economicHash;
        if ($effect->effect_key !== $envelope->identity->key() || $observation->economic_sha256 !== $economicHash ||
            $observation->payload_sha256 !== hash('sha256', $observation->payload_bytes) ||
            $observation->signature_sha256 !== hash('sha256', $observation->signature_bytes) ||
            $observation->disposition !== ($conflict ? 'economic_conflict' : 'pending_source_qualification')) {
            throw new RuntimeException('The retained observation conflicts with its effect.');
        }
        $ack = [
            'disposition' => $conflict ? 'quarantined' : 'stored_pending',
            'economic_sha256' => $economicHash,
            'effect_key' => $effect->effect_key,
            'movement_id' => null,
            'observation_key' => $observation->observation_key,
            'payload_sha256' => $observation->payload_sha256,
            'receipt_sha256' => null,
            'result_id' => null,
            'result_observation_key' => null,
            'signature_sha256' => $observation->signature_sha256,
        ];
        if ($conflict) {
            return $ack;
        }
        $posted = LegacyPostingResult::query()->useWritePdo()->where('effect_id', $effect->id)->first();
        if ($posted === null) {
            return $ack;
        }
        $original = LegacyObservation::query()->useWritePdo()->whereKey($posted->observation_id)->sole();
        if ($posted->economic_sha256 !== $economicHash || $original->economic_sha256 !== $economicHash ||
            $original->effect_id !== $effect->id || $original->disposition !== 'pending_source_qualification' ||
            !in_array($posted->disposition, ['included', 'applied', 'applied_zero'], true) ||
            ($posted->disposition === 'applied') !== ($posted->movement_id !== null)) {
            throw new RuntimeException('The committed result differs from its original observation.');
        }
        ProofSchema::hash($original->observation_key);
        ProofSchema::hash($posted->receipt_sha256);

        return array_replace($ack, [
            'disposition' => $posted->disposition,
            'movement_id' => $posted->movement_id,
            'receipt_sha256' => $posted->receipt_sha256,
            'result_id' => $posted->id,
            'result_observation_key' => $original->observation_key,
        ]);
    }

    private function readFrame(): array
    {
        $limit = 2 * self::DOCUMENT_LIMIT + strlen(self::STATUS_HEADER) + 13;
        $wire = stream_get_contents(STDIN, $limit + 1);
        if ($wire === false || strlen($wire) > $limit) {
            throw new RuntimeException('Legacy delivery exceeded its frame limit.');
        }
        $frame = explode("\n", $wire, 4);
        if (count($frame) !== 4 || !in_array($frame[0], [self::HEADER, self::STATUS_HEADER], true) ||
            !preg_match('/^[1-9][0-9]{0,4}$/D', $frame[1]) || !preg_match('/^[1-9][0-9]{0,4}$/D', $frame[2])) {
            throw new RuntimeException('Invalid legacy delivery frame.');
        }
        $payloadSize = (int) $frame[1];
        $signatureSize = (int) $frame[2];
        if ($payloadSize > self::DOCUMENT_LIMIT || $signatureSize > self::DOCUMENT_LIMIT || strlen($frame[3]) !== $payloadSize + $signatureSize) {
            throw new RuntimeException('Invalid legacy delivery lengths.');
        }

        return [substr($frame[3], 0, $payloadSize), substr($frame[3], $payloadSize), $frame[0] === self::STATUS_HEADER];
    }
}
