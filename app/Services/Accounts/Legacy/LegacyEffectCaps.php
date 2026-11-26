<?php

namespace App\Services\Accounts\Legacy;

use App\Models\AccountMovement;
use App\Models\LegacyEffect;
use App\Models\LegacyPostingResult;
use App\Models\LegacyReceiptConflict;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use App\Services\BillmanagerMigration\Opening\StrictProofJson;
use Brick\Math\BigDecimal;
use RuntimeException;

final class LegacyEffectCaps
{
    /** Lock effects before reading any observation or conflict ranges. */
    public static function lockChildren(LegacyEffect $current, ?LegacyEffect $parent, ?LegacyPostingResult $prior, array $preview): array
    {
        $posted = $unrecorded = [];
        if ($parent === null || $prior === null) {
            return [$posted, $unrecorded];
        }
        $members = $prior->disposition === 'included' && $preview['classification'] === 'excluded' ? $preview['opening_members'] : [];
        unset($members[$parent->effect_key]);
        $results = LegacyPostingResult::where('parent_effect_id', $parent->id)->where('effect_id', '<>', $current->id)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($results as $result) {
            $effect = LegacyEffect::whereKey($result->effect_id)->lockForUpdate()->firstOrFail();
            $posted[] = [$result, $effect];
            if ($result->disposition === 'included') {
                unset($members[$effect->effect_key]);
            }
        }
        foreach (array_chunk($members, 128, true) as $entries) {
            $effects = LegacyEffect::whereIn('effect_key', array_keys($entries))->orderBy('effect_key')->lockForUpdate()->get()->keyBy('effect_key');
            foreach ($entries as $key => $entry) {
                $effect = $effects->get($key);
                if (!$effect || $effect->economic_sha256 !== $entry['economic_sha256']) {
                    throw new RuntimeException('Accepted opening economics are incomplete for this wallet.');
                }
                $unrecorded[] = [$entry, $effect];
            }
        }

        return [$posted, $unrecorded];
    }

    public static function assertAllowed(LegacyPostingReceipt $receipt, ?LegacyEffect $parent, ?LegacyPostingResult $prior, ?array $cap, array $children): ?string
    {
        $kind = $receipt->identity->data()['effect_kind'];
        $direction = in_array($kind, ['capture', 'manual_settlement', 'manual_restore'], true) ? 1 : -1;
        if (BigDecimal::of($receipt->economic->delta())->compareTo(0) === -$direction) {
            throw new RuntimeException('Legacy movement direction conflicts with its original effect kind.');
        }
        if ($parent === null && $prior === null && in_array($kind, ['capture', 'manual_settlement'], true) && $cap === null) {
            return null;
        }
        if (!$parent || !$prior || $prior->effect_id !== $parent->id ||
            $prior->economic_sha256 !== $parent->economic_sha256 ||
            $prior->opening_receipt_id !== $receipt->facts['opening_receipt_id'] ||
            $prior->membership_sha256 !== $receipt->lineage['membership_sha256']) {
            throw new RuntimeException('A reversal requires its original accepted parent and opening.');
        }

        $identity = EffectIdentity::fromArray(StrictProofJson::decode($parent->identity_json));
        $economic = self::economic($parent);
        self::assertParentLink($receipt->identity, $receipt->economic, $identity, $economic);
        self::assertParentMovement($receipt, $parent, $prior, $economic);

        $limits = self::amounts($economic);
        $includedParent = $prior->disposition === 'included';
        $needsOpeningCap = $includedParent && $receipt->facts['disposition'] !== 'included';
        if ($needsOpeningCap !== ($cap !== null)) {
            throw new RuntimeException('An included parent requires its accepted remaining capacity at opening.');
        }
        $capacityBytes = $cap === null ? null : CanonicalPolicy::bytes($cap);
        $gross = $included = $excluded = [];
        [$posted, $unrecorded] = $children;
        foreach ($posted as [$decision, $child]) {
            if ($decision->opening_receipt_id !== $prior->opening_receipt_id ||
                $decision->membership_sha256 !== $prior->membership_sha256) {
                throw new RuntimeException('Legacy child receipt capacity or opening lineage changed.');
            }
            if ($child->economic_sha256 !== $decision->economic_sha256 ||
                LegacyReceiptConflict::where('effect_id', $child->id)->lockForUpdate()->first(['id']) !== null) {
                throw new RuntimeException('Previously consumed legacy components changed or are disputed.');
            }
            $amounts = self::amounts(self::economic($child));
            self::consume($amounts, $limits, $gross);
            if ($decision->disposition === 'included') {
                self::consume($amounts, $limits, $included);
            } else {
                self::consume($amounts, $limits, $excluded);
                if ($includedParent) {
                    $originalCapacity = $decision->parent_cap_json;
                    if (!is_string($originalCapacity) || $decision->parent_cap_sha256 !== hash('sha256', $originalCapacity) ||
                        ($capacityBytes !== null && $capacityBytes !== $originalCapacity)) {
                        throw new RuntimeException('Legacy child receipt capacity or opening lineage changed.');
                    }
                    $capacityBytes = $originalCapacity;
                }
            }
        }
        if ($unrecorded !== []) {
            self::countUnrecordedOpeningChildren($receipt, $identity, $economic, $unrecorded, $limits, $gross, $included);
        }
        $amounts = self::amounts($receipt->economic);
        self::consume($amounts, $limits, $gross);
        if ($receipt->facts['disposition'] === 'included') {
            self::consume($amounts, $limits, $included);
        } else {
            self::consume($amounts, $limits, $excluded);
        }
        if ($capacityBytes !== null) {
            $accepted = StrictProofJson::decode($capacityBytes);
            if (CanonicalPolicy::bytes($accepted) !== $capacityBytes) {
                throw new RuntimeException('Stored original parent capacity must remain canonical.');
            }
            $openingLimits = self::openingLimits($receipt, $parent, $economic, $limits, $accepted);
            foreach ($limits as $name => $maximum) {
                $capacity = ($openingLimits[$name] ?? BigDecimal::zero())->abs();
                if (($included[$name] ?? BigDecimal::zero())->plus($capacity)->compareTo($maximum->abs()) > 0) {
                    throw new RuntimeException('Remaining capacity contradicts a known included allocation.');
                }
                if (($excluded[$name] ?? BigDecimal::zero())->compareTo($capacity) > 0) {
                    throw new RuntimeException('Legacy reversals exceed an original component or net amount.');
                }
            }
        }

        return $needsOpeningCap ? hash('sha256', $capacityBytes) : null;
    }

    private static function countUnrecordedOpeningChildren(LegacyPostingReceipt $receipt, EffectIdentity $parent, EconomicFacts $parentEconomic,
        array $unrecorded, array $limits, array &$gross, array &$included): void
    {
        $transport = LegacyOpeningExclusion::sourceTrust();
        if ($transport->sha256 !== $receipt->sourceTrustHash) {
            throw new RuntimeException('Original transport trust changed while checking related included effects.');
        }
        // Membership is already tied to this opening. Use its keys and current
        // economics so delivery order cannot hide amounts already in the balance.
        foreach ($unrecorded as [$entry, $effect]) {
            $economic = self::economic($effect);
            if ($economic->data()['parent_effect_key'] !== $parent->key()) {
                continue;
            }
            if (LegacyReceiptConflict::where('effect_id', $effect->id)->lockForUpdate()->first(['id']) !== null) {
                throw new RuntimeException('Accepted included effects are disputed for this wallet.');
            }
            [$retained, $event, $sourceKey] = LegacyOpeningExclusion::originalObservation($effect, $transport, true);
            LegacyPostingReceipt::assertIncludedProvenance($entry, $retained, $event);
            if (in_array($sourceKey, array_slice($receipt->evidenceKeys, 1), true)) {
                throw new RuntimeException('An included effect needs independent source and opening evidence.');
            }
            self::assertParentLink($event->identity, $economic, $parent, $parentEconomic);
            $amounts = self::amounts($economic);
            self::consume($amounts, $limits, $gross);
            self::consume($amounts, $limits, $included);
        }
    }

    private static function assertParentLink(EffectIdentity $child, EconomicFacts $childEconomic, EffectIdentity $parent, EconomicFacts $parentEconomic): void
    {
        $allowedParentKinds = match ($child->data()['effect_kind']) {
            'refund', 'reversal', 'chargeback' => ['capture'],
            'manual_unsettlement' => ['manual_settlement', 'manual_restore'],
            'manual_restore' => ['manual_unsettlement'],
            default => [],
        };
        if ($parent->key() !== $childEconomic->data()['parent_effect_key'] ||
            !in_array($parent->data()['effect_kind'], $allowedParentKinds, true) ||
            !self::sameFields($parent->data(), $child->data(), ['source_instance', 'merchant_binding', 'source_payment']) ||
            !self::sameFields($parentEconomic->data(), $childEconomic->data(), ['source_account', 'source_subaccount', 'wallet_currency', 'provider_currency'])) {
            throw new RuntimeException('Legacy reversal crosses its original payment, ownership or currency.');
        }
    }

    private static function assertParentMovement(LegacyPostingReceipt $receipt, LegacyEffect $parent, LegacyPostingResult $result, EconomicFacts $economic): void
    {
        $applied = $result->disposition === 'applied';
        if ($applied !== ($result->movement_id !== null) ||
            $result->net_delta !== ($result->disposition === 'included' ? '0.0000' : $economic->delta())) {
            throw new RuntimeException('Original legacy parent result lacks its expected amount and movement.');
        }
        if (!$applied) {
            return;
        }
        $movement = AccountMovement::whereKey($result->movement_id)->lockForUpdate()->firstOrFail();
        if ($movement->kind !== 'legacy_effect' || $movement->reference_type !== LegacyEffect::class || $movement->reference_id !== $parent->id ||
            $movement->wallet_id !== $receipt->facts['wallet_id'] || $movement->user_id !== $receipt->facts['user_id'] ||
            $movement->currency_code !== $receipt->facts['currency'] || $movement->delta !== $result->net_delta ||
            $movement->source_key !== LegacyPostingReceipt::movementSourceKey($parent->effect_key) ||
            ($movement->payload['receipt_sha256'] ?? null) !== $result->receipt_sha256) {
            throw new RuntimeException('Original legacy reversal parent movement changed.');
        }
    }

    private static function openingLimits(LegacyPostingReceipt $receipt, LegacyEffect $parent, EconomicFacts $economic, array $original, array $cap): array
    {
        ProofSchema::keys($cap, ['parent_effect_key', 'parent_economic_sha256', 'opening_receipt_sha256', 'membership_sha256',
            'history_sha256', 'wallet_delta', 'wallet_components', 'provider_components']);
        ProofSchema::hash($cap['history_sha256']);
        if ($cap['parent_effect_key'] !== $parent->effect_key || $cap['parent_economic_sha256'] !== $parent->economic_sha256 ||
            $cap['opening_receipt_sha256'] !== $receipt->lineage['opening_receipt_sha256'] ||
            $cap['membership_sha256'] !== $receipt->lineage['membership_sha256']) {
            throw new RuntimeException('Remaining capacity belongs to a different parent or opening.');
        }
        $remaining = self::amounts(EconomicFacts::fromArray(array_replace($economic->data(),
            array_intersect_key($cap, array_flip(['wallet_delta', 'wallet_components', 'provider_components'])))));
        foreach ($remaining as $component => $amount) {
            $limit = $original[$component] ?? null;
            if (!$limit || (!$amount->isZero() && $amount->compareTo(0) !== $limit->compareTo(0)) ||
                $amount->abs()->compareTo($limit->abs()) > 0) {
                throw new RuntimeException('Opening capacity exceeds its original signed component.');
            }
        }

        return $remaining;
    }

    private static function consume(array $amounts, array $limits, array &$consumed): void
    {
        foreach ($amounts as $component => $amount) {
            if ($amount->isZero()) {
                continue;
            }
            $limit = $limits[$component] ?? null;
            $next = ($consumed[$component] ?? BigDecimal::zero())->plus($amount->abs());
            if (!$limit || $amount->compareTo(0) !== -$limit->compareTo(0) || $next->compareTo($limit->abs()) > 0) {
                throw new RuntimeException('Legacy reversals exceed an original component or net amount.');
            }
            $consumed[$component] = $next;
        }
    }

    private static function economic(LegacyEffect $effect): EconomicFacts
    {
        $facts = EconomicFacts::fromArray(StrictProofJson::decode($effect->economic_json));
        if ($facts->fingerprint() !== $effect->economic_sha256) {
            throw new RuntimeException('Original legacy effect economics changed.');
        }

        return $facts;
    }

    private static function amounts(EconomicFacts $facts): array
    {
        $data = $facts->data();
        $amounts = ['wallet-total' => BigDecimal::of($facts->delta())];
        foreach ($data['wallet_components'] as $component) {
            $amounts['wallet:' . $component['component_id']] = BigDecimal::of($component['delta']);
        }
        foreach ($data['provider_components'] as $component) {
            $amounts['provider:' . $component['component_id']] = BigDecimal::of($component['amount']);
        }

        return $amounts;
    }

    private static function sameFields(array $first, array $second, array $fields): bool
    {
        $keys = array_flip($fields);

        return CanonicalPolicy::bytes(array_intersect_key($first, $keys)) === CanonicalPolicy::bytes(array_intersect_key($second, $keys));
    }
}
