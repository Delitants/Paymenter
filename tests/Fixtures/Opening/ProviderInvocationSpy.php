<?php

namespace Tests\Fixtures\Opening;

use App\Classes\Extension\Server;

class ProviderInvocationSpy extends Server
{
    public static array $invocations = [];

    public function probe(): void
    {
        self::$invocations[] = 'probe';
    }
}

class_alias(ProviderInvocationSpy::class, 'Paymenter\\Extensions\\Servers\\OpeningProviderSpy\\OpeningProviderSpy');
