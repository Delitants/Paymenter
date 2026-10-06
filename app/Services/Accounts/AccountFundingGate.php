<?php

namespace App\Services\Accounts;

use RuntimeException;

final class AccountFundingGate
{
    public function assertEnabled(): void
    {
        if (config('account-funding.enabled', false) !== true) {
            throw new RuntimeException('Account funding is disabled.');
        }
        if (!app()->bound(OpeningAuthority::class)) {
            throw new RuntimeException('An accepted account funding release authority is required.');
        }
        app(OpeningAuthority::class)->assertAcceptedRelease();
    }
}
