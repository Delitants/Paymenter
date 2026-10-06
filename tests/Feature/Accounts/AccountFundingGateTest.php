<?php

namespace Tests\Feature\Accounts;

use App\Services\Accounts\AccountFundingGate;
use App\Services\Accounts\OpeningAuthority;
use App\Services\Accounts\OpeningEvidence;
use RuntimeException;
use Tests\Fixtures\Accounts\SyntheticOpeningAuthority;
use Tests\TestCase;

class AccountFundingGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(AccountFundingGate::class), 'Account funding activation contract is missing');
    }

    public function test_default_disabled_gate_refuses_funding(): void
    {
        self::assertFalse(config('account-funding.enabled'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disabled');
        (new AccountFundingGate)->assertEnabled();
    }

    public function test_true_flag_without_accepted_release_authority_stays_blocked(): void
    {
        config(['account-funding.enabled' => true]);
        self::assertFalse($this->app->bound(OpeningAuthority::class));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('authority');
        (new AccountFundingGate)->assertEnabled();
    }

    public function test_test_authority_can_attest_only_explicit_synthetic_evidence(): void
    {
        $evidence = new OpeningEvidence('synthetic', 'fixture-account', 1, 'USD', '-40.0050', '100.00', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), true);
        $authority = new SyntheticOpeningAuthority([$evidence]);
        $this->app->instance(OpeningAuthority::class, $authority);
        config(['account-funding.enabled' => true]);
        (new AccountFundingGate)->assertEnabled();
        $authority->assertApproved($evidence);
        $this->expectException(RuntimeException::class);
        $authority->assertApproved(new OpeningEvidence('synthetic', 'other-account', 2, 'USD', '0.00', '100.00', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), true));
    }

    public function test_test_authority_cannot_authorize_production(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->expectException(RuntimeException::class);
            (new SyntheticOpeningAuthority)->assertAcceptedRelease();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
