<?php

namespace Tests\Unit\Accounts\Legacy;

use App\Services\Accounts\Legacy\EffectIdentity;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\LegacyReceipts\GoldenVectors;

final class EffectIdentityTest extends TestCase
{
    public function test_identity_contract_is_available(): void
    {
        self::assertTrue(class_exists(EffectIdentity::class), 'The permanent economic identity contract must exist.');
    }

    public function test_independent_frozen_vectors_and_every_identity_member(): void
    {
        foreach (GoldenVectors::vectors() as $vector) {
            $identity = EffectIdentity::fromArray($vector['identity']);
            self::assertSame($vector['effect_key'], $identity->key());
            self::assertSame($vector['identity'], $identity->data());
            foreach ($vector['identity'] as $field => $value) {
                $replacement = $field === 'effect_kind' ? 'refund' : $value . 'x';
                self::assertNotSame($identity->key(), EffectIdentity::fromArray([...$vector['identity'], $field => $replacement])->key(), $field);
            }
        }
    }

    public function test_case_and_leading_zeros_are_economic_identity(): void
    {
        $data = GoldenVectors::identity();
        $key = EffectIdentity::fromArray($data)->key();
        self::assertNotSame($key, EffectIdentity::fromArray([...$data, 'source_payment' => '1'])->key());
        self::assertNotSame($key, EffectIdentity::fromArray([...$data, 'merchant_binding' => strtoupper($data['merchant_binding'])])->key());
    }

    public static function invalidIdentities(): iterable
    {
        yield 'extra schema' => [['schema_version' => 1], null];
        yield 'extra observation' => [['custody_id' => 'delivery'], null];
        yield 'numeric identifier' => [['source_payment' => 1], null];
        yield 'empty' => [['source_payment' => ''], null];
        yield 'control' => [['source_payment' => "x\0y"], null];
        yield 'del' => [['source_payment' => "x\x7fy"], null];
        yield 'invalid UTF8' => [['source_payment' => "\xff"], null];
        yield 'too long' => [['source_payment' => str_repeat('x', 513)], null];
        yield 'unknown kind' => [['effect_kind' => 'authorization'], null];
        yield 'missing member' => [[], 'source_instance'];
    }

    #[DataProvider('invalidIdentities')]
    public function test_closed_identity_rejects_ambiguous_fields(array $override, ?string $remove): void
    {
        $value = [...GoldenVectors::identity(), ...$override];
        if ($remove !== null) {
            unset($value[$remove]);
        }
        $this->expectException(DomainException::class);
        EffectIdentity::fromArray($value);
    }
}
