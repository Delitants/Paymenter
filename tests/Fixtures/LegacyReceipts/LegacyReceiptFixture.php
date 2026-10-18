<?php

namespace Tests\Fixtures\LegacyReceipts;

use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;

final class LegacyReceiptFixture
{
    public static function payload(array $rootOverrides = []): string
    {
        return CanonicalPolicy::bytes([...[
            'schema_version' => 1, 'purpose' => 'legacy-payment-observation', 'issuer' => 'fictional-source',
            'not_before' => '2026-01-01T00:00:00Z', 'expires_at' => '2026-01-02T00:00:00Z', 'nonce' => 'delivery-one',
            'identity' => GoldenVectors::identity(), 'economic' => GoldenVectors::economic(),
            'observation' => ['custody_id' => 'custody-one', 'journal_id' => 'journal-one', 'raw_sha256' => str_repeat('b', 64),
                'validation_ref' => 'validation-one', 'validator_sha256' => str_repeat('c', 64), 'credential_version' => 'credential-one', 'commit_binding_ref' => 'unproven-commit-reference'],
        ], ...$rootOverrides]);
    }
}
