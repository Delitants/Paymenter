<?php

namespace Paymenter\Extensions\Gateways\BTCPay;

use App\Services\Gateways\Operations\ProviderJson;
use Brick\Math\BigDecimal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class Greenfield
{
    private readonly string $url;

    public function __construct(string $url, public readonly string $storeId, private readonly string $apiKey)
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !$parts || ($parts['scheme'] ?? null) !== 'https'
            || array_intersect_key($parts, array_flip(['user', 'pass', 'query', 'fragment']))
            || !preg_match('~\A(?:/[a-zA-Z0-9_-]+)*\z~', $parts['path'] ?? '')
            || !self::validId($storeId) || !preg_match('/\A[0-9a-f]{40}\z/i', $apiKey)) {
            throw new RuntimeException('BTCPay requires a valid HTTPS server, store ID and Greenfield API key.');
        }

        $this->url = $url;
    }

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $id) === 1;
    }

    public static function decimal(mixed $value): ?BigDecimal
    {
        return (is_string($value) || is_int($value)) && strlen((string) $value) <= 64
            && preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', (string) $value)
            ? BigDecimal::of($value) : null;
    }

    public function merchantFingerprint(): string
    {
        return hash('sha256', $this->url . ':' . $this->storeId . ':USD');
    }

    public function store(): array
    {
        return $this->request('GET', $this->storePath());
    }

    public function createInvoice(array $terms): array
    {
        return $this->request('POST', $this->storePath() . '/invoices', $terms);
    }

    public function invoice(string $id): array
    {
        if (!self::validId($id)) {
            throw new RuntimeException('Invalid BTCPay invoice identity.');
        }

        return $this->request('GET', $this->storePath() . '/invoices/' . $id);
    }

    public function findInvoiceIds(string $order): array
    {
        $list = $this->request('GET', $this->storePath() . '/invoices', [
            'orderId' => $order, 'includeArchived' => 'true', 'take' => 2,
        ]);
        if (!array_is_list($list)) {
            throw new RuntimeException('BTCPay returned an invalid invoice list.');
        }
        $invoices = [];
        foreach ($list as $invoice) {
            $id = $invoice['id'] ?? null;
            if (!self::validId($id) || ($invoice['metadata']['orderId'] ?? null) !== $order) {
                throw new RuntimeException('BTCPay returned a mismatched checkout order.');
            }
            $invoices[] = $id;
        }

        return $invoices;
    }

    public function paymentMethods(string $id): array
    {
        if (!self::validId($id)) {
            throw new RuntimeException('Invalid BTCPay invoice identity.');
        }

        return $this->request('GET', '/invoices/' . $id . '/payment-methods', [
            'includeSensitive' => 'false', 'onlyAccountedPayments' => 'true',
        ]);
    }

    public function checkoutLink(string $id): string
    {
        return $this->url . '/i/' . $id;
    }

    private function storePath(): string
    {
        return '/stores/' . $this->storeId;
    }

    private function request(string $method, string $path, array $parameters = []): array
    {
        $url = $this->url . '/api/v1' . $path;
        try {
            $client = Http::withToken($this->apiKey, 'token')->acceptJson()->asJson()
                ->withoutRedirecting()->connectTimeout(5)->timeout(15);
            $response = $method === 'POST' ? $client->post($url, $parameters) : $client->get($url, $parameters);
            if ($response->successful()) {
                return ProviderJson::decode($response->body());
            }
        } catch (ConnectionException) {
            // A timeout can follow a committed request. The durable attempt is reconciled with GET.
        }

        throw new RuntimeException('BTCPay could not verify the request outcome.');
    }
}
