<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Services\BillmanagerMigration\Opening\OpeningBackupVerifier;
use App\Services\BillmanagerMigration\Opening\OpeningProcessContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\AcceptedFixture;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningBackupSocketUserTest extends TestCase
{
    use UsesCommittedDatabase;

    public function test_full_dump_uses_the_configured_socket_user_without_elevating_the_application_account(): void
    {
        $argv = $_SERVER['argv'];
        $_SERVER['argv'] = [base_path('artisan'), 'billmanager:openings:apply'];
        $default = DB::getDefaultConnection();
        $admin = DB::connection();
        $username = 'opening_test_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(12));
        $created = false;

        try {
            $target = OpeningProcessContext::capture()->targetIdentity();
            $database = $target['db_database'];
            self::assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/D', $database);
            $fixture = AcceptedFixture::make(heartbeat: false, realBackup: true);

            $admin->unprepared("CREATE USER '$username'@'localhost' IDENTIFIED BY '$password'");
            $created = true;
            $admin->unprepared("GRANT SELECT, SHOW VIEW, TRIGGER ON `$database`.* TO '$username'@'localhost'");
            config(['database.connections.opening_test' => [...$admin->getConfig(), 'username' => $username, 'password' => $password]]);
            DB::setDefaultConnection('opening_test');
            self::assertSame("$username@localhost", DB::selectOne('SELECT CURRENT_USER() AS found')->found);
            $verifier = new OpeningBackupVerifier;
            try {
                $verifier->assertReady($fixture['bundle']);
                self::fail('The restricted application login unexpectedly dumped event definitions.');
            } catch (RuntimeException $error) {
                self::assertSame('Current full baseline SQL differs.', $error->getMessage());
            }

            config(['account-opening.backup_socket_user' => 'root']);
            $verifier->assertReady($fixture['bundle']);
            self::assertSame("$username@localhost", DB::selectOne('SELECT CURRENT_USER() AS found')->found);
            self::assertSame(0, DB::table('account_opening_receipts')->count());
        } finally {
            config(['account-opening.backup_socket_user' => null]);
            DB::purge('opening_test');
            DB::setDefaultConnection($default);
            if ($created) {
                $admin->unprepared("DROP USER '$username'@'localhost'");
            }
            $_SERVER['argv'] = $argv;
            ProofFactory::cleanup();
        }
    }
}
