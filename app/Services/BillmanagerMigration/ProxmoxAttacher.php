<?php

namespace App\Services\BillmanagerMigration;

use App\Models\IpAddress;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Proxmox\Proxmox;
use RuntimeException;

final class ProxmoxAttacher
{
    /** Attach existing resources using an operator-supplied module-to-server map. */
    public function attach(Snapshot $snapshot, ImportContext $context, array $serverByModule): ImportReport
    {
        if ($snapshot->sourceHost() !== $context->sourceHost || !$serverByModule) {
            throw new RuntimeException('An explicit source and Proxmox server mapping are required');
        }
        $providers = $inventories = [];
        foreach ($serverByModule as $serverId) {
            $server = Server::findOrFail($serverId);
            if ($server->extension !== 'Proxmox') {
                throw new RuntimeException('Mapped server is not Proxmox');
            }
            $providers[$serverId] = new Proxmox($server->settings->pluck('value', 'key')->all());
            $inventories[$serverId] = $providers[$serverId]->getResourceInventory();
        }

        return DB::transaction(function () use ($snapshot, $context, $serverByModule, $providers, $inventories) {
            $counts = ['attached' => 0, 'active' => 0, 'processing' => 0, 'ip_ownership_review' => 0, 'ip_hostname_conflicts' => 0, 'ip_missing_from_pool' => 0];
            foreach ($snapshot->rows('items') as $row) {
                $serverId = $serverByModule[$row['processingmodule'] ?? ''] ?? null;
                if (!$serverId || !in_array((string) $row['status'], ['2', '5'], true)) {
                    continue;
                }
                $service = Service::findOrFail($context->mappedId('items', (string) $row['id']));
                $owner = DB::table('billmanager_accounts')->where('id', $context->mappedId('accounts', (string) $row['account']))->value('owner_user_id');
                if ($service->user_id != $owner || !MigrationHold::isHeld($service)) {
                    throw new RuntimeException('Attachment requires the mapped service owner and an active hold');
                }
                $parameters = array_column(array_filter($snapshot->rows('itemparams'), fn ($p) => (string) $p['item'] === (string) $row['id']), 'value', 'intname');
                $resource = (new ProxmoxMatcher)->match($row + $parameters, $inventories[$serverId]);
                $duplicate = DB::table('properties')->join('services', 'services.id', '=', 'properties.model_id')
                    ->join('products', 'products.id', '=', 'services.product_id')
                    ->where('properties.model_type', Service::class)->where('properties.key', 'proxmox_vm_id')
                    ->where('properties.value', (string) $resource['vmid'])->where('products.server_id', $serverId)
                    ->where('services.id', '!=', $service->id)->exists();
                if ($duplicate || ($service->product->server_id !== null && $service->product->server_id != $serverId)) {
                    throw new RuntimeException('Proxmox resource is already assigned to another service or server');
                }
                DB::table('products')->where('id', $service->product_id)->update(['server_id' => $serverId]);
                $properties = ['proxmox_vm_id' => (string) $resource['vmid'], 'proxmox_vm_name' => $resource['name'], 'proxmox_vm_type' => $resource['type'], 'proxmox_node' => $resource['node']];
                foreach ($properties as $key => $value) {
                    $identity = ['model_type' => Service::class, 'model_id' => $service->id, 'key' => $key];
                    $existing = DB::table('properties')->where($identity)->first();
                    if ($existing && $existing->value !== $value && $key !== 'proxmox_node') {
                        throw new RuntimeException('Existing Proxmox service identity conflicts');
                    }
                    DB::table('properties')->updateOrInsert($identity, ['value' => $value]);
                }
                // A real status GET also validates the freshly written identity
                // against current inventory. No create/power/console API is used.
                $status = $providers[$serverId]->getServerInfo($service->fresh());
                $binding = ['server_id' => (int) $serverId, 'vmid' => (string) $resource['vmid'], 'type' => $resource['type'], 'name' => $resource['name']];
                $context->archive('provider_bindings', (string) $row['id'], $binding, (int) $row['account']);
                $observations = ['binding' => $binding, 'node' => $status['node'], 'ips' => []];
                foreach (preg_split('/[\s,;]+/', trim($parameters['ip'] ?? ''), flags: PREG_SPLIT_NO_EMPTY) as $ip) {
                    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                        throw new RuntimeException('Source service IP requires explicit reconciliation');
                    }
                    $reservation = IpAddress::where('ip_address', $ip)->first();
                    if (!$reservation) {
                        $counts['ip_missing_from_pool']++;
                    }
                    if (!$reservation || !$reservation->is_assigned || $reservation->assigned_to_type !== Service::class || $reservation->assigned_to_id != $service->id) {
                        $counts['ip_ownership_review']++;
                    }
                    if ($reservation && $reservation->hostname !== $resource['name']) {
                        $counts['ip_hostname_conflicts']++;
                    }
                    // Keep pre-existing reservations byte-for-byte. A hostname
                    // match alone never authorizes reassignment or release.
                    $observations['ips'][] = ['ip' => $ip, 'reservation' => $reservation?->getRawOriginal()];
                }
                $context->archive('provider_observations', hash('sha256', json_encode($observations, JSON_THROW_ON_ERROR)), $observations, (int) $row['account']);
                $counts['attached']++;
                $counts[$row['status'] === '2' ? 'active' : 'processing']++;
            }

            return new ImportReport('proxmox_attached_held', $counts);
        });
    }
}
