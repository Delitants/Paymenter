<?php

namespace App\Services\Accounts;

use App\Services\BillmanagerMigration\Opening\InactiveOpeningAuthority;
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
        $authority = app(OpeningAuthority::class);
        if ($authority instanceof InactiveOpeningAuthority) {
            throw new RuntimeException('Inactive opening authority cannot authorize normal funding.');
        }
        $authority->assertAcceptedRelease();
    }
}
