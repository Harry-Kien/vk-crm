<?php

namespace App\Support\Settings;

use App\Http\Middleware\RestrictAdminIpAllowlist;
use App\Models\SystemHealth;
use App\Models\User;
use App\Support\LoginThrottle;
use App\Support\Security\ContentSecurityPolicy;
use App\Support\Security\HttpsDefaults;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Trạng thái bảo mật đăng nhập ĐANG CHẠY, đọc-chỉ (làn st) — cho trang
 * `App\Filament\Admin\Pages\Settings\LoginSecurityPage` và thẻ "Bảo mật đăng nhập" của trang tổng.
 * Mỗi giá trị đọc từ CHÍNH nơi mã thi hành nó, không chép tay một con số:
 *
 *  - 2FA: bắt buộc cho mọi nhân sự (`AdminPanelProvider`, `isRequired: true` — không có công tắc), số
 *    nhân sự ĐANG HOẠT ĐỘNG đã/chưa cài (`User::hasAppAuthenticationSecret()`);
 *  - giới hạn IP của /admin: {@see RestrictAdminIpAllowlist::entries()} — chỉ ĐẾM số dải, không trả dải
 *    nào (một danh sách IP được phép là thứ không in ra màn hình);
 *  - khoá đăng nhập: {@see LoginThrottle::MAX_ATTEMPTS} lần trong {@see LoginThrottle::DECAY_SECONDS};
 *  - HTTPS bắt buộc / HSTS: {@see HttpsDefaults} trên đúng hai khoá mà `EnforceHttps` đọc;
 *  - CSP: {@see ContentSecurityPolicy::mode()};
 *  - quét virus: `vkcrm.clamav.enabled` (cùng khoá mà `AppServiceProvider` dùng để chọn `ClamAvScanner`
 *    hay `NullScanner`);
 *  - sao lưu ngoài máy chủ: `vkcrm.backup.rclone.remote` có đặt chưa, và
 *    `system_health.last_offsite_backup_at` (bản gần nhất ĐÃ XÁC MINH trên Google Drive).
 */
final class LoginSecurityStatus
{
    /** Số giây của HSTS khi `HSTS_MAX_AGE` để trống ngoài `local`/`testing` — cùng con số `EnforceHttps` truyền vào `HttpsDefaults::secondsFromRaw()`. */
    public const STRICT_HSTS_SECONDS = 31536000;

    /** @return Collection<int, User> nhân sự đang hoạt động, sắp theo tên */
    private static function activeStaff(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get();
    }

    /** @return array{total: int, done: int, missing: list<string>} `missing` = TÊN nhân sự chưa cài 2FA */
    public static function twoFactor(): array
    {
        $staff = self::activeStaff();
        $missing = $staff->reject(fn (User $user): bool => $user->hasAppAuthenticationSecret());

        return [
            'total' => $staff->count(),
            'done' => $staff->count() - $missing->count(),
            'missing' => $missing->pluck('name')->map(fn ($name): string => (string) $name)->values()->all(),
        ];
    }

    public static function ipAllowlistCount(): int
    {
        return count(RestrictAdminIpAllowlist::entries());
    }

    public static function lockoutAttempts(): int
    {
        return LoginThrottle::MAX_ATTEMPTS;
    }

    public static function lockoutMinutes(): int
    {
        return intdiv(LoginThrottle::DECAY_SECONDS, 60);
    }

    public static function forcesHttps(): bool
    {
        return HttpsDefaults::boolFromRaw(config('vkcrm.security.force_https'));
    }

    public static function hstsSeconds(): int
    {
        return HttpsDefaults::secondsFromRaw(config('vkcrm.security.hsts_max_age'), self::STRICT_HSTS_SECONDS);
    }

    /** `off`, `report` hay `enforce`. */
    public static function cspMode(): string
    {
        return ContentSecurityPolicy::mode();
    }

    public static function scansForViruses(): bool
    {
        return (bool) config('vkcrm.clamav.enabled');
    }

    public static function offsiteBackupConfigured(): bool
    {
        return filled(config('vkcrm.backup.rclone.remote'));
    }

    /** Bản sao lưu ngoài máy chủ gần nhất đã xác minh; `null` khi chưa từng có. Không tạo dòng `system_health`. */
    public static function lastOffsiteBackupAt(): ?Carbon
    {
        return SystemHealth::query()->where('singleton', 1)->first()?->last_offsite_backup_at;
    }
}
