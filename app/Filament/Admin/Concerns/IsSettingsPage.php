<?php

namespace App\Filament\Admin\Concerns;

use App\Enums\Permission;
use App\Filament\Admin\Pages\Settings\SettingsHub;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Cổng và đường vào chung của các trang con của "Cài đặt" (làn st): "Cài đặt nghiệp vụ", "Vai trò &
 * quyền", "Bảo mật đăng nhập", "Thư điện tử".
 *
 *  - Chỉ người có `settings.manage` (chỉ quản trị viên, SPEC §5) — {@see self::canAccess()} hỏi
 *    `Gate::forUser()` tường minh.
 *  - {@see self::boot()} trả 404 cho người khác ở ĐẦU cả lần mount lẫn MỌI request cập nhật Livewire
 *    (hook `hydrateCanAuthorizeAccess()` của Filament trả 403, và middleware 404 của panel không phủ
 *    request cập nhật) — cùng ba chỗ hỏi với `OfficeProfilePage`; hành động thật của trang hỏi lại.
 *  - Không có mục riêng trên menu: cửa vào là thẻ của trang tổng {@see SettingsHub}, và đường dẫn
 *    "Cài đặt › …" ở đầu trang dẫn ngược về đó.
 */
trait IsSettingsPage
{
    public static function canAccess(): bool
    {
        return Gate::forUser(Auth::user())->allows(Permission::SettingsManage->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    /** @return array<int|string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            SettingsHub::getUrl() => __('settings.breadcrumb'),
            (string) $this->getTitle(),
        ];
    }
}
