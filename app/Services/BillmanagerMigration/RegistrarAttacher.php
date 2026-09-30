<?php

namespace App\Services\BillmanagerMigration;

use App\Models\BillmanagerServiceDetail;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;
use RuntimeException;

final class RegistrarAttacher
{
    /** Bind existing domains using current read-only evidence; keep all migration holds. */
    public function attach(Snapshot $snapshot, ImportContext $context, array $serverByModule, bool $dryRun = false): ImportReport
    {
        $this->assertImport($snapshot, $context);
        $servers = $this->servers($context, $serverByModule);
        $configuration = [];
        foreach ($servers as $id => $server) {
            $configuration[$id] = $this->settings($server);
        }
        $entries = $domains = [];
        foreach ($snapshot->rows('items') as $row) {
            $serverId = $serverByModule[$row['processingmodule'] ?? ''] ?? null;
            if (!$serverId || !in_array((string) $row['status'], ['2', '3'], true)) {
                continue;
            }
            $parameters = array_values(array_filter($snapshot->rows('itemparams'), fn ($p) => (string) $p['item'] === (string) $row['id']));
            $domainRows = array_values(array_filter($parameters, fn ($p) => $p['intname'] === 'domain'));
            if (count($domainRows) !== 1) {
                throw new RuntimeException('Source domain identity is missing or ambiguous');
            }
            $domain = $this->domain($domainRows[0]['value']);
            if (isset($domains[$domain])) {
                throw new RuntimeException('Source domain is assigned to multiple services');
            }
            $domains[$domain] = true;
            $entry = ['row' => $row, 'parameters' => $parameters, 'domain' => $domain, 'server_id' => (int) $serverId,
                'source_nameservers' => $this->nameservers(array_column(array_filter($parameters, fn ($p) => preg_match('/^ns[0-9]+$/D', $p['intname'])), 'value'))];
            $this->service($snapshot, $context, $entry);
            $entries[] = $entry;
        }
        if (!$entries) {
            throw new RuntimeException('No source registrar services match the explicit mapping');
        }

        // Validate all local identities before sending any customer domain to a provider.
        foreach ($entries as &$entry) {
            try {
                $details = (new ResellerClub($configuration[$entry['server_id']]))->getDomainDetails($entry['domain']);
                if (!preg_match('/^[1-9][0-9]*$/D', $details['order_id']) || !preg_match('/^[1-9][0-9]*$/D', $details['customer_id']) || $this->domain($details['domain']) !== $entry['domain']) {
                    throw new RuntimeException('Invalid registrar identity');
                }
                $entry['binding'] = ['server_id' => $entry['server_id'], 'extension' => 'ResellerClub', 'domain' => $entry['domain'], 'order_id' => $details['order_id'], 'customer_id' => $details['customer_id']];
                $sourceNs = $entry['source_nameservers'];
                $providerNs = $this->nameservers($details['nameservers']);
                $sourceExpiry = substr($entry['row']['expiredate'] ?? '', 0, 10);
                $validDate = preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $sourceExpiry, $date) && checkdate((int) $date[2], (int) $date[3], (int) $date[1]);
                $providerExpiry = gmdate('Y-m-d', $details['expiry']);
                $nsMatch = $sourceNs && $providerNs && $sourceNs === $providerNs;
                $expiryMatch = $validDate && $sourceExpiry === $providerExpiry;
                $entry['observation'] = [
                    'binding' => $entry['binding'], 'provider_status' => $details['status'],
                    'source_expiry' => $sourceExpiry, 'provider_expiry' => $providerExpiry,
                    'source_nameservers' => $sourceNs, 'provider_nameservers' => $providerNs,
                    'expiry_drift' => !$expiryMatch, 'nameserver_drift' => !$nsMatch,
                ];
                $entry['review'] = $details['status'] !== ((string) $entry['row']['status'] === '2' ? 'Active' : 'Suspended')
                    ? 'status_mismatch' : (!$nsMatch && !$expiryMatch ? 'uncorroborated_identity' : null);
            } catch (RuntimeException) {
                // Provider exceptions must not expose request URLs or private response data.
                unset($entry['binding'], $entry['observation']);
                $entry['review'] = 'lookup_unverified';
            }
        }
        unset($entry);
        $orders = [];
        foreach ($entries as $entry) {
            if (isset($entry['binding'])) {
                $order = $entry['binding']['order_id'];
                if (isset($orders[$order])) {
                    throw new RuntimeException('Registrar order is assigned to multiple source domains');
                }
                $orders[$order] = true;
            }
        }

        return DB::transaction(function () use ($snapshot, $context, $serverByModule, $entries, $dryRun, $configuration) {
            $this->assertImport($snapshot, $context, true);
            // Serialize registrar binding stages, including duplicates across registrar configurations.
            Server::where('extension', 'ResellerClub')->orderBy('id')->lockForUpdate()->get();
            foreach ($this->servers($context, $serverByModule, true) as $id => $server) {
                if ($this->settings($server, true) !== $configuration[$id]) {
                    throw new RuntimeException('Registrar configuration changed during verification');
                }
            }
            $counts = ['attached' => 0, 'active' => 0, 'suspended' => 0, 'review_required' => 0, 'expiry_drift' => 0, 'nameserver_drift' => 0];
            $conflicts = [];
            foreach ($entries as $entry) {
                $service = $this->service($snapshot, $context, $entry, true);
                if (isset($entry['binding'])) {
                    $this->assertBinding($service, $entry['binding']);
                    $this->assertProvenance($context, $entry);
                    $counts['expiry_drift'] += (int) $entry['observation']['expiry_drift'];
                    $counts['nameserver_drift'] += (int) $entry['observation']['nameserver_drift'];
                }
                if (!$dryRun && isset($entry['observation'])) {
                    $observation = $entry['observation'];
                    $digest = hash('sha256', json_encode($observation, JSON_THROW_ON_ERROR));
                    $context->archive('registrar_observations', $entry['row']['id'] . ':' . $digest, $observation, (int) $entry['row']['account']);
                }
                if ($entry['review'] !== null) {
                    $counts['review_required']++;
                    $conflicts[] = ['source_id' => (string) $entry['row']['id'], 'reason' => $entry['review']];

                    continue;
                }
                if (!$dryRun) {
                    $context->archive('provider_bindings', (string) $entry['row']['id'], $entry['binding'], (int) $entry['row']['account']);
                    foreach ($this->properties($entry['binding']) as $key => $value) {
                        DB::table('properties')->updateOrInsert(['model_type' => Service::class, 'model_id' => $service->id, 'key' => $key], ['value' => $value]);
                    }
                    DB::table('products')->where('id', $service->product_id)->update(['server_id' => $entry['server_id']]);
                }
                $counts['attached']++;
                $counts[$service->status]++;
            }
            if ($counts['attached'] === 0) {
                throw new RuntimeException('No registrar identities were verified; bindings require review');
            }
            $status = $counts['review_required'] > 0 ? 'registrar_accounts_review_required' : ($dryRun ? 'registrar_accounts_verified_dry_run' : 'registrar_accounts_attached_held');

            return new ImportReport($status, $counts, $conflicts);
        });
    }

    private function assertImport(Snapshot $snapshot, ImportContext $context, bool $lock = false): void
    {
        $query = DB::table('billmanager_imports')->where('id', $context->importId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($snapshot->sourceHost() !== $context->sourceHost || !$row || $row->source_host !== $context->sourceHost || $row->snapshot_sha256 !== $snapshot->checksum()) {
            throw new RuntimeException('Registrar attachment requires an existing exact source snapshot import');
        }
    }

    private function mapped(ImportContext $context, string $table, string $id, string $target, bool $lock = false): int
    {
        $query = DB::table('billmanager_mappings')->where(['source_host' => $context->sourceHost, 'source_table' => $table, 'source_id' => $id]);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row || $row->import_id != $context->importId || $row->target_table !== $target) {
            throw new RuntimeException('Registrar source mapping is missing or conflicts');
        }

        return (int) $row->target_id;
    }

    private function servers(ImportContext $context, array $mapping, bool $lock = false): array
    {
        if (!$mapping) {
            throw new RuntimeException('Explicit registrar server mapping is required');
        }
        $servers = [];
        foreach ($mapping as $module => $id) {
            if (!is_numeric($id) || (int) $id !== $this->mapped($context, 'provider_servers', (string) $module, 'extensions', $lock)) {
                throw new RuntimeException('Registrar server mapping conflicts');
            }
            $query = Server::where('id', $id);
            $server = ($lock ? $query->lockForUpdate() : $query)->first();
            if (!$server || $server->extension !== 'ResellerClub' || $server->enabled) {
                throw new RuntimeException('Registrar configuration must be ResellerClub and disabled');
            }
            $servers[(int) $id] = $server;
        }

        return $servers;
    }

    private function settings(Server $server, bool $lock = false): array
    {
        $query = $server->settings();
        $values = ($lock ? $query->lockForUpdate() : $query)->get()->pluck('value', 'key')->all();
        ksort($values);

        return $values;
    }

    private function service(Snapshot $snapshot, ImportContext $context, array $entry, bool $lock = false): Service
    {
        $row = $entry['row'];
        $serviceId = $this->mapped($context, 'items', (string) $row['id'], 'services', $lock);
        $accountId = $this->mapped($context, 'accounts', (string) $row['account'], 'billmanager_accounts', $lock);
        $accountQuery = DB::table('billmanager_accounts')->where('id', $accountId);
        $account = ($lock ? $accountQuery->lockForUpdate() : $accountQuery)->first();
        $owner = $account?->owner_user_id;
        $query = Service::where('id', $serviceId);
        $service = ($lock ? $query->lockForUpdate() : $query)->first();
        $status = (string) $row['status'] === '2' ? 'active' : 'suspended';
        if (!$service || !$owner || $account->source_account_id != $row['account'] || $service->user_id != $owner || $service->status !== $status || !$this->held($service, $lock)) {
            throw new RuntimeException('Registrar attachment requires mapped ownership, source status and an active hold');
        }
        $metadataQuery = BillmanagerServiceDetail::where('service_id', $serviceId);
        $metadata = ($lock ? $metadataQuery->lockForUpdate() : $metadataQuery)->first();
        $details = $metadata?->details;
        if (!$metadata || $metadata->import_id != $context->importId || $metadata->source_id !== (string) $row['id'] || $metadata->source_status !== $status || ($details['original'] ?? null) !== $row || ($details['parameters'] ?? null) !== $entry['parameters']) {
            throw new RuntimeException('Registrar source service metadata changed');
        }
        $timezone = $snapshot->sourceTimezone() ?? config('billmanager-migration.source_timezone');
        if (!$timezone || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException('Source timezone is required for registrar billing verification');
        }
        $expiry = $row['expiredate'] ?? null;
        $expectedExpiry = !$expiry || str_starts_with($expiry, '0000-') ? null : Carbon::parse($expiry, $timezone)->utc()->format('Y-m-d H:i:s');
        $currency = array_column($snapshot->rows('currencies'), 'iso', 'id')[$row['currency']] ?? null;
        if ((string) $service->getRawOriginal('expires_at') !== (string) $expectedExpiry || $service->price !== ($details['price']['native_amount'] ?? null) || $service->currency_code !== $currency) {
            throw new RuntimeException('Registrar service billing fields changed');
        }
        $productId = $this->mapped($context, 'service_products', $row['pricelist'] . ':' . $row['processingmodule'], 'products', $lock);
        $productQuery = DB::table('products')->where('id', $productId);
        $product = ($lock ? $productQuery->lockForUpdate() : $productQuery)->first();
        if ($service->product_id != $productId || !$product || !$product->hidden || $product->stock != 0 || ($product->server_id !== null && $product->server_id != $entry['server_id'])) {
            throw new RuntimeException('Registrar product identity must remain imported, hidden and held');
        }

        return $service;
    }

    private function properties(array $binding): array
    {
        return ['resellerclub_domain' => $binding['domain'], 'resellerclub_order_id' => $binding['order_id'], 'resellerclub_customer_id' => $binding['customer_id']];
    }

    private function assertBinding(Service $service, array $binding): void
    {
        foreach ($this->properties($binding) as $key => $value) {
            $existing = DB::table('properties')->where(['model_type' => Service::class, 'model_id' => $service->id, 'key' => $key])->lockForUpdate()->get();
            if ($existing->count() > 1 || ($existing->count() === 1 && $existing[0]->value !== $value)) {
                throw new RuntimeException('Existing registrar identity conflicts; explicit reconciliation required');
            }
            if ($key === 'resellerclub_customer_id') {
                continue; // One registrar customer may own multiple domains.
            }
            $duplicate = DB::table('properties')->where('model_type', Service::class)->where('key', $key)->where('model_id', '!=', $service->id)
                ->whereRaw('LOWER(TRIM(value)) = ?', [strtolower($value)])->lockForUpdate()->first();
            if ($duplicate) {
                throw new RuntimeException('Registrar domain or order is already bound to another service');
            }
        }
    }

    private function held(Service $service, bool $lock): bool
    {
        if (!$lock) {
            return MigrationHold::isHeld($service);
        }

        // Locking reads see current committed state inside the CLI's repeatable-read transaction.
        return DB::table('billmanager_holds')->whereNull('released_at')->where(function ($query) use ($service) {
            $query->where(fn ($q) => $q->where('model_type', $service->getMorphClass())->where('model_id', $service->id))
                ->orWhere(fn ($q) => $q->where('model_type', (new User)->getMorphClass())->where('model_id', $service->user_id));
        })->lockForUpdate()->get()->isNotEmpty();
    }

    private function assertProvenance(ImportContext $context, array $entry): void
    {
        $record = DB::table('billmanager_records')->where(['import_id' => $context->importId, 'source_table' => 'provider_bindings', 'source_id' => (string) $entry['row']['id']])->lockForUpdate()->first();
        if (!$record) {
            return;
        }
        $payload = json_encode($entry['binding'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        try {
            $original = Crypt::decryptString($record->payload);
        } catch (\Throwable) {
            throw new RuntimeException('Existing registrar binding provenance is corrupt');
        }
        if ($record->source_account_id != $entry['row']['account'] || !hash_equals($record->payload_sha256, hash('sha256', $payload)) || $original !== $payload) {
            throw new RuntimeException('Existing registrar binding provenance conflicts');
        }
    }

    private function domain(string $value): string
    {
        $value = strtolower(rtrim(trim($value), '.'));
        if (!filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($value, '.')) {
            throw new RuntimeException('Valid source domain identity is required');
        }

        return $value;
    }

    private function nameservers(array $values): array
    {
        $values = array_values(array_unique(array_map(fn ($v) => $this->domain((string) $v), array_filter($values, fn ($v) => trim((string) $v) !== ''))));
        sort($values);

        return $values;
    }
}
