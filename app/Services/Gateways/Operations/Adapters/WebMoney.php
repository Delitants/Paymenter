<?php

namespace App\Services\Gateways\Operations\Adapters;

use App\Models\GatewayPaymentAttempt;
use App\Models\PaymentOperation;
use App\Services\Gateways\Operations\AcceptedRequest;
use App\Services\Gateways\Operations\OperationResult;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

final class WebMoney extends ProviderAdapter
{
    public function capabilities(): array
    {
        $configured = !filter_var($this->config('test_mode'), FILTER_VALIDATE_BOOLEAN) &&
            filter_var($this->config('wm_exclusive_sequence'), FILTER_VALIDATE_BOOLEAN) &&
            $this->config('wm_certificate') !== '' && $this->config('wm_private_key') !== '' && preg_match('/^[0-9]{12}$/D', $this->config('wm_wmid')) === 1;

        return ['refund' => $configured, 'capture' => false, 'reconcile' => $configured,
            'reason' => $configured ? 'capture_not_supported' : 'certificate_authorization_required_test_mode_unsupported'];
    }

    private function xml(string $body): SimpleXMLElement
    {
        $this->require(strlen($body) <= 1048576 && !preg_match('/<!DOCTYPE|<!ENTITY/i', $body));
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
            $this->require($xml !== false);

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function certificates(): array
    {
        $certificate = @openssl_x509_read($this->config('wm_certificate'));
        $key = @openssl_pkey_get_private($this->config('wm_private_key'), $this->config('wm_key_passphrase'));
        $this->require($certificate !== false && $key !== false && openssl_x509_check_private_key($certificate, $key));
        $details = openssl_x509_parse($certificate);
        $this->require(is_array($details) && ($details['validFrom_time_t'] ?? PHP_INT_MAX) <= now()->timestamp && ($details['validTo_time_t'] ?? 0) > now()->timestamp);
        $paths = [];
        try {
            foreach (['wm_certificate', 'wm_private_key'] as $field) {
                $path = tempnam(sys_get_temp_dir(), 'paymenter-wm-');
                $this->require($path !== false);
                $paths[] = $path;
                $this->require(chmod($path, 0600) && file_put_contents($path, $this->config($field)) !== false);
            }

            return $paths;
        } catch (Throwable) {
            foreach ($paths as $path) {
                @unlink($path);
            }
            throw new RuntimeException('Certificate configuration could not be used.');
        }
    }

    /** Serialize allocation and its one request across this database; no SQL transaction spans HTTP. */
    private function certificateRequest(string $endpoint, string $parameters, ?PaymentOperation $operation = null): SimpleXMLElement
    {
        $this->require($this->capabilities()['refund'] && DB::transactionLevel() === 0 && in_array($endpoint, ['XMLPursesCert.asp', 'XMLOperationsCert.asp', 'XMLTransMoneybackCert.asp'], true));
        $scope = hash('sha256', 'webmoney-global');
        $lock = 'paymenter-wm-' . substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 48);
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $this->require((int) $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock], false)->acquired === 1);
        $paths = [];
        try {
            $paths = $this->certificates();
            $reqn = DB::transaction(function () use ($scope, $operation) {
                $row = DB::table('gateway_operation_sequences')->where('scope', $scope)->lockForUpdate()->first();
                $floor = $this->config('wm_sequence_floor');
                $this->require(preg_match('/^[0-9]{1,15}$/D', $floor) === 1);
                $last = max($row ? (int) $row->last_reqn : 0, (int) $floor);
                $this->require($last < 999999999999999);
                $next = $last + 1;
                if ($row) {
                    DB::table('gateway_operation_sequences')->where('scope', $scope)->update(['last_reqn' => $next]);
                } else {
                    DB::table('gateway_operation_sequences')->insert(['scope' => $scope, 'last_reqn' => $next]);
                }
                if ($operation !== null) {
                    (new AcceptedRequest)->claim($operation, 'WebMoney', 'x14_refund', $scope, $next);
                }

                return $next;
            });
            $this->require($connection->getPdo() === $pdo);
            $body = '<w3s.request><reqn>' . $reqn . '</reqn>' . $parameters . '</w3s.request>';
            $response = Http::withOptions(['cert' => $paths[0], 'ssl_key' => [$paths[1], $this->config('wm_key_passphrase')]])
                ->withBody($body, 'application/xml')->withoutRedirecting()->connectTimeout(10)->timeout(25)
                ->post('https://w3s.wmtransfer.com/asp/' . $endpoint);
            $this->require($response->successful());
            $xml = $this->xml($response->body());
            $this->require($xml->getName() === 'w3s.response' && (string) $xml->reqn === (string) $reqn && (string) $xml->retval === '0');

            return $xml;
        } catch (Throwable) {
            throw new RuntimeException('Certificate-authorized provider request could not be verified; do not retry a refund.');
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
            if ($connection->getPdo() === $pdo) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false);
            }
        }
    }

    private function owner(): void
    {
        $this->require(preg_match('/^Z[0-9]{12}$/D', $this->config('purse')) === 1 && $this->config('currency') === 'USD');
        $xml = $this->certificateRequest('XMLPursesCert.asp', '<getpurses><wmid>' . $this->config('wm_wmid') . '</wmid></getpurses>');
        $purses = $xml->purses->purse;
        $this->require((int) $xml->purses['cnt'] === count($purses));
        $matches = 0;
        foreach ($purses as $purse) {
            $matches += (string) $purse->pursename === $this->config('purse') ? 1 : 0;
        }
        $this->require($matches === 1);
    }

    private function mapping(string $reference): SimpleXMLElement
    {
        $this->require(preg_match('/^[1-9][0-9]{0,19}$/D', $reference) === 1 && $this->config('secret') !== '');
        $hash = hash('sha256', $this->config('wm_wmid') . $this->config('purse') . $reference . $this->config('secret'));
        $body = '<merchant.request><wmid>' . $this->config('wm_wmid') . '</wmid><lmi_payee_purse>' . $this->config('purse') . '</lmi_payee_purse><lmi_payment_no>' . $reference . '</lmi_payment_no><lmi_payment_no_type>3</lmi_payment_no_type><sha256>' . $hash . '</sha256></merchant.request>';
        $response = Http::withBody($body, 'application/xml')->withoutRedirecting()->connectTimeout(10)->timeout(25)->post('https://merchant.webmoney.ru/conf/xml/XMLTransGet.asp');
        $this->require($response->successful());
        $xml = $this->xml($response->body());
        $this->require($xml->getName() === 'merchant.response' && (string) $xml->retval === '0' && count($xml->operation) === 1 && (string) $xml->operation['wmtransid'] === $reference);
        $mapping = $xml->operation;
        $this->require((string) $mapping->hold_period === '0' && (string) $mapping->hold_state === '0' &&
            (string) $mapping->capitallerflag === '0' && (string) $mapping->paymer_number === '' && (string) $mapping->sdp_type === '0');

        return $mapping;
    }

    private function history(?string $reference = null): SimpleXMLElement
    {
        $parameters = '<getoperations><purse>' . $this->config('purse') . '</purse><wmtranid>' . ($reference ?? '0') . '</wmtranid><tranid>0</tranid><wminvid>0</wminvid><orderid>0</orderid><datestart>' . now('Europe/Moscow')->subDays(90)->format('Ymd H:i:s') . '</datestart><datefinish>' . now('Europe/Moscow')->format('Ymd H:i:s') . '</datefinish></getoperations>';
        $xml = $this->certificateRequest('XMLOperationsCert.asp', $parameters);
        $this->require((int) $xml->operations['cnt'] === count($xml->operations->operation));

        return $xml->operations;
    }

    protected function inspect(array $context, ?GatewayPaymentAttempt $attempt, bool $eligible): array
    {
        $this->require($this->capabilities()['refund'] && $context['kind'] === 'provider_refund' && $attempt !== null && $context['currency'] === 'USD' &&
            $attempt->merchant_fingerprint === hash('sha256', $this->config('purse') . ':live'));
        $this->owner();
        $mapping = $this->mapping($context['original_reference']);
        $operations = $this->history($context['original_reference']);
        $this->require(count($operations->operation) === 1);
        $original = $operations->operation;
        $this->require((string) $original['id'] === $context['original_reference'] && (string) $original->pursedest === $this->config('purse') &&
            (string) $original->pursesrc === (string) $mapping->pursefrom && preg_match('/^Z[0-9]{12}$/D', (string) $original->pursesrc) === 1 &&
            (string) $original->opertype === '0' && (string) $original->period === '0' && (string) $original->orderid === $attempt->reference &&
            $this->money((string) $original->amount) === $this->money((string) $mapping->amount));
        $date = CarbonImmutable::createFromFormat('!Ymd H:i:s', (string) $original->datecrt, 'Europe/Moscow');
        $this->require($date !== false && $date->greaterThan(now()->subDays(89)) && $date->lessThanOrEqualTo(now()));
        $refunded = BigDecimal::zero();
        if ($eligible) {
            $seen = [];
            foreach ($this->history()->operation as $entry) {
                $id = (string) $entry['id'];
                $this->require(!isset($seen[$id]));
                $seen[$id] = true;
                if (str_starts_with((string) $entry->desc, 'Moneyback transaction WMTranId: ' . $context['original_reference'] . '. (')) {
                    $this->require((string) $entry->pursesrc === $this->config('purse') && (string) $entry->pursedest === (string) $original->pursesrc && (string) $entry->opertype === '0');
                    $refunded = $refunded->plus($this->money((string) $entry->amount));
                }
            }
        }

        return ['merchant' => $this->config('wm_wmid') . ':' . $this->config('purse'), 'environment' => 'live', 'provider_object_type' => 'wm_transaction',
            'original_amount' => $this->money((string) $original->amount), 'already_refunded' => $eligible ? (string) $refunded->toScale(2) : $context['already_refunded'],
            'payer_purse' => (string) $original->pursesrc];
    }

    protected function write(PaymentOperation $operation, array $context): string
    {
        $xml = $this->certificateRequest('XMLTransMoneybackCert.asp', '<trans><inwmtranid>' . $context['original_reference'] . '</inwmtranid><amount>' . $context['amount'] . '</amount></trans>', $operation);
        $this->require(count($xml->operation) === 1);
        $child = $xml->operation;
        $id = $this->id((string) $child['id']);
        $this->require((string) $child->inwmtranid === $context['original_reference'] && (string) $child->pursesrc === $this->config('purse') &&
            (string) $child->pursedest === $context['payer_purse'] && $this->money((string) $child->amount) === $context['amount'] &&
            $this->money((string) $child->comiss) === '0.00');
        (new AcceptedRequest)->accept($operation, 'WebMoney', 'x14_refund', ['reference' => $id, 'original' => $context['original_reference'],
            'request_key' => $operation->request_key, 'reqn' => (string) $xml->reqn]);

        return $id;
    }

    protected function read(PaymentOperation $operation, ?string $reference): OperationResult
    {
        $proof = (new AcceptedRequest)->proof($operation, 'WebMoney', 'x14_refund');
        $this->require($proof !== null && ($proof['request_key'] ?? null) === $operation->request_key &&
            ($proof['original'] ?? null) === $operation->payload['provider_context']['original_reference'] && ($reference === null || $reference === $proof['reference']));
        $context = $this->current($operation, false);
        $reference = $this->id($proof['reference']);
        $operations = $this->history($reference);
        $this->require(count($operations->operation) === 1);
        $child = $operations->operation;
        $this->require((string) $child['id'] === $reference && (string) $child->pursesrc === $this->config('purse') && (string) $child->pursedest === $context['payer_purse'] &&
            $this->money((string) $child->amount) === $context['amount'] && (string) $child->opertype === '0' &&
            str_starts_with((string) $child->desc, 'Moneyback transaction WMTranId: ' . $context['original_reference'] . '. ('));

        return $this->verified($operation, $context, $reference, 'succeeded');
    }
}
