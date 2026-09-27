<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Pages;

use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use Filament\Resources\Pages\ListRecords;

/** Chỉ liệt kê — không có nút "Tạo mới" (nhật ký thư không tạo tay được). */
class ListOutboundMessages extends ListRecords
{
    protected static string $resource = OutboundMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
