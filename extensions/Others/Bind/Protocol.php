<?php

namespace Paymenter\Extensions\Others\Bind;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class Protocol
{
    public const VERSION = 'paymenter-bind-v1';

    public const INPUT_LIMIT = 32768;

    public const OUTPUT_LIMIT = 1048576;

    public static function need(bool $condition): void
    {
        if (!$condition) {
            throw new InvalidArgumentException('Invalid BIND protocol');
        }
    }

    public static function uuid(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $v) === 1;
    }

    private static function keys(mixed $v, array $keys): void
    {
        self::need($v instanceof stdClass);
        $actual = array_keys((array) $v);
        sort($actual);
        sort($keys);
        self::need($actual === $keys);
    }

    public static function encode(string $requestId, string $bindingId, string $operation, array $payload): string
    {
        try {
            $raw = json_encode(['protocol' => self::VERSION, 'request_id' => $requestId, 'binding_id' => $bindingId, 'operation' => $operation, 'payload' => (object) $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid BIND JSON', previous: $e);
        }
        self::parseRequest($raw);

        return $raw;
    }

    public static function parseRequest(string $raw): array
    {
        $r = DuplicateJson::decode($raw, self::INPUT_LIMIT);
        self::keys($r, ['protocol', 'request_id', 'binding_id', 'operation', 'payload']);
        self::need($r->protocol === self::VERSION && self::uuid($r->request_id) && self::uuid($r->binding_id) && in_array($r->operation, ['inspect', 'replace_records', 'prepare_zone', 'prepare_secondary', 'inspect_request'], true));
        $p = $r->payload;
        if ($r->operation === 'replace_records') {
            self::keys($p, ['expected_serial', 'expected_revision', 'changes']);
            self::need(is_int($p->expected_serial) && $p->expected_serial >= 0 && $p->expected_serial <= 4294967295 && is_string($p->expected_revision) && preg_match('/^[a-f0-9]{64}$/D', $p->expected_revision) === 1 && is_array($p->changes) && count($p->changes) >= 1 && count($p->changes) <= 100);
            $seen = [];
            foreach ($p->changes as $c) {
                self::keys($c, ['owner', 'type', 'ttl', 'records']);
                self::need(is_string($c->owner) && mb_strlen($c->owner) > 0 && mb_strlen($c->owner) <= 253 && in_array($c->type, ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'SRV'], true) && is_int($c->ttl) && $c->ttl >= 1 && $c->ttl <= 2147483647 && is_array($c->records) && count($c->records) <= 500);
                foreach ($c->records as $value) {
                    self::need(is_string($value) && $value !== '');
                }
                $id = strtolower($c->owner) . '|' . $c->type;
                self::need(!isset($seen[$id]));
                $seen[$id] = true;
            }
        } elseif ($r->operation === 'inspect_request') {
            self::keys($p, ['request_id']);
            self::need(self::uuid($p->request_id));
        } else {
            self::keys($p, []);
        }

        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }

    public static function decode(string $raw, string $requestId, string $bindingId): array
    {
        $r = DuplicateJson::decode($raw, self::OUTPUT_LIMIT);
        self::keys($r, ['protocol', 'request_id', 'binding_id', 'state', 'code', 'data']);
        self::need($r->protocol === self::VERSION && $r->request_id === $requestId && $r->binding_id === $bindingId && self::uuid($requestId) && self::uuid($bindingId) && in_array($r->state, ['observed', 'committed', 'pending_replication', 'rejected', 'uncertain'], true) && is_string($r->code) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $r->code) === 1 && $r->data instanceof stdClass);

        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }
}

/** Structural scan preserves duplicate-key detection before associative decoding. */
final class DuplicateJson
{
    private int $offset = 0;

    private function __construct(private readonly string $raw) {}

    public static function decode(string $raw, int $limit): mixed
    {
        Protocol::need(strlen($raw) > 0 && strlen($raw) <= $limit);
        try {
            $value = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            $scanner = new self($raw);
            $scanner->value(0);
            $scanner->space();
            Protocol::need($scanner->offset === strlen($raw));
            self::finite($value);

            return $value;
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid BIND JSON', previous: $e);
        }
    }

    private static function finite(mixed $v): void
    {
        if (is_float($v)) {
            Protocol::need(is_finite($v));
        } elseif (is_array($v) || $v instanceof stdClass) {
            foreach ($v as $item) {
                self::finite($item);
            }
        }
    }

    private function space(): void
    {
        while (isset($this->raw[$this->offset]) && str_contains(" \n\r\t", $this->raw[$this->offset])) {
            $this->offset++;
        }
    }

    private function string(): string
    {
        $start = $this->offset++;
        while (isset($this->raw[$this->offset])) {
            $c = $this->raw[$this->offset++];
            if ($c === '\\') {
                $this->offset++;
            } elseif ($c === '"') {
                return json_decode(substr($this->raw, $start, $this->offset - $start), true, 32, JSON_THROW_ON_ERROR);
            }
        }
        throw new InvalidArgumentException('Invalid BIND JSON string');
    }

    private function value(int $depth): void
    {
        Protocol::need($depth < 32);
        $this->space();
        $c = $this->raw[$this->offset] ?? '';
        if ($c === '"') {
            $this->string();
        } elseif ($c === '{' || $c === '[') {
            $object = $c === '{';
            $end = $object ? '}' : ']';
            $seen = [];
            $this->offset++;
            $this->space();
            if (($this->raw[$this->offset] ?? '') === $end) {
                $this->offset++;

                return;
            }
            while (true) {
                $this->space();
                if ($object) {
                    Protocol::need(($this->raw[$this->offset] ?? '') === '"');
                    $key = $this->string();
                    Protocol::need(!array_key_exists($key, $seen));
                    $seen[$key] = true;
                    $this->space();
                    Protocol::need(($this->raw[$this->offset++] ?? '') === ':');
                }
                $this->value($depth + 1);
                $this->space();
                $next = $this->raw[$this->offset++] ?? '';
                if ($next === $end) {
                    return;
                }
                Protocol::need($next === ',');
            }
        } else {
            Protocol::need(preg_match('/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/A', $this->raw, $m, 0, $this->offset) === 1);
            $this->offset += strlen($m[0]);
        }
    }
}
