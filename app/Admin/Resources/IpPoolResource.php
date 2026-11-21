<?php

namespace App\Admin\Resources;

use App\Admin\Resources\IpPoolResource\Pages\CreateIpPool;
use App\Admin\Resources\IpPoolResource\Pages\EditIpPool;
use App\Admin\Resources\IpPoolResource\Pages\ListIpPools;
use App\Admin\Resources\IpPoolResource\RelationManagers\IpAddressesRelationManager;
use App\Models\IpPool;
use App\Rules\UniqueNetwork;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IpPoolResource extends Resource
{
    protected static ?string $model = IpPool::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

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
                            ->rule('bail')
                            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                                if (self::networkDefaults($value) === null) {
                                    $fail('The :attribute must be a valid IPv4 or IPv6 CIDR subnet.');
                                }
                            })
                            ->rule(fn (?IpPool $record) => new UniqueNetwork($record?->id))
                            ->afterStateUpdated(function (callable $set, $state): void {
                                $defaults = self::networkDefaults($state);
                                if ($defaults === null) {
                                    return;
                                }
                                foreach ($defaults as $field => $value) {
                                    $set($field, $value);
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
                            ->maxLength(255)
                            ->rule(fn (callable $get) => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $version = self::networkDefaults($get('network_address'))['ip_version'] ?? null;
                                if ($version !== null && !filter_var($value, FILTER_VALIDATE_IP, $version === 'ipv6' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4)) {
                                    $fail('The :attribute must be a valid address matching the pool IP version.');
                                }
                            }),

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

    private static function networkDefaults(mixed $state): ?array
    {
        if (!is_string($state) || !preg_match('/^(.+)\/([0-9]{1,3})$/D', $state, $parts)) {
            return null;
        }
        $ip = $parts[1];
        $cidr = (int) $parts[2];
        $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'ipv6'
            : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'ipv4' : null);
        if ($version === null || $cidr > ($version === 'ipv6' ? 128 : 32)) {
            return null;
        }
        if ($version === 'ipv4') {
            $ipLong = ip2long($ip);
            $mask = long2ip(-1 << (32 - $cidr));
            $gateway = long2ip($ipLong + 1);
            $broadcast = long2ip($ipLong + pow(2, 32 - $cidr) - 1);
        } else {
            $network = inet_pton($ip);
            for ($index = 0; $index < 16; $index++) {
                $bits = max(0, min(8, $cidr - $index * 8));
                $network[$index] = chr(ord($network[$index]) & (0xFF << (8 - $bits)));
            }
            $mask = '/' . $cidr;
            $gateway = null;
            if ($cidr < 128) {
                // The masked network has a clear final host bit, so +1 stays in this subnet.
                $network[15] = chr(ord($network[15]) + 1);
                $gateway = inet_ntop($network);
            }
            $broadcast = null;
        }

        return ['name' => $state, 'ip_version' => $version, 'subnet_mask' => $mask,
            'gateway' => $gateway, 'broadcast_address' => $broadcast];
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
