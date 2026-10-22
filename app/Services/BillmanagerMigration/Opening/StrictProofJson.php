<?php

namespace App\Services\BillmanagerMigration\Opening;

use JsonException;
use RuntimeException;

final class StrictProofJson
{
    private int $offset = 0;

    private function __construct(private readonly string $bytes) {}

    public static function decode(string $bytes): array
    {
        try {
            json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
            $parser = new self($bytes);
            $parser->space();
            if (($bytes[$parser->offset] ?? '') !== '{') {
                throw new RuntimeException('Proof root must be an object.');
            }
            $value = $parser->value(0);
            $parser->space();
            if ($parser->offset !== strlen($bytes)) {
                throw new RuntimeException('Trailing proof tokens.');
            }

            return $value;
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid proof JSON.', previous: $e);
        }
    }

    private function space(): void
    {
        while (isset($this->bytes[$this->offset]) && str_contains(" \n\r\t", $this->bytes[$this->offset])) {
            $this->offset++;
        }
    }

    private function value(int $depth): mixed
    {
        if ($depth > 510) {
            throw new RuntimeException('Proof nesting is too deep.');
        }
        $this->space();
        $char = $this->bytes[$this->offset] ?? '';
        if ($char === '"') {
            return $this->string();
        }
        if ($char === '{' || $char === '[') {
            $object = $char === '{';
            $close = $object ? '}' : ']';
            $this->offset++;
            $result = [];
            $seen = [];
            $this->space();
            if (($this->bytes[$this->offset] ?? '') === $close) {
                $this->offset++;

                return $result;
            }
            do {
                $this->space();
                $key = $object ? $this->string() : count($result);
                if ($object) {
                    if (isset($seen[$key])) {
                        throw new RuntimeException('Duplicate decoded proof key.');
                    }
                    $seen[$key] = true;
                    $this->space();
                    if (($this->bytes[$this->offset++] ?? '') !== ':') {
                        throw new RuntimeException('Invalid proof object.');
                    }
                }
                $result[$key] = $this->value($depth + 1);
                if ($object && self::monetaryKey($key) && (is_int($result[$key]) || is_float($result[$key]))) {
                    throw new RuntimeException('Financial proof values must be decimal strings.');
                }
                $this->space();
                $delimiter = $this->bytes[$this->offset++] ?? '';
                if ($delimiter === $close) {
                    return $result;
                }
                if ($delimiter !== ',') {
                    throw new RuntimeException('Invalid proof delimiter.');
                }
            } while (true);
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $token => $value) {
            if (substr($this->bytes, $this->offset, strlen($token)) === $token) {
                $this->offset += strlen($token);

                return $value;
            }
        }
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A', $this->bytes, $match, 0, $this->offset)) {
            $this->offset += strlen($match[0]);
            $value = json_decode($match[0], true, 512, JSON_THROW_ON_ERROR);
            if (!is_int($value)) {
                throw new RuntimeException('Floating-point or oversized proof numbers are forbidden.');
            }

            return $value;
        }
        throw new RuntimeException('Invalid proof value.');
    }

    private function string(): string
    {
        if (($this->bytes[$this->offset] ?? '') !== '"') {
            throw new RuntimeException('Proof key must be a string.');
        }
        $start = $this->offset++;
        while (isset($this->bytes[$this->offset])) {
            $char = $this->bytes[$this->offset++];
            if ($char === '\\') {
                $this->offset++;
            } elseif ($char === '"') {
                return json_decode(substr($this->bytes, $start, $this->offset - $start), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        throw new RuntimeException('Unterminated proof string.');
    }

    public static function monetaryKey(string $key): bool
    {
        return in_array($key, ['opening', 'limit', 'credit_limit', 'borrowing_limit', 'principal', 'debt'], true) || str_ends_with($key, 'amount') || str_ends_with($key, 'balance');
    }
}
