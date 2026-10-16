<?php

namespace Tests\Fixtures\Opening;

use Illuminate\Bus\Batchable;

class IsolationJob
{
    use Batchable;

    public function handle(): void
    {
        throw new \RuntimeException('Synthetic opening isolation job must never execute.');
    }
}
