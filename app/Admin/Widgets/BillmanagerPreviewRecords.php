<?php

namespace App\Admin\Widgets;

use App\Admin\Pages\BillmanagerPreview;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\UserResource;
use App\Services\BillmanagerMigration\PreviewSnapshot;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Throwable;

final class BillmanagerPreviewRecords extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'admin.widgets.billmanager-preview-records';

    protected int|string|array $columnSpan = 'full';

    #[Locked]
    public string $dataset;

    #[Locked]
    public string $search = '';

    #[Locked]
    public int $page = 1;

    public static function canView(): bool
    {
        return BillmanagerPreview::canAccess();
    }

    public function mount(): void
    {
        $search = request()->query('billmanager_search', '');
        $this->search = is_string($search) ? mb_substr(trim($search), 0, 100) : '';
        $this->page = filter_var(request()->query('billmanager_page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    }

    protected function getViewData(): array
    {
        abort_unless(static::canView(), 403);

        $resource = match ($this->dataset) {
            'users' => UserResource::class,
            'services' => ServiceResource::class,
            default => abort(404),
        };
        $url = $resource::getUrl(panel: 'admin');

        try {
            $records = PreviewSnapshot::open()->records($this->dataset, $this->search, 'billmanager_page', $this->page)
                ->withPath($url)->appends(['billmanager_search' => $this->search]);
        } catch (Throwable $error) {
            Log::warning('The BILLmanager migration preview could not load.', ['exception_type' => $error::class]);
            $records = null;
        }

        return [
            'columns' => PreviewSnapshot::COLUMNS[$this->dataset],
            'formUrl' => $url,
            'records' => $records,
            'search' => $this->search,
            'searchLabel' => 'Search copied '.strtolower(PreviewSnapshot::LABELS[$this->dataset]),
            'searchName' => 'billmanager_search',
        ];
    }
}
