<?php

namespace App\Services\Accounts\Legacy;

use DateTimeImmutable;
use DateTimeZone;

final class SystemLegacyClock implements LegacyClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
