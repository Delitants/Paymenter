<?php

namespace App\Services\BillmanagerMigration\Opening;

use RuntimeException;

/** One verification's bounded original control documents; never financial authority. */
final class ControlProofGraph
{
    private const CONTROL_BYTES = 65536;

    private const HISTORY_BYTES = 4194304;

    private const TOTAL_BYTES = 33554432;

    private const MAX_DEPTH = 8;

    private int $bytes = 0;

    /** @var array<string,array{sha256:string,device:int,inode:int}> */
    private array $documents = [];

    /** @var array<string,array{sha256:string,device:int,inode:int}> */
    private array $paths = [];

    /** @return array{bytes:string,sha256:string,device:int,inode:int} */
    public function read(array $reference, string $kind, array $ancestors = []): array
    {
        ProofSchema::keys($reference, ['path', 'sha256']);
        $expected = ProofSchema::hash($reference['sha256']);
        if (!is_string($reference['path'])) {
            throw new RuntimeException('Invalid control proof path.');
        }
        $file = $this->load($reference['path'], $kind, $ancestors);
        if (!hash_equals($expected, $file['sha256'])) {
            throw new RuntimeException('Control proof checksum changed.');
        }

        return $file;
    }

    /** @return array{bytes:string,sha256:string,device:int,inode:int} */
    public function readPath(string $path, string $kind): array
    {
        return $this->load($path, $kind, []);
    }

    /** @return array{bytes:int,documents:int} */
    public function usage(): array
    {
        return ['bytes' => $this->bytes, 'documents' => count($this->documents)];
    }

    private function load(string $path, string $kind, array $ancestors): array
    {
        $limit = match ($kind) {
            'control' => self::CONTROL_BYTES,
            'history' => self::HISTORY_BYTES,
            default => throw new RuntimeException('Unknown control proof kind.'),
        };
        if (!array_is_list($ancestors) || count($ancestors) > self::MAX_DEPTH || $path === '' || $path[0] !== '/' || str_contains($path, '://') || str_contains($path, "\0")) {
            throw new RuntimeException('Control proof reference depth or path is invalid.');
        }
        clearstatcache();
        $stat = @lstat($path);
        foreach ($ancestors as $ancestor) {
            if (!is_string($ancestor) || $ancestor === '' || $ancestor[0] !== '/' || str_contains($ancestor, '://') || str_contains($ancestor, "\0")) {
                throw new RuntimeException('Invalid control proof ancestry.');
            }
            $parent = @lstat($ancestor);
            if ($ancestor === $path || ($parent && $stat && $parent['dev'] === $stat['dev'] && $parent['ino'] === $stat['ino'])) {
                throw new RuntimeException('Control proof reference cycle.');
            }
        }
        $identity = $stat ? $stat['dev'] . ':' . $stat['ino'] : '';
        $known = $this->documents[$identity] ?? null;
        // Known originals may be revalidated at a full budget. A new original
        // must fit the remaining allowance before any content is allocated.
        $file = PrivateProofFile::read($path, 0, $known ? $limit : min($limit, self::TOTAL_BYTES - $this->bytes));
        $facts = ['sha256' => $file['sha256'], 'device' => $file['device'], 'inode' => $file['inode']];
        if ((isset($this->paths[$path]) && $this->paths[$path] !== $facts) || ($known !== null && $known !== $facts)) {
            throw new RuntimeException('Control proof identity changed within verification.');
        }
        $openedIdentity = $file['device'] . ':' . $file['inode'];
        if ($openedIdentity !== $identity) {
            throw new RuntimeException('Control proof changed before bounded open.');
        }
        if (!isset($this->documents[$openedIdentity])) {
            $this->bytes += strlen($file['bytes']);
            $this->documents[$openedIdentity] = $facts;
        }
        $this->paths[$path] = $facts;

        return $file;
    }
}
