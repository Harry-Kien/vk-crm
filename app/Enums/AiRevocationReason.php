<?php

namespace App\Enums;

use App\Actions\Mcp\RevokeAiConnections;

/**
 * Vì sao mọi kết nối AI của một nhân sự bị thu hồi (M11 R8, {@see RevokeAiConnections}). Giá trị đi
 * vào thuộc tính `reason` của dòng audit `ai_connections_revoked` (và `ai_access_changed` khi lần thu
 * hồi cũng hạ chế độ về `off`).
 */
enum AiRevocationReason: string
{
    /** Quản trị hạ `ai_access` về `off` (`SetUserAiAccess`). */
    case AiAccessOff = 'ai_access_off';

    /** Tài khoản bị vô hiệu hoá (`is_active` true → false, trang sửa nhân sự). */
    case Deactivated = 'deactivated';

    /** Đổi chức danh hay vai trò spatie (trang sửa nhân sự) — phán quyết controller Task 6. */
    case RoleChanged = 'role_changed';

    /** Quản trị đặt mật khẩu mới, hoặc nhân sự tự đổi ở trang hồ sơ. */
    case PasswordChanged = 'password_changed';

    /** "Đặt lại 2FA" (nút trên trang sửa nhân sự, lệnh `vkcrm:reset-2fa`). */
    case TwoFactorReset = 'two_factor_reset';

    /** Xoá (mềm) tài khoản (`DeleteStaffMember`). */
    case Deleted = 'deleted';

    public function label(): string
    {
        return __('enums.ai_revocation_reason.'.$this->value);
    }

    /**
     * Lần thu hồi này có hạ `users.ai_access` về `off` không — tức quản trị phải bật lại đích danh.
     *
     *  - Có: tắt AI, vô hiệu hoá, đổi vai, xoá. Người đó không còn là người quản trị đã chọn lúc bật:
     *    đổi vai làm đổi quyền xem (một nhân sự thành kế toán thì mất `matter.view`), còn một tài
     *    khoản được kích hoạt lại hay khôi phục sau khi xoá không được lặng lẽ mang theo quyền AI cũ.
     *  - Không: đổi mật khẩu và đặt lại 2FA. Đó là chuyện thông tin đăng nhập có thể đã lộ, không
     *    phải chuyện người đó được dùng AI hay không — họ kết nối lại là đủ.
     */
    public function turnsAccessOff(): bool
    {
        return match ($this) {
            self::AiAccessOff, self::Deactivated, self::RoleChanged, self::Deleted => true,
            self::PasswordChanged, self::TwoFactorReset => false,
        };
    }

    /**
     * Lý do thu hồi cho MỘT lần lưu trang sửa nhân sự, hoặc `null` khi lần lưu đó không đụng tới
     * thứ gì R8 đòi thu hồi (ví dụ chỉ sửa tên). Một lần lưu đổi nhiều thứ thì ghi lý do nặng nhất:
     * vô hiệu hoá, rồi đổi vai (hai lý do này còn hạ `ai_access`), rồi đổi mật khẩu.
     */
    public static function forStaffUpdate(bool $deactivated, bool $roleChanged, bool $passwordChanged): ?self
    {
        return match (true) {
            $deactivated => self::Deactivated,
            $roleChanged => self::RoleChanged,
            $passwordChanged => self::PasswordChanged,
            default => null,
        };
    }
}
