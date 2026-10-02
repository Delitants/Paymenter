<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Admin\Actions\PaymentActions;
use App\Models\PaymentOperation;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentOperationsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentOperations';

    protected static ?string $title = 'Payment operation history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $actor && User::find($actor->id)?->hasPermission('admin.invoice_transactions.viewAny');
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Operation')->sortable(),
            TextColumn::make('kind')->label('Action')->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state))),
            TextColumn::make('state')->badge()->color(fn (string $state) => match ($state) {
                'succeeded' => 'success', 'failed' => 'danger', default => 'warning',
            }),
            TextColumn::make('result')->state(fn (PaymentOperation $record) => PaymentActions::resultLabel($record))->wrap(),
            TextColumn::make('amount'), TextColumn::make('currency_code')->label('Currency'),
            TextColumn::make('original_transaction_id')->label('Original payment'),
            TextColumn::make('gateway.name')->label('Gateway'),
            TextColumn::make('actor.name')->label('Administrator')->state(fn (PaymentOperation $record) => ($record->actor_snapshot['name'] ?? 'Administrator') . ' (#' . ($record->actor_snapshot['id'] ?? $record->actor_id) . ')'),
            TextColumn::make('execution_actor')->label('Executor')->state(function (PaymentOperation $record) {
                $start = collect($record->outcome_evidence ?? [])->firstWhere('outcome_code', 'execution_started');

                return $start ? ($start['actor_snapshot']['name'] ?? 'Administrator') . ' (#' . $start['actor_id'] . ')' : null;
            })->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('reason')->wrap(),
            TextColumn::make('reference')->state(fn (PaymentOperation $record) => PaymentActions::reference($record))->wrap(),
            TextColumn::make('provider_reference')->label('Provider result reference')->wrap(),
            TextColumn::make('effective_at')->label('Effective time (UTC)')->dateTime()->timezone('UTC'),
            TextColumn::make('created_at')->label('Recorded time (UTC)')->dateTime()->timezone('UTC'),
            TextColumn::make('request_key')->label('Request UUID')->toggleable(isToggledHiddenByDefault: true),
        ])->defaultSort('id', 'desc')->recordActions([PaymentActions::resume(), PaymentActions::reconcile()]);
    }
}
