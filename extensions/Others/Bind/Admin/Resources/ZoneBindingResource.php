<?php

namespace Paymenter\Extensions\Others\Bind\Admin\Resources;

use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Others\Bind\Models\ZoneBinding;
use Paymenter\Extensions\Others\Bind\ZoneManager;

class ZoneBindingResource extends Resource
{
    protected static ?string $model = ZoneBinding::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    public static string|\UnitEnum|null $navigationGroup = 'Services';

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('admin.bind.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canAccess() && (Auth::user()?->hasPermission('admin.bind.approve') ?? false);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('zone')->required()->maxLength(253)->helperText('Exact verified domain; bindings cannot be reassigned after creation.'), Select::make('service_id')->label('Verified DNS service')->required()->searchable()->getSearchResultsUsing(fn (string $search) => Service::with('user')->whereIn('status', ['active', 'pending'])->where(fn ($q) => $q->where('id', $search)->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%' . $search . '%')))->limit(50)->get()->mapWithKeys(fn ($s) => [$s->id => '#' . $s->id . ' — ' . $s->user?->email])->all())->getOptionLabelUsing(fn ($value) => Service::with('user')->find($value)?->user?->email)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('zone')->searchable(), TextColumn::make('service_id')->label('Service')->sortable(), TextColumn::make('service.user.email')->label('Owner'), IconColumn::make('enabled')->boolean(), TextColumn::make('approved_at')->dateTime(), TextColumn::make('id')->label('Binding reference')->copyable()])->recordActions([
            Action::make('approve')->label('Approve verified ownership')->visible(fn ($record) => !$record->approved_at && Auth::user()->hasPermission('admin.bind.approve'))->schema([Checkbox::make('verified')->label('I independently verified the exact domain, service and owner match')->accepted()->required()])->action(function (ZoneBinding $record, array $data) {
                abort_unless(Auth::user()->hasPermission('admin.bind.approve') && ($data['verified'] ?? false), 403);
                $record->forceFill(['approved_at' => now(), 'enabled' => false])->save();
            }),
            Action::make('enable')->label('Enable customer access')->visible(fn ($record) => $record->approved_at && !$record->enabled && Auth::user()->hasPermission('admin.bind.approve'))->requiresConfirmation()->modalDescription('Enable only after native helper and ownership verification and the approved activation stage. This does not release any service/account hold.')->action(function (ZoneBinding $record) {
                abort_unless(Auth::user()->hasPermission('admin.bind.approve') && $record->approved_at, 403);
                $record->forceFill(['enabled' => true])->save();
            }),
            Action::make('disable')->visible(fn ($record) => $record->enabled && Auth::user()->hasPermission('admin.bind.approve'))->requiresConfirmation()->action(function (ZoneBinding $record) {
                abort_unless(Auth::user()->hasPermission('admin.bind.approve'), 403);
                $record->forceFill(['enabled' => false])->save();
            }),
            Action::make('inspect')->visible(fn () => Auth::user()->hasPermission('admin.bind.inspect'))->action(function (ZoneBinding $record) {
                try {
                    $r = app(ZoneManager::class)->inspect(Auth::user(), $record);
                    Notification::make()->title('DNS zone verified')->body('Loaded serial ' . $r['data']['serial'])->success()->send();
                } catch (\Throwable) {
                    Notification::make()->title('DNS inspection could not be verified')->danger()->send();
                }
            }),
            Action::make('prepare')->label('Prepare approved zone')->visible(fn () => Auth::user()->hasPermission('admin.bind.prepare'))->requiresConfirmation()->modalDescription('Create only a pre-allowlisted absent zone. Existing zones will be refused. Both helpers and ownership must already be verified.')->action(function (ZoneBinding $record) {
                try {
                    $r = app(ZoneManager::class)->prepare(Auth::user(), $record);
                    Notification::make()->title('DNS preparation: ' . $r['state'])->body('Reference ' . $r['request_id'])->send();
                } catch (\Throwable) {
                    Notification::make()->title('DNS preparation unavailable; check permissions, holds and unresolved operations')->danger()->send();
                }
            }),
            Action::make('reconcile')->visible(fn () => Auth::user()->hasPermission('admin.bind.reconcile'))->action(function (ZoneBinding $record) {
                $op = $record->operations()->whereIn('state', ['prepared', 'uncertain', 'pending_replication'])->latest()->first();
                if (!$op) {
                    return;
                }try {
                    $r = app(ZoneManager::class)->reconcile(Auth::user(), $record, ['request_id' => $op->id]);
                    Notification::make()->title('DNS outcome: ' . $r['state'])->body('Reference ' . $op->id)->send();
                } catch (\Throwable) {
                    Notification::make()->title('DNS reconciliation remains unresolved')->danger()->send();
                }
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ZoneBindingResource\Pages\ListZoneBindings::route('/')];
    }
}
