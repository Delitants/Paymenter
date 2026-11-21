<?php

namespace App\Services\Accounts\Legacy;

use App\Services\Accounts\AccountAmount;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use Brick\Math\BigDecimal;
use DomainException;

final readonly class EconomicFacts
{
    private function __construct(private array $value) {}

    public static function fromArray(array $value): self
    {
        EffectIdentity::closed($value, ['source_account', 'source_subaccount', 'parent_effect_key', 'wallet_currency', 'wallet_delta', 'wallet_components', 'provider_currency', 'provider_components', 'allocation_rule', 'allocation_evidence_sha256']);
        foreach (['source_account', 'source_subaccount', 'allocation_rule'] as $field) {
            EffectIdentity::id($value[$field]);
        }
        EffectIdentity::sha256($value['allocation_evidence_sha256']);
        if ($value['parent_effect_key'] !== null) {
            EffectIdentity::sha256($value['parent_effect_key']);
        }
        foreach (['wallet_currency', 'provider_currency'] as $field) {
            if (!is_string($value[$field]) || !preg_match('/^[A-Z]{3}$/D', $value[$field])) {
                throw new DomainException('A three-letter uppercase currency is required.');
            }
        }
        self::wallet($value['wallet_delta']);
        $sum = BigDecimal::zero();
        foreach (self::components($value['wallet_components'], 'delta') as $component) {
            self::wallet($component['delta']);
            $sum = $sum->plus($component['delta']);
        }
        if (AccountAmount::parse((string) $sum)->exact() !== $value['wallet_delta']) {
            throw new DomainException('Legacy wallet components must conserve the exact delta.');
        }
        foreach (self::components($value['provider_components'], 'amount') as $component) {
            $amount = $component['amount'];
            if (!is_string($amount) || strlen($amount) > 64 || !preg_match('/^-?(?:0|[1-9][0-9]{0,29})(?:\.[0-9]{1,18})?$/D', $amount)) {
                throw new DomainException('An exact bounded provider decimal string is required.');
            }
            $canonical = str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
            $canonical = $canonical === '-0' ? '0' : $canonical;
            if ($canonical !== $amount) {
                throw new DomainException('Provider decimals must already be canonical.');
            }
        }

        return new self($value);
    }

    private static function wallet(mixed $value): void
    {
        if (AccountAmount::parse($value)->exact() !== $value) {
            throw new DomainException('Legacy wallet amounts must be canonical four-decimal strings.');
        }
    }

    private static function components(mixed $value, string $amountField): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 16) {
            throw new DomainException('A bounded component list is required.');
        }
        $previous = null;
        foreach ($value as $component) {
            if (!is_array($component)) {
                throw new DomainException('A closed component object is required.');
            }
            EffectIdentity::closed($component, ['component_id', $amountField]);
            EffectIdentity::id($component['component_id']);
            if ($previous !== null && strcmp($previous, $component['component_id']) >= 0) {
                throw new DomainException('Component IDs must be distinct and in bytewise order.');
            }
            $previous = $component['component_id'];
        }

        return $value;
    }

    public function fingerprint(): string
    {
        return hash('sha256', "legacy-economic-facts-v1\0" . CanonicalPolicy::bytes($this->value));
    }

    public function delta(): string
    {
        return $this->value['wallet_delta'];
    }

    public function data(): array
    {
        return $this->value;
    }
}
