<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Filament\Admin\Resources\Matters\MatterResource;
use Filament\Resources\Pages\ListRecords;

class ListMatters extends ListRecords
{
    protected static string $resource = MatterResource::class;

    // Không có CreateAction: mở vụ việc mới đi qua Action `OpenMatter` (Task 5), không phải
    // form CRUD mặc định.
}
