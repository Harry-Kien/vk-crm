<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * User::$fillable không có cột vai trò (spatie/laravel-permission quản lý riêng) — nhân sự
     * mới tạo qua form này chưa có vai trò nào cho tới khi gọi hàm này. Bình luận sẵn trên model
     * ghi rõ: "Action sửa nhân sự (M3) phải gọi lại hàm này" khi chức danh được đặt/đổi.
     */
    protected function afterCreate(): void
    {
        $this->record->assignRoleFromPosition();
    }
}
