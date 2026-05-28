<?php

namespace App\Admin\Resources;

use App\Admin\Resources\IpPoolResource\Pages\CreateIpPool;
use App\Admin\Resources\IpPoolResource\Pages\EditIpPool;
use App\Admin\Resources\IpPoolResource\Pages\ListIpPools;
use App\Admin\Resources\IpPoolResource\RelationManagers\IpAddressesRelationManager;
use App\Models\IpPool;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\EditBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IpPoolResource extends Resource
{
    protected static ?string $model = IpPool::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

    protected static ?string $navigationLabel = 'IP Pools';

    protected static ?string $modelLabel = 'IP Pool';

    protected static ?string $pluralModelLabel = 'IP Pools';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Pool Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Production IPv4 Pool'),

                        Select::make('ip_version')
                            ->label('IP Version')
                            ->options([
                                'ipv4' => 'IPv4',
                                'ipv6' => 'IPv6',
                                'both' => 'Both',
                            ])
                            ->default('ipv4')
                            ->required()
                            ->live(),

                        TextInput::make('subnet_mask')
                            ->label('Subnet Mask')
                            ->placeholder('255.255.255.0')
                            ->maxLength(255),

                        TextInput::make('gateway')
                            ->label('Gateway IP')
                            ->placeholder('192.168.1.1')
                            ->maxLength(255),

                        TextInput::make('dns_primary')
                            ->label('Primary DNS')
                            ->placeholder('8.8.8.8')
                            ->maxLength(255),

                        TextInput::make('dns_secondary')
                            ->label('Secondary DNS')
                            ->placeholder('8.8.4.4')
                            ->maxLength(255),

                        Select::make('server_id')
                            ->label('Associated Server')
                            ->relationship('server', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Select server (optional)'),

                        Textarea::make('description')
                            ->label('Description')
                            ->columnSpanFull()
                            ->rows(2),
                    ]),
            ]);
    }

    public static function getRelationManagers(): array
    {
        return [
            IpAddressesRelationManager::class,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Pool Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('ip_version')
                    ->label('IP Version')
                    ->badge()
                    ->formatStateUsing(fn ($state) => strtoupper($state)),

                TextColumn::make('server.name')
                    ->label('Server')
                    ->searchable(),

                TextColumn::make('total_ips')
                    ->label('Total IPs')
                    ->formatStateUsing(fn ($record) => $record->getTotalIpsAttribute()),

                TextColumn::make('available_ips')
                    ->label('Available')
                    ->formatStateUsing(fn ($record) => $record->getAvailableIpsAttribute())
                    ->color('success'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('import_ips')
                    ->label('Import IPs from Range')
                    ->icon('ri-download-line')
                    ->requiresConfirmation()
                    ->modalHeading('Import IP Addresses')
                    ->modalDescription('Enter an IP range to automatically generate IP addresses in this pool.')
                    ->form([
                        TextInput::make('start_ip')
                            ->label('Start IP')
                            ->required()
                            ->placeholder('192.168.1.1'),
                        TextInput::make('end_ip')
                            ->label('End IP')
                            ->required()
                            ->placeholder('192.168.1.254'),
                    ])
                    ->action(function ($record, array $data) {
                        $startIp = ip2long($data['start_ip']);
                        $endIp = ip2long($data['end_ip']);

                        if ($startIp === false || $endIp === false || $startIp > $endIp) {
                            throw new \Exception('Invalid IP range');
                        }

                        $count = 0;
                        for ($ip = $startIp; $ip <= $endIp; $ip++) {
                            $ipAddress = long2ip($ip);
                            if (!$record->ipAddresses()->where('ip_address', $ipAddress)->exists()) {
                                $record->ipAddresses()->create([
                                    'ip_address' => $ipAddress,
                                    'is_assigned' => false,
                                ]);
                                $count++;
                            }
                        }

                        return redirect()->route('filament.admin.resources.ip-pools.edit', ['record' => $record]);
                    })
                    ->visible(fn ($record) => $record !== null),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIpPools::route('/'),
            'create' => CreateIpPool::route('/create'),
            'edit' => EditIpPool::route('/{record}/edit'),
        ];
    }
}
