<?php

namespace App\Filament\Admin\Resources\ClientUsers\Pages;

use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClientUser extends CreateRecord
{
    protected static string $resource = ClientUserResource::class;
}
