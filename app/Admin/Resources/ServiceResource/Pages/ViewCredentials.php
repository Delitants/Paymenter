<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Resources\ServiceResource;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Support\Colors\Color;

class ViewCredentials extends Page
{
    protected static string $resource = ServiceResource::class;

    protected string $view = 'admin.resources.service-resource.pages.view-credentials';

    public Service $record;

    public ?array $credentials = null;

    public function mount(): void
    {
        $this->credentials = $this->getCredentials();
    }

    public function getCredentials(): ?array
    {
        $properties = $this->record->properties->pluck('value', 'key')->toArray();

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

    public function getHeaderActions(): array
    {
        return [
            Action::make('copyCredentials')
                ->label('Copy to Clipboard')
                ->icon('ri-file-copy-line')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Copy Credentials')
                ->modalDescription('The credentials have been copied to your clipboard.')
                ->action(function () {
                    Notification::make('credentials-copied')
                        ->title('Credentials copied')
                        ->success()
                        ->send();
                }),
            Action::make('regeneratePassword')
                ->label('Regenerate Password')
                ->icon('ri-refresh-line')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerate Cloud-Init Password')
                ->modalDescription('This will generate a new random password for the VM. The old password will no longer work.')
                ->action(function () {
                    // Generate new password and store it
                    $newPassword = \Str::random(16);
                    $this->record->properties()->updateOrCreate(
                        ['key' => 'cloud_init_password'],
                        ['value' => $newPassword]
                    );
                    $this->credentials = $this->getCredentials();

                    Notification::make('password-regenerated')
                        ->title('Password regenerated')
                        ->body('The new password has been generated and saved.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getTitle(): string
    {
        return 'VM Credentials - ' . $this->record->product->name;
    }
}
