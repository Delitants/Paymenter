<?php

namespace App\Services\Accounts\Legacy;

use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use DomainException;

final readonly class EffectIdentity
{
    private const FIELDS = ['source_instance', 'merchant_binding', 'source_payment', 'effect_kind', 'economic_effect_id'];

    public const KINDS = ['capture', 'refund', 'reversal', 'chargeback', 'manual_settlement', 'manual_unsettlement', 'manual_restore'];

    private function __construct(private array $value) {}

    public static function fromArray(array $value): self
    {
        self::closed($value, self::FIELDS);
        foreach ($value as $item) {
            self::id($item);
        }
        if (!in_array($value['effect_kind'], self::KINDS, true)) {
            throw new DomainException('Unsupported legacy effect kind.');
        }

        return new self($value);
    }

    public function key(): string
    {
        return hash('sha256', "legacy-economic-effect-v1\0" . CanonicalPolicy::bytes($this->value));
    }

    public function data(): array
    {
        return $this->value;
    }

    /** Closed contracts also refuse numeric-map coercion and unexpected fields. */
    public static function closed(array $value, array $fields): void
    {
        if (count($value) !== count($fields) || array_diff($fields, array_keys($value)) || array_diff(array_keys($value), $fields)) {
            throw new DomainException('A closed legacy receipt object is required.');
        }
    }

    public static function id(mixed $value, int $limit = 512): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > $limit || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new DomainException('A bounded opaque UTF8 legacy identifier is required.');
        }
    }

    public static function sha256(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new DomainException('A lowercase SHA256 is required.');
        }
    }
}
