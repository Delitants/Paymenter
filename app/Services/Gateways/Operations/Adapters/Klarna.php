<?php

namespace App\Services\Gateways\Operations\Adapters;

use App\Models\GatewayPaymentAttempt;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\OperationResult;
use Illuminate\Support\Facades\Http;

final class Klarna extends ProviderAdapter
{
    public function capabilities(): array
    {
        return ['refund' => $this->config('merchant_id') !== '' && $this->config('secret') !== '', 'capture' => $this->config('merchant_id') !== '' && $this->config('secret') !== '', 'reconcile' => true];
    }

    private function base(): string
    {
        $this->require(in_array($this->config('region'), ['eu', 'na', 'oc'], true) && in_array($this->config('environment'), ['test', 'production'], true));

        return 'https://api' . ($this->config('region') === 'eu' ? '' : '-' . $this->config('region')) . ($this->config('environment') === 'test' ? '.playground' : '') . '.klarna.com';
    }

    private function api(string $path): array
    {
        return $this->json(Http::withBasicAuth($this->config('merchant_id'), $this->config('secret')), 'GET', $this->base(), $path);
    }

    protected function inspect(array $context, ?GatewayPaymentAttempt $attempt, bool $eligible): array
    {
        $this->require(in_array($context['kind'], ['provider_refund', 'provider_capture'], true) && $attempt !== null && $context['currency'] === $this->config('currency') &&
            $attempt->merchant_fingerprint === hash('sha256', $this->base() . ':' . $this->config('merchant_id') . ':' . $this->config('currency')));
        $capture = $context['kind'] === 'provider_capture';
        $orderId = $context['original_reference'];
        $priorCapture = null;
        if (!$capture) {
            $receipt = InvoiceTransaction::findOrFail($context['transaction_id']);
            if ($receipt->settlement_origin === 'manual_capture') {
                $priorCapture = $this->captureAncestry($context, $attempt, $receipt);
                $orderId = $priorCapture->payload['provider_context']['order'];
            }
        }
        if ($capture) {
            $this->require($attempt->pricing_payload !== null && $attempt->pricing_fingerprint !== null && $attempt->provider_reference === $context['original_reference']);
            $hpp = $this->api('/hpp/v1/sessions/' . $this->id($context['original_reference']));
            $this->require(($hpp['session_id'] ?? null) === $context['original_reference'] && ($hpp['status'] ?? null) === 'COMPLETED');
            $orderId = $this->id($hpp['order_id'] ?? null);
        }
        $order = $this->api('/ordermanagement/v1/orders/' . $this->id($orderId));
        $this->require(($order['order_id'] ?? null) === $orderId && ($order['merchant_reference1'] ?? null) === $attempt->reference &&
            ($order['purchase_currency'] ?? null) === $context['currency'] && ($order['fraud_status'] ?? null) === 'ACCEPTED' &&
            ($order['original_order_amount'] ?? null) === ($order['order_amount'] ?? null));
        if ($capture && $eligible) {
            $this->require(($order['status'] ?? null) === 'AUTHORIZED' && ($order['captured_amount'] ?? null) === 0 && ($order['refunded_amount'] ?? null) === 0 &&
                ($order['remaining_authorized_amount'] ?? null) === $this->units($context['amount']) && count($order['captures'] ?? []) === 0 &&
                is_string($order['expires_at'] ?? null) && strtotime($order['expires_at']) > now()->timestamp && $context['amount'] === $attempt->amount);
        } else {
            $this->require(in_array($order['status'] ?? null, ['CAPTURED', 'PART_CAPTURED', 'CLOSED'], true) && count($order['captures'] ?? []) === 1 &&
                ($order['captured_amount'] ?? null) === ($order['order_amount'] ?? null));
        }
        if ($priorCapture !== null) {
            $originalChild = $this->api('/ordermanagement/v1/orders/' . $this->id($orderId) . '/captures/' . $this->id($context['original_reference']));
            $this->require(($order['captures'][0]['capture_id'] ?? null) === $context['original_reference'] &&
                ($order['captures'][0]['reference'] ?? null) === $priorCapture->request_key &&
                ($originalChild['capture_id'] ?? null) === $context['original_reference'] && ($originalChild['reference'] ?? null) === $priorCapture->request_key &&
                $this->minor($originalChild['captured_amount'] ?? null) === $priorCapture->amount &&
                is_string($originalChild['captured_at'] ?? null) && strtotime($originalChild['captured_at']) !== false);
        }
        $allocation = $attempt->provider_payload['order_allocation'] ?? null;
        $this->require(is_array($allocation) && ($allocation['order_amount'] ?? null) === $order['order_amount'] &&
            is_array($allocation['order_lines'] ?? null) && count($allocation['order_lines']) === count($order['order_lines'] ?? []));
        $remote = [];
        foreach ($order['order_lines'] as $line) {
            $this->require(is_string($line['reference'] ?? null) && !isset($remote[$line['reference']]));
            $remote[$line['reference']] = $line;
        }
        foreach ($allocation['order_lines'] as $line) {
            foreach (['type', 'reference', 'quantity', 'unit_price', 'total_amount', 'total_tax_amount', 'tax_rate'] as $field) {
                $this->require(($remote[$line['reference']][$field] ?? null) === ($line[$field] ?? null));
            }
        }

        return ['merchant' => $this->config('merchant_id'), 'environment' => $this->base(), 'provider_object_type' => $priorCapture === null ? 'order' : 'capture',
            'original_amount' => $this->minor($order['order_amount']), 'already_refunded' => $this->minor($order['refunded_amount'] ?? null),
            'order_lines' => $allocation['order_lines'], 'order' => $orderId];
    }

    private function captureAncestry(array $context, GatewayPaymentAttempt $attempt, InvoiceTransaction $receipt): PaymentOperation
    {
        $matches = PaymentOperation::where('result_transaction_id', $receipt->id)->where('kind', 'provider_capture')->get();
        $this->require($matches->count() === 1);
        $capture = $matches->first();
        $frozen = $capture->payload['provider_context'] ?? [];
        $this->require($capture->state === 'succeeded' && $capture->provider_reference === $context['original_reference'] &&
            $capture->invoice_id === $context['invoice_id'] && $capture->gateway_id === $context['gateway_id'] &&
            $capture->currency_code === $context['currency'] && $capture->amount === $receipt->amount && $capture->amount === $attempt->amount &&
            $receipt->invoice_id === $context['invoice_id'] && $receipt->gateway_id === $context['gateway_id'] &&
            $receipt->transaction_id === 'gateway:' . $context['gateway_id'] . ':' . $context['original_reference'] &&
            $attempt->state === 'paid' && $attempt->provider_transaction_id === $context['original_reference']);
        foreach (['invoice_id', 'gateway_id', 'currency', 'attempt_id', 'attempt_reference', 'merchant_fingerprint'] as $field) {
            $this->require(($frozen[$field] ?? null) === $context[$field]);
        }
        $this->require(($frozen['kind'] ?? null) === 'provider_capture' && ($frozen['transaction_id'] ?? null) === null &&
            ($frozen['original_reference'] ?? null) === $attempt->provider_reference && ($frozen['amount'] ?? null) === $capture->amount &&
            ($frozen['original_amount'] ?? null) === $capture->amount && ($frozen['provider_object_type'] ?? null) === 'order' &&
            ($frozen['merchant'] ?? null) === $this->config('merchant_id') && ($frozen['environment'] ?? null) === $this->base());
        $history = $capture->outcome_evidence ?? [];
        $last = end($history);
        $this->require(is_array($last) && ($last['state'] ?? null) === 'succeeded' && ($last['provider_reference'] ?? null) === $context['original_reference']);
        (new OperationResult('succeeded', $context['original_reference'], $last['evidence'] ?? []))->assertVerified($capture);

        return $capture;
    }

    protected function write(PaymentOperation $operation, array $context): string
    {
        $capture = $context['kind'] === 'provider_capture';
        $path = '/ordermanagement/v1/orders/' . $context['order'] . ($capture ? '/captures' : '/refunds');
        $data = [$capture ? 'captured_amount' : 'refunded_amount' => $this->units($context['amount']), 'reference' => $operation->request_key];
        if ($capture || $context['amount'] === $context['original_amount']) {
            $data['order_lines'] = $context['order_lines'];
        }
        // Klarna may return a string child ID or a Location header with an empty body.
        $response = Http::withBasicAuth($this->config('merchant_id'), $this->config('secret'))->withHeaders(['Klarna-Idempotency-Key' => $operation->request_key])
            ->acceptJson()->withoutRedirecting()->connectTimeout(10)->timeout(25)->post($this->base() . $path, $data);
        $this->require($response->successful() && strlen($response->body()) <= 1048576);
        $body = $response->json();
        if (is_string($body)) {
            return $this->id($body);
        }
        if (is_array($body) && isset($body[$capture ? 'capture_id' : 'refund_id'])) {
            return $this->id($body[$capture ? 'capture_id' : 'refund_id']);
        }
        $location = $response->header('Location');
        $prefix = $this->base() . $path . '/';
        $this->require(is_string($location) && str_starts_with($location, $prefix));

        return $this->id(substr($location, strlen($prefix)));
    }

    protected function read(PaymentOperation $operation, ?string $reference): OperationResult
    {
        $context = $this->current($operation, false);
        $capture = $context['kind'] === 'provider_capture';
        $collection = $capture ? 'captures' : 'refunds';
        $idField = $capture ? 'capture_id' : 'refund_id';
        $amountField = $capture ? 'captured_amount' : 'refunded_amount';
        $dateField = $capture ? 'captured_at' : 'refunded_at';
        $path = '/ordermanagement/v1/orders/' . $context['order'];
        if ($reference === null) {
            $order = $this->api($path);
            $this->require(is_array($order[$collection] ?? null));
            $matches = array_values(array_filter($order[$collection], fn ($child) => ($child['reference'] ?? null) === $operation->request_key));
            $this->require(count($matches) === 1);
            $reference = $this->id($matches[0][$idField] ?? null);
        }
        $child = $this->api($path . '/' . $collection . '/' . $this->id($reference));
        $this->require(($child[$idField] ?? null) === $reference && ($child['reference'] ?? null) === $operation->request_key &&
            $this->minor($child[$amountField] ?? null) === $context['amount'] && is_string($child[$dateField] ?? null) && strtotime($child[$dateField]) !== false);

        return $this->verified($operation, $context, $reference, 'succeeded');
    }
}
