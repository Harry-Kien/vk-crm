<?php

namespace App\Filament\Portal\Pages;

use App\Filament\Concerns\ManagesOwnPushDevices;
use App\Models\ClientUser;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * M12 R8 — "Thông báo trên điện thoại" của cổng khách (`/portal/thong-bao-dien-thoai`), mở từ user
 * menu. Toàn bộ hành vi ở {@see ManagesOwnPushDevices}; ở đây chỉ có ai được vào.
 *
 * `instanceof ClientUser` là cổng thật: `canAccess()` mặc định của Filament trả `true` cho mọi
 * người đã đăng nhập, và hai panel dùng chung cookie phiên.
 */
class PushDevices extends Page
{
    use ManagesOwnPushDevices;

    protected string $view = 'pwa.push-devices';

    /** Đường dẫn tiếng Việt không dấu, cùng lối `ho-so`, `yeu-cau`. */
    protected static ?string $slug = 'thong-bao-dien-thoai';

    public static function canAccess(): bool
    {
        return Filament::auth()->user() instanceof ClientUser;
    }
}
