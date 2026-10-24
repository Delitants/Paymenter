<?php

namespace Tests\Unit\Bind;

use Paymenter\Extensions\Others\Bind\PrivateFiles;
use Paymenter\Extensions\Others\Bind\SshTransport;
use Paymenter\Extensions\Others\Bind\TransportUncertain;
use PHPUnit\Framework\TestCase;

final class SshTransportTest extends TestCase
{
    private const R = '00000000-0000-4000-8000-000000000001';

    private const B = '00000000-0000-4000-8000-000000000002';

    private function config(): array
    {
        return ['host' => 'dns.example.test', 'port' => 2222, 'username' => 'paymenter-dns', 'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n" . base64_encode('not a functioning key') . "\n-----END OPENSSH PRIVATE KEY-----\n", 'host_key' => 'ssh-ed25519 ' . base64_encode('not a functioning host key')];
    }

    private function response(): string
    {
        return json_encode(['protocol' => 'paymenter-bind-v1', 'request_id' => self::R, 'binding_id' => self::B, 'state' => 'observed', 'code' => 'OK', 'data' => (object) ['serial' => 1]]);
    }

    public function test_fixed_arguments_private_files_and_cleanup(): void
    {
        $this->assertTrue(class_exists(SshTransport::class), 'SSH transport is missing');
        $calls = 0;
        $files = [];
        $runner = function (array $argv, string $stdin, int $timeout, int $limit) use (&$calls, &$files) {
            $calls++;
            $this->assertSame('/usr/bin/ssh', $argv[0]);
            $this->assertContains('StrictHostKeyChecking=yes', $argv);
            $this->assertContains('IdentityAgent=none', $argv);
            $this->assertContains('ConnectionAttempts=1', $argv);
            $this->assertSame('paymenter-bind-v1', end($argv));
            $this->assertSame(40, $timeout);
            $this->assertSame(1048576, $limit);
            $key = $argv[array_search('-i', $argv, true) + 1];
            $files[] = $key;
            $files[] = dirname($key) . '/known_hosts';
            foreach ($files as $f) {
                $this->assertFileExists($f);
                $this->assertSame(0600, fileperms($f) & 0777);
            }$this->assertStringContainsString('[dns.example.test]:2222', file_get_contents($files[1]));
            $this->assertSame(self::B, json_decode($stdin, true)['binding_id']);

            return $this->response();
        };
        $t = new SshTransport($this->config(), $runner);
        $this->assertSame('observed', $t->request(self::B, 'inspect', [], self::R)['state']);
        $this->assertSame(1, $calls);
        foreach ($files as $f) {
            $this->assertFileDoesNotExist($f);
        }
    }

    public function test_disconnect_does_not_retry_and_cleans_owned_files(): void
    {
        $this->assertTrue(class_exists(SshTransport::class));
        $calls = 0;
        $path = '';
        $runner = function ($argv) use (&$calls, &$path) {
            $calls++;
            $path = $argv[array_search('-i', $argv, true) + 1];
            throw new \RuntimeException('sensitive diagnostic must not escape');
        };
        try {
            (new SshTransport($this->config(), $runner))->request(self::B, 'inspect', [], self::R);
            $this->fail('disconnect accepted');
        } catch (TransportUncertain $e) {
            $this->assertStringNotContainsString('sensitive', $e->getMessage());
            $this->assertSame(self::R, $e->requestId);
        }
        $this->assertSame(1, $calls);
        $this->assertFileDoesNotExist($path);
    }

    public function test_invalid_endpoint_and_hostkey_refuse_before_runner(): void
    {
        $this->assertTrue(class_exists(SshTransport::class));
        foreach (['host' => '-oProxyCommand=bad', 'username' => 'root', 'port' => 0, 'host_key' => '', 'private_key' => ''] as $key => $value) {
            $calls = 0;
            $runner = function () use (&$calls) {
                $calls++;

                return '';
            };
            $rejected = false;
            try {
                (new SshTransport(array_replace($this->config(), [$key => $value]), $runner))->request(self::B, 'inspect', [], self::R);
            } catch (\InvalidArgumentException $e) {
                $rejected = true;
            }$this->assertTrue($rejected);
            $this->assertSame(0, $calls);
        }
    }

    public function test_expired_owned_key_files_are_reaped_but_foreign_replacements_survive(): void
    {
        $this->assertTrue(class_exists(PrivateFiles::class), 'private SSH files are missing');
        $config = $this->config();
        $files = new PrivateFiles($config['private_key'], 'fixture host');
        $key = $files->keyPath;
        $dir = dirname($key);
        $manifest = $dir . '/custody.json';
        $m = json_decode(file_get_contents($manifest), true);
        $m['created_at'] = time() - 1000;
        $m['pid'] = 2147483647;
        file_put_contents($manifest, json_encode($m));
        PrivateFiles::reap(dirname($dir), time());
        $this->assertFileDoesNotExist($key);
        $files = new PrivateFiles($config['private_key'], 'fixture host');
        $key = $files->keyPath;
        $known = $files->knownHostsPath;
        $old = $known . '.old';
        rename($known, $old);
        file_put_contents($known, 'foreign');
        $files->cleanup();
        $this->assertFileDoesNotExist($key);
        $this->assertSame('foreign', file_get_contents($known));
        unlink($known);
        unlink($old);
        @unlink(dirname($key) . '/custody.json');
        rmdir(dirname($key));
    }

    public function test_unknown_key_custody_does_not_report_confirmed_success(): void
    {
        $path = '';
        $runner = function ($argv) use (&$path) {
            $path = $argv[array_search('-i', $argv, true) + 1];
            chmod($path, 0400);

            return $this->response();
        };
        $uncertain = false;
        try {
            (new SshTransport($this->config(), $runner))->request(self::B, 'inspect', [], self::R);
        } catch (TransportUncertain $e) {
            $uncertain = true;
        }
        try {
            $this->assertTrue($uncertain, 'unknown key custody was silently ignored');
        } finally {
            if (is_file($path)) {
                chmod($path, 0600);
                unlink($path);
            }@unlink(dirname($path) . '/known_hosts');
            @unlink(dirname($path) . '/custody.json');
            @rmdir(dirname($path));
        }
    }

    public function test_oversized_or_mismatched_result_remains_uncertain(): void
    {
        $this->assertTrue(class_exists(SshTransport::class));
        foreach ([str_repeat(' ', 1048577), str_replace(self::B, self::R, $this->response())] as $raw) {
            $rejected = false;
            try {
                (new SshTransport($this->config(), fn () => $raw))->request(self::B, 'inspect', [], self::R);
            } catch (TransportUncertain $e) {
                $rejected = true;
            }$this->assertTrue($rejected);
        }
    }
}
