<?php

namespace Paymenter\Extensions\Gateways\BTCPay;

use App\Models\GatewayPaymentAttempt;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\Adapters\ProviderAdapter;
use App\Services\Gateways\Operations\OperationResult;
use App\Services\Gateways\Operations\PreparedAdapter;
use Brick\Math\BigDecimal;
use RuntimeException;
use Throwable;

final class Operations extends ProviderAdapter implements PreparedAdapter
{
    public function capabilities(): array
    {
        return ['refund' => true, 'capture' => false, 'reconcile' => true, 'serialized_refunds' => true];
    }

    protected function id(mixed $value): string
    {
        $this->require(is_string($value) && str_starts_with($value, 'invoice:') && Greenfield::validId(substr($value, 8)));

        return $value;
    }

    public function claimLink(PaymentOperation $operation): ?string
    {
        if ($operation->kind !== 'provider_refund' || $operation->gateway_id !== $this->gateway->id
            || $operation->state !== 'pending' || !in_array($operation->outcome_code, ['btcpay_awaiting_claim', 'btcpay_payout_pending'], true)
            || !Greenfield::validId($operation->provider_reference)) {
            return null;
        }
        $store = $this->store();

        return ($operation->payload['provider_context']['merchant_fingerprint'] ?? null) === $store->merchantFingerprint()
            ? $store->claimLink($operation->provider_reference)
            : null;
    }

    public function prepareExecution(PaymentOperation $operation): array
    {
        $this->require($operation->state === 'queued');

        return $this->current($operation, true);
    }

    public function execute(PaymentOperation $operation): OperationResult
    {
        throw new RuntimeException('BTCPay refunds require authenticated write preparation.');
    }

    public function executePrepared(PaymentOperation $operation, array $context): OperationResult
    {
        $this->require($operation->state === 'processing' && $context === $operation->payload['provider_context']);

        try {
            return new OperationResult('pending', $this->write($operation, $context));
        } catch (Throwable) {
            return new OperationResult('uncertain', outcomeCode: 'write_outcome_unknown');
        }
    }

    protected function inspect(array $context, ?GatewayPaymentAttempt $attempt, bool $eligible): array
    {
        $this->require($context['kind'] === 'provider_refund' && $context['currency'] === 'USD'
            && $attempt?->state === 'paid' && Greenfield::validId($attempt->provider_reference)
            && $attempt->provider_transaction_id === $context['original_reference']
            && $attempt->provider_transaction_id === 'invoice:' . $attempt->provider_reference);
        $store = $this->store();
        $this->require(hash_equals($attempt->merchant_fingerprint, $store->merchantFingerprint()));
        $invoiceId = $attempt->provider_reference;
        $invoice = $store->invoice($invoiceId);
        $store->assertInvoice($invoice, $attempt);
        $read = [
            'merchant' => $store->merchantFingerprint(), 'environment' => 'greenfield', 'provider_object_type' => 'btcpay_invoice',
            'btcpay_invoice_id' => $invoiceId, 'original_amount' => $this->money($invoice['amount']),
        ];
        if (!$eligible) {
            return $read + ['already_refunded' => $context['already_refunded']];
        }

        $received = Greenfield::decimal($invoice['paidAmount'] ?? null);
        $this->require(($invoice['status'] ?? null) === 'Settled'
            && in_array($invoice['additionalStatus'] ?? null, ['None', 'Marked'], true)
            && $received?->isEqualTo($attempt->amount) === true);
        $methods = Greenfield::confirmedMethods($store->paymentMethods($invoiceId));
        $payoutMethod = array_values(array_intersect(['BTC-CHAIN', 'BTC-LN'], array_column($methods, 'paymentMethodId')))[0] ?? null;
        $this->require($payoutMethod !== null);

        return $read + [
            'payout_method' => $payoutMethod,
            'already_refunded' => $this->verifiedPreviousRefunds($store, $invoiceId, $context['transaction_id']),
        ];
    }

    protected function write(PaymentOperation $operation, array $context): string
    {
        $invoiceId = $context['btcpay_invoice_id'];
        $pull = $this->store()->refundInvoice(
            $invoiceId, $context['payout_method'], $operation->amount,
            $this->refundName($invoiceId, $operation->request_key), $this->description($invoiceId),
        );

        return $this->verifyPullPayment($pull, $operation, $invoiceId);
    }

    protected function read(PaymentOperation $operation, ?string $reference): OperationResult
    {
        $context = $this->current($operation, false);
        $invoiceId = $context['btcpay_invoice_id'];
        $store = $this->store();
        $name = $this->refundName($invoiceId, $operation->request_key);
        $matches = array_values(array_filter($this->invoicePullPayments($store, $invoiceId), fn ($pull) => $pull['name'] === $name));
        $this->require(count($matches) === 1);
        $pull = $matches[0];
        $id = $this->verifyPullPayment($pull, $operation, $invoiceId);
        $this->require($reference === null || $reference === $id);

        $payouts = $store->payouts($id);
        $this->require(array_is_list($payouts));
        $paid = BigDecimal::zero();
        $reserved = BigDecimal::zero();
        $seen = [];
        foreach ($payouts as $payout) {
            $payoutId = $payout['id'] ?? null;
            $state = $payout['state'] ?? null;
            $this->require(Greenfield::validId($payoutId) && !isset($seen[$payoutId]) && ($payout['pullPaymentId'] ?? null) === $id
                && ($payout['originalCurrency'] ?? null) === $operation->currency_code && ($payout['payoutMethodId'] ?? null) === $context['payout_method']
                && in_array($state, ['AwaitingApproval', 'AwaitingPayment', 'InProgress', 'Completed', 'Cancelled'], true));
            $seen[$payoutId] = true;
            $amount = BigDecimal::of($this->money($payout['originalAmount'] ?? null));
            $this->require($amount->isPositive());
            if ($state !== 'Cancelled') {
                $reserved = $reserved->plus($amount);
            }
            if ($state === 'Completed') {
                $this->verifyCompletedPayout($payout, $context['payout_method']);
                $paid = $paid->plus($amount);
            }
        }
        $this->require($reserved->isLessThanOrEqualTo($operation->amount));
        if ($paid->isEqualTo($operation->amount)) {
            return $this->verified($operation, $context, $id, 'succeeded', 'btcpay_refund_completed');
        }
        if ($pull['archived']) {
            $this->require($reserved->isZero());

            return $this->verified($operation, $context, $id, 'failed', 'btcpay_claim_archived');
        }

        return $this->verified($operation, $context, $id, 'pending', $reserved->isZero() ? 'btcpay_awaiting_claim' : 'btcpay_payout_pending');
    }

    private function verifyCompletedPayout(array $payout, string $method): void
    {
        $proof = $payout['paymentProof'] ?? null;
        $this->require(is_array($proof) && ($payout['payoutCurrency'] ?? null) === 'BTC'
            && Greenfield::decimal($payout['payoutAmount'] ?? null)?->isPositive() === true);
        $fields = array_change_key_case($proof, CASE_LOWER);
        $this->require(count($proof) === count($fields));
        $type = $method === 'BTC-CHAIN' ? 'PayoutTransactionOnChainBlob' : 'PayoutLightningBlob';
        $identity = $fields[$method === 'BTC-CHAIN' ? 'transactionid' : 'paymenthash'] ?? null;
        $this->require(($fields['prooftype'] ?? null) === $type && is_string($identity) && preg_match('/\A[0-9a-f]{64}\z/i', $identity) === 1);
        if ($method === 'BTC-CHAIN') {
            $this->require(($fields['accounted'] ?? true) === true);
        } elseif (isset($fields['preimage'])) {
            $this->require(is_string($fields['preimage']) && preg_match('/\A[0-9a-f]{64}\z/i', $fields['preimage']) === 1
                && hash('sha256', hex2bin($fields['preimage'])) === strtolower($identity));
        }
    }

    private function verifiedPreviousRefunds(Greenfield $store, string $invoiceId, int $transaction): string
    {
        $operations = PaymentOperation::where('gateway_id', $this->gateway->id)->where('kind', 'provider_refund')
            ->whereNotNull('provider_reference')->get();
        $known = $operations->keyBy('provider_reference');
        $this->require($known->count() === $operations->count());
        $expected = $known->filter(fn (PaymentOperation $operation) => $operation->original_transaction_id === $transaction);
        $total = BigDecimal::zero();
        $seen = [];
        foreach ($store->pullPayments() as $pull) {
            $id = $pull['id'] ?? null;
            $this->require(is_array($pull) && Greenfield::validId($id) && !isset($seen[$id]));
            $seen[$id] = true;
            $operation = $known->get($id);
            if ($operation === null) {
                // The store list omits the invoice relation, even for custom
                // refund names. An unbound claim may already refund this invoice.
                if (($pull['archived'] ?? null) !== true || collect($store->payouts($id))->contains(fn ($payout) => ($payout['pullPaymentId'] ?? null) !== $id || ($payout['state'] ?? null) !== 'Cancelled')) {
                    throw new RuntimeException('An unbound BTCPay pull payment requires refund reconciliation.');
                }

                continue;
            }
            $binding = $operation->payload['provider_context'];
            $linkedInvoice = $binding['btcpay_invoice_id'] ?? null;
            $this->require(Greenfield::validId($linkedInvoice) && ($binding['merchant_fingerprint'] ?? null) === $store->merchantFingerprint());
            $this->verifyPullPayment($pull, $operation, $linkedInvoice);
            if ($operation->original_transaction_id !== $transaction) {
                $this->require($linkedInvoice !== $invoiceId);

                continue;
            }
            $this->require($linkedInvoice === $invoiceId && in_array($operation->state, ['succeeded', 'failed'], true));
            $expected->forget($id);
            if ($operation->state === 'succeeded') {
                $total = $total->plus($operation->amount);
            } else {
                $this->require($pull['archived']);
            }
        }
        $this->require($expected->isEmpty());

        return (string) $total->toScale(2);
    }

    private function invoicePullPayments(Greenfield $store, string $invoiceId): array
    {
        $pulls = $store->pullPayments();
        $this->require(array_is_list($pulls));

        return array_filter($pulls, function ($pull) use ($invoiceId) {
            $this->require(is_array($pull));
            $name = $pull['name'] ?? '';
            $this->require(is_string($name));

            return str_starts_with($name, $this->refundName($invoiceId, ''))
                || $name === 'Refund ' . $invoiceId || ($pull['description'] ?? null) === $this->description($invoiceId);
        });
    }

    private function verifyPullPayment(array $pull, PaymentOperation $operation, string $invoiceId): string
    {
        $id = $pull['id'] ?? null;
        $this->require(Greenfield::validId($id) && ($pull['name'] ?? null) === $this->refundName($invoiceId, $operation->request_key)
            && ($pull['description'] ?? null) === $this->description($invoiceId) && ($pull['currency'] ?? null) === $operation->currency_code
            && $this->money($pull['amount'] ?? null) === $operation->amount && is_bool($pull['archived'] ?? null)
            && ($pull['autoApproveClaims'] ?? null) === false);

        return $id;
    }

    private function refundName(string $invoice, string $key): string
    {
        return 'Paymenter refund ' . $invoice . ' ' . $key;
    }

    private function description(string $invoice): string
    {
        return 'BTCPay invoice ' . $invoice;
    }

    private function store(): Greenfield
    {
        return new Greenfield($this->config('server_url'), $this->config('store_id'), $this->config('api_key'));
    }
}
