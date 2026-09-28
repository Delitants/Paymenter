<?php

namespace App\Services\BillmanagerMigration;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\DNSmanager\DNSmanager;
use Paymenter\Extensions\Servers\ISPmanager\ISPmanager;
use RuntimeException;

final class ProviderAttacher
{
    /** Bind existing hosting/DNS accounts. No provisioning or remote writes. */
    public function attach(Snapshot $snapshot, ImportContext $context, array $serverByModule): ImportReport
    {
        if ($snapshot->sourceHost() !== $context->sourceHost || !$serverByModule) {
            throw new RuntimeException('Explicit provider source and mapping are required');
        }
        $servers = $resources = [];
        foreach ($serverByModule as $module => $id) {
            $server = Server::findOrFail($id);
            if ($context->mappedId('provider_servers', (string) $module) !== $server->id) {
                throw new RuntimeException('Provider source mapping conflicts');
            }
            $class = match ($server->extension) {
                'ISPmanager' => ISPmanager::class,'DNSmanager' => DNSmanager::class,default => throw new RuntimeException('Provider attachment is not supported by this stage')
            };
            $servers[$id] = $server;
            $resources[$id] = (new $class($server->settings->pluck('value', 'key')->all()))->getResourceInventory();
        }

        return DB::transaction(function () use ($snapshot, $context, $serverByModule, $servers, $resources) {
            $counts = ['attached' => 0, 'active' => 0, 'suspended' => 0];
            foreach ($snapshot->rows('items') as $row) {
                $serverId = $serverByModule[$row['processingmodule'] ?? ''] ?? null;
                if (!$serverId || !in_array((string) $row['status'], ['2', '3'], true)) {
                    continue;
                }
                $service = Service::findOrFail($context->mappedId('items', (string) $row['id']));
                $owner = DB::table('billmanager_accounts')->where('id', $context->mappedId('accounts', (string) $row['account']))->value('owner_user_id');
                if ($service->user_id != $owner || !MigrationHold::isHeld($service)) {
                    throw new RuntimeException('Attachment requires mapped ownership and an active hold');
                }
                $parameters = array_column(array_filter($snapshot->rows('itemparams'), fn ($p) => (string) $p['item'] === (string) $row['id']), 'value', 'intname');
                $username = $parameters['username'] ?? null;
                $matches = array_values(array_filter($resources[$serverId], fn ($r) => $username && $r['username'] === $username));
                if (count($matches) !== 1 || $matches[0]['owner'] === '') {
                    throw new RuntimeException('Provider account identity is missing or ambiguous');
                }
                $resource = $matches[0];
                if ($resource['status'] !== ($row['status'] === '2' ? 'active' : 'suspended')) {
                    throw new RuntimeException('Provider account status conflicts with source');
                }
                if ($service->product->server_id !== null && $service->product->server_id != $serverId) {
                    throw new RuntimeException('Product provider identity conflicts');
                }
                $duplicate = DB::table('properties')->join('services', 'services.id', '=', 'properties.model_id')->join('products', 'products.id', '=', 'services.product_id')
                    ->where('properties.model_type', Service::class)->where('properties.key', 'provider_username')->where('properties.value', $username)
                    ->where('products.server_id', $serverId)->where('services.id', '!=', $service->id)->exists();
                if ($duplicate) {
                    throw new RuntimeException('Provider account is already bound to another service');
                }
                $binding = ['server_id' => (int) $serverId, 'extension' => $servers[$serverId]->extension, 'username' => $username, 'owner' => $resource['owner']];
                $context->archive('provider_bindings', (string) $row['id'], $binding, (int) $row['account']);
                foreach (['provider_username' => $username, 'provider_owner' => $resource['owner']] as $key => $value) {
                    $identity = ['model_type' => Service::class, 'model_id' => $service->id, 'key' => $key];
                    $existing = DB::table('properties')->where($identity)->first();
                    if ($existing && $existing->value !== $value) {
                        throw new RuntimeException('Existing provider identity conflicts');
                    }
                    DB::table('properties')->updateOrInsert($identity, ['value' => $value]);
                }
                DB::table('products')->where('id', $service->product_id)->update(['server_id' => $serverId]);
                $counts['attached']++;
                $counts[$resource['status']]++;
            }

            return new ImportReport('provider_accounts_attached_held',$counts);
        });
    }
}
