<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Chỉ `DeleteAction`. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction` và
     * `RestoreAction`, nhưng policy của model này không định nghĩa `restore` lẫn `forceDelete`,
     * và Laravel từ chối một ability không có phương thức tương ứng khi model đã có policy — nên
     * hai nút đó luôn bị từ chối. `HeaderActionsAreReachableTest` giữ luật này cho mọi trang.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** Đổi chức danh phải đồng bộ lại vai trò ngay (xem ghi chú ở User::assignRoleFromPosition()). */
    protected function afterSave(): void
    {
        $this->record->assignRoleFromPosition();
    }
}
