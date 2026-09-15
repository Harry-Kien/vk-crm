<?php

namespace App\Filament\Admin\Resources\MatterTypes\Pages;

use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMatterType extends EditRecord
{
    protected static string $resource = MatterTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
