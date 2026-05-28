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
                    ->state(function (IpAddress $record): ?string {
                        if (!$record->assigned_to_id || !$record->assigned_to_type) {
                            return null;
                        }

                        // Look up hostname from settings table
                        $hostname = DB::table('settings')
                            ->where('settingable_type', 'App\\Models\\Service')
                            ->where('settingable_id', $record->assigned_to_id)
                            ->where('key', 'hostname')
                            ->value('value');

                        return $hostname ?: null;
                    })
                    ->placeholder('-'),

                TextColumn::make('assignedTo.name')
                    ->label('Assigned To')
                    ->state(function (IpAddress $record): ?string {
                        if (!$record->assigned_to_id || !$record->assigned_to_type) {
                            return null;
                        }

                        $model = $record->assigned_to_type;
                        if (class_exists($model)) {
                            $related = $model::find($record->assigned_to_id);
                            return $related?->name ?? $related?->email ?? null;
                        }

                        return null;
                    })
                    ->placeholder('Free')
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
                EditAction::make(),
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
