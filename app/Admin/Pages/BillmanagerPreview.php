<?php

namespace App\Admin\Pages;

use App\Models\User;
use App\Services\BillmanagerMigration\PreviewSnapshot;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BillmanagerPreview extends Page
{
    protected static ?string $title = 'BILLmanager migration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-archive-line';

    protected static ?int $navigationSort = -10;

    protected string $view = 'admin.billmanager-preview';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasPermission('*') && PreviewSnapshot::available();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $dataset = request()->query('dataset', 'users');
        $search = request()->query('q', '');
        abort_unless(is_string($dataset) && isset(PreviewSnapshot::LABELS[$dataset]) && is_string($search), 404);
        $search = mb_substr(trim($search), 0, 100);

        try {
            $records = PreviewSnapshot::open()->records($dataset, $search);
        } catch (Throwable $error) {
            Log::warning('The BILLmanager migration preview could not load.', ['exception_type' => $error::class]);
            $records = null;
        }

        return ['dataset' => $dataset, 'datasets' => PreviewSnapshot::LABELS, 'previewUrl' => static::getUrl(),
            'columns' => PreviewSnapshot::COLUMNS[$dataset], 'records' => $records, 'search' => $search];
    }
}
