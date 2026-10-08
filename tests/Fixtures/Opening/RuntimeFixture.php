<?php

namespace Tests\Fixtures\Opening;

use App\Services\Accounts\AcceptedFundingReleaseAuthority;
use App\Services\BillmanagerMigration\Opening\OpeningProcessContext;
use App\Services\BillmanagerMigration\Opening\ReleaseVerifier;

final class RuntimeFixture
{
    public static function accepted(): array
    {
        $parent = self::root();
        $fixture = ProofFactory::signed('account-funding-runtime-acceptance', [
            'not_before' => gmdate('Y-m-d\TH:i:s\Z', time() - 5),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'manifest_path' => '/pending', 'manifest_sha256' => str_repeat('a', 64),
            'target_identity' => OpeningProcessContext::runtimeTargetIdentity(),
            'configuration_sha256' => ReleaseVerifier::configurationHash(),
            'decision_ref_sha256' => str_repeat('b', 64),
        ], $parent);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $name = substr($file->getPathname(), strlen(base_path()) + 1);
            if (!str_starts_with($name, 'storage/logs/') && !str_starts_with($name, 'storage/framework/cache/data/') && !str_starts_with($name, 'storage/framework/sessions/')) {
                $files[$name] = hash_file('sha256', $file->getPathname());
            }
        }
        $manifest = $fixture['dir'] . '/manifest.json';
        ProofFactory::write($manifest, json_encode(['schema_version' => 1, 'purpose' => 'account-funding-file-manifest', 'files' => $files,
            'generated_paths' => ['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions']], JSON_THROW_ON_ERROR));
        $fixture['value']['manifest_path'] = $manifest;
        $fixture['value']['manifest_sha256'] = hash_file('sha256', $manifest);
        self::resign($fixture);
        chmod($fixture['dir'], 0750);
        chgrp($fixture['dir'], self::gid());
        foreach (glob($fixture['dir'] . '/*') as $path) {
            chmod($path, 0640);
            chgrp($path, self::gid());
        }
        config(['account-funding.enabled' => true, 'account-funding.runtime_reader_gid' => self::gid()]);

        return [...$fixture, 'authority' => new AcceptedFundingReleaseAuthority($fixture['payload'], $fixture['signature'], $fixture['trust'])];
    }

    public static function root(): string
    {
        $parent = getenv('RUNTIME_FIXTURE_ROOT');
        if (!$parent || $parent[0] !== '/' || !is_dir($parent) || is_link($parent)) {
            throw new \RuntimeException('A protected owned runtime proof fixture root is required.');
        }
        $stat = lstat($parent);
        if ($stat['uid'] !== 0 || $stat['gid'] !== self::gid() || ($stat['mode'] & 07777) !== 0750) {
            throw new \RuntimeException('Runtime fixture root must have protected root/group ownership.');
        }

        return $parent;
    }

    public static function gid(): int
    {
        $value = filter_var(getenv('RUNTIME_FIXTURE_GID'), FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < 0) {
            throw new \RuntimeException('An explicit runtime fixture reader group is required.');
        }

        return $value;
    }

    public static function resign(array $fixture): void
    {
        $bytes = json_encode($fixture['value'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        openssl_sign($bytes, $signature, $fixture['key'], OPENSSL_ALGO_SHA256);
        file_put_contents($fixture['payload'], $bytes);
        file_put_contents($fixture['signature'], json_encode(['schema_version' => 1, 'key_id' => 'fixture', 'algorithm' => 'rsa-sha256', 'signature_b64' => base64_encode($signature)], JSON_THROW_ON_ERROR));
    }
}
