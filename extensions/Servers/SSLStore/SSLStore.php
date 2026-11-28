<?php

namespace Paymenter\Extensions\Servers\SSLStore;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\ReadOnlyServer;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Listeners\RequestListener;
use App\Models\CartItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Services\Providers\XmlResponse;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

#[ExtensionMeta(name: 'SSLStore', description: 'SSLStore certificate enrollment and linked renewals from paid invoices', version: '0.3.0', author: 'Paymenter Community')]
class SSLStore extends ReadOnlyServer
{
    public function getConfig($values = []): array
    {
        return [
            ['name' => 'url', 'label' => 'API HTTPS origin', 'type' => 'text', 'required' => true],
            ['name' => 'partner_code', 'label' => 'Partner code', 'type' => 'text', 'required' => true],
            ['name' => 'auth_token', 'label' => 'Authentication token', 'type' => 'password', 'encrypted' => true, 'required' => true],
            ['name' => 'enrollment_host', 'label' => 'Verified customer enrollment hostname', 'type' => 'text'],
            ['name' => 'orders_enabled', 'label' => 'Enable new certificate orders', 'type' => 'checkbox', 'default' => false],
            ['name' => 'renewals_enabled', 'label' => 'Enable customer-requested certificate renewals', 'type' => 'checkbox', 'default' => false],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $url = XmlResponse::httpsEndpoint((string) $this->config('url'));
            if (trim(parse_url($url, PHP_URL_PATH) ?? '', '/') !== '') {
                throw new RuntimeException('SSLStore requires its API origin without a path');
            }
            if (!$this->config('partner_code') || !$this->config('auth_token')) {
                throw new RuntimeException('SSLStore credentials are not configured');
            }
            $body = new SimpleXMLElement('<AuthRequest/>');
            $body->addChild('PartnerCode', htmlspecialchars($this->config('partner_code'), ENT_XML1));
            $body->addChild('AuthToken', htmlspecialchars($this->config('auth_token'), ENT_XML1));
            try {
                $response = Context::scope(
                    fn () => Http::withHeaders(['Accept' => 'application/xml'])->connectTimeout(10)->timeout(30)->withOptions(['verify' => true, 'allow_redirects' => false])
                        ->withBody($body->asXML(), 'application/xml')->post($url . '/rest/health/validate'),
                    hidden: [RequestListener::PRIVATE_HTTP => true],
                );
            } catch (Throwable) {
                throw new RuntimeException('SSLStore authentication request failed');
            }
            if (!$response->successful()) {
                throw new RuntimeException('SSLStore authentication rejected (HTTP ' . $response->status() . ')');
            }
            $xml = XmlResponse::parse($response->body());
            if ($xml->getName() !== 'AuthResponse' || !isset($xml->isError) || strtolower((string) $xml->isError) !== 'false') {
                throw new RuntimeException('SSLStore authentication was not confirmed');
            }

            return true;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    public function getProductConfig($values = []): array
    {
        return [
            ['name' => 'product_code', 'label' => 'SSL Store product code', 'type' => 'text', 'required' => true],
        ];
    }

    public function validateCheckout(Product $product, Plan $plan, array $values): array
    {
        try {
            (new SSLStoreOrders)->quote($product, $plan, session('currency', config('settings.default_currency', 'USD')));
        } catch (RuntimeException) {
            throw new DisplayException(__('This certificate is unavailable for purchase.'));
        }

        return [];
    }

    public function validateCartItem(CartItem $item): array
    {
        try {
            [$api, $terms] = (new SSLStoreOrders)->quote($item->product, $item->plan, $item->cart->currency_code);
            if ((int) $item->quantity !== 1 || !BigDecimal::of($item->price->total)->isPositive()) {
                throw new RuntimeException('SSLStore requires one paid certificate per order item');
            }
            $api->verifyOffer($terms['product_code'], $terms['months'], $terms['currency']);
        } catch (RuntimeException) {
            throw new DisplayException(__('This certificate term is temporarily unavailable. Please review your cart.'));
        }

        return $this->checkoutQuote($item, $terms, (string) $item->price->total);
    }

    public function validateLockedCartItem(CartItem $item, ?array $verified, string $amount): array
    {
        try {
            $server = $item->product->server()->lockForUpdate()->first();
            $server?->setRelation('settings', $server->settings()->lockForUpdate()->get());
            $item->product->setRelation('server', $server);
            [, $terms] = (new SSLStoreOrders)->quote($item->product, $item->plan, $item->cart->currency_code, checkStock: false);
            if ((int) $item->quantity !== 1 || !BigDecimal::of($amount)->isPositive()
                || $this->checkoutQuote($item, $terms, $amount) !== $verified) {
                throw new RuntimeException('SSLStore checkout changed after provider verification');
            }
        } catch (RuntimeException) {
            throw new DisplayException(__('The certificate terms changed. Please review your cart before paying.'));
        }

        return $verified;
    }

    private function checkoutQuote(CartItem $item, array $terms, string $amount): array
    {
        $terms['line_price'] = (string) BigDecimal::of($amount)->toScale(2);
        $terms['cart_item_id'] = $item->id;

        return [SSLStoreOrders::QUOTE => json_encode($terms, JSON_THROW_ON_ERROR)];
    }

    public function handlePaidInvoice(Service $service, InvoiceItem $item): void
    {
        $quote = $item->invoice->properties()->where('key', ExtensionHelper::SERVICE_RENEWAL_QUOTE)->value('value');
        if ($quote !== null) {
            (new SSLStoreRenewals)->fulfill($service, $item, $quote);

            return;
        }
        (new SSLStoreOrders)->fulfill($service, $item);
    }

    public function validateRenewalInvoice(Service $service, Invoice $invoice, string $terms): void
    {
        try {
            (new SSLStoreRenewals)->validateForPayment($service, $invoice, $terms);
        } catch (RuntimeException) {
            throw new DisplayException(__('The certificate renewal terms changed. Review the service before paying.'));
        }
    }

    public function shouldScheduleRenewalInvoice(Service $service): bool
    {
        // The customer supplies a CSR and accepts the subscriber agreement before billing.
        return false;
    }

    public function getServerInfo(Service $service): array
    {
        Gate::authorize('view', $service);
        $receipt = (new SSLStoreOrders)->confirmed($service)?->payload['receipt'] ?? [];

        return ['order_id' => $receipt['order_id'] ?? null, 'status' => $receipt['status'] ?? null];
    }

    public function getActions(Service $service): array
    {
        $order = $this->getServerInfo($service);
        $status = match ($order['status']) {
            'INITIAL' => __('Ready for certificate enrollment'),
            'PENDING' => __('Certificate validation in progress'),
            'ACTIVE' => __('Certificate order completed'),
            'CANCELLED' => __('Certificate order cancelled'),
            'EXPIRED' => __('Certificate order expired'),
            default => __('Certificate enrollment is unavailable'),
        };
        $actions = [['type' => 'text', 'label' => __('Certificate status'), 'text' => $status]];
        if ($order['order_id'] && Gate::allows('update', $service)) {
            if ($order['status'] === 'INITIAL' && $service->status === Service::STATUS_ACTIVE) {
                $actions[] = ['type' => 'button', 'label' => __('Complete certificate enrollment'), 'function' => 'openEnrollment'];
            }
            if ($order['status'] === 'ACTIVE' || ($order['status'] === 'PENDING' && (new SSLStoreOrders)->hasPreviousCertificate($service))) {
                $actions[] = ['type' => 'button', 'label' => __('Download certificate'), 'function' => 'downloadCertificate'];
            }
            if (in_array($order['status'], ['ACTIVE', 'EXPIRED'], true)) {
                $actions = [...$actions, ...$this->renewalActions($service)];
            }
            $actions[] = ['type' => 'button', 'label' => __('Check certificate status'), 'function' => 'refreshCertificateStatus'];
        }

        return $actions;
    }

    public function openEnrollment(Service $service): string
    {
        Gate::authorize('update', $service);

        return route('services.sslstore.enroll', $service);
    }

    public function getSuspendedActions(Service $service): array
    {
        Gate::authorize('view', $service);

        return $this->renewalActions($service);
    }

    private function renewalActions(Service $service): array
    {
        if (!Gate::allows('update', $service) || !filter_var($this->config('renewals_enabled'), FILTER_VALIDATE_BOOL)
            || !(new SSLStoreOrders)->confirmed($service, allowSuspended: true)) {
            return [];
        }

        return [['type' => 'button', 'label' => __('Renew certificate'), 'function' => 'openRenewal']];
    }

    public function openRenewal(Service $service): string
    {
        Gate::authorize('update', $service);

        return route('services.sslstore.renewal', $service);
    }

    public function refreshCertificateStatus(Service $service): bool
    {
        $service = $service->fresh();
        Gate::authorize('update', $service);
        (new SSLStoreOrders)->refresh($service);

        return true;
    }

    public function downloadCertificate(Service $service): string
    {
        Gate::authorize('update', $service);

        return route('services.sslstore.download', $service);
    }
}
