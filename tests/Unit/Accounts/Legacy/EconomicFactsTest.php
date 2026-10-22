<?php

namespace Tests\Unit\Accounts\Legacy;

use App\Services\Accounts\Legacy\EconomicFacts;
use App\Services\Accounts\Legacy\EffectIdentity;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\LegacyReceipts\GoldenVectors;

final class EconomicFactsTest extends TestCase
{
    public function test_economic_contract_is_available(): void
    {
        self::assertTrue(class_exists(EconomicFacts::class), 'The economic facts contract must exist.');
    }

    public function test_frozen_economic_vectors_preserve_exact_signed_decomposition(): void
    {
        foreach (GoldenVectors::vectors() as $vector) {
            $facts = EconomicFacts::fromArray($vector['economic']);
            self::assertSame($vector['economic_sha256'], $facts->fingerprint());
            self::assertSame($vector['economic'], $facts->data());
            self::assertSame('5.5001', $facts->delta());
        }
    }

    public function test_amount_changes_fingerprint_without_changing_identity(): void
    {
        $facts = GoldenVectors::economic();
        $changed = [...$facts, 'wallet_delta' => '6.5001', 'wallet_components' => [['component_id' => 'net', 'delta' => '6.5001']]];
        self::assertNotSame(EconomicFacts::fromArray($facts)->fingerprint(), EconomicFacts::fromArray($changed)->fingerprint());
        self::assertSame(GoldenVectors::vectors()[0]['effect_key'], EffectIdentity::fromArray(GoldenVectors::identity())->key());
    }

    public function test_large_components_may_cancel_after_unbounded_intermediate_sum(): void
    {
        $value = [...GoldenVectors::economic(), 'wallet_delta' => '0.0000', 'wallet_components' => [
            ['component_id' => 'a', 'delta' => '999999999999999.0000'],
            ['component_id' => 'b', 'delta' => '999999999999999.0000'],
            ['component_id' => 'c', 'delta' => '-999999999999999.0000'],
            ['component_id' => 'd', 'delta' => '-999999999999999.0000'],
        ]];
        self::assertSame('0.0000', EconomicFacts::fromArray($value)->delta());
    }

    public static function invalidEconomics(): iterable
    {
        foreach ([5.5, 5, '5.50010', '5.5', '05.5001', '+5.5001', '-0.0000', '1e1', '1000000000000000.0000'] as $value) {
            yield 'wallet ' . serialize($value) => [['wallet_delta' => $value]];
        }
        foreach (['01', '+1', '1e2', '1.0', '-0', '-0.00', '.5', '0.1234567890123456789', str_repeat('1', 31)] as $amount) {
            yield 'provider ' . $amount => [['provider_components' => [['component_id' => 'paid', 'amount' => $amount]]]];
        }
        yield 'numeric provider' => [['provider_components' => [['component_id' => 'paid', 'amount' => 5]]]];
        yield 'extra version' => [['schema_version' => 1]];
        yield 'observation' => [['raw_sha256' => str_repeat('a', 64)]];
        yield 'sum mismatch' => [['wallet_delta' => '5.5000']];
        yield 'empty components' => [['wallet_components' => []]];
        yield 'duplicate wallet IDs' => [['wallet_components' => [['component_id' => 'a', 'delta' => '7.5001'], ['component_id' => 'a', 'delta' => '-2.0000']]]];
        yield 'unsorted wallet IDs' => [['wallet_components' => [['component_id' => 'z', 'delta' => '7.5001'], ['component_id' => 'a', 'delta' => '-2.0000']]]];
        yield 'unsorted provider IDs' => [['provider_components' => [['component_id' => 'z', 'amount' => '1'], ['component_id' => 'a', 'amount' => '2']]]];
        yield 'duplicate provider IDs' => [['provider_components' => [['component_id' => 'a', 'amount' => '1'], ['component_id' => 'a', 'amount' => '2']]]];
        yield 'numeric currency' => [['wallet_currency' => 840]];
        yield 'lowercase currency' => [['provider_currency' => 'usd']];
        yield 'invalid parent' => [['parent_effect_key' => str_repeat('A', 64)]];
        yield 'invalid hash' => [['allocation_evidence_sha256' => 'not-a-hash']];
        yield 'final overflow' => [['wallet_components' => [['component_id' => 'a', 'delta' => '999999999999999.0000'], ['component_id' => 'b', 'delta' => '999999999999999.0000']]]];
        yield 'too many components' => [['provider_components' => array_fill(0, 17, ['component_id' => 'a', 'amount' => '1'])]];
        yield 'unknown component key' => [['provider_components' => [['component_id' => 'a', 'amount' => '1', 'body' => 'private']]]];
        yield 'missing field' => [[], 'source_account'];
    }

    #[DataProvider('invalidEconomics')]
    public function test_malformed_economic_claims_are_refused(array $override, ?string $remove = null): void
    {
        $value = [...GoldenVectors::economic(), ...$override];
        if ($remove !== null) {
            unset($value[$remove]);
        }
        $this->expectException(DomainException::class);
        EconomicFacts::fromArray($value);
    }
}
