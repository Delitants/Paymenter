<?php

namespace Tests\Fixtures\Accounts;

use App\Services\Accounts\OpeningAuthority;
use App\Services\Accounts\OpeningEvidence;
use RuntimeException;

final readonly class SyntheticOpeningAuthority implements OpeningAuthority
{
    public function __construct(private array $approved = []) {}

    public function assertAcceptedRelease(): void
    {
        if (!app()->environment('testing')) {
            throw new RuntimeException('Synthetic authority is restricted to isolated tests.');
        }
    }

    public function assertApproved(OpeningEvidence $evidence): void
    {
        $this->assertAcceptedRelease();
        if (!in_array($evidence, $this->approved, true)) {
            throw new RuntimeException('Opening evidence is not an approved synthetic fixture.');
        }
    }
}
