<?php

namespace App\Services\BillmanagerMigration\Opening;

use RuntimeException;

final class PrivateProofFile
{
    /** @return array{bytes:string,sha256:string,device:int,inode:int} */
    public static function read(string $path, int $ownerUid): array
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '://') || str_contains($path, "\0") || str_contains($path, '/../') || str_contains($path, '/./')) {
            throw new RuntimeException('An absolute ordinary proof path is required.');
        }
        clearstatcache();
        $stat = @lstat($path);
        $parent = @lstat(dirname($path));
        if (!$stat || !$parent || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600 || $stat['uid'] !== $ownerUid ||
            ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0777) !== 0700 || $parent['uid'] !== $ownerUid || $stat['nlink'] !== 1) {
            throw new RuntimeException('Private ordinary proof ownership and permissions are required.');
        }
        $cursor = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            $cursor .= '/' . $part;
            $entry = @lstat($cursor);
            if (!$entry || is_link($cursor) || ($cursor !== $path && ($entry['mode'] & 0170000) !== 0040000) ||
                ($cursor !== $path && !in_array($entry['uid'], [0, $ownerUid], true)) ||
                ($cursor !== $path && ($entry['mode'] & 0022) !== 0 && !(($entry['mode'] & 01000) !== 0 && $entry['uid'] === 0))) {
                throw new RuntimeException('Untrusted or symbolic proof ancestor.');
            }
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Private proof cannot be read.');
        }
        try {
            $opened = fstat($file);
            if (!$opened || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'] || $opened['mode'] !== $stat['mode'] || $opened['uid'] !== $stat['uid']) {
                throw new RuntimeException('Private proof changed during open.');
            }
            $bytes = stream_get_contents($file);
            $after = fstat($file);
            clearstatcache(true, $path);
            $named = @lstat($path);
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime', 'nlink'] as $key) {
                if ($opened[$key] !== $after[$key] || !$named || $after[$key] !== $named[$key]) {
                    throw new RuntimeException('Private proof changed during read.');
                }
            }
            if ($bytes === false || strlen($bytes) !== $opened['size']) {
                throw new RuntimeException('Incomplete private proof.');
            }

            return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'device' => $opened['dev'], 'inode' => $opened['ino']];
        } finally {
            fclose($file);
        }
    }
}
