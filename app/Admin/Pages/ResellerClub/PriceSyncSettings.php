<?php

namespace App\Admin\Pages\ResellerClub;

use App\Console\Commands\ResellerClubSyncPrices;
use App\Models\Category;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;

class PriceSyncSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $title = 'ResellerClub';

    protected static ?string $navigationLabel = 'ResellerClub';

    protected static string|\UnitEnum|null $navigationGroup = 'Domain Names';

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
                    Select::make('server_id')->label('ResellerClub Server')
                        ->options(fn () => Server::where('extension', 'ResellerClub')->pluck('name', 'id'))->required(),
                    Select::make('category_id')->label('Domain Category')
                        ->options(fn () => Category::pluck('name', 'id'))->required()
                        ->helperText('New TLDs are created as hidden, out-of-stock draft products.'),
                    Select::make('price_source')->label('Price Source')
                        ->options(['customer' => 'ResellerClub customer selling prices', 'cost' => 'Reseller cost prices'])
                        ->default('customer')->required(),
                    Checkbox::make('sync_enabled')
                        ->label('Enable Automatic Sync')->live()
                        ->helperText('Discover TLDs and refresh prices using the Paymenter scheduler'),

                    Select::make('sync_frequency')
                        ->label('Sync Frequency')->live()
                        ->options([
                            'daily' => 'Daily',
                            'weekly' => 'Weekly',
                            'monthly' => 'Monthly',
                        ])
                        ->default('daily')
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
                        ->default(0)->required()->numeric()->step(0.01)
                        ->minValue(0)
                        ->maxValue(1000)
                        ->suffix('%')
                        ->helperText('Markup to apply on top of ResellerClub prices (e.g., 20 for 20% markup)'),

                    Checkbox::make('sync_all_tlds')
                        ->label('Sync All TLDs')
                        ->default(true)
                        ->helperText('Includes TLDs with no existing customer domains')->live(),

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
                                ->modalDescription('This refreshes catalog prices for new orders. Existing customer service amounts are preserved. New products remain drafts.')
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
        return auth()->user()?->hasPermission('admin.settings.view') && Server::where('extension', 'ResellerClub')->exists();
    }

    private function getSettings(): array
    {
        $saved = ResellerClubSyncPrices::settings();

        return [
            'server_id' => $saved['server_id'] ?? null,
            'category_id' => $saved['category_id'] ?? null,
            'price_source' => $saved['price_source'] ?? 'customer',
            'sync_enabled' => ($saved['sync_enabled'] ?? '0') === '1',
            'sync_frequency' => $saved['sync_frequency'] ?? 'daily',
            'sync_day' => $saved['sync_day'] ?? 'monday',
            'markup_percentage' => $saved['markup_percentage'] ?? '0',
            'sync_all_tlds' => ($saved['sync_all_tlds'] ?? '1') === '1',
            'tld_list' => $saved['tld_list'] ?? '',
        ];
    }

    public function save(): void
    {
        Gate::authorize('has-permission', 'admin.settings.update');
        $data = $this->form->getState();
        foreach ($data as $key => $value) {
            if (in_array($key, array_keys($this->getSettings()), true)) {
                ResellerClubSyncPrices::saveSetting($key, is_bool($value) ? (int) $value : ($value ?? ''));
            }
        }
        Notification::make()->success()->title('Catalog settings saved')->send();
    }

    public function runSync(bool $dryRun): void
    {
        Gate::authorize('has-permission', 'admin.settings.update');
        // Use the displayed form, including unsaved choices, for an explicit manual run.
        $data = $this->form->getState();
        $parameters = [
            '--server' => $data['server_id'], '--category' => $data['category_id'],
            '--source' => $data['price_source'], '--markup' => $data['markup_percentage'],
        ];
        if (!$data['sync_all_tlds']) {
            $parameters['--tlds'] = $data['tld_list'] ?? '';
        } else {
            $parameters['--all'] = true;
        }
        if ($dryRun) {
            $parameters['--dry-run'] = true;
        }
        $code = Artisan::call('resellerclub:sync-prices', $parameters);
        Notification::make()->status($code === 0 ? 'success' : 'danger')
            ->title($code === 0 ? ($dryRun ? 'Catalog preview complete' : 'Catalog synchronized') : 'Catalog sync failed')
            ->body(Artisan::output())->send();
    }
}
