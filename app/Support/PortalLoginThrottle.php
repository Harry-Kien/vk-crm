<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Giới hạn tần suất đăng nhập cổng khách hàng — SPEC §10.3: "đăng nhập 5 lần / 15 phút theo
 * email và theo IP".
 *
 * # Vì sao lớp này tồn tại thay vì dùng thẳng thứ Filament có sẵn
 *
 * Đã đọc bản 5.8 đang cài. Bộ đăng nhập của Filament có BA bộ đếm và cả ba đều lệch SPEC theo
 * cùng một kiểu — đúng số lần, sai cửa sổ, thiếu một chiều khoá:
 *
 *  1. `Filament\Auth\Pages\Login::authenticate()` gọi `$this->rateLimit(5)` của
 *     `danharrin/livewire-rate-limiting`. Tham số `$decaySeconds` mặc định là **60**, và khoá là
 *     `sha1(component|method|ip)` — tức **60 giây, chỉ theo IP, không theo email**. Hệ quả đo
 *     được: một kẻ dò mật khẩu thử 5 lần mỗi phút, mãi mãi, và không bao giờ chạm trần.
 *  2. `Filament\Auth\MultiFactor\MultiFactorChallenge::hitRateLimiter()` gọi
 *     `RateLimiter::hit($key)` **không truyền decay**, nên cũng là 60 giây; khoá là
 *     `sha1(guard|class|id)` — **chỉ theo tài khoản, không có chiều IP**.
 *  3. `EmailAuthentication::sendCode()` có bộ đếm riêng (2 lần/60 giây) cho nút "Gửi lại mã".
 *     Cái này KHÔNG phải cổng của SPEC §10.3 (nó chặn việc xin mã, không chặn việc thử mã) nên
 *     giữ nguyên; màn hình chỉ cần nói ra bằng tiếng Việt khi nó chặn.
 *
 * Lớp này lấp (1) và (2). Nó không thay bộ đếm của Filament bằng một bộ đếm "tốt hơn" một cách
 * chung chung — nó cài đúng hai con số SPEC viết ra, ở đúng hai chỗ SPEC nói tới.
 *
 * # Hai bộ đếm tách rời, cố ý
 *
 * Bước nhập mật khẩu và bước nhập mã có bộ đếm riêng, mỗi bộ 5 lần / 15 phút. Gộp chung sẽ làm
 * một người gõ nhầm mật khẩu hai lần rồi gõ nhầm mã ba lần bị khoá, trong khi không có lần nào
 * là một lần dò. Tách ra cũng đúng nghĩa hơn: hai bước hỏi hai bí mật khác nhau.
 *
 * # Cái giá của chiều IP, ghi ra vì nó có thật
 *
 * Khoá theo IP nghĩa là nhiều người sau cùng một đường truyền (văn phòng, wifi quán, mạng di
 * động dùng NAT) chia nhau một bộ đếm: một người gõ sai 5 lần thì người ngồi cạnh cũng phải đợi
 * 15 phút. SPEC §10.3 đòi "cả tài khoản lẫn IP" nên đây là điều đã được chọn, không phải điều bị
 * bỏ sót — và vì vậy thông điệp khoá tạm (`portal.login.throttled`) BẮT BUỘC kèm số điện thoại
 * văn phòng: người bị khoá oan cần một con đường không đi qua tài khoản.
 *
 * # Ranh giới với SPEC §10.10
 *
 * Khoá theo email được tính từ **email vừa gõ vào ô**, không phải từ một tài khoản tra ra được
 * trong cơ sở dữ liệu. Nhờ vậy một email không tồn tại và một email có thật đi qua đúng cùng một
 * đường, cùng một số lần, cùng một câu trả lời — không có gì trong phản hồi nói cho người gõ
 * biết tài khoản có tồn tại hay không.
 */
final class PortalLoginThrottle
{
    /** SPEC §10.3: 5 lần. */
    public const MAX_ATTEMPTS = 5;

    /** SPEC §10.3: 15 phút. */
    public const DECAY_SECONDS = 900;

    /**
     * Khoá của bước nhập email + mật khẩu: một theo email vừa gõ, một theo địa chỉ mạng.
     *
     * Email rỗng thì chỉ còn chiều IP — nếu vẫn băm chuỗi rỗng thì mọi lần gửi form thiếu email
     * trên khắp hệ thống sẽ dồn vào chung một bộ đếm và khoá lẫn nhau.
     *
     * @return array<int, string>
     */
    public static function passwordKeys(?string $email): array
    {
        $email = mb_strtolower(trim((string) $email));

        return array_values(array_filter([
            $email === '' ? null : 'portal-login-email:'.sha1($email),
            'portal-login-ip:'.sha1(self::ip()),
        ]));
    }

    /**
     * Khoá của bước nhập mã: một theo tài khoản đã qua được mật khẩu, một theo địa chỉ mạng.
     *
     * @return array<int, string>
     */
    public static function codeKeys(Authenticatable $user): array
    {
        return [
            'portal-login-code-account:'.sha1($user::class.'|'.$user->getAuthIdentifier()),
            'portal-login-code-ip:'.sha1(self::ip()),
        ];
    }

    /**
     * Đủ để khoá nếu **bất kỳ** chiều nào đã chạm trần — đó chính là nghĩa của "theo email và
     * theo IP": hai điều kiện độc lập, chạm một cái là chặn.
     *
     * @param  array<int, string>  $keys
     */
    public static function tooManyAttempts(array $keys): bool
    {
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $keys
     */
    public static function hit(array $keys): void
    {
        foreach ($keys as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }

    /**
     * @param  array<int, string>  $keys
     */
    public static function clear(array $keys): void
    {
        foreach ($keys as $key) {
            RateLimiter::clear($key);
        }
    }

    /**
     * Số giây còn phải đợi: lấy theo chiều bị khoá lâu nhất, để câu thông báo không hứa một thời
     * điểm mà lần thử kế tiếp vẫn còn bị chặn.
     *
     * @param  array<int, string>  $keys
     */
    public static function availableInSeconds(array $keys): int
    {
        $seconds = 0;

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        return $seconds;
    }

    /**
     * Làm tròn LÊN, và tối thiểu một phút: "đợi 0 phút" là một câu vô nghĩa với người đang đọc.
     *
     * @param  array<int, string>  $keys
     */
    public static function availableInMinutes(array $keys): int
    {
        return max(1, (int) ceil(self::availableInSeconds($keys) / 60));
    }

    private static function ip(): string
    {
        return (string) (request()->ip() ?? 'unknown');
    }
}
