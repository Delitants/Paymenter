<?php

namespace App\Services\Accounts\Legacy;

use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use RuntimeException;

/** A parsed envelope is unauthenticated and authorizes no retention or money. */
final readonly class LegacyEnvelope
{
    private function __construct(public int $schemaVersion, public EffectIdentity $identity, public EconomicFacts $economic, public array $observation, public array $data) {}

    public static function parse(string $bytes): self
    {
        $data = BoundedLegacyJson::decode($bytes, 65536);
        EffectIdentity::closed($data, ['schema_version', 'purpose', 'issuer', 'not_before', 'expires_at', 'nonce', 'identity', 'economic', 'observation']);
        if (!in_array($data['schema_version'], [1, 2], true) || $data['purpose'] !== 'legacy-payment-observation' || CanonicalPolicy::bytes($data) !== $bytes) {
            throw new RuntimeException('Unsupported or noncanonical legacy envelope.');
        }
        EffectIdentity::id($data['issuer'], 128);
        EffectIdentity::id($data['nonce'], 128);
        $before = ProofSchema::date($data['not_before']);
        $expiry = ProofSchema::date($data['expires_at']);
        $ttl = $expiry->getTimestamp() - $before->getTimestamp();
        if ($ttl <= 0 || $ttl > 86400) {
            throw new RuntimeException('A bounded positive legacy validity interval is required.');
        }
        foreach (['identity', 'economic', 'observation'] as $field) {
            if (!is_array($data[$field]) || array_is_list($data[$field])) {
                throw new RuntimeException('Closed legacy objects are required.');
            }
        }
        $identity = EffectIdentity::fromArray($data['identity']);
        $economic = EconomicFacts::fromArray($data['economic']);
        $requiresParent = !in_array($identity->data()['effect_kind'], ['capture', 'manual_settlement'], true);
        if ($requiresParent !== ($economic->data()['parent_effect_key'] !== null)) {
            throw new RuntimeException('Legacy parent relationship conflicts with effect kind.');
        }
        $observation = $data['observation'];
        EffectIdentity::closed($observation, ['custody_id', 'journal_id', 'raw_sha256', 'validation_ref', 'validator_sha256', 'credential_version', 'commit_binding_ref']);
        foreach ($observation as $field => $value) {
            if (in_array($field, ['raw_sha256', 'validator_sha256'], true)) {
                EffectIdentity::sha256($value);
            } else {
                EffectIdentity::id($value);
            }
        }

        return new self($data['schema_version'], $identity, $economic, $observation, $data);
    }
}
