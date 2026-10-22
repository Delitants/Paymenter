<?php

namespace Paymenter\Extensions\Gateways\Klarna;

final class ReferenceRates
{
    public function get(string $currency): array
    {
        return (new \App\Services\Gateways\ReferenceRates)->get($currency);
    }
}
