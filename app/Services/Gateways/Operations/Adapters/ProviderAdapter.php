<?php

namespace App\Services\Gateways\Operations\Adapters;

use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\Operations\OperationResult;
use App\Services\Gateways\Operations\ProviderJson;
use Brick\Math\BigDecimal;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

abstract class ProviderAdapter implements Adapter
{
    protected array $settings;

    public function __construct(protected Gateway $gateway)
    {
        $this->settings = $gateway->settings->pluck('value', 'key')->all();
        ksort($this->settings);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([1, $this->gateway->id, $this->gateway->extension, $this->settings], JSON_THROW_ON_ERROR));
    }

    protected function config(string $key): string
    {
        return (string) ($this->settings[$key] ?? '');
    }

    protected function require(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('Provider identity, configuration or operation eligibility could not be verified.');
        }
    }

    protected function id(mixed $value): string
    {
        $this->require(is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,190}$/D', $value) === 1);

        return $value;
    }

    protected function money(mixed $amount): string
    {
        $this->require((is_string($amount) || is_int($amount)) && preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', (string) $amount) === 1);

        return (string) BigDecimal::of($amount)->toScale(2);
    }

    protected function minor(mixed $amount): string
    {
        $this->require(is_int($amount) && $amount >= 0);

        return (string) BigDecimal::of($amount)->dividedBy(100, 2);
    }

    protected function units(string $amount): int
    {
        return BigDecimal::of($this->money($amount))->multipliedBy(100)->toInt();
    }

    protected function currency(string $currency): void
    {
        // Explicitly supported two-decimal currencies; special exponents fail closed.
        $this->require(in_array($currency, ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK'], true));
    }

    protected function json(PendingRequest $request, string $method, string $base, string $path, array $data = [], bool $form = false): array
    {
        $this->require(DB::transactionLevel() === 0 && str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '#'));
        try {
            $request = ($form ? $request->asForm() : $request->asJson())->withoutRedirecting()->connectTimeout(10)->timeout(25)->acceptJson();
            $options = $method === 'GET' ? ($data === [] ? [] : ['query' => $data]) : [$form ? 'form_params' : 'json' => $data];
            $response = $request->send($method, $base . $path, $options);
            $this->require($response->successful() && strlen($response->body()) <= 1048576);
            $body = ProviderJson::decode($response->body());
            $this->require(is_array($body));

            return $body;
        } catch (Throwable) {
            // Never surface a provider body, credentials, or transport exception.
            throw new RuntimeException('Provider request could not be verified; reconcile before retrying.');
        }
    }

    final public function prepare(Invoice $invoice, ?InvoiceTransaction $transaction, string $kind, string $providerReference, string $amount, string $currency): array
    {
        $this->require(in_array($kind, ['provider_refund', 'provider_capture'], true) && $invoice->currency_code === $currency);
        $this->currency($currency);
        $this->require($this->money($amount) === $amount && BigDecimal::of($amount)->isPositive());
        $this->id($providerReference);
        $attempt = $this->attempt($invoice, $transaction, $kind, $providerReference);
        if ($transaction) {
            $this->require($transaction->invoice_id === $invoice->id && $transaction->gateway_id === $this->gateway->id &&
                $transaction->settlement_origin !== 'manual_record' && ($attempt !== null || $transaction->transaction_id === $providerReference));
        } else {
            $this->require($kind === 'provider_capture' && $attempt !== null);
        }
        $context = ['authenticated' => true, 'invoice_id' => $invoice->id, 'gateway_id' => $this->gateway->id,
            'transaction_id' => $transaction?->id, 'attempt_id' => $attempt?->id, 'attempt_reference' => $attempt?->reference,
            'merchant_fingerprint' => $attempt?->merchant_fingerprint, 'original_reference' => $providerReference, 'amount' => $amount, 'currency' => $currency,
            'kind' => $kind];
        $context = array_merge($context, $this->inspect($context, $attempt, true));
        $this->require($context['original_amount'] === $this->money($transaction?->amount ?? $attempt->amount));
        $this->require(BigDecimal::of($amount)->isLessThanOrEqualTo(BigDecimal::of($context['original_amount'])->minus($context['already_refunded'])));

        return $context;
    }

    protected function attempt(Invoice $invoice, ?InvoiceTransaction $transaction, string $kind, string $reference): ?GatewayPaymentAttempt
    {
        $query = GatewayPaymentAttempt::where('invoice_id', $invoice->id)->where('gateway_id', $this->gateway->id);
        $matches = $kind === 'provider_capture'
            ? $query->where('state', 'open')->where('provider_reference', $reference)->get()
            : $query->where('state', 'paid')->where('provider_transaction_id', $reference)->get();
        $this->require($matches->count() <= 1);
        $attempt = $matches->first();
        if ($attempt) {
            $this->require($attempt->user_id === $invoice->user_id && $attempt->currency_code === $invoice->currency_code &&
                ($kind === 'provider_capture' || $transaction?->transaction_id === 'gateway:' . $this->gateway->id . ':' . $reference));
        }

        return $attempt;
    }

    protected function current(PaymentOperation $operation, bool $eligible): array
    {
        $context = $operation->payload['provider_context'];
        $this->require($operation->gateway_id === $this->gateway->id && $context['gateway_id'] === $this->gateway->id &&
            $operation->kind === $context['kind'] && $operation->amount === $context['amount'] && $operation->currency_code === $context['currency']);
        $attempt = $context['attempt_id'] === null ? null : GatewayPaymentAttempt::findOrFail($context['attempt_id']);
        if ($attempt) {
            $this->require($attempt->gateway_id === $context['gateway_id'] && $attempt->invoice_id === $context['invoice_id'] &&
                $attempt->reference === $context['attempt_reference'] && $attempt->merchant_fingerprint === $context['merchant_fingerprint']);
        }
        $read = $this->inspect($context, $attempt, $eligible);
        foreach ($read as $key => $value) {
            if ($key !== 'already_refunded' && array_key_exists($key, $context)) {
                $this->require($context[$key] === $value);
            }
        }
        if ($eligible) {
            $this->require($read['already_refunded'] === $context['already_refunded']);
        }

        return array_merge($context, $read);
    }

    public function execute(PaymentOperation $operation): OperationResult
    {
        try {
            $this->require($operation->state === 'processing');
            $context = $this->current($operation, true);
            $id = $this->write($operation, $context);

            return $this->read($operation, $id);
        } catch (Throwable) {
            return new OperationResult('uncertain', $id ?? null, outcomeCode: 'write_outcome_unknown');
        }
    }

    public function reconcile(PaymentOperation $operation): OperationResult
    {
        try {
            return $this->read($operation, $operation->provider_reference);
        } catch (Throwable) {
            return new OperationResult('uncertain', $operation->provider_reference, outcomeCode: 'readback_unverified');
        }
    }

    protected function verified(PaymentOperation $operation, array $context, string $reference, string $state, string $code = 'provider_readback'): OperationResult
    {
        $evidence = array_merge($context, ['authenticated' => true, 'request_key' => $operation->request_key,
            'provider_reference' => $reference, 'failure_proven' => $state === 'failed']);
        $result = new OperationResult($state, $reference, $evidence, $code);
        $result->assertVerified($operation);

        return $result;
    }

    abstract protected function inspect(array $context, ?GatewayPaymentAttempt $attempt, bool $eligible): array;

    abstract protected function write(PaymentOperation $operation, array $context): string;

    abstract protected function read(PaymentOperation $operation, ?string $reference): OperationResult;
}
