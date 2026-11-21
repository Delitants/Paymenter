<?php

namespace App\Services\BillmanagerMigration\Opening;

use RuntimeException;

final class OpeningReportFile
{
    public static function write(string $path, array $report): void
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '://') || str_contains($path, "\0") || realpath(dirname($path)) !== dirname($path) ||
            fileowner(dirname($path)) !== 0 || (fileperms(dirname($path)) & 0777) !== 0700) {
            throw new RuntimeException('A new private root-owned report destination is required.');
        }
        $cursor = '';
        foreach (explode('/', trim(dirname($path), '/')) as $part) {
            $cursor .= '/' . $part;
            $entry = lstat($cursor);
            if (!$entry || $entry['uid'] !== 0 || is_link($cursor) || ($entry['mode'] & 0170000) !== 0040000 || (($entry['mode'] & 0022) !== 0 && ($entry['mode'] & 01000) === 0)) {
                throw new RuntimeException('Untrusted report ancestor.');
            }
        }
        $mask = umask(0077);
        try {
            $file = fopen($path, 'x');
        } finally {
            umask($mask);
        }
        if (!$file) {
            throw new RuntimeException('Private receipt export could not be created.');
        }
        try {
            $bytes = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || !fsync($file)) {
                throw new RuntimeException('Private receipt export was incomplete.');
            }
        } finally {
            fclose($file);
        }
        PrivateProofFile::read($path, 0);
        $directory = fopen(dirname($path), 'r');
        if (!$directory) {
            throw new RuntimeException('Private receipt export directory could not be synced.');
        }
        try {
            if (!fsync($directory)) {
                throw new RuntimeException('Private receipt export directory could not be synced.');
            }
        } finally {
            fclose($directory);
        }
    }
}
