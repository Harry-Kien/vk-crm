<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Pages;

use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use Filament\Resources\Pages\ViewRecord;

/** Chỉ đọc — không có EditAction/DeleteAction (nhật ký thư không sửa/xoá tay được). */
class ViewOutboundMessage extends ViewRecord
{
    protected static string $resource = OutboundMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
