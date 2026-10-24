<?php

namespace Tests\Unit\Bind;

use Paymenter\Extensions\Others\Bind\Protocol;
use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
    private const R = '00000000-0000-4000-8000-000000000001';

    private const B = '00000000-0000-4000-8000-000000000002';

    public function test_shared_requests_round_trip(): void
    {
        $this->assertTrue(class_exists(Protocol::class), 'strict PHP protocol is missing');
        $fixtures = json_decode(file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Bind/protocol-v1.json'), true);
        foreach ($fixtures['requests'] as $r) {
            $raw = Protocol::encode($r['request_id'], $r['binding_id'], $r['operation'], $r['payload']);
            $this->assertEquals($r, json_decode($raw, true));
        }
    }

    public function test_duplicate_keys_and_mismatched_response_reject(): void
    {
        $this->assertTrue(class_exists(Protocol::class), 'strict PHP protocol is missing');
        $good = ['protocol' => 'paymenter-bind-v1', 'request_id' => self::R, 'binding_id' => self::B, 'state' => 'observed', 'code' => 'OK', 'data' => (object) []];
        $raw = json_encode($good);
        $this->assertSame('OK', Protocol::decode($raw, self::R, self::B)['code']);
        foreach ([$raw . '{}', str_replace('"data":{}', '"data":{},"code":"OK"', $raw), str_replace('"data":{}', '"data":{"a":1,"a":2}', $raw), str_replace(self::B, self::R, $raw), str_replace('observed', 'made_up', $raw), str_repeat(' ', 1048577), "\xff"] as $bad) {
            $rejected = false;
            try {
                Protocol::decode($bad, self::R, self::B);
            } catch (\InvalidArgumentException $e) {
                $rejected = true;
            }$this->assertTrue($rejected, 'unsafe result accepted');
        }
    }

    public function test_encoding_invalid_utf8_rejects_with_protocol_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Protocol::encode(self::R, self::B, 'replace_records', ['expected_serial' => 1, 'expected_revision' => str_repeat('a', 64), 'changes' => [['owner' => "\xff", 'type' => 'TXT', 'ttl' => 300, 'records' => ['"hello"']]]]);
    }

    public function test_invalid_payloads_reject(): void
    {
        $this->assertTrue(class_exists(Protocol::class));
        foreach ([['inspect', ['host' => '192.0.2.1']], ['shell', []], ['replace_records', ['expected_serial' => true, 'expected_revision' => str_repeat('a', 64), 'changes' => []]]] as [$op,$p]) {
            $rejected = false;
            try {
                Protocol::encode(self::R, self::B, $op, $p);
            } catch (\InvalidArgumentException $e) {
                $rejected = true;
            }$this->assertTrue($rejected);
        }
    }
}
