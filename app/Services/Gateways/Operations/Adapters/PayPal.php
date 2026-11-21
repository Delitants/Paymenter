<?php

namespace App\Services\Gateways\Operations\Adapters;

use App\Models\GatewayPaymentAttempt;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\OperationResult;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;

final class PayPal extends ProviderAdapter
{
    private ?string $token = null;

    private array $refunds = [];

    public function capabilities(): array
    {
        return ['refund' => $this->config('client_id') !== '' && $this->config('client_secret') !== '', 'capture' => false, 'reconcile' => true, 'reason' => 'v2_capture_only_native_authorization_unavailable'];
    }

    private function base(): string
    {
        return filter_var($this->config('test_mode'), FILTER_VALIDATE_BOOLEAN) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private function api(string $method, string $path, array $data = [], ?string $key = null): array
    {
        if ($this->token === null) {
            $auth = $this->json(Http::withBasicAuth($this->config('client_id'), $this->config('client_secret')), 'POST', $this->base(), '/v1/oauth2/token', ['grant_type' => 'client_credentials'], true);
            $this->require(is_string($auth['access_token'] ?? null) && ($auth['token_type'] ?? null) === 'Bearer');
            $this->token = $auth['access_token'];
        }
        $request = Http::withToken($this->token);
        if ($key !== null) {
            $request = $request->withHeaders(['PayPal-Request-Id' => $key, 'Prefer' => 'return=representation']);
        }

        return $this->json($request, $method, $this->base(), $path, $data);
    }

    private function amount(array $object, string $currency): string
    {
        $this->require(($object['amount']['currency_code'] ?? null) === $currency);

        return $this->money($object['amount']['value'] ?? null);
    }

    private function originalLink(array $refund, string $reference): void
    {
        $links = array_values(array_filter($refund['links'] ?? [], fn ($link) => ($link['rel'] ?? null) === 'up'));
        $this->require(count($links) === 1 && ($links[0]['method'] ?? null) === 'GET');
        $parts = parse_url($links[0]['href'] ?? '');
        $hosts = filter_var($this->config('test_mode'), FILTER_VALIDATE_BOOLEAN) ? ['api-m.sandbox.paypal.com', 'api.sandbox.paypal.com'] : ['api-m.paypal.com', 'api.paypal.com'];
        $this->require(($parts['scheme'] ?? null) === 'https' && in_array($parts['host'] ?? '', $hosts, true) &&
            ($parts['path'] ?? null) === '/v2/payments/captures/' . $reference && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !isset($parts['query']) && !isset($parts['fragment']));
    }

    protected function inspect(array $context, ?GatewayPaymentAttempt $attempt, bool $eligible): array
    {
        $this->refunds = [];
        $this->require($context['kind'] === 'provider_refund');
        // V1 subscription sale IDs cannot satisfy this authenticated V2 capture/order graph.
        $capture = $this->api('GET', '/v2/payments/captures/' . $this->id($context['original_reference']));
        $this->require(($capture['id'] ?? null) === $context['original_reference'] && in_array($capture['status'] ?? null, ['COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED'], true));
        $orderId = $this->id($capture['supplementary_data']['related_ids']['order_id'] ?? null);
        $order = $this->api('GET', '/v2/checkout/orders/' . $orderId);
        $this->require(($order['id'] ?? null) === $orderId && ($order['intent'] ?? null) === 'CAPTURE' && ($order['status'] ?? null) === 'COMPLETED' && count($order['purchase_units'] ?? []) === 1);
        $unit = $order['purchase_units'][0];
        $merchant = $this->id($unit['payee']['merchant_id'] ?? null);
        $this->require(($capture['payee']['merchant_id'] ?? null) === $merchant && (string) ($unit['invoice_id'] ?? '') === (string) $context['invoice_id'] &&
            count($unit['payments']['captures'] ?? []) === 1 && ($unit['payments']['captures'][0]['id'] ?? null) === $capture['id'] &&
            empty($unit['payment_instruction']) && (!isset($capture['invoice_id']) || (string) $capture['invoice_id'] === (string) $context['invoice_id']));
        $amount = $this->amount($capture, $context['currency']);
        $this->require($this->amount($unit, $context['currency']) === $amount && $this->amount($unit['payments']['captures'][0], $context['currency']) === $amount);
        $refunded = BigDecimal::zero();
        $refunds = $unit['payments']['refunds'] ?? [];
        $this->require(is_array($refunds) && count($refunds) <= 100);
        foreach ($refunds as $entry) {
            $id = $this->id($entry['id'] ?? null);
            $refund = $this->api('GET', '/v2/payments/refunds/' . $id);
            $this->require(($refund['id'] ?? null) === $id);
            $this->originalLink($refund, $capture['id']);
            $this->refunds[] = $refund;
            if (in_array($refund['status'] ?? null, ['COMPLETED', 'PENDING'], true)) {
                $refunded = $refunded->plus($this->amount($refund, $context['currency']));
            } else {
                $this->require(in_array($refund['status'] ?? null, ['FAILED', 'CANCELLED'], true));
            }
        }
        if ($eligible) {
            // Missing refund history on a partially refunded capture cannot prove the remaining balance.
            $this->require($capture['status'] === 'COMPLETED' || count($refunds) > 0);
        }

        return ['merchant' => $merchant, 'environment' => $this->base(), 'provider_object_type' => 'v2_capture', 'order' => $orderId,
            'original_amount' => $amount, 'already_refunded' => (string) $refunded->toScale(2)];
    }

    protected function write(PaymentOperation $operation, array $context): string
    {
        return $this->id($this->api('POST', '/v2/payments/captures/' . $context['original_reference'] . '/refund',
            ['amount' => ['currency_code' => $context['currency'], 'value' => $context['amount']], 'custom_id' => $operation->request_key], $operation->request_key)['id'] ?? null);
    }

    protected function read(PaymentOperation $operation, ?string $reference): OperationResult
    {
        $context = $this->current($operation, false);
        if ($reference === null) {
            // Only authenticated children of the frozen original order/capture can discover a lost response.
            $matches = array_values(array_filter($this->refunds, fn (array $refund) => ($refund['custom_id'] ?? null) === $operation->request_key));
            $this->require(count($matches) === 1);
            $reference = $this->id($matches[0]['id'] ?? null);
        }
        $refund = $this->api('GET', '/v2/payments/refunds/' . $this->id($reference));
        $this->originalLink($refund, $context['original_reference']);
        $this->require(($refund['id'] ?? null) === $reference && ($refund['custom_id'] ?? null) === $operation->request_key &&
            $this->amount($refund, $context['currency']) === $context['amount']);
        $state = match ($refund['status'] ?? null) {
            'COMPLETED' => 'succeeded', 'PENDING' => 'pending', 'FAILED', 'CANCELLED' => 'failed', default => 'uncertain',
        };

        return $this->verified($operation, $context, $reference, $state);
    }
}
