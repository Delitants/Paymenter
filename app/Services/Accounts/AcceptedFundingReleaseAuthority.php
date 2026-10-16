<?php

namespace App\Services\Accounts;

use App\Services\BillmanagerMigration\Opening\ReleaseVerifier;
use App\Services\BillmanagerMigration\Opening\SignedAttestation;
use DateTimeImmutable;
use RuntimeException;

final readonly class AcceptedFundingReleaseAuthority implements OpeningAuthority
{
    public function __construct(private string $releasePath, private string $signaturePath, private string $trustPath) {}

    public function assertAcceptedRelease(): void
    {
        $gid = config('account-funding.runtime_reader_gid');
        if (!is_int($gid) || $gid < 0) {
            throw new RuntimeException('An explicit runtime acceptance reader group is required.');
        }
        $release = SignedAttestation::verifyRuntime($this->releasePath, $this->signaturePath, $this->trustPath, new DateTimeImmutable('now'), $gid);
        ReleaseVerifier::assertRuntimeCurrent($release, $gid);
    }

    public function assertApproved(OpeningEvidence $evidence): void
    {
        throw new RuntimeException('Standing runtime acceptance cannot authorize a wallet opening.');
    }
}
