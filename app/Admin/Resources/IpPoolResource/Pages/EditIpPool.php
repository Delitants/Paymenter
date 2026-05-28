<?php

namespace App\Admin\Resources\IpPoolResource\Pages;

use App\Admin\Resources\IpPoolResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIpPool extends EditRecord
{
    protected static string $resource = IpPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return $this->getResource()::getRelationManagers();
    }
}
