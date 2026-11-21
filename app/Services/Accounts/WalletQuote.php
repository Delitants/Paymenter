<?php

namespace App\Services\Accounts;

final readonly class WalletQuote
{
    public function __construct(
        public string $balance,
        public string $debt,
        public string $borrowingLimit,
        public string $remainingAllowance,
        public string $excessDebt,
        public string $reservedPrincipal,
        public string $residual,
        public string $cashAvailable,
        public string $fundingAvailable,
        public bool $blocked,
    ) {}
}
