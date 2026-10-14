<?php

namespace App\Filament\Admin\Auth;

use App\Actions\User\ResetStaffTwoFactor;
use App\Models\User;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\Actions\RegenerateAppAuthenticationRecoveryCodesAction;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use SensitiveParameter;

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
 * Hai test giữ lời hứa đó: `tests/Feature/Filament/StaffEditProfileTest.php` — (a) trang hồ sơ
 * không có nút này, (b) ép gọi thẳng action `disableAppAuthentication` qua Livewire bị từ chối
 * (action không tồn tại trong schema); và `tests/Feature/Filament/StaffTwoFactorEscapeRoutesTest.php`
 * — (c) quét `app/`: không lời gọi `saveAppAuthenticationSecret(null)` nào ngoài
 * `ResetStaffTwoFactor`.
 */
class StaffAppAuthentication extends AppAuthentication
{
    /**
     * Làn fb (mục B — "tự cài 2FA không để lại dòng nhật ký"): ghi `staff_two_factor_enabled` mỗi
     * lần một nhân sự tự lưu một secret mới (cài lần đầu, hoặc cài lại sau "Đặt lại 2FA"). Ghi ở
     * ĐÂY — chỗ duy nhất Filament lưu secret của nút "Cài xác thực ứng dụng" — chứ không ở
     * `->after()` của nút: thân nút có một nhánh `return` sớm (secret mã hoá cho người khác) mà
     * `after()` vẫn chạy. Lần xoá secret (`null`) chỉ đi qua `ResetStaffTwoFactor`, thứ tự ghi
     * `staff_two_factor_reset` của nó và không gọi tới hàm này.
     */
    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        parent::saveSecret($user, $secret);

        if ($secret !== null && $user instanceof User) {
            Audit::record('staff_two_factor_enabled', $user, [], $user);
        }
    }

    /**
     * Ghi `staff_recovery_codes_regenerated` khi một nhân sự ĐỔI bộ mã khôi phục đang có (nút "Tạo
     * lại mã khôi phục"). Lần lưu mã đầu tiên đi cùng lần cài 2FA (trước đó chưa có mã nào) nên
     * không sinh dòng này — dòng `staff_two_factor_enabled` đã nói việc đó.
     */
    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[SensitiveParameter] ?array $codes): void
    {
        $hadCodes = $user->getAppAuthenticationRecoveryCodes() !== null;

        parent::saveRecoveryCodes($user, $codes);

        if ($hadCodes && is_array($codes) && $user instanceof User) {
            Audit::record('staff_recovery_codes_regenerated', $user, [], $user);
        }
    }

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
