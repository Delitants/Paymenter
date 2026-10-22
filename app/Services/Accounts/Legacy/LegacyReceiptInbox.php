<?php

namespace App\Services\Accounts\Legacy;

use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Models\LegacyReceiptBudget;
use App\Models\LegacyReceiptConflict;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Disabled-by-default quarantine. Never calls a ledger or provider. */
final class LegacyReceiptInbox
{
    public function __construct(private readonly LegacyClock $clock) {}

    public function retain(string $payloadBytes, string $signatureBytes): LegacyRetentionResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('Legacy retention requires its own durable transaction.');
        }
        $trust = LegacyReceiptWriteGuard::configuredTrust();
        $auth = $this->authenticate($payloadBytes, $signatureBytes, $trust);
        $context = LegacyRetentionContext::fromObservation($auth, $trust);

        // One attempt: uniqueness/deadlocks are not implicit retries. A caller
        // may explicitly retry the original bytes after a database failure.
        return DB::transaction(function () use ($context) {
            $result = LegacyReceiptWriteGuard::during($context, fn () => $this->retainLocked($context));
            // Recheck after the inner guard savepoint, immediately before this
            // service's outer commit, including for a zero-cost exact replay.
            if (LegacyReceiptWriteGuard::configuration() !== $context->configuration) {
                throw new DomainException('Legacy receipt configuration changed.');
            }
            $current = LegacyReceiptWriteGuard::configuredTrust();
            if ($current->sha256 !== $context->observation->policySha256) {
                throw new DomainException('Legacy receipt trust changed.');
            }
            $this->authenticate($context->observation->payloadBytes, $context->observation->signatureBytes, $current);

            return $result;
        }, 1);
    }

    private function authenticate(string $payload, string $signature, LegacyTransportTrust $trust): AuthenticatedLegacyObservation
    {
        try {
            return AuthenticatedLegacyObservation::verify($payload, $signature, $trust, $this->clock->now());
        } catch (\RuntimeException $exception) {
            throw new DomainException('Legacy receipt authentication denied.', previous: $exception);
        }
    }

    private function retainLocked(LegacyRetentionContext $context): LegacyRetentionResult
    {
        $auth = $context->observation;
        // Global lock order: fixed budget, effect, observation, conflict.
        // An absent row is NOT a mutex: physical unique indexes settle first
        // deliveries; a losing transaction propagates rather than guessing.
        $budget = LegacyReceiptBudget::where('store_key', LegacyReceiptWriteGuard::STORE_KEY)->lockForUpdate()->first();
        $effect = LegacyEffect::where('effect_key', $auth->identity->key())->lockForUpdate()->first();
        $observation = LegacyObservation::where('observation_key', $context->observationKey())->lockForUpdate()->first();
        if ($budget === null && (LegacyEffect::exists() || LegacyObservation::exists() || LegacyReceiptConflict::exists())) {
            throw new DomainException('Retained legacy evidence has no usage budget.');
        }
        if ($observation !== null) {
            if ($effect === null || $budget === null || (int) $observation->effect_id !== (int) $effect->id || $observation->payload_bytes !== $auth->payloadBytes || $observation->signature_bytes !== $auth->signatureBytes) {
                throw new DomainException('Legacy replay binding conflicts.');
            }
            LegacyReceiptConflict::where('observation_id', $observation->id)->lockForUpdate()->first();

            return new LegacyRetentionResult($effect->effect_key, $observation->observation_key, (int) $observation->id, $observation->disposition, true);
        }
        $charge = strlen($auth->payloadBytes) + strlen($auth->signatureBytes);
        if ($effect === null) {
            $attributes = $context->effectAttributes();
            $charge += strlen($attributes['identity_json']) + strlen($attributes['economic_json']);
        }
        $bytes = $budget ? $budget->retained_bytes : 0;
        $count = $budget ? $budget->retained_observations : 0;
        if ($bytes < 0 || $count < 0 || $bytes > PHP_INT_MAX - $charge || $count === PHP_INT_MAX ||
            $bytes + $charge > $context->trust->data['retained_byte_limit'] || $count + 1 > $context->trust->data['retained_observation_limit']) {
            throw new DomainException('Legacy receipt retention capacity exhausted.');
        }
        if ($budget === null) {
            $budget = LegacyReceiptBudget::create(['store_key' => LegacyReceiptWriteGuard::STORE_KEY, 'retained_bytes' => 0, 'retained_observations' => 0]);
        }
        if ($effect === null) {
            $effect = LegacyEffect::create($context->effectAttributes());
        }
        $conflict = $effect->economic_sha256 !== $auth->economic->fingerprint();
        $disposition = $conflict ? 'economic_conflict' : 'pending_source_qualification';
        $observation = LegacyObservation::create($context->observationAttributes((int) $effect->id, $disposition));
        if ($conflict) {
            LegacyReceiptConflict::create(['effect_id' => (int) $effect->id, 'observation_id' => (int) $observation->id,
                'expected_economic_sha256' => $effect->economic_sha256, 'observed_economic_sha256' => $auth->economic->fingerprint(),
                'reason' => 'economic_facts_changed', 'created_at' => $context->createdAt]);
        }
        $budget->retained_bytes = $bytes + $charge;
        $budget->retained_observations = $count + 1;
        $budget->save();

        return new LegacyRetentionResult($effect->effect_key, $observation->observation_key, (int) $observation->id, $disposition, false);
    }
}
