<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Resources\ServiceResource;
use App\Admin\Widgets\BillmanagerPreviewRecords;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListService extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            BillmanagerPreviewRecords::make(['dataset' => 'services']),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
