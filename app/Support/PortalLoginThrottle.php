<?php

namespace App\Support;

use App\Models\ClientUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;

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
 * Mỗi bộ đếm có hai khoá độc lập: một **chiều tài khoản** (khoá chính của hàng tra được từ email
 * vừa gõ ở bước mật khẩu, id tài khoản ở bước mã) và một **chiều địa chỉ mạng**. Chạm trần ở bất
 * kỳ chiều nào là bị chặn.
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
 * Chiều tài khoản của bước mật khẩu khoá theo **hàng tra được**, không theo chuỗi vừa gõ (lý do
 * ở `passwordAccountKey()`), nên một email có thật và một email bịa ra đi vào hai LOẠI khoá khác
 * nhau. Điều đó không được phép nhìn thấy từ bên ngoài, và không nhìn thấy được: cả hai vẫn bị
 * khoá sau đúng 5 lần, nhận đúng một câu `portal.login.throttled`, với đúng một số phút — nhân
 * chứng là test "locks a real account and an address with no account behind exactly the same
 * wall", thứ so SÁNH CẢ HAI PHẢN HỒI với nhau chứ không chỉ đọc câu chữ.
 *
 * Phần dư phải nói thẳng vì nó có thật: lần tra hàng ấy là một truy vấn, và nó chạy TRƯỚC
 * `Timebox` của lớp cha (`Login::authenticate()` gọi `rateLimit()` ở dòng đầu). Một chỉ mục duy
 * nhất tìm thấy một hàng và không tìm thấy hàng nào không tốn đúng bằng nhau. Chênh lệch ấy nhỏ
 * hơn nhiều bậc so với 500 ms mà `auth.timebox_duration` đệm, và nó không đổi thứ gì người gõ
 * ĐỌC được — nhưng nó là một tín hiệu thời gian, không phải một tín hiệu phản hồi, và nó nằm
 * ngoài vùng đệm. Ghi ra để lần sau không ai phát hiện lại nó như một điều bất ngờ.
 */
final class PortalLoginThrottle
{
    /** SPEC §10.3: 5 lần. */
    public const MAX_ATTEMPTS = 5;

    /** SPEC §10.3: 15 phút. */
    public const DECAY_SECONDS = 900;

    /**
     * Gấp chuỗi email vừa gõ thành một khoá ổn định — CHỈ dùng cho những địa chỉ không ứng với
     * tài khoản nào.
     *
     * **Đây không phải là collation, và không còn khẳng định là.** Vòng trước khẳng định như vậy
     * và khẳng định ấy sai: một phép quét khoảng 2.800 điểm mã trên MariaDB 11.8, đối chiếu
     * `HEX(WEIGHT_STRING(… COLLATE utf8mb4_unicode_ci))` với phép gấp, tìm ra 21 lớp trọng số bất
     * đồng. Mọi lần cài lại UCA 4.0.0 trong PHP đều là một BẢN SAO THỨ HAI của collation, và bản
     * sao thứ hai trôi. Vì vậy chiều tài khoản nay hỏi thẳng cơ sở dữ liệu — xem
     * `passwordAccountKey()` — và phép gấp này chỉ còn đúng một việc.
     *
     * **Việc ấy là: cho một chuỗi KHÔNG tra ra hàng nào một khoá ổn định.** Ở nhánh đó không có
     * tài khoản nào để bảo vệ, nên không có trần 5 lần nào để gia hạn; chiều IP vẫn đếm từng lần
     * thử như thường. Độ đúng của phép gấp vì vậy không còn là thứ giữ SPEC §10.3 đứng vững.
     *
     * Bốn bước — và THỨ TỰ của chúng chính là chỗ vòng trước hỏng:
     *
     *  1. NFKC — gộp các dạng tương thích: `ｕ` → `u`, `ﬁ` → `fi`.
     *  2. NFD rồi xoá `\p{Mn}`, `\p{Me}`, `\p{Cf}` — tách dấu ra khỏi chữ cái rồi bỏ đi: `é` →
     *     `e`, `ậ` → `a`, kể cả dấu BAO QUANH (`U+0488`, `U+0489` — lớp `\p{Me}`, thứ vòng trước
     *     bỏ sót) và ký tự định dạng (ZWJ, ZWNJ, …).
     *  3. `MB_CASE_FOLD` — gấp hoa/thường đầy đủ theo Unicode, nên `ß` → `ss` (thứ `mb_strtolower`
     *     không làm). **Sau** bước 2, không phải trước: `U+0345` là `\p{Mn}` nhưng gấp hoa/thường
     *     biến nó thành chữ iota, nên gấp trước thì bước xoá không còn dấu nào để xoá — đó là
     *     đúng một trong ba nguyên nhân của 21 lớp lệch kia.
     *  4. `trim()`.
     *
     * Chỗ đã đo mà vẫn còn bất đồng (họ `U+0363`–`U+036F`: bị xoá ở đây, được collation cân như
     * chữ cái NỀN) lệch về một phía duy nhất — phép gấp gộp NHIỀU hơn collation, không bao giờ
     * ít hơn. Gộp nhiều hơn thì cùng lắm hai địa chỉ vô can chia nhau một bộ đếm; gộp ít hơn mới
     * là một bộ đếm rỗng mua được. Đó là điều đo được trên các lớp đã biết, không phải một chứng
     * minh cho toàn bộ Unicode.
     *
     * `\Normalizer` viết đủ tên có chủ ý: `App\Support\Normalizer` là một lớp khác hẳn của dự án
     * và không có `normalize()`, nên một dòng `use Normalizer;` ở đầu tệp trông thừa trong khi gỡ
     * nó đi làm MỌI lần đăng nhập vỡ. Ext-intl là phụ thuộc cứng của `filament/support` nên nó
     * không thể vắng mặt.
     *
     * Chuỗi vào không phải UTF-8 hợp lệ thì `Normalizer::normalize()` trả `false` và
     * `preg_replace()` trả `null`; mỗi bước vì thế giữ nguyên kết quả bước trước thay vì biến
     * chuỗi thành rỗng — một chuỗi rỗng sẽ dồn mọi rác vào chung một bộ đếm. Nhân chứng: test
     * "never turns raw pre-validation livewire state into an error of its own".
     */
    public static function foldEmail(?string $email): string
    {
        $folded = (string) $email;

        $normalized = \Normalizer::normalize($folded, \Normalizer::FORM_KC);

        if (is_string($normalized)) {
            $folded = $normalized;
        }

        $decomposed = \Normalizer::normalize($folded, \Normalizer::FORM_D);

        if (is_string($decomposed)) {
            $folded = $decomposed;
        }

        $stripped = preg_replace('/[\p{Mn}\p{Me}\p{Cf}]/u', '', $folded);

        if (is_string($stripped)) {
            $folded = $stripped;
        }

        return trim(mb_convert_case($folded, MB_CASE_FOLD, 'UTF-8'));
    }

    /**
     * Khoá chính của tài khoản mà chuỗi vừa gõ đăng nhập vào, nếu có.
     *
     * Truy vấn ở đây cố ý là ĐÚNG truy vấn `Illuminate\Auth\EloquentUserProvider::retrieveByCredentials()`
     * sắp chạy vài dòng sau: `newModelQuery()` (tức `ClientUser::query()`, mang theo cả global
     * scope xoá mềm) cộng một `where` phẳng trên cột `email` với **chuỗi thô vừa gõ**. Nhờ vậy
     * MariaDB tự gấp chuỗi bằng chính collation của cột, qua chính chỉ mục unique mà lần đăng
     * nhập sắp dùng — và không có bản sao collation thứ hai nào trong PHP để trôi.
     *
     * Mang theo scope xoá mềm là một phần của "đúng truy vấn ấy", không phải một chi tiết: một
     * hàng đã xoá mềm vẫn giữ chỗ trong chỉ mục unique nhưng KHÔNG đăng nhập được, nên nó cũng
     * không được có một bộ đếm tài khoản — chuỗi ấy rơi về phép gấp như mọi địa chỉ không có tài
     * khoản.
     *
     * # Giá phải trả, nói ra vì nó có thật
     *
     * MỘT lần `SELECT` thêm trên chỉ mục unique cho mỗi lần gọi, và nhiều nhất HAI lần gọi trong
     * một lần gửi form: một ở phép kiểm (`Login::rateLimit()`), rồi một ở lần đập
     * (`throwFailureValidationException()`) nếu hỏng, hoặc một ở `clearPasswordAccount()` nếu
     * vào được. Con số "một lần gọi, một truy vấn" được ghim ở test "spends exactly one extra
     * query on the account lookup" để nó không lặng lẽ lớn lên.
     *
     * # Cơ sở dữ liệu hỏng
     *
     * Ngoại lệ truy vấn ở đây cố ý KHÔNG được bắt. Hai lý do:
     *
     *  - Nó không thêm một cách hỏng nào. Đây là cùng bảng, cùng chỉ mục, cùng kết nối với lần
     *    tra mà `retrieveByCredentials()` chạy ngay sau đó, nên một cơ sở dữ liệu không trả lời
     *    được câu này cũng không trả lời được lần đăng nhập — màn hình đăng nhập rơi về đúng
     *    trang 500 của ứng dụng, giống hệt mọi truy vấn khác trong dự án.
     *  - Bắt rồi nuốt thì tệ hơn: khi cơ sở dữ liệu chập chờn, bộ đếm lặng lẽ tụt về khoá gấp
     *    theo chuỗi, tức đúng lúc một kẻ dò cần một bộ đếm rỗng thì nó có. Một cách hỏng ỒN ÀO
     *    và giống phần còn lại của ứng dụng là câu trả lời đúng ở đây.
     *
     * Chuỗi RỖNG không sinh truy vấn: đó là state thô của một ô email chưa gõ gì, thứ phép kiểm
     * nhìn thấy ở mọi lần gửi form (xem `passwordAccountKey()`), và không có hàng nào để tìm.
     * Mọi chuỗi khác — kể cả rác chưa qua luật `email` — vẫn được tra, và việc đó không ném lỗi:
     * đo trên MariaDB 11.8 với một cột `VARCHAR` `utf8mb4_unicode_ci` có chỉ mục unique, UTF-8
     * hỏng, surrogate lẻ và một chuỗi dài hơn cả cột đều trả lời "không có hàng nào", còn một byte
     * NUL nhúng giữa chuỗi thì trả về ĐÚNG hàng ấy (collation bỏ qua nó) — không trường hợp nào
     * là một ngoại lệ.
     * Nhân chứng phía PHP: test "never turns raw pre-validation livewire state into an error of
     * its own".
     */
    public static function accountIdFor(?string $email): int|string|null
    {
        if ($email === null || $email === '') {
            return null;
        }

        /** @var int|string|null $id */
        $id = ClientUser::query()->where('email', $email)->value('id');

        return $id;
    }

    /**
     * Chiều TÀI KHOẢN của bước nhập email + mật khẩu.
     *
     * Khoá dựng từ **tài khoản**, không từ chuỗi vừa gõ. Đó là cả bài học của hai vòng trước:
     * `client_users.email` là `utf8mb4_unicode_ci`, nên `nám@…`, `NAM@…`, `ｎam@…` và
     * `na`+`U+0345`+`m@…` đều ĐĂNG NHẬP VÀO ĐÚNG MỘT HÀNG. Khoá theo chuỗi thì mỗi cách viết mua
     * được một bộ đếm rỗng mới, và `U+0345` lặp lại được — trần 5 lần / 15 phút của SPEC §10.3
     * gia hạn được vô hạn. Nhân chứng: test "gives one account one lock however the email in the
     * box is spelled", thứ hỏi chính MariaDB rằng từng cách viết có ra đúng hàng ấy không trước
     * khi đòi chúng dùng chung một bộ đếm.
     *
     * Không tra ra hàng nào thì rơi về `foldEmail()`: ở đó không có tài khoản nào để bảo vệ.
     *
     * Chuỗi gấp rỗng thì KHÔNG có chiều này — nếu vẫn băm chuỗi rỗng thì mọi lần gửi form thiếu
     * email trên khắp hệ thống dồn vào chung một bộ đếm và khoá lẫn nhau.
     */
    public static function passwordAccountKey(?string $email): ?string
    {
        $id = self::accountIdFor($email);

        if ($id !== null) {
            return 'portal-login-account:'.sha1(ClientUser::class.'|'.$id);
        }

        $folded = self::foldEmail($email);

        return $folded === '' ? null : 'portal-login-email:'.sha1($folded);
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
