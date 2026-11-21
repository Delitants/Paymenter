<?php

namespace Tests\Feature\BillmanagerMigration;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OpeningCommandTest extends TestCase
{
    public function test_only_apply_and_read_only_verify_commands_are_registered(): void
    {
        $commands = Artisan::all();
        self::assertArrayHasKey('billmanager:openings:apply', $commands, 'Native apply command is missing.');
        self::assertArrayHasKey('billmanager:openings:verify', $commands, 'Native read-only verify command is missing.');
        foreach (['freeze', 'approve', 'activate', 'restore', 'skip-holds'] as $name) {
            self::assertArrayNotHasKey($name, $commands['billmanager:openings:apply']->getDefinition()->getOptions());
        }
    }
}
