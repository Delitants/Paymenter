<?php

namespace Paymenter\Extensions\Others\Bind;

final class PrivateFiles
{
    public readonly string $keyPath;

    public readonly string $knownHostsPath;

    private string $directory;

    private array $custody;

    public function __construct(string $key, string $knownHosts, ?string $base = null)
    {
        $base ??= sys_get_temp_dir() . '/paymenter-bind-ssh-' . posix_geteuid();
        $old = umask(0077);
        try {
            if (!is_dir($base) && !mkdir($base, 0700, true)) {
                throw new \RuntimeException('Cannot create private SSH directory');
            }
            self::privateDirectory($base);
            self::reap($base, time());
            $this->directory = $base . '/attempt-' . bin2hex(random_bytes(16));
            if (!mkdir($this->directory, 0700)) {
                throw new \RuntimeException('Cannot create private SSH attempt');
            }
            $this->keyPath = $this->directory . '/identity';
            $this->knownHostsPath = $this->directory . '/known_hosts';
            $this->custody = ['directory' => self::statIdentity($this->directory), 'created_at' => time(), 'pid' => getmypid(), 'process_start' => self::processStart(getmypid()), 'boot' => trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id')), 'files' => []];
            foreach (['identity' => $key, 'known_hosts' => $knownHosts] as $name => $raw) {
                self::save($this->directory . '/' . $name, $raw);
                $this->custody['files'][$name] = self::fileIdentity($this->directory . '/' . $name);
            }
            self::save($this->directory . '/custody.json', json_encode($this->custody, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            if (isset($this->directory, $this->custody)) {
                self::remove($this->directory, $this->custody);
            }
            throw $e;
        } finally {
            umask($old);
        }
    }

    private static function processStart(int $pid): ?string
    {
        $raw = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($raw)) {
            return null;
        }
        $pos = strrpos($raw, ')');
        $fields = preg_split('/\s+/', trim(substr($raw, $pos + 1)));

        return $fields[19] ?? null;
    }

    private static function privateDirectory(string $path): void
    {
        clearstatcache(true, $path);
        $s = lstat($path);
        if (is_link($path) || ($s['mode'] & 0170000) !== 0040000 || $s['uid'] !== posix_geteuid() || ($s['mode'] & 0777) !== 0700) {
            throw new \RuntimeException('Unsafe private SSH directory');
        }
    }

    private static function statIdentity(string $path): array
    {
        clearstatcache(true, $path);
        $s = lstat($path);

        return array_intersect_key($s, array_flip(['dev', 'ino', 'uid', 'gid', 'mode']));
    }

    private static function fileIdentity(string $path): array
    {
        clearstatcache(true, $path);
        $s = lstat($path);
        if (is_link($path) || ($s['mode'] & 0170000) !== 0100000 || $s['uid'] !== posix_geteuid() || ($s['mode'] & 0777) !== 0600 || $s['nlink'] !== 1 || $s['size'] > 32768) {
            throw new \RuntimeException('Unsafe private SSH file');
        }

        return self::statIdentity($path) + ['bytes' => $s['size'], 'sha256' => hash_file('sha256', $path)];
    }

    private static function save(string $path, string $raw): void
    {
        $f = fopen($path, 'x');
        if (!$f) {
            throw new \RuntimeException('Cannot persist private SSH file');
        }
        $created = fstat($f);
        try {
            if (fwrite($f, $raw) !== strlen($raw) || !fflush($f) || !fsync($f) || !chmod($path, 0600)) {
                throw new \RuntimeException('Cannot persist private SSH file');
            }
        } catch (\Throwable $error) {
            clearstatcache(true, $path);
            $current = @lstat($path);
            if ($current && $current['dev'] === $created['dev'] && $current['ino'] === $created['ino'] && $current['uid'] === $created['uid'] && !is_link($path)) {
                unlink($path);
            }
            throw $error;
        } finally {
            fclose($f);
        }
    }

    private static function remove(string $dir, array $custody): void
    {
        try {
            self::privateDirectory($dir);
            if (self::statIdentity($dir) !== ($custody['directory'] ?? null)) {
                return;
            }
            foreach (['identity', 'known_hosts'] as $name) {
                $path = $dir . '/' . $name;
                try {
                    if (isset($custody['files'][$name]) && self::fileIdentity($path) === $custody['files'][$name]) {
                        unlink($path);
                    }
                } catch (\Throwable) {
                    // Retain substituted/unowned entries; never expand the cleanup scope.
                }
            }
            $remaining = array_diff(scandir($dir), ['.', '..', 'custody.json']);
            if ($remaining === []) {
                if (is_file($dir . '/custody.json') && !is_link($dir . '/custody.json')) {
                    unlink($dir . '/custody.json');
                }
                rmdir($dir);
            }
        } catch (\Throwable) {
            // Unknown custody must not lead to a broader removal.
        }
    }

    public function cleanup(): void
    {
        self::remove($this->directory, $this->custody);
        clearstatcache(true, $this->keyPath);
        if (file_exists($this->keyPath) || is_link($this->keyPath)) {
            throw new \RuntimeException('Private SSH key cleanup not confirmed');
        }
    }

    public static function reap(string $base, int $now): void
    {
        self::privateDirectory($base);
        $count = 0;
        foreach (new \DirectoryIterator($base) as $entry) {
            if ($count++ >= 100) {
                break;
            }
            if (!preg_match('/^attempt-[a-f0-9]{32}$/D', $entry->getFilename())) {
                continue;
            }
            $dir = $entry->getPathname();
            try {
                self::privateDirectory($dir);
                self::fileIdentity($dir . '/custody.json');
                $m = json_decode(file_get_contents($dir . '/custody.json'), true, 16, JSON_THROW_ON_ERROR);
                if (!is_int($m['created_at'] ?? null) || $now - $m['created_at'] < 600 || !is_int($m['pid'] ?? null) || $m['pid'] < 1) {
                    continue;
                }
                $boot = trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id'));
                $start = self::processStart($m['pid']);
                $unknownLive = $start === null && (@posix_kill($m['pid'], 0) || posix_get_last_error() === 1);
                if ($boot !== '' && ($m['boot'] ?? null) === $boot && ($unknownLive || ($start !== null && $start === ($m['process_start'] ?? null)))) {
                    continue;
                }
                self::remove($dir, $m);
            } catch (\Throwable) {
                // Incomplete/unknown records are kept for administrator reconciliation.
            }
        }
    }
}
