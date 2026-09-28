<?php

namespace App\Filament\Admin\Auth;

use App\Actions\User\ResetStaffTwoFactor;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\Actions\RegenerateAppAuthenticationRecoveryCodesAction;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;

/**
 * Bộ 2FA ứng dụng (TOTP) của panel `admin` (R2, kế hoạch M8 Task 2, §10 mục 7). Đăng ký ở
 * `AdminPanelProvider::panel()` — `->multiFactorAuthentication([self::make()->recoverable()],
 * isRequired: true)`.
 *
 * # Vì sao lớp này tồn tại: bỏ `DisableAppAuthenticationAction`
 *
 * R2: "'Không có tuỳ chọn tắt' nghĩa là: không màn hình nào, không hành động nào, không cột nào
 * tắt được." Bản gốc của Filament (`AppAuthentication::getActions()`) luôn thêm
 * `DisableAppAuthenticationAction` khi 2FA đang bật — một nút "Tắt xác thực ứng dụng" sống ngay
 * trên trang hồ sơ cá nhân (`App\Filament\Admin\Pages\Auth\EditProfile`, panel này đăng ký qua
 * `->profile()`). Chỉ ghi đè `getActions()` (không đụng `getManagementSchemaComponents()` của lớp
 * cha) là đủ: `getManagementSchemaComponents()` gọi `$this->getActions()` bằng dispatch ảo của
 * PHP, nên một `getActions()` bị ghi đè ở đây tự động được dùng ở đó — không cần chép lại phần
 * dựng `Actions::make(...)->label(...)->afterLabel(...)` của lớp cha.
 *
 * Vẫn giữ `SetUpAppAuthenticationAction` (cài lần đầu — không phải "tắt") và
 * `RegenerateAppAuthenticationRecoveryCodesAction` (đổi mã khôi phục — vẫn còn 2FA, không phải
 * đường tắt). "Mất điện thoại không phải là tắt 2FA": người thật sự cần gỡ 2FA (mất máy, không
 * còn mã khôi phục) đi qua {@see ResetStaffTwoFactor} — một admin KHÁC bấm, có
 * audit, xoá phiên và remember_token — không phải tự tắt trên chính hồ sơ của mình.
 *
 * `tests/Feature/Filament/StaffTwoFactorEscapeRoutesTest.php` quét: (a) trang hồ sơ không có nút
 * này; (b) ép gọi thẳng action `disableAppAuthentication` qua Livewire bị từ chối (action không
 * tồn tại trong schema); (c) grep `app/` — không lời gọi `saveAppAuthenticationSecret(null)` nào
 * ngoài `ResetStaffTwoFactor`.
 */
class StaffAppAuthentication extends AppAuthentication
{
    /** @return array<Action> */
    public function getActions(): array
    {
        $user = Filament::auth()->user();

        return [
            SetUpAppAuthenticationAction::make($this)
                ->hidden(fn (): bool => $this->isEnabled($user)),
            RegenerateAppAuthenticationRecoveryCodesAction::make($this)
                ->visible(fn (): bool => $this->isEnabled($user) && $this->isRecoverable() && $this->canRegenerateRecoveryCodes()),
            // `DisableAppAuthenticationAction::make($this)` CỐ Ý không có mặt — xem docblock lớp.
        ];
    }
}
