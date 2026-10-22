<?php

namespace App\Services\Accounts\Legacy;

/** Evidence retention only. No financial status, movement or provider acknowledgement. */
final readonly class LegacyRetentionResult
{
    public function __construct(public string $effectKey, public string $observationKey, public int $observationId, public string $disposition, public bool $replay)
    {
        if (!in_array($disposition, ['pending_source_qualification', 'economic_conflict'], true) || $observationId <= 0) {
            throw new \DomainException('Invalid legacy retention result.');
        }
    }
}
