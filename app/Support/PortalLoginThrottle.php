<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;
use Normalizer;

/**
 * Giới hạn tần suất đăng nhập cổng khách hàng — SPEC §10.3: "đăng nhập 5 lần / 15 phút theo
 * email và theo IP".
 *
 * # Vì sao lớp này tồn tại thay vì dùng thẳng thứ Filament có sẵn
 *
 * Đã đọc bản 5.8 đang cài. Bộ đăng nhập của Filament có BA bộ đếm. Hai cái đầu là hai cổng mà
 * SPEC §10.3 nói tới, và cả hai lệch theo cùng một kiểu — đúng số lần, sai cửa sổ, thiếu một
 * chiều khoá. Cái thứ ba đếm một thứ khác hẳn và được giữ nguyên:
 *
 *  1. `Filament\Auth\Pages\Login::authenticate()` gọi `$this->rateLimit(5)` của
 *     `danharrin/livewire-rate-limiting`. Tham số `$decaySeconds` mặc định là **60**, và khoá là
 *     `sha1(component|method|ip)` — tức **60 giây, chỉ theo IP, không theo email**. Hệ quả đo
 *     được: một kẻ dò mật khẩu thử 5 lần mỗi phút, mãi mãi, và không bao giờ chạm trần.
 *  2. `Filament\Auth\MultiFactor\MultiFactorChallenge::hitRateLimiter()` gọi
 *     `RateLimiter::hit($key)` **không truyền decay**, nên cũng là 60 giây; khoá là
 *     `sha1(guard|class|id)` — **chỉ theo tài khoản, không có chiều IP**.
 *  3. `EmailAuthentication::sendCode()` có một bộ đếm thứ tư, 2 lần / 60 giây, khoá
 *     `filament-email-authentication:{id}`. Nó đếm số lần MỘT MÃ ĐƯỢC GỬI ĐI, không phải số lần
 *     một mã được thử — nên nó KHÔNG phải cổng của SPEC §10.3 và được giữ nguyên. Nói cho đúng
 *     tên, vì gọi nó là "bộ đếm của nút Gửi lại mã" là sai và cái sai ấy làm người đọc tính nhầm
 *     số lượt còn lại: `PortalEmailAuthentication::beforeChallenge()` tiêu MỘT trong hai lượt ở
 *     **mỗi lần nhập đúng mật khẩu**, nên khách nhập đúng mật khẩu rồi bấm "Gửi lại mã" ngay là
 *     đã hết lượt, và lần bấm thứ hai trong cùng một phút không có thư nào đi. Đó là lý do
 *     `portal.login.code.resend_throttled` tồn tại.
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
 * # Hai CHIỀU của mỗi bộ đếm, và chiều nào được xoá khi vào được
 *
 * Mỗi bộ đếm có hai khoá độc lập: một **chiều tài khoản** (email vừa gõ ở bước mật khẩu, id tài
 * khoản ở bước mã) và một **chiều địa chỉ mạng**. Chạm trần ở bất kỳ chiều nào là bị chặn.
 *
 * Một lần đăng nhập thành công xoá **duy nhất chiều tài khoản** — xem `clearPasswordAccount()` và
 * `clearCodeAccount()`. Chiều địa chỉ mạng KHÔNG BAO GIỜ được xoá bởi một lần đăng nhập, vì nó
 * không thuộc về người vừa đăng nhập: sau một NAT của nhà mạng hay wifi văn phòng có hàng chục
 * người dùng chung đúng một địa chỉ, nên "tôi vào được" không phải là bằng chứng rằng bốn lần
 * hỏng trước đó cũng là của tôi. Xoá nó đi thì bất kỳ ai có một tài khoản dùng được đều mua được
 * một cửa sổ 5 lần mới cho mỗi email khác, không giới hạn số lần — tức chiều IP của SPEC §10.3
 * biến mất hoàn toàn.
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
     * Gấp chuỗi email theo đúng cách collation của cột gấp nó, TRƯỚC khi băm thành khoá.
     *
     * Lý do phải có hàm này, đo được chứ không phải phòng xa: `client_users.email` là
     * `utf8mb4_unicode_ci`, và `utf8mb4_unicode_ci` bỏ qua hoa/thường, **bỏ qua dấu**, bỏ qua
     * khoảng trắng cuối, coi `ｕ` nửa rộng bằng `u`, `ﬁ` bằng `fi`, `ß` bằng `ss`. Đo trên
     * MariaDB 11.8 đang chạy: `'user@example.test' = 'usér@example.test'` trả về 1, và
     * `'ｕser@example.test'` cũng vậy. Cả hai đều qua được luật `email` của Laravel, cả hai đều
     * **đăng nhập vào đúng hàng `user@…`** — nên nếu khoá bộ đếm chỉ `mb_strtolower(trim())` thì
     * mỗi biến thể có một bộ đếm rỗng riêng. Riêng dấu tiếng Việt đã cho vài chục biến thể cho
     * một địa chỉ, tức trần 5 lần / 15 phút của SPEC §10.3 gia hạn được vô hạn.
     *
     * Cách gấp, bốn bước, mỗi bước trả lời một thứ collation làm:
     *
     *  1. NFKC — gộp các dạng tương thích: `ｕ` → `u`, `ﬁ` → `fi`.
     *  2. `MB_CASE_FOLD` — gấp hoa/thường ĐẦY ĐỦ theo Unicode, nên `ß` → `ss` (thứ
     *     `mb_strtolower` không làm).
     *  3. NFD rồi xoá `\p{Mn}` — tách dấu ra khỏi chữ cái rồi bỏ dấu đi: `é` → `e`, `ậ` → `a`.
     *     `\p{Cf}` cũng bị xoá cùng lúc (ZWJ, ZWNJ, …) vì collation bỏ qua chúng.
     *  4. `trim()`.
     *
     * **Đây là một phép gấp, không phải một bản cài lại UCA**, và ranh giới của nó được nói ra:
     * nó KHÔNG khẳng định đúng với mọi chuỗi Unicode, nó khẳng định đúng với mọi chuỗi **qua
     * được luật `email`** — tức mọi chuỗi có thể chạm tới bộ đếm, vì `throwFailureValidationException()`
     * chỉ chạy sau khi form đã xác thực. Điều đó được đo chứ không được tin: test "folds the
     * throttle key exactly the way the shipped column collation folds" hỏi MariaDB thật từng cặp
     * một và đòi **MariaDB bằng ⇔ khoá bằng**, và nó từ chối bất kỳ biến thể nào không qua được
     * luật `email` để danh sách không lặng lẽ trôi sang chỗ không còn liên quan. `đ` KHÔNG bằng
     * `d`, `ø` KHÔNG bằng `o`, `æ` KHÔNG bằng `ae` — đo được, và phép gấp này giữ chúng khác
     * nhau đúng như vậy.
     *
     * Không có migration đổi collation ở đây: quyết định đó thuộc về M8 (và một unique index
     * dưới `_ci` vốn đã coi các biến thể có dấu là MỘT hàng, nên không có hai tài khoản để tách).
     *
     * Nếu chuỗi vào không phải UTF-8 hợp lệ thì `Normalizer::normalize()` trả `false` và
     * `preg_replace()` trả `null`; mỗi bước vì thế giữ nguyên kết quả bước trước thay vì biến
     * chuỗi thành rỗng — một chuỗi rỗng sẽ dồn mọi rác vào chung một bộ đếm.
     */
    public static function foldEmail(?string $email): string
    {
        $folded = (string) $email;

        $normalized = Normalizer::normalize($folded, Normalizer::FORM_KC);

        if (is_string($normalized)) {
            $folded = $normalized;
        }

        $folded = mb_convert_case($folded, MB_CASE_FOLD, 'UTF-8');

        $decomposed = Normalizer::normalize($folded, Normalizer::FORM_D);

        if (is_string($decomposed)) {
            $folded = $decomposed;
        }

        $stripped = preg_replace('/[\p{Mn}\p{Cf}]/u', '', $folded);

        if (is_string($stripped)) {
            $folded = $stripped;
        }

        return trim($folded);
    }

    /**
     * Chiều TÀI KHOẢN của bước nhập email + mật khẩu: khoá dựng từ email vừa gõ, đã gấp.
     *
     * Email rỗng thì không có chiều này — nếu vẫn băm chuỗi rỗng thì mọi lần gửi form thiếu email
     * trên khắp hệ thống sẽ dồn vào chung một bộ đếm và khoá lẫn nhau.
     */
    public static function passwordAccountKey(?string $email): ?string
    {
        $email = self::foldEmail($email);

        return $email === '' ? null : 'portal-login-email:'.sha1($email);
    }

    /** Chiều ĐỊA CHỈ MẠNG của bước nhập email + mật khẩu. */
    public static function passwordIpKey(): string
    {
        return 'portal-login-ip:'.sha1(self::ip());
    }

    /**
     * Cả hai chiều của bước nhập email + mật khẩu — dùng để KIỂM TRA và để ĐẬP.
     *
     * @return array<int, string>
     */
    public static function passwordKeys(?string $email): array
    {
        return array_values(array_filter([
            self::passwordAccountKey($email),
            self::passwordIpKey(),
        ]));
    }

    /** Chiều TÀI KHOẢN của bước nhập mã: tài khoản đã qua được cổng mật khẩu. */
    public static function codeAccountKey(Authenticatable $user): string
    {
        return 'portal-login-code-account:'.sha1($user::class.'|'.$user->getAuthIdentifier());
    }

    /** Chiều ĐỊA CHỈ MẠNG của bước nhập mã. */
    public static function codeIpKey(): string
    {
        return 'portal-login-code-ip:'.sha1(self::ip());
    }

    /**
     * Cả hai chiều của bước nhập mã.
     *
     * @return array<int, string>
     */
    public static function codeKeys(Authenticatable $user): array
    {
        return [
            self::codeAccountKey($user),
            self::codeIpKey(),
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
     * Xoá bộ đếm của bước mật khẩu sau một lần đăng nhập thành công — **chỉ chiều tài khoản**.
     *
     * Chiều địa chỉ mạng cố ý không có mặt ở đây, và lý do nằm ở docblock của lớp: một lần đăng
     * nhập thành công chỉ chứng minh một điều về MỘT tài khoản, không chứng minh gì về những lần
     * hỏng của những người khác sau cùng một đường truyền.
     */
    public static function clearPasswordAccount(?string $email): void
    {
        $key = self::passwordAccountKey($email);

        if ($key !== null) {
            RateLimiter::clear($key);
        }
    }

    /**
     * Xoá bộ đếm của bước mã sau một lần đăng nhập thành công — **chỉ chiều tài khoản**, cùng lý
     * do với `clearPasswordAccount()`.
     */
    public static function clearCodeAccount(Authenticatable $user): void
    {
        RateLimiter::clear(self::codeAccountKey($user));
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
