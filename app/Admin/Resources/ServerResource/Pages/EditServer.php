<?php

namespace App\Admin\Resources\ServerResource\Pages;

use App\Admin\Resources\ServerResource;
use App\Helpers\ExtensionHelper;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditServer extends EditRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(fn ($record) => ExtensionHelper::call($record, 'disabled', [$record], mayFail: true)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->record->settings as $setting) {
            $data['settings'][$setting->key] = $setting->value;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update(Arr::except($data, ['settings']));

        if (!isset($data['settings'])) {
            Notification::make()
                ->title('Server Saved')
                ->body('Server configuration has been saved successfully.')
                ->success()
                ->send();
            return $record->refresh();
        }

        // Test Proxmox connection if API credentials are provided
        if (isset($data['settings']['host'], $data['settings']['api_token_id'], $data['settings']['api_token_secret'])) {
            $testResult = ExtensionHelper::testConfig($record, $data['settings']);

            if ($testResult !== true) {
                Notification::make()
                    ->title('Proxmox Connection Failed')
                    ->body($testResult)
                    ->danger()
                    ->persistent()
                    ->send();

                throw new \Exception('Proxmox connection test failed: ' . $testResult);
            }

            // Check if storage pools are available
            $proxmox = ExtensionHelper::getExtension('server', $record->extension, $data['settings']);
            $storagePools = [];

            try {
                $ref = new \ReflectionClass($proxmox);
                $method = $ref->getMethod('getServerStoragePools');
                $method->setAccessible(true);
                $storagePools = $method->invoke($proxmox, null, $data['settings']);
            } catch (\Exception $e) {
                // Ignore reflection errors
            }

            if (empty($storagePools)) {
                Notification::make()
                    ->title('Warning: No Storage Pools Found')
                    ->body('Connected to Proxmox but no storage pools with "images" content found. Check that the API token has storage permissions. Run: pveum user token add root@pam paymenter -privs "Admin"')
                    ->warning()
                    ->persistent()
                    ->send();
            } else {
                Notification::make()
                    ->title('Proxmox Connection Successful')
                    ->body('Found ' . count($storagePools) . ' storage pool(s): ' . implode(', ', array_keys($storagePools)))
                    ->success()
                    ->send();
            }
        }

        // Pass live settings to getConfig so all fields (including allowed_nodes) are returned
        $config = ExtensionHelper::getConfig($record->type, $record->extension, $data['settings']);

        foreach ($config as $option) {
            $record->settings()->updateOrCreate([
                'key' => $option['name'],
                'settingable_id' => $record->id,
                'settingable_type' => $record->getMorphClass(),
            ], [
                'type' => $option['database_type'] ?? 'string',
                'value' => isset($data['settings'][$option['name']]) ? (is_array($data['settings'][$option['name']]) ? json_encode($data['settings'][$option['name']]) : $data['settings'][$option['name']]) : null,
                'encrypted' => $option['encrypted'] ?? false,
            ]);
        }

        ExtensionHelper::call($record, 'updated', [$record], mayFail: true);

        Notification::make()
            ->title('Server Saved')
            ->body('Proxmox server configuration has been saved successfully.')
            ->success()
            ->send();

        // Maybe the extension changed the record, so we need to refresh it
        return $record->refresh();
    }
}
