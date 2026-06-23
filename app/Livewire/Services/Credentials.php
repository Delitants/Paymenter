<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Credentials extends Component
{
    public Service $service;

    public ?array $credentials = null;

    public function mount()
    {
        $this->credentials = $this->getCredentials();
    }

    public function getCredentials(): ?array
    {
        $properties = $this->service->properties->pluck('value', 'key')->toArray();

        $credentials = [
            'cloud_init_password' => $properties['cloud_init_password'] ?? null,
            'assigned_ipv4' => $properties['assigned_ipv4'] ?? null,
            'assigned_ipv6' => $properties['assigned_ipv6'] ?? null,
            'proxmox_vm_id' => $properties['proxmox_vm_id'] ?? null,
            'proxmox_node' => $properties['proxmox_node'] ?? null,
        ];

        // Filter out null values
        return array_filter($credentials, fn($v) => $v !== null);
    }

    public function regeneratePassword()
    {
        // Generate new password and store it
        $newPassword = Str::random(16);
        $this->service->properties()->updateOrCreate(
            ['key' => 'cloud_init_password'],
            ['value' => $newPassword]
        );
        $this->credentials = $this->getCredentials();

        Notification::make()
            ->title('Password regenerated')
            ->body('The new password has been generated and saved.')
            ->success()
            ->send();
    }

    public function render()
    {
        return view('services.credentials')->layoutData([
            'title' => 'VM Credentials',
            'sidebar' => true,
        ]);
    }
}
