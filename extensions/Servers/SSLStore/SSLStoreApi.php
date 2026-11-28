<?php

namespace Paymenter\Extensions\Servers\SSLStore;

use App\Listeners\RequestListener;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class SSLStoreApi
{
    private const ORIGINS = ['https://api.thesslstore.com', 'https://api.thesslstore.eu', 'https://sandbox-wbapi.thesslstore.com'];

    private string $origin;

    private string $enrollmentHost;

    public function __construct(private readonly array $settings)
    {
        $this->origin = rtrim((string) ($settings['url'] ?? ''), '/');
        $this->enrollmentHost = strtolower(trim((string) ($settings['enrollment_host'] ?? '')));
        if (!in_array($this->origin, self::ORIGINS, true)
            || !str_contains($this->enrollmentHost, '.')
            || !filter_var($this->enrollmentHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($this->enrollmentHost, FILTER_VALIDATE_IP)
            || empty($settings['partner_code']) || empty($settings['auth_token'])) {
            throw new RuntimeException('SSLStore ordering requires a supported API origin, credentials, and a verified enrollment hostname');
        }
    }

    public function merchant(): string
    {
        return hash('sha256', $this->origin . "\0" . $this->settings['partner_code'] . "\0" . $this->enrollmentHost);
    }

    public function ordersEnabled(): bool
    {
        return filter_var($this->settings['orders_enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    public function renewalsEnabled(): bool
    {
        return filter_var($this->settings['renewals_enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    public function verifyOffer(string $code, int $months, string $currency): void
    {
        $products = $this->post('/rest/product/query', [
            'ProductType' => 0, 'ProductCode' => $code, 'IsForceNewSKUs' => true,
        ]);
        if (array_is_list($products) && count($products) === 1 && is_array($product = $products[0])
            && $this->accepted($product) && ($product['ProductCode'] ?? null) === $code
            && ($product['CurrencyCode'] ?? null) === $currency && is_array($product['PricingInfo'] ?? null)) {
            foreach ($product['PricingInfo'] as $price) {
                if (is_array($price) && filter_var($price['NumberOfMonths'] ?? null, FILTER_VALIDATE_INT) === $months) {
                    return;
                }
            }
        }
        throw new RuntimeException('SSLStore did not confirm the selected certificate term');
    }

    public function invite(string $customId, array $terms, string $email): array
    {
        $reply = $this->post('/rest/order/inviteorder', [
            'CustomOrderID' => $customId, 'ProductCode' => $terms['product_code'], 'ValidityPeriod' => $terms['months'],
            'RequestorEmail' => $email, 'ServerCount' => 1, 'ExtraSAN' => 0, 'AddInstallationSupport' => false,
            'PreferVendorLink' => false, 'PreferSendOrderEmails' => false,
        ]);

        return $this->receipt($reply, $customId, $terms);
    }

    public function orderInfo(string $customId, array $terms, ?string $orderId = null, bool $invitation = true, ?string $parent = null): array
    {
        return $this->receipt($this->fetchOrder($customId, $orderId), $customId, $terms, $orderId, $invitation, $parent);
    }

    public function renewalSource(string $customId, array $terms, string $orderId, bool $invitation, ?string $ancestor = null): array
    {
        $reply = $this->fetchOrder($customId, $orderId);
        $receipt = $this->receipt($reply, $customId, $terms, $orderId, $invitation, $ancestor);
        if (!in_array($receipt['status'], ['ACTIVE', 'EXPIRED'], true)
            || ($invitation && ($reply['OrderStatus']['isTinyOrderClaimed'] ?? null) !== true)
            || ($receipt['status'] === 'EXPIRED' && !$this->hasIssuedCertificateDates($reply))) {
            throw new RuntimeException('SSLStore did not confirm an issued order eligible for renewal');
        }

        return $reply;
    }

    private function hasIssuedCertificateDates(array $reply): bool
    {
        $issued = $reply['CertificateStartDateInUTC'] ?? null;
        $expires = $reply['CertificateEndDateInUTC'] ?? null;
        if (!is_string($issued) || !is_string($expires)) {
            return false;
        }
        $start = strtotime($issued);
        $end = strtotime($expires);

        return $start !== false && $start > 0 && $end !== false && $end > $start;
    }

    public function verifyApprover(string $product, string $domain, string $email): void
    {
        $reply = $this->post('/rest/order/approverlist', ['DomainName' => $domain, 'ProductCode' => $product]);
        if ($this->accepted($reply) && is_array($reply['ApproverEmailList'] ?? null)) {
            foreach ($reply['ApproverEmailList'] as $address) {
                if (is_string($address) && strcasecmp($address, $email) === 0) {
                    return;
                }
            }
        }
        throw new RuntimeException('SSLStore did not confirm the selected certificate approval email');
    }

    public function agreement(string $code): string
    {
        $reply = $this->post('/rest/order/agreement', ['ProductCode' => $code]);
        $agreement = $reply['Agreement'] ?? null;
        if (!$this->accepted($reply) || !is_string($agreement) || trim($agreement) === '' || strlen($agreement) > 65536) {
            throw new RuntimeException('SSLStore did not return the current subscriber agreement');
        }

        return $agreement;
    }

    public function renew(string $customId, array $terms, string $parent, array $request): array
    {
        $reply = $this->post('/rest/order/neworder', [
            'CustomOrderID' => $customId, 'ProductCode' => $terms['product_code'], 'ValidityPeriod' => $terms['months'],
            'RelatedTheSSLStoreOrderID' => $parent, 'isRenewalOrder' => true, 'isCUOrder' => false,
            'ServerCount' => 1, 'ReserveSANCount' => 0, 'AddInstallationSupport' => false,
        ] + $request);

        return $this->receipt($reply, $customId, $terms, invitation: false, parent: $parent);
    }

    private function fetchOrder(string $customId, ?string $orderId): array
    {
        $request = $orderId === null ? ['CustomOrderID' => $customId] : ['TheSSLStoreOrderID' => $orderId];

        return $this->post('/rest/order/info', $request);
    }

    public function download(array $receipt): string
    {
        $reply = $this->post('/rest/order/downloadaszip', ['TheSSLStoreOrderID' => $receipt['order_id']]);
        $zip = $this->accepted($reply) && is_string($reply['Zip'] ?? null) ? base64_decode($reply['Zip'], true) : false;
        $status = is_string($reply['ValidationStatus'] ?? null) ? strtoupper(trim($reply['ValidationStatus'])) : '';
        if ($zip === false || !str_starts_with($zip, "PK\x03\x04") || !in_array($status, ['ISSUED', 'VALID'], true)
            || ($receipt['partner_order_id'] !== null && ($reply['PartnerOrderID'] ?? null) !== $receipt['partner_order_id'])) {
            throw new RuntimeException('SSLStore did not return an issued certificate archive');
        }

        return $zip;
    }

    public function enrollmentLink(mixed $url): string
    {
        if (!is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('SSLStore returned an invalid enrollment link');
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || strtolower($parts['host'] ?? '') !== $this->enrollmentHost
            || ($parts['port'] ?? 443) !== 443 || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('SSLStore enrollment link did not match the configured hostname');
        }

        return $url;
    }

    private function post(string $path, array $body): array
    {
        try {
            $response = Context::scope(
                fn () => Http::asJson()->acceptJson()->connectTimeout(10)->timeout(30)
                    ->withOptions(['verify' => true, 'allow_redirects' => false])
                    ->post($this->origin . $path, ['AuthRequest' => [
                        'PartnerCode' => $this->settings['partner_code'], 'AuthToken' => $this->settings['auth_token'],
                        'IsUsedForTokenSystem' => false,
                    ]] + $body),
                hidden: [RequestListener::PRIVATE_HTTP => true],
            );
        } catch (Throwable) {
            throw new RuntimeException('SSLStore request failed and its outcome is unknown');
        }
        $result = $response->successful() && strlen($response->body()) <= 2097152 ? $response->json() : null;
        if (!is_array($result)) {
            throw new RuntimeException('SSLStore response did not confirm success');
        }

        return $result;
    }

    private function accepted(array $reply): bool
    {
        $auth = $reply['AuthResponse'] ?? null;
        if (!is_array($auth)) {
            return false;
        }
        $flags = array_intersect_key($auth, ['isError' => true, 'IsError' => true]);

        return $flags !== [] && !array_filter($flags, fn ($flag) => $flag !== false);
    }

    private function receipt(array $reply, string $customId, array $terms, ?string $orderId = null, bool $invitation = true, ?string $parent = null): array
    {
        $id = $reply['TheSSLStoreOrderID'] ?? null;
        $status = $reply['OrderStatus'] ?? null;
        $major = is_string($status['MajorStatus'] ?? null) ? strtoupper($status['MajorStatus']) : '';
        $claimed = $status['isTinyOrderClaimed'] ?? null;
        if (!$this->accepted($reply) || ($reply['CustomOrderID'] ?? null) !== $customId
            || (!is_string($id) && !is_int($id)) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', (string) $id)
            || filter_var($reply['Validity'] ?? null, FILTER_VALIDATE_INT) !== $terms['months']
            || filter_var($reply['ServerCount'] ?? null, FILTER_VALIDATE_INT) !== 1
            || (isset($reply['ProductCode']) && $reply['ProductCode'] !== $terms['product_code'])
            || ($orderId !== null && (string) $id !== $orderId) || !is_array($status)
            || ($parent !== null && ((string) $id === $parent
                || (($orderId === null || isset($reply['RelatedTheSSLStoreOrderID']))
                    && (string) ($reply['RelatedTheSSLStoreOrderID'] ?? '') !== $parent)))
            || ($status['isTinyOrder'] ?? null) !== $invitation
            || ($invitation && (!is_bool($claimed) || ($major === 'INITIAL' && $claimed) || (in_array($major, ['PENDING', 'ACTIVE'], true) && !$claimed)))
            || (!$invitation && $major === 'INITIAL')
            || !in_array($major, ['INITIAL', 'PENDING', 'ACTIVE', 'CANCELLED', 'EXPIRED'], true)) {
            throw new RuntimeException('SSLStore did not confirm the expected enrollment order');
        }
        $link = $major === 'INITIAL' ? $this->enrollmentLink($reply['TinyOrderLink'] ?? null) : null;
        $partnerId = $reply['PartnerOrderID'] ?? null;

        return ['order_id' => (string) $id, 'partner_order_id' => is_string($partnerId) && $partnerId !== '' ? $partnerId : null,
            'status' => $major, 'link' => $link];
    }
}
