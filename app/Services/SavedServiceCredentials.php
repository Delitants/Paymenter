<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\Crypt;

/** Reads saved values only; callers must authorize access before reading. */
class SavedServiceCredentials
{
    public function read(Service $service): array
    {
        $properties = $service->properties()->pluck('value', 'key')->all();

        return array_filter([
            'cloud_init_password' => isset($properties['cloud_init_password_encrypted'])
                ? Crypt::decryptString($properties['cloud_init_password_encrypted']) : ($properties['cloud_init_password'] ?? null),
            'cloud_init_username' => $properties['cloud_init_username'] ?? null,
            'assigned_ipv4' => $properties['assigned_ipv4'] ?? null,
            'assigned_ipv4_list' => $properties['assigned_ipv4_list'] ?? null,
            'assigned_ipv4_private' => $properties['assigned_ipv4_private'] ?? null,
            'assigned_ipv6' => $properties['assigned_ipv6'] ?? null,
            'proxmox_vm_id' => $properties['proxmox_vm_id'] ?? null,
            'proxmox_node' => $properties['proxmox_node'] ?? null,
        ], fn ($value) => $value !== null);
    }
}
