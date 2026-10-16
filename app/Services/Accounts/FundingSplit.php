<?php

namespace App\Services\Accounts;

/** Exact principal allocation; refund reservations affect eligibility separately. */
final readonly class FundingSplit
{
    private function __construct(public string $cash, public string $debt) {}

    public static function forPayment(string $balance, string $principal): self
    {
        $before = AccountAmount::parse($balance);
        $zero = AccountAmount::parse('0');
        $positive = $before->compare($zero) > 0 ? $before : $zero;
        $amount = AccountAmount::positiveCents($principal);
        $cash = $positive->compare($amount) < 0 ? $positive : $amount;

        return new self($cash->exact(), $amount->subtract($cash)->exact());
    }
}
