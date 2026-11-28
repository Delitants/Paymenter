<?php

namespace App\Admin\Widgets;

use App\Admin\Pages\BillmanagerPreview;
use App\Services\BillmanagerMigration\PreviewSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Throwable;

final class BillmanagerPreviewOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

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
        return 'Read-only copy. BILLmanager continues billing.';
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
            $stats[] = Stat::make(PreviewSnapshot::LABELS[$dataset], number_format($count))
                ->url(BillmanagerPreview::getUrl(['dataset' => $dataset]));
        }

        return $stats;
    }
}
