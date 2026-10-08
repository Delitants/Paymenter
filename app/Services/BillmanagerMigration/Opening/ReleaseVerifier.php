<?php

namespace App\Services\BillmanagerMigration\Opening;

use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ReleaseVerifier
{
    private static ?string $parsedHash = null;

    private static ?array $parsedManifest = null;

    public static function assertCurrent(array $acceptedRelease): void
    {
        self::assertEvidence($acceptedRelease);
        self::assertAccepted($acceptedRelease, PrivateProofFile::read($acceptedRelease['manifest_path'], 0), OpeningProcessContext::capture()->targetIdentity());
    }

    public static function assertRuntimeCurrent(array $acceptedRelease, int $readerGid): void
    {
        self::assertEvidence($acceptedRelease);
        self::assertAccepted($acceptedRelease, RuntimeProofFile::read($acceptedRelease['manifest_path'], $readerGid), OpeningProcessContext::runtimeTargetIdentity());
    }

    private static function assertEvidence(array $acceptedRelease): void
    {
        if (!isset($acceptedRelease['manifest_path'], $acceptedRelease['manifest_sha256'], $acceptedRelease['target_identity'], $acceptedRelease['configuration_sha256'])) {
            throw new RuntimeException('Accepted release evidence is incomplete.');
        }
    }

    private static function assertAccepted(array $acceptedRelease, array $manifest, array $target): void
    {
        if (!hash_equals(ProofSchema::hash($acceptedRelease['manifest_sha256']), $manifest['sha256'])) {
            throw new RuntimeException('Accepted release manifest changed.');
        }
        if ($acceptedRelease['target_identity'] !== $target) {
            throw new RuntimeException('Accepted release target changed.');
        }
        // Cache parsing only. Every use still reads the protected manifest and hashes
        // the complete installed file population, persisted configuration and target.
        if (self::$parsedHash !== $manifest['sha256']) {
            self::$parsedManifest = StrictProofJson::decode($manifest['bytes']);
            self::$parsedHash = $manifest['sha256'];
        }
        self::assertManifest($target['runtime_realpath'], self::$parsedManifest);
        if (!hash_equals(ProofSchema::hash($acceptedRelease['configuration_sha256']), self::configurationHash())) {
            throw new RuntimeException('Persisted accepted configuration changed.');
        }
    }

    public static function assertManifest(string $root, array $manifest): void
    {
        ProofSchema::keys($manifest, ['schema_version', 'purpose', 'files', 'generated_paths']);
        if ($manifest['schema_version'] !== 1 || $manifest['purpose'] !== 'account-funding-file-manifest' || !is_array($manifest['files']) || !$manifest['files'] ||
            $manifest['generated_paths'] !== ['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions']) {
            throw new RuntimeException('Invalid accepted release manifest.');
        }
        foreach (['artisan', 'composer.lock', 'vendor/autoload.php', 'app/Services/Accounts/WalletLedger.php', 'app/Services/Accounts/AccountWriteGuard.php', 'config/account-funding.php'] as $required) {
            if (!isset($manifest['files'][$required])) {
                throw new RuntimeException('Accepted runtime manifest is incomplete.');
            }
        }
        $actual = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($file->isLink()) {
                throw new RuntimeException('Symbolic installed release file.');
            }
            $generated = false;
            foreach ($manifest['generated_paths'] as $prefix) {
                if (str_starts_with($path, $prefix . '/')) {
                    if (preg_match('/\.(?:php|phtml|phar)$/i', $path)) {
                        throw new RuntimeException('Executable file in excluded generated path.');
                    }
                    $generated = true;
                }
            }
            if (!$generated) {
                $actual[$path] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($actual);
        $expected = $manifest['files'];
        ksort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Installed release bytes or population changed.');
        }
    }

    public static function configurationHash(): string
    {
        $env = base_path('.env');

        return hash('sha256', CanonicalPolicy::bytes(['env_sha256' => is_file($env) ? hash_file('sha256', $env) : null,
            'settings' => array_map(fn ($row) => (array) $row, DB::table('settings')->orderBy('id')->get()->all())]));
    }
}
