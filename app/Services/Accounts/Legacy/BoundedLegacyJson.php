<?php

namespace App\Services\Accounts\Legacy;

use App\Services\BillmanagerMigration\Opening\StrictProofJson;
use RuntimeException;

/** Allocation/depth/item admission before the shared strict decoder. */
final class BoundedLegacyJson
{
    private int $offset = 0;

    private int $items = 0;

    private function __construct(private readonly string $bytes, private readonly int $depthLimit, private readonly int $itemLimit) {}

    public static function decode(string $bytes, int $byteLimit, int $depthLimit = 8, int $itemLimit = 256): array
    {
        if ($byteLimit < 1 || $byteLimit > 262144 || $depthLimit < 1 || $depthLimit > 8 || $itemLimit < 1 || $itemLimit > 4096 || strlen($bytes) > $byteLimit) {
            throw new RuntimeException('Legacy JSON admission bounds exceeded.');
        }
        $scanner = new self($bytes, $depthLimit, $itemLimit);
        $scanner->space();
        if (($bytes[$scanner->offset] ?? '') !== '{') {
            throw new RuntimeException('Legacy JSON root must be an object.');
        }
        $scanner->value(0);
        $scanner->space();
        if ($scanner->offset !== strlen($bytes)) {
            throw new RuntimeException('Trailing legacy JSON bytes.');
        }

        return StrictProofJson::decode($bytes);
    }

    private function space(): void
    {
        while (isset($this->bytes[$this->offset]) && str_contains(" \t\r\n", $this->bytes[$this->offset])) {
            $this->offset++;
        }
    }

    private function string(): void
    {
        if (($this->bytes[$this->offset++] ?? '') !== '"') {
            throw new RuntimeException('Expected legacy JSON string.');
        }
        while (isset($this->bytes[$this->offset])) {
            $c = $this->bytes[$this->offset++];
            if ($c === '\\') {
                $this->offset++;
            } elseif ($c === '"') {
                return;
            }
        }
        throw new RuntimeException('Unterminated legacy JSON string.');
    }

    private function value(int $depth): void
    {
        $this->space();
        $c = $this->bytes[$this->offset] ?? '';
        if ($c === '"') {
            $this->string();

            return;
        }
        if ($c === '{' || $c === '[') {
            if (++$depth > $this->depthLimit) {
                throw new RuntimeException('Legacy JSON nesting exceeded.');
            }
            $object = $c === '{';
            $end = $object ? '}' : ']';
            $this->offset++;
            $this->space();
            if (($this->bytes[$this->offset] ?? '') === $end) {
                $this->offset++;

                return;
            }
            while (true) {
                if (++$this->items > $this->itemLimit) {
                    throw new RuntimeException('Legacy JSON item budget exceeded.');
                }
                $this->space();
                if ($object) {
                    $this->string();
                    $this->space();
                    if (($this->bytes[$this->offset++] ?? '') !== ':') {
                        throw new RuntimeException('Expected legacy JSON field delimiter.');
                    }
                }
                $this->value($depth);
                $this->space();
                $delimiter = $this->bytes[$this->offset++] ?? '';
                if ($delimiter === $end) {
                    return;
                }
                if ($delimiter !== ',') {
                    throw new RuntimeException('Invalid legacy JSON container.');
                }
            }
        }
        $start = $this->offset;
        while (isset($this->bytes[$this->offset]) && !str_contains(",]} \t\r\n", $this->bytes[$this->offset])) {
            $this->offset++;
        }
        if ($start === $this->offset) {
            throw new RuntimeException('Missing legacy JSON value.');
        }
    }
}
