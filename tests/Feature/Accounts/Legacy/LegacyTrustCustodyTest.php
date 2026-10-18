<?php

namespace Tests\Feature\Accounts\Legacy;

use App\Services\Accounts\Legacy\LegacyTrustReader;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\RuntimeProofFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\LegacyReceipts\LegacyReceiptFixture as Fixture;

#[Group('opening-native')]
final class LegacyTrustCustodyTest extends TestCase
{
    public function test_only_current_protected_trust_is_read(): void
    {
        self::assertTrue(class_exists(LegacyTrustReader::class));
        Fixture::withProtectedTrust(Fixture::trust(Fixture::keyPair()), function ($path, $gid) {
            $before = LegacyTrustReader::read($path, $gid);
            self::assertSame(hash_file('sha256', $path), $before->sha256);
            $data = $before->data;
            $data['revoked_keys'] = ['fixture-key'];
            file_put_contents($path, CanonicalPolicy::bytes($data));
            self::assertNotSame($before->sha256, LegacyTrustReader::read($path, $gid)->sha256);
        });
    }

    public function test_proof_reader_admits_exact_cap_and_preserves_default(): void
    {
        Fixture::withProtectedTrust(Fixture::trust(Fixture::keyPair()), function ($path, $gid) {
            file_put_contents($path, str_repeat('x', 262144));
            self::assertSame(262144, strlen(RuntimeProofFile::read($path, $gid, 262144)['bytes']));
            file_put_contents($path, 'x', FILE_APPEND);
            self::assertSame(262145, strlen(RuntimeProofFile::read($path, $gid)['bytes']));
            $this->expectException(RuntimeException::class);
            RuntimeProofFile::read($path, $gid, 262144);
        });
    }

    public function test_invalid_caps_are_denied_before_content_is_read(): void
    {
        Fixture::withProtectedTrust(Fixture::trust(Fixture::keyPair()), function ($path, $gid) {
            foreach ([0, -1, PHP_INT_MAX] as $cap) {
                $this->denied(fn () => RuntimeProofFile::read($path, $gid, $cap));
            }
        });
    }

    public function test_links_writable_parents_and_wrong_group_are_denied(): void
    {
        Fixture::withProtectedTrust(Fixture::trust(Fixture::keyPair()), function ($path, $gid) {
            link($path, $path . '.hard');
            $this->denied(fn () => LegacyTrustReader::read($path, $gid));
            unlink($path . '.hard');
            symlink($path, $path . '.link');
            $this->denied(fn () => LegacyTrustReader::read($path . '.link', $gid));
            unlink($path . '.link');
            chmod(dirname($path), 0770);
            $this->denied(fn () => LegacyTrustReader::read($path, $gid));
            chmod(dirname($path), 0750);
            chgrp($path, $gid + 1);
            $this->denied(fn () => LegacyTrustReader::read($path, $gid));
            chgrp($path, $gid);
            self::assertSame(hash_file('sha256', $path), LegacyTrustReader::read($path, $gid)->sha256);
        });
    }

    public function test_growth_during_a_real_descriptor_read_is_denied(): void
    {
        Fixture::withProtectedTrust(Fixture::trust(Fixture::keyPair()), function ($path, $gid) {
            file_put_contents($path, str_repeat('x', 67108864));
            $pid = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                try {
                    RuntimeProofFile::read($path, $gid, 67108864);
                    exit(0);
                } catch (RuntimeException) {
                    exit(17);
                }
            }
            $stopped = false;
            $deadline = hrtime(true) + 5000000000;
            try {
                while (hrtime(true) < $deadline && is_dir('/proc/' . $pid . '/fd')) {
                    foreach (glob('/proc/' . $pid . '/fd/*') as $fd) {
                        if (@readlink($fd) === $path) {
                            posix_kill($pid, SIGSTOP);
                            pcntl_waitpid($pid, $status, WUNTRACED);
                            $stopped = pcntl_wifstopped($status);
                            break 2;
                        }
                    }
                    usleep(100);
                }
                self::assertTrue($stopped, 'Owned real reader must be stopped with its descriptor open.');
                file_put_contents($path, 'y', FILE_APPEND);
            } finally {
                posix_kill($pid, SIGCONT);
                pcntl_waitpid($pid, $status);
            }
            self::assertSame(17, pcntl_wexitstatus($status));
        });
    }

    private function denied(\Closure $call): void
    {
        $denied = false;
        try {
            $call();
        } catch (RuntimeException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Unsafe protected legacy proof was accepted.');
    }
}
