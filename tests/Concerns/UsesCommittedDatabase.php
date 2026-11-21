<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseTruncation;

trait UsesCommittedDatabase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        try {
            // These tests need autocommit. Restore the seeded baseline before
            // later RefreshDatabase tests start their rollback transaction.
            $this->truncateDatabaseTables();
        } finally {
            parent::tearDown();
        }
    }
}
