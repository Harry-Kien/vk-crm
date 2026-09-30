<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Pages;

use App\Filament\Admin\Resources\OutboundMessages\Actions\ResendOutboundMessageAction;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Không có EditAction/DeleteAction (nhật ký thư không sửa/xoá tay được). Chỉ một nút ở đầu trang:
 * "Gửi lại" ({@see ResendOutboundMessageAction}, M6 Task 10) — cùng nút với hàng của bảng, chỉ hiện
 * trên dòng `failed` gửi lại được và chỉ với admin.
 */
class ViewOutboundMessage extends ViewRecord
{
    protected static string $resource = OutboundMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ResendOutboundMessageAction::make(),
        ];
    }
}
