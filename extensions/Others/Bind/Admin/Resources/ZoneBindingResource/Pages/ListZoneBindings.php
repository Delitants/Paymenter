<?php

namespace Paymenter\Extensions\Others\Bind\Admin\Resources\ZoneBindingResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Others\Bind\Admin\Resources\ZoneBindingResource;

class ListZoneBindings extends ListRecords
{
    protected static string $resource = ZoneBindingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
