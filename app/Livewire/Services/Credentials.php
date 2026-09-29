<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use Livewire\Attributes\Locked;
use Illuminate\Support\Facades\Crypt;

class Credentials extends Component
{
    public Service $service;

    #[Locked]
    public ?array $credentials = null;

    public function mount()
    {
        $this->credentials = $this->getCredentials();
    }

    public function getCredentials(): ?array
    {
        $this->authorize('update', $this->service);
        $properties = $this->service->properties->pluck('value', 'key')->toArray();

        $credentials = [
            'cloud_init_password' => isset($properties['cloud_init_password_encrypted'])
                ? Crypt::decryptString($properties['cloud_init_password_encrypted']) : ($properties['cloud_init_password'] ?? null),
            'cloud_init_username' => $properties['cloud_init_username'] ?? null,
            'assigned_ipv4' => $properties['assigned_ipv4'] ?? null,
            'assigned_ipv4_list' => $properties['assigned_ipv4_list'] ?? null,
            'assigned_ipv4_private' => $properties['assigned_ipv4_private'] ?? null,
            'assigned_ipv6' => $properties['assigned_ipv6'] ?? null,
            'proxmox_vm_id' => $properties['proxmox_vm_id'] ?? null,
            'proxmox_node' => $properties['proxmox_node'] ?? null,
        ];

        // Filter out null values
        return array_filter($credentials, fn ($v) => $v !== null);
    }

    public function render()
    {
        $this->authorize('update', $this->service);

        return view('services.credentials')->layoutData([
            'title' => 'VM Credentials',
            'sidebar' => true,
        ]);
    }
}
