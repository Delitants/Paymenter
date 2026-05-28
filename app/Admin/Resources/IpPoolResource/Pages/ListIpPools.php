<?php

namespace App\Admin\Resources\IpPoolResource\Pages;

use App\Admin\Resources\IpPoolResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIpPools extends ListRecords
{
    protected static string $resource = IpPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New IP Pool'),
        ];
    }
}
