<?php

namespace App\Admin\Resources;

use App\Admin\Resources\BillmanagerRecordResource\Pages\ListBillmanagerRecords;
use App\Models\BillmanagerAttachment;
use App\Models\BillmanagerRecord;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class BillmanagerRecordResource extends Resource
{
    protected static ?string $model = BillmanagerRecord::class;

    protected static ?string $modelLabel = 'BILLmanager archive';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-archive-line';

    public static string|\UnitEnum|null $navigationGroup = 'Support';

    public static function canAccess(): bool
    {
        return Auth::user() && count(BillmanagerRecord::tablesVisibleTo(Auth::user())) > 0;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return Auth::user() && in_array($record->source_table, BillmanagerRecord::tablesVisibleTo(Auth::user()), true);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('source_table', Auth::user() ? BillmanagerRecord::tablesVisibleTo(Auth::user()) : []);
    }

    public static function table(Table $table): Table
    {
        $names = Auth::user() ? BillmanagerRecord::tablesVisibleTo(Auth::user()) : [];

        return $table->columns([
            TextColumn::make('source_table')->label('Record type')->sortable(),
            TextColumn::make('source_id')->label('Original ID')->searchable(),
            TextColumn::make('source_account_id')->label('Original account')->searchable()->sortable(),
            TextColumn::make('import_id')->label('Snapshot')->sortable(),
        ])->filters([
            SelectFilter::make('source_table')->label('Record type')->options(array_combine($names, $names)),
        ])->recordActions([
            ViewAction::make(),
            Action::make('download')->label('Download attachment')
                ->visible(fn (BillmanagerRecord $record) => $record->source_table === 'ticket_attachments' && Auth::user()->hasPermission('admin.tickets.view'))
                ->url(function (BillmanagerRecord $record) {
                    $attachment = BillmanagerAttachment::where(['import_id' => $record->import_id, 'source_id' => $record->source_id])->first();

                    return $attachment ? route('billmanager.attachments.show', $attachment) : null;
                }),
        ])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextEntry::make('source_table')->label('Record type'),
            TextEntry::make('source_id')->label('Original ID'),
            TextEntry::make('source_account_id')->label('Original account'),
            TextEntry::make('original_record')->label('Original record')->state(
                fn (BillmanagerRecord $record) => json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            )->extraAttributes(['class' => 'whitespace-pre-wrap break-words']),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBillmanagerRecords::route('/')];
    }
}
