<?php

namespace App\Services\Gateways\Operations;

use App\Models\PaymentOperation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Durable single-write claim, with an append-once minimal authenticated response. */
final class AcceptedRequest
{
    private function digest(PaymentOperation $operation, string $provider, string $action): string
    {
        return hash('sha256', json_encode([$operation->id, $operation->request_key, $operation->kind, $operation->gateway_id,
            $operation->amount, $operation->currency_code, $operation->payload['provider_context'], $provider, $action], JSON_THROW_ON_ERROR));
    }

    public function claim(PaymentOperation $operation, string $provider, string $action, ?string $scope = null, ?int $reqn = null): void
    {
        if (!$operation->exists || $operation->state !== 'processing') {
            throw new RuntimeException('A durable processing operation is required before provider movement.');
        }
        $inserted = DB::table('gateway_operation_requests')->insertOrIgnore(['request_key' => $operation->request_key, 'payment_operation_id' => $operation->id,
            'provider' => $provider, 'action' => $action, 'sequence_scope' => $scope, 'reqn' => $reqn,
            'request_digest' => $this->digest($operation, $provider, $action), 'created_at' => now()]);
        if ($inserted !== 1) {
            throw new RuntimeException('Provider write was already claimed; authenticated read-only reconciliation is required.');
        }
    }

    public function accept(PaymentOperation $operation, string $provider, string $action, array $proof): void
    {
        $updated = DB::table('gateway_operation_requests')->where('request_key', $operation->request_key)
            ->where('payment_operation_id', $operation->id)->where('request_digest', $this->digest($operation, $provider, $action))->whereNull('accepted_proof')
            ->update(['accepted_proof' => Crypt::encryptString(json_encode($proof, JSON_THROW_ON_ERROR))]);
        if ($updated !== 1) {
            throw new RuntimeException('Accepted provider response binding cannot be replaced.');
        }
    }

    public function proof(PaymentOperation $operation, string $provider, string $action): ?array
    {
        $row = DB::table('gateway_operation_requests')->where('request_key', $operation->request_key)->first();
        if (!$row || (int) $row->payment_operation_id !== $operation->id || $row->provider !== $provider || $row->action !== $action ||
            !hash_equals($row->request_digest, $this->digest($operation, $provider, $action)) || !$row->accepted_proof) {
            return null;
        }

        $proof = json_decode(Crypt::decryptString($row->accepted_proof), true, 32, JSON_THROW_ON_ERROR);
        if ($row->reqn !== null && (string) ($proof['reqn'] ?? '') !== (string) $row->reqn) {
            throw new RuntimeException('Provider response does not match its durable request number.');
        }

        return $proof;
    }
}
