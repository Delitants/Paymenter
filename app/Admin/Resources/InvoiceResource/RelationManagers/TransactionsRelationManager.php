<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Admin\Actions\PaymentActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_id')
            ->columns([
                TextColumn::make('gateway.name')->label('Gateway'),
                TextColumn::make('transaction_id'),
                TextColumn::make('status')->label('Original status')->badge()->formatStateUsing(fn ($state) => ucfirst($state->value)),
                TextColumn::make('formattedAmount')->label('Amount'),
                TextColumn::make('formattedFee')->label('Fee'),
                ...PaymentActions::columns(),
                TextColumn::make('created_at'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                PaymentActions::receipt(fn () => $this->getOwnerRecord()),
                PaymentActions::capture(fn () => $this->getOwnerRecord()),
            ])
            ->recordActions([
                ActionGroup::make(PaymentActions::transactionActions()),
                DeleteAction::make()->visible(fn ($record) => !$record->isManaged()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ]);
    }
}
