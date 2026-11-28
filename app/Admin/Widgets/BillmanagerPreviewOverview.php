<?php

namespace App\Admin\Widgets;

use App\Admin\Pages\BillmanagerPreview;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\UserResource;
use App\Services\BillmanagerMigration\PreviewSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Throwable;

final class BillmanagerPreviewOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return BillmanagerPreview::canAccess();
    }

    protected function getHeading(): ?string
    {
        return 'BILLmanager migration';
    }

    protected function getDescription(): ?string
    {
        return 'Read-only snapshot of BILLmanager. Billing and incoming support mail continue there.';
    }

    protected function getStats(): array
    {
        abort_unless(static::canView(), 403);
        try {
            $counts = PreviewSnapshot::open()->counts();
        } catch (Throwable) {
            return [Stat::make('BILLmanager copy', 'Unavailable')->url(BillmanagerPreview::getUrl())];
        }
        $stats = [];
        foreach ($counts as $dataset => $count) {
            [$label, $url] = match ($dataset) {
                'users' => ['Copied users', UserResource::getUrl(panel: 'admin')],
                'services' => ['Copied services', ServiceResource::getUrl(panel: 'admin')],
                default => [PreviewSnapshot::LABELS[$dataset], BillmanagerPreview::getUrl(['dataset' => $dataset], panel: 'admin')],
            };
            $stats[] = Stat::make($label, number_format($count))->url($url);
        }

        return $stats;
    }
}
