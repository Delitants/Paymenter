<?php

namespace App\Services\Gateways\Operations\Adapters;

use App\Models\GatewayPaymentAttempt;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\OperationResult;
use Illuminate\Support\Facades\Http;

final class Mollie extends ProviderAdapter
{
    public function capabilities(): array
    {
        return ['refund' => $this->config('api_key') !== '', 'capture' => false, 'reconcile' => true, 'reason' => 'capture_requires_native_authorization_attempt'];
    }

    private function api(string $method, string $path, array $data = [], ?string $key = null): array
    {
        $request = Http::withToken($this->config('api_key'));
        if ($key !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $key]);
        }

        return $this->json($request, $method, 'https://api.mollie.com', $path, $data);
    }

    protected function inspect(array $context, ?GatewayPaymentAttempt $attempt, bool $eligible): array
    {
        $this->require($context['kind'] === 'provider_refund' && preg_match('/^(test|live)_/', $this->config('api_key'), $mode) === 1);
        $profile = $this->api('GET', '/v2/profiles/me');
        $payment = $this->api('GET', '/v2/payments/' . $this->id($context['original_reference']));
        $this->require(($payment['id'] ?? null) === $context['original_reference'] && ($payment['profileId'] ?? null) === $this->id($profile['id'] ?? null) &&
            ($payment['mode'] ?? null) === $mode[1] && (string) ($payment['metadata']['invoice_id'] ?? '') === (string) $context['invoice_id'] &&
            ($payment['amount']['currency'] ?? null) === $context['currency'] && ($payment['amountRefunded']['currency'] ?? null) === $context['currency'] &&
            ($payment['status'] ?? null) === 'paid' && empty($payment['orderId']) && empty($payment['routing']) && (!isset($payment['amountChargedBack']) || $this->money($payment['amountChargedBack']['value'] ?? null) === '0.00') &&
            !in_array($payment['method'] ?? null, [null, 'paypal', 'riverty', 'billink'], true));
        if ($eligible && isset($context['credential_version'])) {
            $this->require($context['credential_version'] === hash('sha256', $this->config('api_key')));
        }

        // Credential binding is checked only for writes; authenticated rotation may read back.
        return ['merchant' => $profile['id'], 'environment' => $mode[1], 'provider_object_type' => 'payment',
            'original_amount' => $this->money($payment['amount']['value'] ?? null), 'already_refunded' => $this->money($payment['amountRefunded']['value'] ?? null),
            'credential_version' => $context['credential_version'] ?? hash('sha256', $this->config('api_key'))];
    }

    protected function write(PaymentOperation $operation, array $context): string
    {
        return $this->id($this->api('POST', '/v2/payments/' . $context['original_reference'] . '/refunds',
            ['amount' => ['currency' => $context['currency'], 'value' => $context['amount']], 'metadata' => ['paymenter_operation' => $operation->request_key]], $operation->request_key)['id'] ?? null);
    }

    protected function read(PaymentOperation $operation, ?string $reference): OperationResult
    {
        $context = $this->current($operation, false);
        $path = '/v2/payments/' . $context['original_reference'] . '/refunds';
        if ($reference === null) {
            $next = $path . '?limit=250';
            $seen = $matches = [];
            for ($page = 0; $page < 100; $page++) {
                $this->require(!isset($seen[$next]));
                $seen[$next] = true;
                $list = $this->api('GET', $next);
                $this->require(is_array($list['_embedded']['refunds'] ?? null));
                foreach ($list['_embedded']['refunds'] as $refund) {
                    if (($refund['metadata']['paymenter_operation'] ?? null) === $operation->request_key) {
                        $matches[] = $this->id($refund['id'] ?? null);
                    }
                }
                $url = $list['_links']['next']['href'] ?? null;
                if ($url === null) {
                    break;
                }
                $this->require(is_string($url) && str_starts_with($url, 'https://api.mollie.com' . $path . '?') && $page < 99);
                $next = substr($url, strlen('https://api.mollie.com'));
            }
            $this->require(count($matches) === 1);
            $reference = $matches[0];
        }
        $refund = $this->api('GET', $path . '/' . $this->id($reference));
        $this->require(($refund['id'] ?? null) === $reference && ($refund['paymentId'] ?? null) === $context['original_reference'] &&
            ($refund['mode'] ?? null) === $context['environment'] && ($refund['metadata']['paymenter_operation'] ?? null) === $operation->request_key &&
            ($refund['amount']['currency'] ?? null) === $context['currency'] && $this->money($refund['amount']['value'] ?? null) === $context['amount']);
        $state = match ($refund['status'] ?? null) {
            'refunded' => 'succeeded', 'queued', 'pending', 'processing' => 'pending', 'failed', 'canceled' => 'failed', default => 'uncertain',
        };

        return $this->verified($operation, $context, $reference, $state);
    }
}
