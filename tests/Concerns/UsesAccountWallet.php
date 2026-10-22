<?php

namespace Tests\Concerns;

use App\Models\AccountWallet;
use App\Models\User;
use App\Services\Accounts\OpeningAuthority;
use App\Services\Accounts\OpeningEvidence;
use App\Services\Accounts\WalletLedger;
use Tests\Fixtures\Accounts\SyntheticOpeningAuthority;

trait UsesAccountWallet
{
    private array $syntheticWalletEvidence = [];

    protected function wallet(User $owner, string $opening, string $limit, bool $active = true, string $currency = 'USD'): AccountWallet
    {
        $evidence = new OpeningEvidence('synthetic', 'wallet-fixture-' . $owner->id, $owner->id, $currency, $opening, $limit,
            str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), $active);
        $this->syntheticWalletEvidence[] = $evidence;
        $authority = new SyntheticOpeningAuthority($this->syntheticWalletEvidence);
        config(['account-funding.enabled' => true]);
        app()->instance(OpeningAuthority::class, $authority);

        return (new WalletLedger)->initialize($evidence, $authority);
    }
}
