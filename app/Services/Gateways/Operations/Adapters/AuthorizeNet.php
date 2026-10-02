<?php

namespace App\Services\Gateways\Operations\Adapters;

use App\Models\GatewayPaymentAttempt;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\AcceptedRequest;
use App\Services\Gateways\Operations\OperationResult;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AuthorizeNet extends ProviderAdapter
{
    public function capabilities(): array
    {
        return ['refund' => $this->config('api_login_id') !== '' && $this->config('transaction_key') !== '', 'capture' => false, 'reconcile' => true, 'reason' => 'native_hosted_flow_has_no_auth_only_attempt'];
    }

    private function api(string $name, array $data): array
    {
        $this->require(in_array($this->config('environment'), ['test', 'production'], true) && $this->config('currency') === 'USD');
        $base = $this->config('environment') === 'test' ? 'https://apitest.authorize.net' : 'https://api.authorize.net';
        $body = $this->json(Http::asJson(), 'POST', $base, '/xml/v1/request.api', [$name => array_merge([
            'merchantAuthentication' => ['name' => $this->config('api_login_id'), 'transactionKey' => $this->config('transaction_key')]], $data)]);
        $this->require(($body['messages']['resultCode'] ?? null) === 'Ok');

        return $body;
    }

    private function transaction(string $id): array
    {
        $this->require(preg_match('/^[0-9]+$/D', $id) === 1);
        $transaction = $this->api('getTransactionDetailsRequest', ['transId' => $id])['transaction'] ?? [];
        $this->require((string) ($transaction['transId'] ?? '') === $id && (!isset($transaction['currencyCode']) || $transaction['currencyCode'] === 'USD'));

        return $transaction;
    }

    protected function inspect(array $context, ?GatewayPaymentAttempt $attempt, bool $eligible): array
    {
        $this->require($attempt !== null && $context['currency'] === 'USD' && $context['kind'] === 'provider_refund' &&
            $attempt->merchant_fingerprint === hash('sha256', $this->config('api_login_id') . ':' . $this->config('environment') . ':USD'));
        $original = $this->transaction($context['original_reference']);
        $this->require(($original['order']['invoiceNumber'] ?? null) === $attempt->reference && in_array($original['transactionType'] ?? null, ['authCaptureTransaction', 'priorAuthCaptureTransaction'], true));
        $amount = $this->money($original['authAmount'] ?? null);
        $submitted = CarbonImmutable::parse($original['submitTimeUTC'] ?? throw new RuntimeException('Missing provider transaction date.'));
        $this->require($submitted->lessThanOrEqualTo(now()));
        $status = $original['transactionStatus'] ?? null;
        $action = $context['action'] ?? ($status === 'capturedPendingSettlement' ? 'voidTransaction' : 'refundTransaction');
        if ($eligible) {
            $this->require(in_array($status, ['settledSuccessfully', 'capturedPendingSettlement'], true) && $submitted->greaterThan(now()->subDays(180)));
            $this->require($this->money($original['settleAmount'] ?? null) === $amount);
            if ($action === 'voidTransaction') {
                $this->require($context['amount'] === $amount);
            }
            // No PAN ever enters operation context; masked last four is fetched again for write.
            $card = $original['payment']['creditCard']['cardNumber'] ?? null;
            $this->require(is_string($card) && preg_match('/^X{4,16}[0-9]{4}$/D', $card) === 1 && !isset($original['payment']['bankAccount']));
        }
        $refunded = $eligible ? $this->refunded($context['original_reference'], $submitted) : $context['already_refunded'];

        return ['merchant' => $this->config('api_login_id'), 'environment' => $this->config('environment'), 'provider_object_type' => 'transaction',
            'original_amount' => $amount, 'already_refunded' => $refunded, 'action' => $action];
    }

    private function refunded(string $original, CarbonImmutable $submitted): string
    {
        $requests = [['getUnsettledTransactionListRequest', []]];
        $end = CarbonImmutable::now();
        for ($start = $submitted; $start->lessThan($end); $start = $finish) {
            $finish = $start->addDays(30)->min($end);
            $batches = $this->api('getSettledBatchListRequest', ['firstSettlementDate' => $start->toIso8601String(), 'lastSettlementDate' => $finish->toIso8601String()]);
            $this->require(is_array($batches['batchList'] ?? null) && count($batches['batchList']) <= 200);
            foreach ($batches['batchList'] as $batch) {
                $requests[] = ['getTransactionListRequest', ['batchId' => $this->id((string) ($batch['batchId'] ?? ''))]];
            }
        }
        $sum = BigDecimal::zero();
        $seen = [];
        foreach ($requests as [$name, $data]) {
            for ($page = 1; $page <= 100; $page++) {
                $list = $this->api($name, array_merge($data, ['paging' => ['limit' => 100, 'offset' => $page]]));
                $this->require(is_array($list['transactions'] ?? null) && isset($list['totalNumInResultSet']) && is_numeric($list['totalNumInResultSet']));
                foreach ($list['transactions'] as $entry) {
                    $id = (string) ($entry['transId'] ?? '');
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    if (!in_array($entry['transactionStatus'] ?? null, ['refundPendingSettlement', 'refundSettledSuccessfully'], true)) {
                        continue;
                    }
                    $refund = $this->transaction($id);
                    if ((string) ($refund['refTransId'] ?? '') === $original) {
                        $this->require(($refund['transactionType'] ?? null) === 'refundTransaction');
                        $sum = $sum->plus($this->money($refund['settleAmount'] ?? null));
                    }
                }
                if ($page * 100 >= (int) $list['totalNumInResultSet']) {
                    break;
                }
                $this->require(count($list['transactions']) > 0 && $page < 100);
            }
        }

        return (string) $sum->toScale(2);
    }

    protected function write(PaymentOperation $operation, array $context): string
    {
        $original = $this->transaction($context['original_reference']);
        $action = $context['action'];
        $refId = substr(hash('sha256', $operation->request_key), 0, 20);
        $data = ['transactionType' => $action, 'refTransId' => $context['original_reference'],
            'transactionSettings' => ['setting' => [['settingName' => 'emailCustomer', 'settingValue' => 'false']]]];
        if ($action === 'refundTransaction') {
            $card = $original['payment']['creditCard']['cardNumber'] ?? null;
            $this->require(is_string($card) && preg_match('/^X{4,16}([0-9]{4})$/D', $card, $digits) === 1 &&
                ($original['transactionStatus'] ?? null) === 'settledSuccessfully');
            $data += ['amount' => $context['amount'], 'payment' => ['creditCard' => ['cardNumber' => $digits[1], 'expirationDate' => 'XXXX']],
                'order' => ['invoiceNumber' => $context['attempt_reference'], 'description' => $operation->request_key]];
        } else {
            $this->require($action === 'voidTransaction' && ($original['transactionStatus'] ?? null) === 'capturedPendingSettlement' && $context['amount'] === $context['original_amount']);
        }
        $binding = new AcceptedRequest;
        $binding->claim($operation, 'AuthorizeNet', $action);
        $response = $this->api('createTransactionRequest', ['refId' => $refId, 'transactionRequest' => $data]);
        $transaction = $response['transactionResponse'] ?? [];
        $this->require(($response['refId'] ?? null) === $refId && (string) ($transaction['responseCode'] ?? '') === '1');
        $id = $this->id((string) ($transaction['transId'] ?? ''));
        $this->require(preg_match('/^[1-9][0-9]*$/D', $id) === 1 && ($action === 'voidTransaction'
            ? $id === $context['original_reference'] : (string) ($transaction['refTransID'] ?? '') === $context['original_reference']));
        $binding->accept($operation, 'AuthorizeNet', $action, ['reference' => $id, 'original' => $context['original_reference'], 'request_key' => $operation->request_key, 'ref_id' => $refId]);

        return $id;
    }

    protected function read(PaymentOperation $operation, ?string $reference): OperationResult
    {
        $context = $this->current($operation, false);
        $proof = (new AcceptedRequest)->proof($operation, 'AuthorizeNet', $context['action']);
        $this->require($proof !== null && ($proof['request_key'] ?? null) === $operation->request_key && ($proof['original'] ?? null) === $context['original_reference'] &&
            ($proof['ref_id'] ?? null) === substr(hash('sha256', $operation->request_key), 0, 20));
        $this->require($reference === null || $reference === $proof['reference']);
        $reference = $this->id($proof['reference']);
        $child = $this->transaction($reference);
        $this->require(($child['order']['invoiceNumber'] ?? null) === $context['attempt_reference']);
        if ($context['action'] === 'voidTransaction') {
            $this->require($reference === $context['original_reference'] && $this->money($child['authAmount'] ?? null) === $context['amount']);
            $state = ($child['transactionStatus'] ?? null) === 'voided' ? 'succeeded' : 'uncertain';

            return $this->verified($operation, $context, $reference, $state, 'provider_void');
        }
        $this->require(($child['transactionType'] ?? null) === 'refundTransaction' && (string) ($child['refTransId'] ?? '') === $context['original_reference'] &&
            ($child['order']['description'] ?? null) === $operation->request_key && $this->money($child['settleAmount'] ?? null) === $context['amount']);
        $state = match ($child['transactionStatus'] ?? null) {
            'refundSettledSuccessfully' => 'succeeded', 'refundPendingSettlement' => 'pending', 'voided', 'declined' => 'failed', default => 'uncertain',
        };

        return $this->verified($operation, $context, $reference, $state, 'provider_refund');
    }
}
