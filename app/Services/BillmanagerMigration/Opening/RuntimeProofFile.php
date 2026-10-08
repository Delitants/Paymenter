<?php

namespace App\Services\BillmanagerMigration\Opening;

use RuntimeException;

final class RuntimeProofFile
{
    /** @return array{bytes:string,sha256:string,device:int,inode:int} */
    public static function read(string $path, int $readerGid): array
    {
        if ($readerGid < 0 || $path === '' || $path[0] !== '/' || str_contains($path, '://') || str_contains($path, "\0") ||
            array_intersect(explode('/', substr($path, 1)), ['', '.', '..'])) {
            throw new RuntimeException('An absolute ordinary runtime proof path and reader group are required.');
        }
        clearstatcache();
        $stat = @lstat($path);
        $parent = @lstat(dirname($path));
        if (!$stat || !$parent || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== 0640 ||
            $stat['uid'] !== 0 || $stat['gid'] !== $readerGid || $stat['nlink'] !== 1 ||
            $parent['uid'] !== 0 || $parent['gid'] !== $readerGid || ($parent['mode'] & 0007) !== 0) {
            throw new RuntimeException('Protected service-readable runtime proof ownership and permissions are required.');
        }
        $cursor = '/';
        foreach (['', ...explode('/', substr($path, 1))] as $part) {
            $cursor = $part === '' ? '/' : rtrim($cursor, '/') . '/' . $part;
            $entry = @lstat($cursor);
            if (!$entry || is_link($cursor) || $entry['uid'] !== 0 ||
                ($cursor !== $path && (($entry['mode'] & 0170000) !== 0040000 || ($entry['mode'] & 0022) !== 0))) {
                throw new RuntimeException('Runtime proof ancestors must be root-owned and non-writable by the service.');
            }
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Protected runtime proof cannot be read.');
        }
        try {
            $opened = fstat($file);
            if (!$opened) {
                throw new RuntimeException('Runtime proof could not be inspected.');
            }
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime', 'nlink'] as $key) {
                if ($opened[$key] !== $stat[$key]) {
                    throw new RuntimeException('Runtime proof changed during open.');
                }
            }
            $bytes = stream_get_contents($file);
            $after = fstat($file);
            clearstatcache(true, $path);
            $named = @lstat($path);
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime', 'nlink'] as $key) {
                if (!$after || !$named || $opened[$key] !== $after[$key] || $after[$key] !== $named[$key]) {
                    throw new RuntimeException('Runtime proof changed during read.');
                }
            }
            if ($bytes === false || strlen($bytes) !== $opened['size']) {
                throw new RuntimeException('Incomplete runtime proof.');
            }

            return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'device' => $opened['dev'], 'inode' => $opened['ino']];
        } finally {
            fclose($file);
        }
    }
}
