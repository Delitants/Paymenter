<?php

namespace App\Services\Gateways\Operations\Adapters;

use App\Models\GatewayPaymentAttempt;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\OperationResult;
use Illuminate\Support\Facades\Http;

final class Stripe extends ProviderAdapter
{
    public function capabilities(): array
    {
        return ['refund' => $this->config('stripe_secret_key') !== '', 'capture' => false, 'reconcile' => true, 'reason' => 'capture_requires_native_authorization_attempt'];
    }

    private function api(string $method, string $path, array $data = [], ?string $key = null): array
    {
        $request = Http::withToken($this->config('stripe_secret_key'))->withHeaders(['Stripe-Version' => '2025-07-30.basil']);
        if ($key !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $key]);
        }

        return $this->json($request, $method, 'https://api.stripe.com', $path, $data, true);
    }

    protected function inspect(array $context, ?GatewayPaymentAttempt $attempt, bool $eligible): array
    {
        $this->require($context['kind'] === 'provider_refund' && preg_match('/^(?:sk|rk)_(test|live)_/', $this->config('stripe_secret_key'), $mode) === 1);
        $account = $this->api('GET', '/v1/account');
        $merchant = $this->id($account['id'] ?? null);
        $pi = $this->api('GET', '/v1/payment_intents/' . $this->id($context['original_reference']));
        $this->require(($pi['id'] ?? null) === $context['original_reference'] && ($pi['object'] ?? null) === 'payment_intent' &&
            ($pi['livemode'] ?? null) === ($mode[1] === 'live') && ($pi['metadata']['invoice_id'] ?? null) === (string) $context['invoice_id'] &&
            ($pi['currency'] ?? null) === strtolower($context['currency']) && ($pi['status'] ?? null) === 'succeeded' &&
            empty($pi['on_behalf_of']) && empty($pi['transfer_data']) && empty($pi['application_fee_amount']));
        $chargeId = $this->id($pi['latest_charge'] ?? null);
        $charge = $this->api('GET', '/v1/charges/' . $chargeId);
        $this->require(($charge['id'] ?? null) === $chargeId && ($charge['payment_intent'] ?? null) === $pi['id'] &&
            ($charge['livemode'] ?? null) === $pi['livemode'] && ($charge['currency'] ?? null) === $pi['currency'] &&
            ($charge['paid'] ?? null) === true && ($charge['captured'] ?? null) === true && empty($charge['disputed']) &&
            empty($charge['transfer']) && empty($charge['application_fee']) &&
            ($charge['amount_captured'] ?? null) === ($pi['amount_received'] ?? null) && ($charge['amount'] ?? null) === ($pi['amount'] ?? null));

        return ['merchant' => $merchant, 'environment' => $mode[1], 'provider_object_type' => 'payment_intent',
            'original_amount' => $this->minor($pi['amount_received']), 'already_refunded' => $this->minor($charge['amount_refunded'] ?? null), 'charge' => $chargeId];
    }

    protected function write(PaymentOperation $operation, array $context): string
    {
        return $this->id($this->api('POST', '/v1/refunds', ['charge' => $context['charge'], 'amount' => $this->units($context['amount']),
            'metadata' => ['paymenter_operation' => $operation->request_key]], $operation->request_key)['id'] ?? null);
    }

    protected function read(PaymentOperation $operation, ?string $reference): OperationResult
    {
        $context = $this->current($operation, false);
        if ($reference === null) {
            $matches = [];
            $query = ['charge' => $context['charge'], 'limit' => 100];
            $seen = [];
            for ($page = 0; $page < 100; $page++) {
                $list = $this->api('GET', '/v1/refunds', $query);
                $this->require(is_array($list['data'] ?? null) && is_bool($list['has_more'] ?? null));
                foreach ($list['data'] as $item) {
                    $id = $this->id($item['id'] ?? null);
                    $this->require(!isset($seen[$id]));
                    $seen[$id] = true;
                    if (($item['metadata']['paymenter_operation'] ?? null) === $operation->request_key) {
                        $matches[] = $id;
                    }
                }
                if (!$list['has_more']) {
                    break;
                }
                $this->require(count($list['data']) > 0 && $page < 99);
                $query['starting_after'] = $id;
            }
            $this->require(count($matches) === 1);
            $reference = $matches[0];
        }
        $refund = $this->api('GET', '/v1/refunds/' . $this->id($reference));
        $this->require(($refund['id'] ?? null) === $reference && ($refund['object'] ?? null) === 'refund' &&
            ($refund['payment_intent'] ?? null) === $context['original_reference'] && ($refund['charge'] ?? null) === $context['charge'] &&
            ($refund['metadata']['paymenter_operation'] ?? null) === $operation->request_key &&
            ($refund['currency'] ?? null) === strtolower($context['currency']) && $this->minor($refund['amount'] ?? null) === $context['amount']);
        $state = match ($refund['status'] ?? null) {
            'succeeded' => 'succeeded', 'pending', 'requires_action' => 'pending', 'failed', 'canceled' => 'failed', default => 'uncertain',
        };

        return $this->verified($operation, $context, $reference, $state);
    }
}
