<?php

namespace Tests\Unit\Accounts\Legacy;

use App\Services\Accounts\AccountWriteContext;
use App\Services\Accounts\Legacy\AuthenticatedLegacyObservation;
use App\Services\Accounts\Legacy\LegacyTransportTrust;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\LegacyReceipts\LegacyReceiptFixture as Fixture;

final class LegacyObservationVerifierTest extends TestCase
{
    private static array $keys;

    public static function setUpBeforeClass(): void
    {
        self::$keys = Fixture::keyPair();
    }

    private function verify(string $payload, ?string $signature = null, ?array $trust = null, string $now = '2026-01-01T00:01:00Z'): AuthenticatedLegacyObservation
    {
        return AuthenticatedLegacyObservation::verify($payload, $signature ?? Fixture::signature($payload, self::$keys), LegacyTransportTrust::fromBytes(CanonicalPolicy::bytes($trust ?? Fixture::trust(self::$keys))), new DateTimeImmutable($now));
    }

    public function test_original_bytes_signature_is_required(): void
    {
        self::assertTrue(class_exists(AuthenticatedLegacyObservation::class));
        $payload = Fixture::payload();
        $sig = Fixture::signature($payload, self::$keys);
        $observation = $this->verify($payload, $sig);
        self::assertSame('5.5001', $observation->economic->delta());
        self::assertSame($payload, $observation->payloadBytes);
        self::assertSame($sig, $observation->signatureBytes);
        self::assertSame(hash('sha256', $payload), $observation->payloadSha256);
        self::assertSame(hash('sha256', $sig), $observation->signatureSha256);
        $this->expectException(\RuntimeException::class);
        $this->verify(str_replace('account-01', 'account-02', $payload), $sig);
    }

    public function test_new_signed_observation_retains_identity_and_economics_only(): void
    {
        $a = $this->verify(Fixture::payload());
        $data = json_decode($a->payloadBytes, true, 512, JSON_THROW_ON_ERROR);
        $data['schema_version'] = 2;
        $data['nonce'] = 'second-delivery';
        $data['observation']['raw_sha256'] = str_repeat('d', 64);
        $data['observation']['custody_id'] = 'custody-two';
        $data['observation']['validator_sha256'] = str_repeat('e', 64);
        $data['observation']['credential_version'] = 'rotated-source-credential';
        $b = $this->verify(CanonicalPolicy::bytes($data));
        self::assertSame($a->identity->key(), $b->identity->key());
        self::assertSame($a->economic->fingerprint(), $b->economic->fingerprint());
        self::assertNotSame($a->observation, $b->observation);
        self::assertNotSame($a->payloadSha256, $b->payloadSha256);
    }

    public function test_start_is_inclusive_and_expiry_is_exclusive(): void
    {
        self::assertSame('fixture-key', $this->verify(Fixture::payload(), now: '2026-01-01T00:00:00Z')->keyId);
        $this->expectException(\RuntimeException::class);
        $this->verify(Fixture::payload(), now: '2026-01-02T00:00:00Z');
    }

    public function test_future_payload_is_denied(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->verify(Fixture::payload(), now: '2025-12-31T23:59:59Z');
    }

    public static function deniedTrustChanges(): iterable
    {
        foreach (['opening-purpose', 'issuer', 'source', 'merchant', 'kind', 'key-id', 'revoked-key', 'revoked-nonce', 'zero-quota', 'numeric-key', 'wildcard', 'extra', 'bad-public-key', 'quota-overflow'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('deniedTrustChanges')]
    public function test_trust_is_closed_and_explicit(string $case): void
    {
        $trust = Fixture::trust(self::$keys);
        switch ($case) {
            case 'opening-purpose': $trust['purpose'] = 'opening-trust';
                break;
            case 'issuer': $trust['keys']['fixture-key']['issuer'] = 'another-source';
                break;
            case 'source': $trust['keys']['fixture-key']['sources'][0]['source_instance'] = 'another-source';
                break;
            case 'merchant': $trust['keys']['fixture-key']['sources'][0]['merchant_binding'] = 'another-merchant';
                break;
            case 'kind': $trust['keys']['fixture-key']['sources'][0]['effect_kinds'] = ['refund'];
                break;
            case 'key-id': $trust['keys'] = ['other-key' => $trust['keys']['fixture-key']];
                break;
            case 'revoked-key': $trust['revoked_keys'] = ['fixture-key'];
                break;
            case 'revoked-nonce': $trust['revoked_nonces'] = ['delivery-one'];
                break;
            case 'zero-quota': $trust['retained_byte_limit'] = 0;
                break;
            case 'numeric-key': $trust['keys'] = ['123' => $trust['keys']['fixture-key']];
                break;
            case 'wildcard': $trust['keys']['fixture-key']['sources'][0]['merchant_binding'] = '*';
                break;
            case 'extra': $trust['accept_all'] = true;
                break;
            case 'bad-public-key': $trust['keys']['fixture-key']['public_key'] = 'not a key';
                break;
            case 'quota-overflow': $trust['retained_observation_limit'] = 2147483648;
                break;
        }
        $this->expectException(\Exception::class);
        $this->verify(Fixture::payload(), trust: $trust);
    }

    public static function invalidSignatureChanges(): iterable
    {
        yield 'wrong key' => ['key_id', 'other-key'];
        yield 'wrong algorithm' => ['algorithm', 'rsa-sha1'];
        yield 'wrong version' => ['schema_version', 2];
        yield 'invalid base64' => ['signature_b64', '%%%'];
        yield 'noncanonical base64' => ['signature_b64', "YQ==\n"];
        yield 'extra' => ['verified', true];
    }

    #[DataProvider('invalidSignatureChanges')]
    public function test_bad_signature_metadata_cannot_authenticate(string $field, mixed $value): void
    {
        $payload = Fixture::payload();
        $signature = json_decode(Fixture::signature($payload, self::$keys), true, 512, JSON_THROW_ON_ERROR);
        $signature[$field] = $value;
        $this->expectException(\Exception::class);
        $this->verify($payload, CanonicalPolicy::bytes($signature));
    }

    public static function noncanonicalSignatureWrappers(): iterable
    {
        foreach (['leading-space', 'trailing-newline', 'reordered-keys', 'alternate-escape'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('noncanonicalSignatureWrappers')]
    public function test_original_signature_wrapper_must_be_canonical(string $case): void
    {
        $payload = Fixture::payload();
        $signature = Fixture::signature($payload, self::$keys);
        $altered = match ($case) {
            'leading-space' => ' ' . $signature,
            'trailing-newline' => $signature . "\n",
            'reordered-keys' => json_encode(array_reverse(json_decode($signature, true, 512, JSON_THROW_ON_ERROR), true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'alternate-escape' => str_replace('fixture-key', 'fixture\\u002dkey', $signature),
        };
        self::assertNotSame($signature, $altered);
        self::assertEquals(json_decode($signature, true, 512, JSON_THROW_ON_ERROR), json_decode($altered, true, 512, JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        $this->verify($payload, $altered);
    }

    public function test_small_rsa_key_is_refused(): void
    {
        $keys = Fixture::keyPair(2048);
        $payload = Fixture::payload();
        $this->expectException(\RuntimeException::class);
        $this->verify($payload, Fixture::signature($payload, $keys), Fixture::trust($keys));
    }

    public function test_commit_reference_does_not_produce_financial_authority(): void
    {
        $authenticated = $this->verify(Fixture::payload());
        self::assertSame('unproven-commit-reference', $authenticated->observation['commit_binding_ref']);
        self::assertInstanceOf(AuthenticatedLegacyObservation::class, $authenticated);
        self::assertNotInstanceOf(AccountWriteContext::class, $authenticated);
        foreach (['committed', 'included', 'posted', 'financialAuthority'] as $field) {
            self::assertFalse(property_exists($authenticated, $field));
        }
        self::assertTrue((new \ReflectionClass($authenticated))->getConstructor()->isPrivate());
        $this->expectException(\TypeError::class);
        AccountWriteContext::depositOperation($authenticated);
    }

    public function test_transport_policy_hash_binds_exact_current_bytes(): void
    {
        $bytes = CanonicalPolicy::bytes(Fixture::trust(self::$keys));
        $trust = LegacyTransportTrust::fromBytes($bytes);
        $payload = Fixture::payload();
        $a = AuthenticatedLegacyObservation::verify($payload, Fixture::signature($payload, self::$keys), $trust, new DateTimeImmutable('2026-01-01T00:01:00Z'));
        self::assertSame(hash('sha256', $bytes), $a->policySha256);
    }
}
