<?php

namespace App\Admin\Resources\IpPoolResource\RelationManagers;

use App\Admin\Resources\ServiceResource;
use App\Models\IpAddress;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class IpAddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'ipAddresses';

    public static string $name = 'IP Addresses';

    public static ?string $label = 'IP Addresses';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                TextColumn::make('hostname')
                    ->label('Hostname')
                    ->state(fn (IpAddress $record) => $record->hostname ?: '-')
                    ->placeholder('-'),

                TextColumn::make('assignedToUserEmail')
                    ->label('Assigned To User')
                    ->state(function (IpAddress $record): ?string {
                        if (!$record->assigned_to_id || $record->assigned_to_type !== 'App\\Models\\User') {
                            return '-';
                        }

                        $user = User::find($record->assigned_to_id);
                        return $user?->email ?? '-';
                    })
                    ->placeholder('-')
                    ->searchable(),

                BadgeColumn::make('status')
                    ->label('Status')
                    ->state(fn (IpAddress $record) => $record->is_assigned ? 'Assigned' : 'Free')
                    ->colors([
                        'success' => 'Free',
                        'warning' => 'Assigned',
                    ]),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'free' => 'Free',
                        'assigned' => 'Assigned',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if ($data['value'] === 'free') {
                            return $query->where('is_assigned', false);
                        }
                        if ($data['value'] === 'assigned') {
                            return $query->where('is_assigned', true);
                        }
                        return $query;
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add IP')
                    ->icon('ri-add-line'),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading(fn (IpAddress $record) => "Edit IP: {$record->ip_address}")
                    ->modalSubmitAction(fn ($action) => $action->label('Save'))
                    ->form([
                        TextInput::make('ip_address')
                            ->label('IP Address')
                            ->required()
                            ->maxLength(255)
                            ->readOnly(),

                        Toggle::make('is_assigned')
                            ->label('Is Assigned')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function (callable $set, $state) {
                                if (!$state) {
                                    $set('hostname', null);
                                    $set('assigned_to_id', null);
                                }
                            }),

                        Select::make('assigned_to_id')
                            ->label('Assigned To User')
                            ->options(function () {
                                return \App\Models\User::pluck('email', 'id');
                            })
                            ->placeholder('Select user')
                            ->searchable()
                            ->hidden(fn (callable $get) => !$get('is_assigned'))
                            ->columnSpanFull(),

                        TextInput::make('hostname')
                            ->label('Hostname')
                            ->placeholder('e.g., vm.example.com')
                            ->maxLength(255)
                            ->hidden(fn (callable $get) => !$get('is_assigned'))
                            ->dehydrateStateUsing(fn ($state, callable $get) => $get('is_assigned') ? $state : null)
                            ->columnSpanFull(),
                    ]),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('ip_address')
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
