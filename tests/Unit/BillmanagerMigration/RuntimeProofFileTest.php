<?php

namespace Tests\Unit\BillmanagerMigration;

use App\Services\BillmanagerMigration\Opening\RuntimeProofFile;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\Fixtures\Opening\RuntimeFixture;
use Tests\TestCase;

#[Group('opening-native')]
class RuntimeProofFileTest extends TestCase
{
    protected function tearDown(): void
    {
        try {
            ProofFactory::cleanup();
        } finally {
            parent::tearDown();
        }
    }

    public function test_missing_fixture_root_fails_before_filesystem_creation(): void
    {
        $original = getenv('RUNTIME_FIXTURE_ROOT');
        putenv('RUNTIME_FIXTURE_ROOT');
        try {
            $this->expectException(RuntimeException::class);
            RuntimeFixture::root();
        } finally {
            $original === false ? putenv('RUNTIME_FIXTURE_ROOT') : putenv('RUNTIME_FIXTURE_ROOT=' . $original);
        }
    }

    public function test_runtime_reader_rejects_write_access_links_and_untrusted_ancestors(): void
    {
        $dir = RuntimeFixture::root() . '/reader-' . bin2hex(random_bytes(8));
        mkdir($dir, 0750);
        chgrp($dir, RuntimeFixture::gid());
        ProofFactory::$directories[] = $dir;
        $path = $dir . '/proof.json';
        file_put_contents($path, '{}');
        chmod($path, 0640);
        chgrp($path, RuntimeFixture::gid());
        self::assertSame('{}', RuntimeProofFile::read($path, RuntimeFixture::gid())['bytes']);
        foreach ([0660, 0644, 0600, 04640] as $mode) {
            chmod($path, $mode);
            $this->denied($path);
        }
        chmod($path, 0640);
        chgrp($path, RuntimeFixture::gid() + 1);
        $this->denied($path);
        chgrp($path, RuntimeFixture::gid());
        chown($path, 60001);
        $this->denied($path);
        chown($path, 0);
        link($path, $dir . '/hard-link');
        $this->denied($path);
        unlink($dir . '/hard-link');
        symlink($path, $dir . '/symbolic-link');
        $this->denied($dir . '/symbolic-link');
        unlink($dir . '/symbolic-link');
        chmod($dir, 0770);
        $this->denied($path);
        chmod($dir, 0750);
        chown($dir, 60001);
        $this->denied($path);
        chown($dir, 0);
        foreach (['php://filter/resource=' . $path, $dir . '/../' . basename($dir) . '/proof.json', $dir . '/./proof.json', $dir . '//proof.json', $dir . '/missing'] as $invalid) {
            $this->denied($invalid);
        }
        self::assertSame('{}', RuntimeProofFile::read($path, RuntimeFixture::gid())['bytes']);
    }

    private function denied(string $path): void
    {
        try {
            RuntimeProofFile::read($path, RuntimeFixture::gid());
            self::fail('Unsafe runtime proof was accepted');
        } catch (RuntimeException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
