<?php

namespace App\Services\Accounts\Legacy;

use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use DateTimeZone;
use DomainException;

/** Binds exact retention facts only; never an AccountWriteContext. */
final readonly class LegacyRetentionContext
{
    private function __construct(public AuthenticatedLegacyObservation $observation, public LegacyTransportTrust $trust, public string $createdAt, public array $configuration) {}

    public static function fromObservation(AuthenticatedLegacyObservation $observation, LegacyTransportTrust $trust): self
    {
        if ($observation->policySha256 !== $trust->sha256) {
            throw new DomainException('Legacy retention policy binding conflicts.');
        }
        $clock = app()->bound(LegacyClock::class) ? app(LegacyClock::class) : new SystemLegacyClock;

        return new self($observation, $trust, $clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), LegacyReceiptWriteGuard::configuration());
    }

    public function observationKey(): string
    {
        return hash('sha256', "legacy-observation-v1\0" . $this->observation->payloadSha256 . $this->observation->signatureSha256);
    }

    public function effectAttributes(): array
    {
        $auth = $this->observation;

        return ['effect_key' => $auth->identity->key(), 'economic_sha256' => $auth->economic->fingerprint(),
            'identity_json' => CanonicalPolicy::bytes($auth->identity->data()), 'economic_json' => CanonicalPolicy::bytes($auth->economic->data()), 'created_at' => $this->createdAt];
    }

    public function observationAttributes(int $effectId, string $disposition): array
    {
        $auth = $this->observation;

        return ['effect_id' => $effectId, 'observation_key' => $this->observationKey(), 'payload_bytes' => $auth->payloadBytes, 'signature_bytes' => $auth->signatureBytes,
            'payload_sha256' => $auth->payloadSha256, 'signature_sha256' => $auth->signatureSha256, 'economic_sha256' => $auth->economic->fingerprint(),
            'policy_sha256' => $auth->policySha256, 'disposition' => $disposition, 'created_at' => $this->createdAt];
    }
}
