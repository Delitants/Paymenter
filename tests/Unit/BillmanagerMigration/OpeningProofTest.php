<?php

namespace Tests\Unit\BillmanagerMigration;

use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\OpeningProcessContext;
use App\Services\BillmanagerMigration\Opening\PrivateProofFile;
use App\Services\BillmanagerMigration\Opening\ReleaseVerifier;
use App\Services\BillmanagerMigration\Opening\SignedAttestation;
use App\Services\BillmanagerMigration\Opening\StrictProofJson;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\Opening\ProofFactory;

#[Group('opening-native')]
class OpeningProofTest extends TestCase
{
    protected function tearDown(): void
    {
        ProofFactory::cleanup();
        parent::tearDown();
    }

    private function denied(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());

            return;
        }
        self::fail('Unsafe evidence was accepted.');
    }

    public function test_duplicate_keys_and_changed_bytes_are_rejected(): void
    {
        self::assertTrue(class_exists(StrictProofJson::class), 'Strict opening proof parsing is missing.');
        foreach (['{"a":1,"a":2}', '{"a":1,"\\u0061":2}', '{"a":{"b":1,"b":2}}', '{"a":1} null', '{"amount":12}', '{"amount":1.23}', '{"sequence":1.0}'] as $bytes) {
            $this->denied(fn () => StrictProofJson::decode($bytes));
        }
        self::assertSame(['schema_version' => 1, 'amount' => '1.0050', 'nested' => [true, null]], StrictProofJson::decode('{"schema_version":1,"amount":"1.0050","nested":[true,null]}'));
        self::assertSame('{"a":{"x":"1.0050","y":"é"},"z":["b","a"]}', CanonicalPolicy::bytes(['z' => ['b', 'a'], 'a' => ['y' => 'é', 'x' => '1.0050']]));
        $proof = ProofFactory::signed('account-opening-fence', ProofFactory::fence());
        self::assertSame('fenced', SignedAttestation::verify($proof['payload'], $proof['signature'], 'account-opening-fence', $proof['trust'], new DateTimeImmutable('2026-01-01T00:00:30Z'))['status']);
        ProofFactory::write($proof['payload'], file_get_contents($proof['payload']) . ' ');
        $this->denied(fn () => SignedAttestation::verify($proof['payload'], $proof['signature'], 'account-opening-fence', $proof['trust'], new DateTimeImmutable('2026-01-01T00:00:30Z')));
    }

    public function test_private_file_identity_and_signature_purpose_are_bound(): void
    {
        self::assertTrue(class_exists(PrivateProofFile::class), 'Private opening proof reader is missing.');
        $p = ProofFactory::signed('account-opening-fence', ProofFactory::fence());
        $read = PrivateProofFile::read($p['payload'], posix_geteuid());
        self::assertSame(hash('sha256', file_get_contents($p['payload'])), $read['sha256']);
        self::assertSame(fileinode($p['payload']), $read['inode']);
        chmod($p['payload'], 0644);
        $this->denied(fn () => PrivateProofFile::read($p['payload'], posix_geteuid()));
        chmod($p['payload'], 0600);
        $this->denied(fn () => PrivateProofFile::read($p['payload'], posix_geteuid() + 1));
        $this->denied(fn () => PrivateProofFile::read('php://memory', posix_geteuid()));
        symlink($p['payload'], $p['dir'] . '/link');
        $this->denied(fn () => PrivateProofFile::read($p['dir'] . '/link', posix_geteuid()));
        symlink($p['dir'], $p['dir'] . '/linked-parent');
        $this->denied(fn () => PrivateProofFile::read($p['dir'] . '/linked-parent/payload.json', posix_geteuid()));
        $this->denied(fn () => SignedAttestation::verify($p['payload'], $p['signature'], 'account-funding-release', $p['trust'], new DateTimeImmutable('2026-01-01T00:00:30Z')));
        $this->denied(fn () => SignedAttestation::verify($p['payload'], $p['signature'], 'account-opening-fence', $p['trust'], new DateTimeImmutable('2026-01-01T00:01:00Z')));
        $trust = json_decode(file_get_contents($p['trust']), true);
        $trust['revoked_nonces'] = ['fixture-nonce'];
        ProofFactory::write($p['trust'], json_encode($trust));
        $this->denied(fn () => SignedAttestation::verify($p['payload'], $p['signature'], 'account-opening-fence', $p['trust'], new DateTimeImmutable('2026-01-01T00:00:30Z')));
    }

    public function test_release_context_and_fence_fail_closed(): void
    {
        self::assertTrue(class_exists(OpeningProcessContext::class), 'Isolated operator context is missing.');
        $this->denied(fn () => OpeningProcessContext::capture()); // PHPUnit is not an allowed console operator.
        $this->denied(fn () => ReleaseVerifier::assertCurrent(['manifest_path' => 'php://memory']));
        foreach ([['status' => 'released'], ['expires_at' => '2026-01-01T00:02:00Z'], ['sequence' => -1], ['unexpected' => true]] as $bad) {
            $p = ProofFactory::signed('account-opening-fence', [...ProofFactory::fence(), ...$bad]);
            $this->denied(fn () => SignedAttestation::verify($p['payload'], $p['signature'], 'account-opening-fence', $p['trust'], new DateTimeImmutable('2026-01-01T00:00:30Z')));
        }
    }

    public function test_foreign_owned_ancestor_cannot_replace_a_root_private_proof_tree(): void
    {
        self::assertSame(0, posix_geteuid(), 'The isolated opening proof suite requires root.');
        $p = ProofFactory::signed('account-opening-fence', ProofFactory::fence());
        $ancestor = $p['dir'] . '/foreign';
        mkdir($ancestor, 0755);
        mkdir($ancestor . '/private', 0700);
        ProofFactory::write($ancestor . '/private/proof.json', '{}');
        chown($ancestor, 65534);
        $this->denied(fn () => PrivateProofFile::read($ancestor . '/private/proof.json', 0));
    }

    public function test_missing_original_proof_is_a_controlled_denial_without_php_warning(): void
    {
        $p = ProofFactory::signed('account-opening-fence', ProofFactory::fence());
        unlink($p['payload']);
        set_error_handler(static function ($severity, $message) {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $this->denied(fn () => PrivateProofFile::read($p['payload'], posix_geteuid()));
        } finally {
            restore_error_handler();
        }
    }

    public function test_incomplete_manifest_cannot_claim_an_accepted_runtime(): void
    {
        $p = ProofFactory::signed('account-opening-fence', ProofFactory::fence());
        $files = [];
        foreach (glob($p['dir'] . '/*') as $file) {
            $files[basename($file)] = hash_file('sha256', $file);
        }
        $this->denied(fn () => ReleaseVerifier::assertManifest($p['dir'], ['schema_version' => 1, 'purpose' => 'account-funding-file-manifest', 'files' => $files,
            'generated_paths' => ['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions']]));
    }

    public function test_manifest_covers_every_runtime_file_and_denies_changed_or_excluded_executables(): void
    {
        $p = ProofFactory::signed('account-opening-fence', ProofFactory::fence());
        $names = ['artisan', 'composer.lock', 'vendor/autoload.php', 'app/Services/Accounts/WalletLedger.php', 'app/Services/Accounts/AccountWriteGuard.php', 'config/account-funding.php'];
        foreach ($names as $name) {
            if (!is_dir(dirname($p['dir'] . '/' . $name))) {
                mkdir(dirname($p['dir'] . '/' . $name), 0700, true);
            }
            ProofFactory::write($p['dir'] . '/' . $name, 'fixture-bytes');
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p['dir'], \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($p['dir']) + 1)] = hash_file('sha256', $file->getPathname());
        }
        $manifest = ['schema_version' => 1, 'purpose' => 'account-funding-file-manifest', 'files' => $files,
            'generated_paths' => ['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions']];
        ReleaseVerifier::assertManifest($p['dir'], $manifest);
        self::assertTrue(is_file($p['dir'] . '/artisan'));
        ProofFactory::write($p['dir'] . '/vendor/autoload.php', 'changed dependency');
        $this->denied(fn () => ReleaseVerifier::assertManifest($p['dir'], $manifest));
        ProofFactory::write($p['dir'] . '/vendor/autoload.php', 'fixture-bytes');
        ProofFactory::write($p['dir'] . '/unaccepted.php', '<?php unexpected();');
        $this->denied(fn () => ReleaseVerifier::assertManifest($p['dir'], $manifest));
        unlink($p['dir'] . '/unaccepted.php');
        mkdir($p['dir'] . '/storage/logs', 0700, true);
        ProofFactory::write($p['dir'] . '/storage/logs/payload.php', '<?php unexpected();');
        $this->denied(fn () => ReleaseVerifier::assertManifest($p['dir'], $manifest));
    }
}
