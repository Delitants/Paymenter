<?php

namespace App\Services\Accounts\Legacy;

use DateTimeImmutable;

interface LegacyClock
{
    public function now(): DateTimeImmutable;
}
