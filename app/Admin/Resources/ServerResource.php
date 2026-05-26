<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ServerResource\Pages\CreateServer;
use App\Admin\Resources\ServerResource\Pages\EditServer;
use App\Admin\Resources\ServerResource\Pages\ListServers;
use App\Helpers\ExtensionHelper;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-server-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-server-fill';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record->name;
    }

    public static function form(Schema $schema): Schema
    {
        $servers = ExtensionHelper::getExtensions('server');

        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        static::getModel(),
                        'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule) => $rule->where('deleted_at', null)
                    )
                    ->placeholder('Enter the name of the server'),
                Select::make('extension')
                    ->label('Server')
                    ->required()
                    ->searchable()
                    ->options(array_combine(
                        array_column($servers, 'name'),
                        array_column($servers, 'name')
                    ))
                    ->live()
                    ->disabledOn('edit')
                    ->placeholder('Select the type of the server')
                    ->searchPrompt('Type to search...')
                    ->hint('After selecting, settings fields will load below.')
                    ->hintAction(
                        Action::make('testConnection')
                            ->label('Test Connection')
                            ->requiresConfirmation()
                            ->modalHeading('Test Proxmox Connection')
                            ->modalDescription('This will attempt to connect to your Proxmox server using the current settings.')
                            ->modalSubmitActionLabel('Yes, test it')
                            ->action(function (Get $get, $record) {
                                try {
                                    $connection = ExtensionHelper::testConfig($record, $get('settings'));

                                    if ($connection === true) {
                                        Notification::make()
                                            ->title('Connection Successful')
                                            ->body('Successfully connected to Proxmox server.')
                                            ->success()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title('Connection Failed')
                                            ->body($connection)
                                            ->warning()
                                            ->send();
                                    }
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title('Connection Error')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            })
                            ->hidden(function ($record) {
                                return empty($record) || !ExtensionHelper::hasFunction($record, 'testConfig');
                            })
                    ),
                Section::make('Server Settings')
                    ->columnSpanFull()
                    ->description('Specific settings for the selected server.')
                    ->schema([
                        Grid::make(2)->schema(fn (Get $get) => ExtensionHelper::getConfigAsInputs(
                            'server',
                            $get('extension'),
                            array_merge(
                                $get('settings') ?? [],
                                [
                                    'host' => $get('settings.host') ?? '',
                                    'api_token_id' => $get('settings.api_token_id') ?? '',
                                    'api_token_secret' => $get('settings.api_token_secret') ?? '',
                                    'ceph_mode' => $get('settings.ceph_mode') ?? false,
                                ]
                            )
                        ))->key('settings')
                        ->live()
                        ->visible(fn (callable $get) => !empty($get('extension'))),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServers::route('/'),
            'create' => CreateServer::route('/create'),
            'edit' => EditServer::route('/{record}/edit'),
        ];
    }
}
