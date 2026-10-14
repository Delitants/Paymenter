<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Resources\ServiceResource;
use App\Services\SavedServiceCredentials;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class ViewCredentials extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServiceResource::class;

    protected static ?string $title = 'VM credentials';

    protected string $view = 'admin.resources.service-resource.pages.view-credentials';

    public static function canAccess(array $parameters = []): bool
    {
        // Read fresh permissions on every request, including Livewire hydration.
        $actor = auth()->user()?->fresh();

        return $actor !== null
            && $actor->hasPermission('admin.services.view')
            && $actor->hasPermission('admin.services.update');
    }

    public function mount(int|string $record): void
    {
        abort_unless(static::canAccess(), 403);
        $this->record = $this->resolveRecord($record);
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'service' => $this->getRecord(),
            'credentials' => app(SavedServiceCredentials::class)->read($this->getRecord()),
        ];
    }
}
