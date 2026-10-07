<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\ManagesOwnPushDevices;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * M12 R8 — "Thông báo trên điện thoại" của app nội bộ (`/admin/thong-bao-dien-thoai`), mở từ user
 * menu. Toàn bộ hành vi ở {@see ManagesOwnPushDevices}; ở đây chỉ có ai được vào.
 *
 * MỌI nhân sự, mọi vai trò (kể cả kế toán): trang chỉ nói về máy của chính người đang xem, không về
 * khách hàng hay hồ sơ nào — nên không có quyền nghiệp vụ nào để hỏi. `instanceof User` là cổng
 * thật vì `canAccess()` mặc định của Filament trả `true` cho mọi người đã đăng nhập. Cổng 2FA bắt
 * buộc (`isRequired: true`) đã gắn vào route của MỌI trang panel này, gồm trang này.
 */
class PushDevices extends Page
{
    use ManagesOwnPushDevices;

    protected string $view = 'pwa.push-devices';

    protected static ?string $slug = 'thong-bao-dien-thoai';

    public static function canAccess(): bool
    {
        return Filament::auth()->user() instanceof User;
    }
}
