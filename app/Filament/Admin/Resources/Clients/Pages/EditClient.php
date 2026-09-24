<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Filament\Admin\Resources\Clients\ClientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * Chỉ `DeleteAction`. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction` và
     * `RestoreAction`, nhưng policy của model này không định nghĩa `restore` lẫn `forceDelete`,
     * và Laravel từ chối một ability không có phương thức tương ứng khi model đã có policy — nên
     * hai nút đó luôn bị từ chối. `HeaderActionsAreReachableTest` giữ luật này cho mọi trang.
     *
     * Task 2 (rà soát cuối): `ClientPolicy::delete()` giờ có thể từ chối kèm một thông điệp (khách
     * hàng còn vụ việc đang mở). Mặc định Filament, `CanBeHidden::isAuthorizedOrNotHiddenWhenUnauthorized()`
     * BỎ QUA thông điệp đó và ẩn hẳn nút khi không có `authorizationNotification()` hay
     * `authorizationTooltip()` — admin sẽ không hiểu vì sao nút biến mất. `authorizationNotification()`
     * giữ nút hiển thị (miễn `Response::deny()` có message) và đổi một lần bấm bị từ chối thành
     * một thông báo nêu đúng số vụ, thay vì xoá trót lọt hoặc một nút chết không lời giải thích.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorizationNotification(),
        ];
    }
}
