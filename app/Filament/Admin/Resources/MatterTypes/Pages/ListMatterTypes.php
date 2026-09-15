<?php

namespace App\Filament\Admin\Resources\MatterTypes\Pages;

use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMatterTypes extends ListRecords
{
    protected static string $resource = MatterTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
