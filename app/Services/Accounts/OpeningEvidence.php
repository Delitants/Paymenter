<?php

namespace App\Services\Accounts;

final readonly class OpeningEvidence
{
    public string $opening;

    public string $limit;

    public function __construct(
        public string $sourceSystem,
        public string $sourceAccount,
        public int $ownerId,
        public string $currency,
        mixed $opening,
        mixed $limit,
        public string $snapshotHash,
        public string $policyHash,
        public string $grantHash,
        public bool $active,
    ) {
        AccountAmount::parse($opening);
        AccountAmount::nonnegative($limit);
        $this->opening = $opening;
        $this->limit = $limit;
    }
}
