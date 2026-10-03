<?php

namespace App\Services\Gateways\Operations;

use App\Models\PaymentOperation;
use Brick\Math\BigDecimal;
use RuntimeException;

final class OperationResult
{
    public function __construct(
        public readonly string $state,
        public readonly ?string $providerReference = null,
        public readonly array $evidence = [],
        public readonly string $outcomeCode = 'provider_readback',
    ) {
        if (!in_array($state, ['succeeded', 'pending', 'failed', 'uncertain'], true) ||
            ($providerReference !== null && ($providerReference === '' || strlen($providerReference) > 190)) ||
            !preg_match('/^[a-zA-Z0-9_]{1,60}$/D', $outcomeCode)) {
            throw new RuntimeException('Invalid provider operation result.');
        }
    }

    public function assertVerified(PaymentOperation $operation): void
    {
        $context = $operation->payload['provider_context'] ?? [];
        if (($this->evidence['authenticated'] ?? false) !== true ||
            ($this->evidence['request_key'] ?? null) !== $operation->request_key ||
            ($this->state === 'succeeded' && (!$this->providerReference || ($this->evidence['provider_reference'] ?? null) !== $this->providerReference)) ||
            ($this->state === 'failed' && ($this->evidence['failure_proven'] ?? false) !== true)) {
            throw new RuntimeException('The provider outcome requires authenticated operation readback.');
        }
        foreach (['merchant', 'environment', 'provider_object_type', 'original_reference', 'currency', 'invoice_id', 'gateway_id', 'transaction_id', 'attempt_id', 'attempt_reference', 'merchant_fingerprint'] as $key) {
            if (!array_key_exists($key, $context) || !array_key_exists($key, $this->evidence) || $context[$key] !== $this->evidence[$key]) {
                throw new RuntimeException('Provider readback identity does not match the frozen operation.');
            }
        }
        foreach (['amount', 'original_amount'] as $key) {
            if (!isset($this->evidence[$key], $context[$key]) || !is_string($this->evidence[$key]) ||
                !BigDecimal::of($context[$key])->isEqualTo($this->evidence[$key])) {
                throw new RuntimeException('Provider readback money does not match the frozen operation.');
            }
        }
        if ($operation->provider_reference && $this->providerReference && $operation->provider_reference !== $this->providerReference) {
            throw new RuntimeException('Provider operation reference changed during readback.');
        }
    }

    public function safeEvidence(): array
    {
        return array_intersect_key($this->evidence, array_flip(['authenticated', 'request_key', 'merchant', 'environment', 'provider_object_type',
            'original_reference', 'currency', 'amount', 'original_amount', 'invoice_id', 'gateway_id', 'transaction_id', 'attempt_id',
            'attempt_reference', 'merchant_fingerprint', 'failure_proven', 'provider_reference']));
    }
}
