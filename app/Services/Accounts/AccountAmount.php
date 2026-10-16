<?php

namespace App\Services\Accounts;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

final readonly class AccountAmount
{
    private function __construct(private string $value) {}

    public static function parse(mixed $value): self
    {
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?$/D', $value)) {
            throw new DomainException('An exact decimal string within account money bounds is required.');
        }

        return new self((string) BigDecimal::of($value)->toScale(4));
    }

    public static function positiveCents(mixed $value): self
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new DomainException('A positive cent amount is required.');
        }
        $amount = self::parse($value);
        if ($amount->compare(self::parse('0')) <= 0) {
            throw new DomainException('A positive cent amount is required.');
        }

        return $amount;
    }

    public static function nonnegative(mixed $value): self
    {
        $amount = self::parse($value);
        if ($amount->compare(self::parse('0')) < 0) {
            throw new DomainException('A nonnegative exact amount is required.');
        }

        return $amount;
    }

    public function add(self $other): self
    {
        return self::parse((string) BigDecimal::of($this->value)->plus($other->value));
    }

    public function subtract(self $other): self
    {
        return self::parse((string) BigDecimal::of($this->value)->minus($other->value));
    }

    public function compare(self $other): int
    {
        return BigDecimal::of($this->value)->compareTo($other->value);
    }

    public function exact(): string
    {
        return $this->value;
    }

    public function floorCents(): string
    {
        self::nonnegative($this->value);

        return (string) BigDecimal::of($this->value)->toScale(2, RoundingMode::Down);
    }

    public function halfUpCents(): string
    {
        self::nonnegative($this->value);

        return self::parse((string) BigDecimal::of($this->value)->toScale(2, RoundingMode::HalfUp))->floorCents();
    }
}
