<?php

namespace App\Services\Accounts\Legacy;

use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Models\LegacyPostingResult;
use App\Services\Accounts\AccountAmount;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\OpeningProcessContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Immutable facts only. The ledger also requires the live posting transaction. */
final readonly class LegacyPostingReceipt
{
    private function __construct(
        public array $facts,
        public array $lineage,
        public EffectIdentity $identity,
        public EconomicFacts $economic,
        public string $sourceTrustHash,
        public array $evidenceKeys,
        public string $validationRef,
        public ?string $exclusionHash,
    ) {}

    public static function readLocked(
        LegacyEffect $effect,
        AccountOpeningBatch $batch,
        AccountOpeningReceipt $opening,
        array $decision,
        ?LegacyPostingResult $parent,
    ): self {
        $transport = LegacyOpeningExclusion::sourceTrust();
        [$retained, $original, $sourceKey] = LegacyOpeningExclusion::originalObservation($effect, $transport, true);
        $target = OpeningProcessContext::runtimeTargetIdentity();
        $targetFields = array_diff_key($target, ['runtime_realpath' => true]);
        $originalTarget = array_intersect_key($batch->target_identity, $targetFields);
        $economic = $original->economic->data();
        $archive = DB::table('billmanager_financial_records')->where('import_id', $batch->import_id)
            ->where('source_table', 'subaccounts')->where('source_account_id', $opening->source_account)
            ->where('currency_code', $opening->currency)->orderBy('id')->lockForUpdate()->limit(2)
            ->get(['source_id', 'source_account_id', 'user_id']);
        if ($archive->count() !== 1 || $archive->first()->source_id !== $economic['source_subaccount'] ||
            (string) $archive->first()->source_account_id !== $economic['source_account'] ||
            $archive->first()->user_id !== $opening->owner_id) {
            throw new RuntimeException('The original imported subaccount, currency and financial owner are required.');
        }
        if ($batch->state !== 'sealed' || $opening->batch_id !== $batch->id ||
            $decision['opening_receipt_sha256'] !== $opening->receipt_sha256 ||
            $decision['effect_key'] !== $effect->effect_key ||
            CanonicalPolicy::bytes($originalTarget) !== CanonicalPolicy::bytes($targetFields) ||
            in_array($sourceKey, $decision['approval_keys'], true) ||
            !preg_match('/^sha256:[a-f0-9]{64}$/D', $original->observation['commit_binding_ref'])) {
            throw new RuntimeException('Legacy posting requires the original committed source and target lineage.');
        }
        if ($decision['classification'] === 'included') {
            self::assertIncludedProvenance($decision['membership_provenance'], $retained, $original);
        }
        $delta = match ($decision['classification']) {
            'included' => '0.0000',
            'excluded' => $decision['net_delta'],
            default => throw new RuntimeException('An ambiguous legacy receipt cannot post.'),
        };
        if (AccountAmount::parse($delta)->exact() !== $delta ||
            ($decision['classification'] === 'excluded' && $delta !== $original->economic->delta())) {
            throw new RuntimeException('Legacy posting must conserve the original exact delta.');
        }

        return new self(
            facts: [
                'effect_id' => $effect->id, 'effect_key' => $effect->effect_key, 'economic_sha256' => $effect->economic_sha256,
                'observation_id' => $retained->id, 'observation_key' => $retained->observation_key,
                'opening_receipt_id' => $opening->id, 'opening_identity' => $opening->opening_identity,
                'wallet_id' => $opening->wallet_id, 'user_id' => $opening->owner_id, 'currency' => $opening->currency,
                'parent_effect_id' => $parent?->effect_id, 'parent_movement_id' => $parent?->movement_id,
                'disposition' => $decision['classification'] === 'included' ? 'included' : ($delta === '0.0000' ? 'applied_zero' : 'applied'),
                'net_delta' => $delta,
            ],
            lineage: [
                'bundle_sha256' => $batch->bundle_sha256, 'verification_sha256' => $batch->verification_sha256,
                'membership_sha256' => $decision['membership_sha256'], 'opening_receipt_sha256' => $opening->receipt_sha256,
            ],
            identity: $original->identity,
            economic: $original->economic,
            sourceTrustHash: $transport->sha256,
            evidenceKeys: [$sourceKey, ...$decision['approval_keys']],
            validationRef: $original->observation['validation_ref'],
            exclusionHash: $decision['exclusion_sha256'] ?? null,
        );
    }

    public static function assertIncludedProvenance(?array $entry, LegacyObservation $retained, AuthenticatedLegacyObservation $original): void
    {
        if (($entry['observation_key'] ?? null) !== $retained->observation_key ||
            ($entry['journal_id'] ?? null) !== $original->observation['journal_id'] ||
            'sha256:' . ($entry['source_commit_sha256'] ?? '') !== $original->observation['commit_binding_ref']) {
            throw new RuntimeException('An included legacy effect requires its accepted original journal provenance.');
        }
    }

    public function effectKey(): string
    {
        return $this->facts['effect_key'];
    }

    public function requestKey(): string
    {
        return 'legacy-effect:' . $this->effectKey();
    }

    public function sourceKey(): string
    {
        return self::movementSourceKey($this->effectKey());
    }

    public static function movementSourceKey(string $effectKey): string
    {
        return hash('sha256', "legacy-wallet-movement-v1\0" . $effectKey);
    }

    public function digest(): string
    {
        return hash('sha256', "legacy-wallet-receipt-v1\0" . CanonicalPolicy::bytes([
            ...$this->facts, ...$this->lineage, 'source_trust_sha256' => $this->sourceTrustHash,
        ]));
    }

    public function manual(): bool
    {
        return str_starts_with($this->identity->data()['effect_kind'], 'manual_');
    }

    public function assertCurrent(): void
    {
        LegacyPostingService::assertCurrent($this);
    }

    public function durableFacts(): array
    {
        return array_intersect_key($this->facts, array_flip(['effect_id', 'observation_id', 'opening_receipt_id', 'parent_effect_id',
            'disposition', 'net_delta', 'economic_sha256'])) + ['membership_sha256' => $this->lineage['membership_sha256'], 'receipt_sha256' => $this->digest()];
    }
}
