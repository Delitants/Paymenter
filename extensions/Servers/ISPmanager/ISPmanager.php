<?php

namespace Paymenter\Extensions\Servers\ISPmanager;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\ReadOnlyServer;
use App\Models\BillmanagerRecord;
use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Providers\XmlResponse;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

#[ExtensionMeta(name: 'ISPmanager', description: 'Status, suspension and reactivation for existing hosting accounts', version: '0.2.0', author: 'Paymenter Community')]
class ISPmanager extends ReadOnlyServer
{
    public const LIFECYCLE_JOB_TIMEOUT = 85;

    private const LIFECYCLE_REQUEST_TIMEOUT = 12;

    private const PENDING_OPERATION = 'ispmanager_pending_operation';

    public function getConfig($values = []): array
    {
        $fields = [
            ['name' => 'url', 'label' => 'Manager HTTPS endpoint', 'type' => 'text', 'required' => true],
            ['name' => 'username', 'label' => 'Reseller username', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'Reseller password', 'type' => 'password', 'encrypted' => true, 'required' => true],
        ];
        if (static::class === self::class) {
            $fields[] = ['name' => 'lifecycle_enabled', 'label' => 'Enable suspension and reactivation', 'type' => 'checkbox', 'default' => false];
            $fields[] = ['name' => 'lifecycle_endpoint', 'label' => 'Confirmed lifecycle HTTPS endpoint', 'type' => 'text', 'encrypted' => true];
        }

        return $fields;
    }

    private function request(string $function, array $parameters = [], int $timeout = 30): SimpleXMLElement
    {
        $url = XmlResponse::httpsEndpoint((string) $this->config('url'));
        if (!$this->config('username') || !$this->config('password')) {
            throw new RuntimeException('Provider credentials are not configured');
        }
        try {
            $response = Http::asForm()->connectTimeout(10)->timeout($timeout)->withOptions(['verify' => true, 'allow_redirects' => false])
                ->post($url, ['func' => $function, 'out' => 'xml', 'authinfo' => $this->config('username') . ':' . $this->config('password')] + $parameters);
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

        return $xml;
    }

    private function accountStatus(SimpleXMLElement $row, bool $lifecycle): string
    {
        if (!isset($row->active)) {
            return 'suspended';
        }

        return match (strtolower(trim((string) $row->active))) {
            '', '1', 'on', 'true', 'yes' => 'active',
            '0', 'off', 'false', 'no' => 'suspended',
            // A legacy active marker is enough for display. A write requires a known status.
            default => $lifecycle ? throw new RuntimeException('Provider account status is invalid') : 'active',
        };
    }

    public function getResourceInventory(): array
    {
        return $this->inventory();
    }

    private function inventory(bool $lifecycle = false): array
    {
        $resources = [];
        foreach ($this->request('user', timeout: $lifecycle ? self::LIFECYCLE_REQUEST_TIMEOUT : 30)->elem as $row) {
            if (!isset($row->name, $row->owner)) {
                throw new RuntimeException('Provider account identity is incomplete');
            }
            $status = $lifecycle || static::class === self::class
                ? $this->accountStatus($row, $lifecycle)
                : (isset($row->active) ? 'active' : 'suspended');
            $resources[] = ['username' => (string) $row->name, 'owner' => (string) $row->owner, 'status' => $status, 'plan' => (string) $row->preset];
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

    private function accountIdentity(Service $service): array
    {
        $rows = $service->properties()->whereIn('key', ['provider_username', 'provider_owner'])->get();
        $p = $rows->pluck('value', 'key')->all();
        if ($service->product?->server?->extension !== class_basename(static::class) || $rows->count() !== 2
            || empty($p['provider_username']) || empty($p['provider_owner'])) {
            throw new RuntimeException('Provider account identity is not configured');
        }

        return [$p['provider_username'], $p['provider_owner']];
    }

    private function matchingAccount(string $username, string $owner, bool $lifecycle = false): array
    {
        $matches = array_values(array_filter($this->inventory($lifecycle), fn ($row) => $row['username'] === $username));
        if (count($matches) !== 1 || $matches[0]['owner'] !== $owner) {
            throw new RuntimeException('Provider account identity conflicts or is missing');
        }

        return $matches[0];
    }

    public function getServerInfo(Service $service): array
    {
        $account = $this->matchingAccount(...$this->accountIdentity($service));

        return ['username' => $account['username'], 'status' => $account['status'], 'plan' => $account['plan']];
    }

    public function suspendServer(Service $service, $settings = [], $properties = []): void
    {
        $this->setAccountStatus($service, Service::STATUS_SUSPENDED);
    }

    public function unsuspendServer(Service $service, $settings = [], $properties = []): void
    {
        $this->setAccountStatus($service, Service::STATUS_ACTIVE);
    }

    public function applyQueuedSuspension(Service $service, bool $suspended): bool
    {
        return $this->setAccountStatus($service, $suspended ? Service::STATUS_SUSPENDED : Service::STATUS_ACTIVE, queued: true);
    }

    private function lifecycleBinding(Service $service, string $status, bool $queued): ?array
    {
        $current = $service->fresh(['product.server.settings']);
        if (!$current) {
            throw new RuntimeException('Provider service no longer exists');
        }
        $operation = $status === Service::STATUS_SUSPENDED ? 'suspend' : 'unsuspend';
        MigrationHold::assertAllowed($current, $operation);
        // A service may be cancelled in the same cron transaction that suspends it.
        $expectedStatuses = $status === Service::STATUS_SUSPENDED ? [Service::STATUS_SUSPENDED, Service::STATUS_CANCELLED] : [Service::STATUS_ACTIVE];
        if ($queued && !in_array($current->status, $expectedStatuses, true)) {
            return null;
        }
        $server = $current->product?->server;
        if (static::class !== self::class || $server?->extension !== 'ISPmanager' || !$server->enabled) {
            $this->unavailable($current, $operation);
        }
        $settings = $server->settings->pluck('value', 'key')->all();
        if (!in_array($settings['lifecycle_enabled'] ?? false, [true, 1, '1'], true)) {
            $this->unavailable($current, $operation);
        }
        $endpoint = XmlResponse::httpsEndpoint((string) ($settings['url'] ?? ''));
        if (empty($settings['lifecycle_endpoint']) || XmlResponse::httpsEndpoint($settings['lifecycle_endpoint']) !== $endpoint) {
            throw new RuntimeException('The ISPmanager lifecycle endpoint must be confirmed');
        }

        [$username, $owner] = $this->accountIdentity($current);
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/D', $username)) {
            throw new RuntimeException('Provider account identity must identify one account');
        }
        $binding = ['server_id' => (int) $server->id, 'extension' => 'ISPmanager', 'username' => $username, 'owner' => $owner];
        $this->verifyImportedBinding($current, $binding);

        $origin = $this->panelOrigin($endpoint);
        $duplicate = Service::query()->whereKeyNot($current->id)
            ->whereHas('product.server', fn ($query) => $query->whereIn('extension', ['ISPmanager', 'DNSmanager']))
            ->whereHas('properties', fn ($query) => $query->where('key', 'provider_username')->where('value', $username))
            ->with('product.server.settings')->get()
            ->contains(fn (Service $other) => $this->panelOrigin((string) $other->product->server->settings->firstWhere('key', 'url')?->value) === $origin);
        if ($duplicate) {
            throw new RuntimeException('Duplicate provider account binding on the same panel');
        }

        ksort($settings);

        return $binding + ['origin' => $origin, 'settings' => $settings];
    }

    private function panelOrigin(string $url): string
    {
        $parts = parse_url(XmlResponse::httpsEndpoint($url));

        return strtolower(rtrim($parts['host'], '.')) . ':' . ($parts['port'] ?? 443);
    }

    private function verifyImportedBinding(Service $service, array $binding): void
    {
        $mapping = DB::table('billmanager_mappings')->where('source_table', 'items')
            ->where('target_table', 'services')->where('target_id', $service->id)->sole();
        $record = BillmanagerRecord::query()
            ->join('billmanager_imports', 'billmanager_imports.id', '=', 'billmanager_records.import_id')
            ->where('billmanager_imports.source_host', $mapping->source_host)
            ->where('billmanager_records.source_table', 'provider_bindings')->where('billmanager_records.source_id', $mapping->source_id)
            ->orderByDesc('billmanager_records.import_id')->first(['billmanager_records.*']);
        try {
            $matches = $record?->payload === $binding;
        } catch (DecryptException) {
            $matches = false;
        }
        if (!$matches || !DB::table('billmanager_accounts')->where('source_account_id', $record->source_account_id)
            ->where('owner_user_id', $service->user_id)->exists()) {
            throw new RuntimeException('Provider account does not match its imported binding');
        }
    }

    private function setAccountStatus(Service $service, string $status, bool $queued = false): bool
    {
        $binding = $this->lifecycleBinding($service, $status, $queued);
        if ($binding === null) {
            return false;
        }

        $client = new self($binding['settings']);
        ['username' => $username, 'owner' => $owner, 'origin' => $origin] = $binding;
        $lock = Cache::lock('ispmanager:lifecycle:' . hash('sha256', $origin . "\n" . strtolower($username)), 120);
        $fingerprint = hash('sha256', json_encode([$binding['server_id'], XmlResponse::httpsEndpoint($binding['settings']['url']), $username, $owner], JSON_THROW_ON_ERROR));
        try {
            // Three requests take up to 36 seconds. A later job can wait for an in-flight change.
            return $lock->block(40, function () use ($client, $username, $owner, $status, $queued, $service, $binding, $fingerprint): bool {
                $verifyBinding = function () use ($service, $status, $queued, $binding): bool {
                    $current = $this->lifecycleBinding($service, $status, $queued);
                    if ($current !== null && $current !== $binding) {
                        throw new RuntimeException('Provider account binding changed during the lifecycle operation');
                    }

                    return $current !== null;
                };
                if (!$verifyBinding()) {
                    return false;
                }
                $before = $client->matchingAccount($username, $owner, lifecycle: true)['status'];
                if (!$verifyBinding()) {
                    return false;
                }
                $this->resolvePendingOperation($service, $fingerprint, $before);
                if ($before === $status) {
                    return true;
                }
                // Keep an unconfirmed attempt visible to later jobs, including after a worker exits.
                $pending = $service->properties()->create(['key' => self::PENDING_OPERATION, 'value' => $fingerprint . ':' . $status]);
                $client->changeAccountStatus($username, $owner, $status);
                $pending->delete();

                return $verifyBinding();
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Another operation is already running for this ISPmanager account');
        }
    }

    private function resolvePendingOperation(Service $service, string $fingerprint, string $observedStatus): void
    {
        $pending = $service->properties()->where('key', self::PENDING_OPERATION)->limit(2)->get();
        if ($pending->isEmpty()) {
            return;
        }
        if ($pending->count() !== 1 || $pending->first()->value !== $fingerprint . ':' . $observedStatus) {
            throw new RuntimeException('An earlier ISPmanager operation is still unconfirmed');
        }
        $pending->first()->delete();
    }

    private function changeAccountStatus(string $username, string $owner, string $status): void
    {
        try {
            $this->request($status === Service::STATUS_SUSPENDED ? 'user.suspend' : 'user.resume', ['elid' => $username], self::LIFECYCLE_REQUEST_TIMEOUT);
        } catch (RuntimeException) {
            // The provider may have applied the request before the response failed.
        }
        try {
            $confirmed = $this->matchingAccount($username, $owner, lifecycle: true)['status'] === $status;
        } catch (RuntimeException) {
            $confirmed = false;
        }
        if (!$confirmed) {
            throw new RuntimeException('The ISPmanager account status could not be confirmed');
        }
    }

    public function getActions(Service $service): array
    {
        Gate::authorize('view', $service);
        $info = $this->getServerInfo($service);

        return array_map(fn ($key) => ['type' => 'text', 'label' => ucfirst($key), 'text' => $info[$key]], array_keys($info));
    }
}
