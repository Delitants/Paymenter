<?php

namespace Tests\Fixtures\Accounts;

use App\Classes\Extension\Server;
use App\Models\Service;

class UpgradeProvider extends Server
{
    public static ?bool $succeeds = false;

    public static int $calls = 0;

    public static ?\Closure $after = null;

    public function upgradeServer(Service $service, ...$arguments): ?bool
    {
        self::$calls++;
        if (self::$after) {
            (self::$after)();
        }

        return self::$succeeds;
    }
}

class_alias(UpgradeProvider::class, 'Paymenter\\Extensions\\Servers\\AccountUpgradeFixture\\AccountUpgradeFixture');
