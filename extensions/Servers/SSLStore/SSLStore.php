<?php

namespace Paymenter\Extensions\Servers\SSLStore;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\ReadOnlyServer;
use App\Models\Service;
use App\Services\Providers\XmlResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

#[ExtensionMeta(name: 'SSLStore', description: 'SSLStore credential verification; certificate order integration pending', version: '0.1.0', author: 'Paymenter Community')]
class SSLStore extends ReadOnlyServer
{
    public function getConfig($values = []): array
    {
        return [
            ['name' => 'url', 'label' => 'API HTTPS origin', 'type' => 'text', 'required' => true],
            ['name' => 'partner_code', 'label' => 'Partner code', 'type' => 'text', 'required' => true],
            ['name' => 'auth_token', 'label' => 'Authentication token', 'type' => 'password', 'encrypted' => true, 'required' => true],
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
                $response = Http::withHeaders(['Accept' => 'application/xml'])->connectTimeout(10)->timeout(30)->withOptions(['verify' => true, 'allow_redirects' => false])
                    ->withBody($body->asXML(), 'application/xml')->post($url . '/rest/health/validate');
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

    public function getServerInfo(Service $service): never
    {
        throw new RuntimeException('Certificate order integration is not yet available');
    }

    public function getActions(Service $service): array
    {
        Gate::authorize('view', $service);

        return [['type' => 'text', 'label' => 'Certificate management', 'text' => 'Contact support; certificate order integration is pending']];
    }
}
