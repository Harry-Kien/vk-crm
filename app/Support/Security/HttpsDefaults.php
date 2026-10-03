<?php

namespace App\Support\Security;

/**
 * Thành ngữ "để trống là chặt, viết ra mới nới" dùng chung cho `FORCE_HTTPS` và `HSTS_MAX_AGE`
 * (SPEC §10 mục 1, kế hoạch M8 Task 1) — CÙNG công thức với
 * {@see ContentSecurityPolicy::mode()}: để trống là chặt (bật/hạn dài) ở
 * mọi môi trường TRỪ `local`/`testing`; một giá trị viết ra (kể cả rỗng có chủ đích ở hai môi
 * trường đó) được tôn trọng nguyên văn.
 *
 * `SESSION_SECURE_COOKIE` dùng CHUNG thành ngữ này nhưng KHÔNG gọi lớp này từ
 * `config/session.php`: gọi `app()->environment()` bên trong một tệp cấu hình ném
 * `BindingResolutionException` — `LoadConfiguration::bootstrap()` nạp `config/session.php`
 * TRƯỚC dòng `$app->detectEnvironment(...)`, nên container chưa có binding `'env'` ở thời điểm
 * đó (xem docblock dài ở `bootstrap/app.php` cho một bẫy anh em: `env()`/`config()` rỗng trong
 * đúng callback đó). Nơi AN TOÀN DUY NHẤT để áp thành ngữ này cho `session.secure` là
 * `AppServiceProvider::boot()` — chạy SAU khi môi trường đã biết, và chạy TRƯỚC middleware
 * `StartSession` đọc `config('session.secure')` ở request đầu tiên — xem đó để biết vì sao khoá
 * này được ghi đè ở đó chứ không ở đây.
 *
 * Chỉ được gọi các hàm dưới đây SAU khi ứng dụng đã bootstrap xong (middleware, provider `boot()`,
 * lệnh artisan) — không gọi từ một tệp `config/*.php`.
 */
final class HttpsDefaults
{
    /** Cùng danh sách với {@see ContentSecurityPolicy::REPORT_BY_DEFAULT_IN}. */
    public const RELAXED_ENVIRONMENTS = ['local', 'testing'];

    /**
     * `$raw` là giá trị THÔ đọc từ `env()`/`config()` — `null` (biến vắng mặt) hoặc `''` (dòng để
     * trống trong `.env`) đều được coi là "để trống". Một chuỗi/bool khác được ép kiểu bằng
     * `filter_var(..., FILTER_VALIDATE_BOOLEAN)` (chấp nhận `1`/`0`, `yes`/`no`, `on`/`off`,
     * không riêng `true`/`false`).
     */
    public static function boolFromRaw(mixed $raw): bool
    {
        if ($raw === null || $raw === '') {
            return ! app()->environment(self::RELAXED_ENVIRONMENTS);
        }

        return is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Để trống: `$strictSeconds` ở mọi môi trường trừ `local`/`testing` (0 — tắt HSTS ở đó, vì
     * bật HSTS trên máy dev chạy http tự khoá trình duyệt của người dev vào https một thời gian
     * dài). Một giá trị viết ra được ép `int`, không cho âm.
     */
    public static function secondsFromRaw(mixed $raw, int $strictSeconds): int
    {
        if ($raw === null || $raw === '') {
            return app()->environment(self::RELAXED_ENVIRONMENTS) ? 0 : $strictSeconds;
        }

        return max(0, (int) $raw);
    }
}
