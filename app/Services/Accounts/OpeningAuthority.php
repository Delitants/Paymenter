<?php

namespace App\Services\Accounts;

interface OpeningAuthority
{
    public function assertAcceptedRelease(): void;

    public function assertApproved(OpeningEvidence $evidence): void;
}
