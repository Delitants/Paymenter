<?php

namespace Tests\Unit\BillmanagerMigration;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

class OpeningTestProfileTest extends TestCase
{
    public function test_privileged_acceptance_is_explicitly_separated_without_skipping_native_tests(): void
    {
        $root = dirname(__DIR__, 3);
        $ordinary = new DOMDocument;
        self::assertTrue($ordinary->load($root . '/phpunit.xml'));
        $query = new DOMXPath($ordinary);
        self::assertSame(1, $query->query('/phpunit/groups/exclude/group[text()="opening-native"]')->length, 'Ordinary contributor runs discover privileged native tests.');
        self::assertFileExists($root . '/phpunit.opening-native.xml');
        $native = new DOMDocument;
        self::assertTrue($native->load($root . '/phpunit.opening-native.xml'));
        $query = new DOMXPath($native);
        self::assertSame(0, $query->query('/phpunit/groups/exclude/group[text()="opening-native"]')->length);
        self::assertSame('true', $native->documentElement->getAttribute('failOnSkipped'));
        foreach (['Feature/Accounts/AcceptedFundingReleaseTest.php', 'Feature/Accounts/HeldOpeningPermitTest.php',
            'Feature/BillmanagerMigration/OpeningPreparationTest.php', 'Feature/BillmanagerMigration/OpeningBatchTest.php',
            'Feature/BillmanagerMigration/OpeningBatchRaceTest.php', 'Feature/BillmanagerMigration/OpeningHistoryMutationTest.php',
            'Feature/BillmanagerMigration/OpeningIsolationTest.php', 'Unit/BillmanagerMigration/OpeningProofTest.php', 'Unit/BillmanagerMigration/RuntimeProofFileTest.php'] as $path) {
            self::assertStringContainsString("#[Group('opening-native')]", file_get_contents($root . '/tests/' . $path), $path);
        }
    }

    public function test_native_profile_without_explicit_owned_environment_stops_before_framework_setup(): void
    {
        $script = dirname(__DIR__, 3) . '/tests/Fixtures/Opening/native-bootstrap.php';
        $pipes = [];
        $child = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'paymenter_test']);
        self::assertIsResource($child);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(0, proc_close($child));
        self::assertStringContainsString('Native opening acceptance requires an explicit owned isolated root testing environment.', $output);
        self::assertStringNotContainsString('vendor/autoload.php', $output);
    }
}
