<?php

namespace Tests\Unit;

use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\OperationResult;
use RuntimeException;
use Tests\TestCase;

class KlarnaConversionEvidenceTest extends TestCase
{
    private function fixture(): array
    {
        $context = ['merchant' => 'synthetic', 'environment' => 'synthetic-playground', 'provider_object_type' => 'order',
            'original_reference' => 'synthetic-order', 'currency' => 'USD', 'amount' => '5.00', 'original_amount' => '12.34',
            'invoice_id' => 1, 'gateway_id' => 2, 'transaction_id' => 3, 'attempt_id' => 4, 'attempt_reference' => 'synthetic-attempt',
            'merchant_fingerprint' => hash('sha256', 'synthetic'), 'provider_currency' => 'EUR', 'provider_amount' => '4.55',
            'provider_original_amount' => '11.22', 'conversion_fingerprint' => hash('sha256', 'synthetic-conversion')];
        $operation = new PaymentOperation(['payload' => ['provider_context' => $context], 'request_key' => 'synthetic-request']);
        $evidence = $context + ['authenticated' => true, 'request_key' => 'synthetic-request', 'provider_reference' => 'synthetic-refund'];

        return [$operation, $evidence];
    }

    public function test_native_usd_match_cannot_replace_verified_local_currency_money(): void
    {
        foreach (['provider_currency' => 'USD', 'provider_amount' => '5.00', 'provider_original_amount' => '12.34', 'conversion_fingerprint' => 'changed'] as $field => $wrong) {
            [$operation, $evidence] = $this->fixture();
            $evidence[$field] = $wrong;
            $rejected = false;
            try {
                (new OperationResult('succeeded', 'synthetic-refund', $evidence))->assertVerified($operation);
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Local conversion mismatch was accepted');
        }
    }

    public function test_conversion_proof_is_retained_and_missing_local_amount_is_rejected(): void
    {
        [$operation, $evidence] = $this->fixture();
        $result = new OperationResult('succeeded', 'synthetic-refund', $evidence);
        $result->assertVerified($operation);
        $this->assertSame('EUR', $result->safeEvidence()['provider_currency'] ?? null);
        $this->assertSame('4.55', $result->safeEvidence()['provider_amount'] ?? null);
        unset($evidence['provider_amount']);
        $this->expectException(RuntimeException::class);
        (new OperationResult('succeeded', 'synthetic-refund', $evidence))->assertVerified($operation);
    }
}
