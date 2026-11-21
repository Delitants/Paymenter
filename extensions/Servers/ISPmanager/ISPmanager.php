<?php

namespace Paymenter\Extensions\Servers\ISPmanager;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\ReadOnlyServer;
use App\Models\Service;
use App\Services\Providers\XmlResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

#[ExtensionMeta(name: 'ISPmanager', description: 'Existing hosting account status; automated lifecycle integration pending', version: '0.1.0', author: 'Paymenter Community')]
class ISPmanager extends ReadOnlyServer
{
    public function getConfig($values = []): array
    {
        return [
            ['name' => 'url', 'label' => 'Manager HTTPS endpoint', 'type' => 'text', 'required' => true],
            ['name' => 'username', 'label' => 'Reseller username', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'Reseller password', 'type' => 'password', 'encrypted' => true, 'required' => true],
        ];
    }

    public function getResourceInventory(): array
    {
        $url = XmlResponse::httpsEndpoint((string) $this->config('url'));
        if (!$this->config('username') || !$this->config('password')) {
            throw new RuntimeException('Provider credentials are not configured');
        }
        try {
            $response = Http::asForm()->connectTimeout(10)->timeout(30)->withOptions(['verify' => true, 'allow_redirects' => false])
                ->post($url, ['func' => 'user', 'out' => 'xml', 'authinfo' => $this->config('username') . ':' . $this->config('password')]);
        } catch (Throwable) {
            throw new RuntimeException('Provider request failed; account status is unknown');
        }
        if (!$response->successful()) {
            throw new RuntimeException('Provider request rejected (HTTP ' . $response->status() . ')');
        }
        $xml = XmlResponse::parse($response->body());
        if ($xml->getName() !== 'doc') {
            throw new RuntimeException('Provider XML response is invalid');
        }
        $resources = [];
        foreach ($xml->elem as $row) {
            if (!isset($row->name, $row->owner)) {
                throw new RuntimeException('Provider account identity is incomplete');
            }
            $resources[] = ['username' => (string) $row->name, 'owner' => (string) $row->owner, 'status' => isset($row->active) ? 'active' : 'suspended', 'plan' => (string) $row->preset];
        }

        return $resources;
    }

    public function testConfig(): bool|string
    {
        try {
            $this->getResourceInventory();

            return true;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    public function getServerInfo(Service $service): array
    {
        $p = $service->properties()->pluck('value', 'key')->all();
        if ($service->product?->server?->extension !== class_basename(static::class) || empty($p['provider_username']) || empty($p['provider_owner'])) {
            throw new RuntimeException('Provider account identity is not configured');
        }
        $matches = array_values(array_filter($this->getResourceInventory(), fn ($row) => $row['username'] === $p['provider_username']));
        if (count($matches) !== 1 || $matches[0]['owner'] !== $p['provider_owner']) {
            throw new RuntimeException('Provider account identity conflicts or is missing');
        }

        return ['username' => $matches[0]['username'], 'status' => $matches[0]['status'], 'plan' => $matches[0]['plan']];
    }

    public function getActions(Service $service): array
    {
        Gate::authorize('view', $service);
        $info = $this->getServerInfo($service);

        return array_map(fn ($key) => ['type' => 'text', 'label' => ucfirst($key), 'text' => $info[$key]], array_keys($info));
    }
}
