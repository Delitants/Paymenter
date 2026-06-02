<?php

namespace App\Admin\Pages\Cloudflare;

use App\Services\CloudflareService;
use App\Services\WebServerConfigService;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class CloudflareSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-cloud-line';
    protected static ?string $title = 'Cloudflare';
    protected static ?string $navigationLabel = 'Cloudflare';
    protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

    protected string $view = 'admin.pages.cloudflare.settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->getSettings());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Checkbox::make('enabled')
                        ->label('Enable Cloudflare Integration')
                        ->helperText('Restore real client IPs and enable Cloudflare features')
                        ->dehydrated(true),

                    Checkbox::make('whitelist_enabled')
                        ->label('Enable IP Whitelist')
                        ->helperText('Only allow requests from Cloudflare IP ranges')
                        ->dehydrated(true),

                    Textarea::make('custom_whitelist')
                        ->label('Additional Allowed IPs')
                        ->placeholder('1.2.3.4' . PHP_EOL . '5.6.7.8' . PHP_EOL . '192.168.1.0/24')
                        ->helperText('One IP or CIDR per line. Cloudflare IPs are always allowed.')
                        ->rows(6)
                        ->disabled(fn (callable $get) => !$get('whitelist_enabled')),

                    Checkbox::make('auto_update_ranges')
                        ->label('Auto-update IP Ranges')
                        ->helperText('Automatically fetch latest IP ranges from Cloudflare daily')
                        ->dehydrated(true),
                ])
                    ->footer([
                        Actions::make([
                            Action::make('refresh_ranges')
                                ->label('Refresh IP Ranges')
                                ->color('info')
                                ->action(fn () => $this->refreshRanges()),

                            Action::make('save')
                                ->label('Save Settings')
                                ->color('primary')
                                ->action(fn () => $this->save()),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('has-permission', 'admin.settings.update');
    }


    private function getSettings(): array
    {
        return [
            'enabled' => in_array(Setting::where('key', 'cloudflare_enabled')->first()?->value, ['1', 1, true], true),
            'whitelist_enabled' => in_array(Setting::where('key', 'cloudflare_whitelist_enabled')->first()?->value, ['1', 1, true], true),
            'custom_whitelist' => Setting::where('key', 'cloudflare_custom_whitelist')->first()?->value ?? '',
            'auto_update_ranges' => !in_array(Setting::where('key', 'cloudflare_auto_update_ranges')->first()?->value, ['0', 0, false, null], true),
        ];
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            // Ensure checkbox values are always present (unchecked = false)
            $enabled = isset($data['enabled']) ? (bool) $data['enabled'] : false;
            $whitelistEnabled = isset($data['whitelist_enabled']) ? (bool) $data['whitelist_enabled'] : false;
            $autoUpdateRanges = isset($data['auto_update_ranges']) ? (bool) $data['auto_update_ranges'] : true;

            $settings = [
                'cloudflare_enabled' => $enabled ? '1' : '0',
                'cloudflare_whitelist_enabled' => $whitelistEnabled ? '1' : '0',
                'cloudflare_custom_whitelist' => $data['custom_whitelist'] ?? '',
                'cloudflare_auto_update_ranges' => $autoUpdateRanges ? '1' : '0',
            ];

            foreach ($settings as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }

            // Update web server configuration automatically
            if ($settings['cloudflare_enabled'] === '1') {
                $webServerService = app(WebServerConfigService::class);
                $webServerService->updateConfig();
            }

            Notification::make()
                ->success()
                ->title('Settings Saved')
                ->body('Cloudflare settings have been updated successfully. Web server configuration updated.')
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title('Save Failed')
                ->body('Error: ' . $e->getMessage())
                ->send();

            throw $e;
        }
    }

    public function refreshRanges(): void
    {
        $service = app(CloudflareService::class);
        $service->refreshIpRanges();

        $counts = $service->getRangesCount();

        Notification::make()
            ->success()
            ->title('IP Ranges Refreshed')
            ->body("IPv4: {$counts['ipv4_count']} ranges | IPv6: {$counts['ipv6_count']} ranges | Total: {$counts['total']}")
            ->send();
    }
}
