<?php

namespace App\Filament\Admin\Resources\IntakeRequests\Pages;

use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIntakeRequests extends ListRecords
{
    protected static string $resource = IntakeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
