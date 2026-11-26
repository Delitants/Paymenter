<?php

namespace App\Services\Accounts\Legacy;

use App\Services\Accounts\AcceptedFundingReleaseAuthority;
use App\Services\Accounts\OpeningAuthority;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use RuntimeException;

final class LegacyPostingAuthority
{
    public function __construct(private readonly LegacyClock $clock) {}

    /** @return array{sha256:?string,parent_cap:?array} */
    public function approve(LegacyPostingReceipt $receipt): array
    {
        if ($receipt->facts['disposition'] === 'included' && !$receipt->manual()) {
            return ['sha256' => null, 'parent_cap' => null];
        }
        $config = config('legacy-payments.posting');
        if (($config['enabled'] ?? false) !== true || config('account-funding.enabled') !== true || !app()->bound(OpeningAuthority::class)) {
            throw new RuntimeException('Legacy wallet posting is disabled or lacks runtime authority.');
        }
        $runtime = app(OpeningAuthority::class);
        if (!$runtime instanceof AcceptedFundingReleaseAuthority) {
            throw new RuntimeException('A native accepted funding runtime is required for legacy posting.');
        }
        $files = LegacyProof::forEffect($receipt->effectKey(), $config);
        if ($files === []) {
            throw new RuntimeException('A dedicated legacy posting grant is required.');
        }
        [$grant, $signer] = LegacyProof::verify($files, 'legacy-account-posting', $this->clock->now());
        $expected = [
            ...$receipt->lineage, ...array_intersect_key($receipt->identity->data(), array_flip(['source_instance', 'merchant_binding', 'effect_kind'])),
            'effect_key' => $receipt->effectKey(), 'economic_sha256' => $receipt->facts['economic_sha256'],
            'observation_key' => $receipt->facts['observation_key'], 'net_delta' => $receipt->facts['net_delta'],
            'runtime_acceptance_sha256' => hash('sha256', CanonicalPolicy::bytes($runtime->verifiedRuntime(true))),
            'exclusion_sha256' => $receipt->exclusionHash,
        ];
        if (in_array($signer, $receipt->evidenceKeys, true) ||
            CanonicalPolicy::bytes(array_intersect_key($grant, $expected)) !== CanonicalPolicy::bytes($expected)) {
            throw new RuntimeException('Legacy posting grant does not match current runtime and original effect.');
        }
        $this->assertManual($receipt, $grant['manual']);
        if ($grant['parent_cap'] !== null && !is_array($grant['parent_cap'])) {
            throw new RuntimeException('An exact accepted legacy parent capacity is required.');
        }
        if (LegacyProof::forEffect($receipt->effectKey(), config('legacy-payments.posting')) !== $files ||
            $this->clock->now() >= ProofSchema::date($grant['expires_at']) ||
            config('legacy-payments.posting.enabled') !== true) {
            throw new RuntimeException('Legacy posting authority changed during verification.');
        }

        return ['sha256' => $files['acceptance_path']['sha256'], 'parent_cap' => $grant['parent_cap']];
    }

    private function assertManual(LegacyPostingReceipt $receipt, mixed $manual): void
    {
        if (!$receipt->manual()) {
            if ($manual !== null) {
                throw new RuntimeException('Provider effects cannot use manual authorization.');
            }

            return;
        }
        if (!is_array($manual)) {
            throw new RuntimeException('The original authorized manual operation is required.');
        }
        ProofSchema::keys($manual, ['operation_id', 'actor_reference', 'reason_sha256', 'authorization_sha256']);
        EffectIdentity::id($manual['actor_reference']);
        ProofSchema::hash($manual['reason_sha256']);
        ProofSchema::hash($manual['authorization_sha256']);
        if ($manual['operation_id'] !== $receipt->identity->data()['economic_effect_id'] ||
            $receipt->validationRef !== 'sha256:' . hash('sha256', CanonicalPolicy::bytes($manual))) {
            throw new RuntimeException('Manual authorization does not match the original operation and actor evidence.');
        }
    }
}
