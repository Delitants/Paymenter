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
use Filament\Forms\Get;
use Illuminate\Support\Facades\DB;
use App\Rules\UniqueNetwork;
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
                        TextInput::make('network_address')
                            ->label('Network Address')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., 192.168.1.0/24 or 2001:db8::/64')
                            ->live(onBlur: true)
                            ->debounce(500)
                            ->regex('/^[0-9a-fA-F.:]+\/[0-9]{1,3}$/', 'Invalid CIDR notation. Use format like 192.168.1.0/24')
                            ->rule(new UniqueNetwork())
                            ->afterStateUpdated(function (callable $set, $state) {
                                if (empty($state)) {
                                    return;
                                }

                                // Parse network address
                                if (strpos($state, '/') === false) {
                                    return;
                                }

                                [$ip, $cidr] = explode('/', $state);
                                $cidr = (int) $cidr;
                                $ipVersion = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'ipv6' : 'ipv4';

                                // Auto-populate name from network
                                $set('name', $state);

                                // Detect IP version
                                $set('ip_version', $ipVersion);

                                // Calculate subnet mask
                                if ($ipVersion === 'ipv4') {
                                    $mask = -1 << (32 - $cidr);
                                    $subnetMask = long2ip($mask);
                                    $set('subnet_mask', $subnetMask);

                                    // Calculate gateway (first usable IP)
                                    $ipLong = ip2long($ip);
                                    $gatewayLong = $ipLong + 1;
                                    $set('gateway', long2ip($gatewayLong));

                                    // Calculate broadcast
                                    $broadcastLong = $ipLong + pow(2, (32 - $cidr)) - 1;
                                    $set('broadcast_address', long2ip($broadcastLong));
                                } else {
                                    // IPv6
                                    $set('subnet_mask', '/' . $cidr);
                                    $set('gateway', $ip . '::1');
                                    $set('broadcast_address', null); // No broadcast in IPv6
                                }
                            })
                            ->columnSpanFull(),

                        TextInput::make('name')
                            ->label('Pool Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Auto-filled from network, can be customized'),

                        TextInput::make('ip_version')
                            ->label('IP Version')
                            ->readOnly()
                            ->disabled()
                            ->dehydrated(true)
                            ->placeholder('Auto-detected from network'),

                        TextInput::make('subnet_mask')
                            ->label('Subnet Mask')
                            ->readOnly()
                            ->disabled()
                            ->dehydrated(true)
                            ->placeholder('Auto-calculated from CIDR'),

                        TextInput::make('gateway')
                            ->label('Gateway IP')
                            ->placeholder('Auto-calculated, can be overridden')
                            ->maxLength(255),

                        TextInput::make('broadcast_address')
                            ->label('Broadcast Address')
                            ->readOnly()
                            ->disabled()
                            ->dehydrated(true)
                            ->hidden(fn (callable $get) => $get('ip_version') === 'ipv6')
                            ->placeholder('Auto-calculated from network'),

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
                TextColumn::make('network_address')
                    ->label('Network')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Pool Name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ip_version')
                    ->label('IP Version')
                    ->badge()
                    ->formatStateUsing(fn ($state) => strtoupper($state))
                    ->colors([
                        'success' => 'ipv6',
                        'primary' => 'ipv4',
                    ]),

                TextColumn::make('subnet_mask')
                    ->label('Subnet Mask')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('gateway')
                    ->label('Gateway')
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
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
