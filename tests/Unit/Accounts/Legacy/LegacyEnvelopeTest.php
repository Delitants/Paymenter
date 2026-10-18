<?php

namespace Tests\Unit\Accounts\Legacy;

use App\Services\Accounts\Legacy\BoundedLegacyJson;
use App\Services\Accounts\Legacy\LegacyEnvelope;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\LegacyReceipts\GoldenVectors;
use Tests\Fixtures\LegacyReceipts\LegacyReceiptFixture as Fixture;

final class LegacyEnvelopeTest extends TestCase
{
    public function test_reader_admits_exact_byte_boundary(): void
    {
        self::assertTrue(class_exists(BoundedLegacyJson::class));
        self::assertSame(['p' => str_repeat('x', 65528)], BoundedLegacyJson::decode('{"p":"' . str_repeat('x', 65528) . '"}', 65536));
    }

    public static function invalidReaders(): iterable
    {
        yield 'oversize' => ['{"p":"' . str_repeat('x', 65529) . '"}', 65536, 8, 256];
        yield 'depth nine' => ['{"a":[[[[[[[[]]]]]]]]}', 65536, 8, 256];
        yield 'too many fields' => [json_encode(array_combine(range('a', 'z'), range(1, 26))), 65536, 8, 25];
        yield 'nested item counts' => ['{"a":[{"b":1},2]}', 65536, 8, 3];
        yield 'decoded duplicate' => ['{"a":1,"\u0061":2}', 65536, 8, 256];
        yield 'duplicate' => ['{"a":1,"a":2}', 65536, 8, 256];
        yield 'utf8' => ["{\"a\":\"\xff\"}", 65536, 8, 256];
        yield 'byte cap invalid' => ['{}', 262145, 8, 256];
        yield 'zero cap' => ['{}', 0, 8, 256];
        yield 'invalid depth' => ['{}', 65536, 9, 256];
        yield 'invalid item limit' => ['{}', 65536, 8, 4097];
        yield 'numeric float' => ['{"a":1.5}', 65536, 8, 256];
    }

    #[DataProvider('invalidReaders')]
    public function test_decoder_refuses_unsafe_structure_before_shared_decode(string $bytes, int $cap, int $depth, int $items): void
    {
        $this->expectException(RuntimeException::class);
        BoundedLegacyJson::decode($bytes, $cap, $depth, $items);
    }

    public function test_depth_and_item_bounds_count_containers_and_children(): void
    {
        self::assertSame(['a' => [['b' => 1], 2]], BoundedLegacyJson::decode('{"a":[{"b":1},2]}', 65536, 3, 4));
        self::assertSame(['a' => '[[[[["{:'], BoundedLegacyJson::decode(json_encode(['a' => '[[[[["{:'], JSON_THROW_ON_ERROR), 65536, 1, 1));
    }

    public function test_new_schema_and_observation_preserve_economic_identity(): void
    {
        self::assertTrue(class_exists(LegacyEnvelope::class));
        $a = LegacyEnvelope::parse(Fixture::payload());
        $data = $a->data;
        $data['schema_version'] = 2;
        $data['nonce'] = 'delivery-two';
        $data['issuer'] = 'another-issuer';
        $data['not_before'] = '2026-02-01T00:00:00Z';
        $data['expires_at'] = '2026-02-02T00:00:00Z';
        $data['observation']['custody_id'] = 'custody-two';
        $data['observation']['raw_sha256'] = str_repeat('d', 64);
        $b = LegacyEnvelope::parse(CanonicalPolicy::bytes($data));
        self::assertSame($a->identity->key(), $b->identity->key());
        self::assertSame($a->economic->fingerprint(), $b->economic->fingerprint());
        self::assertSame($data, $b->data);
        self::assertSame(2, $b->schemaVersion);
    }

    public static function invalidEnvelopes(): iterable
    {
        foreach ([0, 3, '1', 1.0] as $version) {
            yield 'version ' . serialize($version) => [['schema_version' => $version], null];
        }
        foreach (['body', 'headers', 'token', 'padding'] as $field) {
            yield $field => [[$field => 'private'], null];
        }
        yield 'missing' => [[], 'economic'];
        yield 'wrong purpose' => [['purpose' => 'account-opening-fence'], null];
        yield 'too long nonce' => [['nonce' => str_repeat('n', 129)], null];
        yield 'invalid date' => [['expires_at' => '2026-01-32T00:00:00Z'], null];
        yield 'zero validity' => [['expires_at' => '2026-01-01T00:00:00Z'], null];
        yield 'overlong validity' => [['expires_at' => '2026-01-02T00:00:01Z'], null];
        yield 'bad nested observation' => [['observation' => ['custody_id' => 'one']], null];
        yield 'wrong identity type' => [['identity' => 'identity'], null];
        yield 'capture has parent' => [['economic' => [...GoldenVectors::economic(), 'parent_effect_key' => str_repeat('d', 64)]], null];
        yield 'refund needs parent' => [['identity' => [...GoldenVectors::identity(), 'effect_kind' => 'refund']], null];
    }

    #[DataProvider('invalidEnvelopes')]
    public function test_closed_envelope_refuses_unsupported_claims(array $override, ?string $remove): void
    {
        $data = json_decode(Fixture::payload(), true, 512, JSON_THROW_ON_ERROR);
        $data = [...$data, ...$override];
        if ($remove !== null) {
            unset($data[$remove]);
        }
        $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        // Sort without canonical helper's deliberate float rejection for the invalid-version case.
        ksort($data);
        $bytes = is_float($override['schema_version'] ?? null) ? json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : CanonicalPolicy::bytes($data);
        $this->expectException(\Exception::class);
        LegacyEnvelope::parse($bytes);
    }

    public function test_noncanonical_bytes_cannot_be_rewritten_as_signed_evidence(): void
    {
        $this->expectException(RuntimeException::class);
        LegacyEnvelope::parse("\n" . Fixture::payload());
    }
}
