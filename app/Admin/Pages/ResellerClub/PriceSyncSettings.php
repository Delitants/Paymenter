<?php

namespace App\Admin\Pages\ResellerClub;

use App\Models\Server;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class PriceSyncSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $title = 'ResellerClub';
    protected static ?string $navigationLabel = 'ResellerClub';
    protected static string|\UnitEnum|null $navigationGroup = 'Extensions/Gateways';

    protected string $view = 'admin.pages.resellerclub.price-sync-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->getSettings());
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Checkbox::make('sync_enabled')
                        ->label('Enable Automatic Sync')
                        ->helperText('Automatically sync prices from ResellerClub weekly'),

                    Select::make('sync_frequency')
                        ->label('Sync Frequency')
                        ->options([
                            'daily' => 'Daily',
                            'weekly' => 'Weekly (Recommended)',
                            'monthly' => 'Monthly',
                        ])
                        ->default('weekly')
                        ->visible(fn (callable $get) => $get('sync_enabled')),

                    Select::make('sync_day')
                        ->label('Day of Week')
                        ->options([
                            'monday' => 'Monday',
                            'tuesday' => 'Tuesday',
                            'wednesday' => 'Wednesday',
                            'thursday' => 'Thursday',
                            'friday' => 'Friday',
                            'saturday' => 'Saturday',
                            'sunday' => 'Sunday',
                        ])
                        ->default('monday')
                        ->visible(fn (callable $get) => $get('sync_enabled') && $get('sync_frequency') === 'weekly'),

                    TextInput::make('markup_percentage')
                        ->label('Markup Percentage')
                        ->type('number')
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(1000)
                        ->suffix('%')
                        ->helperText('Markup to apply on top of ResellerClub prices (e.g., 20 for 20% markup)'),

                    Checkbox::make('sync_all_tlds')
                        ->label('Sync All TLDs')
                        ->default(true)
                        ->helperText('If disabled, only selected TLDs will be synced'),

                    Checkbox::make('skip_in_use')
                        ->label('Skip Products In Use')
                        ->default(true)
                        ->helperText('Do not modify products that have active services (prices will still update)'),

                    Checkbox::make('send_notifications')
                        ->label('Send Notifications')
                        ->default(true)
                        ->helperText('Notify admins when prices are updated'),

                    Textarea::make('tld_list')
                        ->label('TLDs to Sync')
                        ->placeholder('.com, .net, .org, .io, .co')
                        ->helperText('Comma-separated list of TLDs (e.g., .com,.net,.org)')
                        ->rows(3)
                        ->disabled(fn (callable $get) => $get('sync_all_tlds')),
                ])
                    ->footer([
                        Actions::make([
                            Action::make('dryRun')
                                ->label('Dry Run (Preview)')
                                ->color('info')
                                ->action(fn () => $this->runSync(true)),

                            Action::make('syncNow')
                                ->label('Sync Now')
                                ->color('success')
                                ->requiresConfirmation()
                                ->modalHeading('Confirm Price Sync')
                                ->modalDescription('This will update prices from ResellerClub. Products in use will only have prices updated.')
                                ->modalSubmitActionLabel('Yes, sync now')
                                ->action(fn () => $this->runSync(false)),

                            Action::make('save')
                                ->label('Save Settings')
                                ->color('primary')
                                ->action(fn () => $this->save()),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public static function canAccess(): bool
    {
        return Server::where('extension', 'ResellerClub')->exists();
    }


    private function getSettings(): array
    {
        return [
            'sync_enabled' => Setting::where('key', 'resellerclub.sync_enabled')->first()?->value === '1',
            'sync_frequency' => Setting::where('key', 'resellerclub.sync_frequency')->first()?->value ?? 'weekly',
            'sync_day' => Setting::where('key', 'resellerclub.sync_day')->first()?->value ?? 'monday',
            'markup_percentage' => Setting::where('key', 'resellerclub.markup_percentage')->first()?->value ?? '0',
            'sync_all_tlds' => Setting::where('key', 'resellerclub.sync_all_tlds')->first()?->value !== '0',
            'skip_in_use' => Setting::where('key', 'resellerclub.skip_in_use')->first()?->value !== '0',
            'send_notifications' => Setting::where('key', 'resellerclub.send_notifications')->first()?->value !== '0',
            'tld_list' => Setting::where('key', 'resellerclub.tld_list')->first()?->value ?? '',
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = [
            'resellerclub.sync_enabled' => $data['sync_enabled'] ? '1' : '0',
            'resellerclub.sync_frequency' => $data['sync_frequency'] ?? 'weekly',
            'resellerclub.sync_day' => $data['sync_day'] ?? 'monday',
            'resellerclub.markup_percentage' => $data['markup_percentage'] ?? '0',
            'resellerclub.sync_all_tlds' => $data['sync_all_tlds'] ? '1' : '0',
            'resellerclub.skip_in_use' => $data['skip_in_use'] ? '1' : '0',
            'resellerclub.send_notifications' => $data['send_notifications'] ? '1' : '0',
            'resellerclub.tld_list' => $data['tld_list'] ?? '',
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Notification::make()
            ->success()
            ->title('Settings Saved')
            ->body('Price sync settings have been updated successfully.')
            ->send();
    }

    public function runSync(bool $dryRun): void
    {
        $settings = $this->getSettings();
        $tlds = $settings['sync_all_tlds'] ? null : $settings['tld_list'];
        $markup = $settings['markup_percentage'] ?? 0;

        $command = 'resellerclub:sync-prices';
        $parameters = [];

        if ($tlds) {
            $parameters['--tlds'] = $tlds;
        }

        $parameters['--markup'] = $markup;

        if ($dryRun) {
            $parameters['--dry-run'] = true;
        }

        try {
            $exitCode = Artisan::call($command, $parameters);
            $output = Artisan::output();

            Log::info('ResellerClub sync output', ['output' => $output]);

            if ($exitCode === 0) {
                Notification::make()
                    ->success()
                    ->title($dryRun ? 'Dry Run Completed' : 'Sync Completed')
                    ->body('Check logs for details.')
                    ->send();
            } else {
                Notification::make()
                    ->danger()
                    ->title('Sync Failed')
                    ->body($output)
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title('Sync Failed')
                ->body($e->getMessage())
                ->send();
        }
    }
}
