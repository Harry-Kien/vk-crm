<?php

namespace App\Support;

/**
 * Lọc/che các khoá nhạy cảm ra khỏi `properties` của một dòng nhật ký trước khi hiện cho nhân sự
 * xem (SPEC §10.6, M6.5 Task 20 — trang Nhật ký hệ thống).
 *
 * # Vì sao lớp này tồn tại dù mọi Action đã ghi nhật ký đều tự tránh ghi định danh thô
 *
 * `App\Models\Client::getActivitylogOptions()` đã loại `id_number` khỏi `logOnly()`, và không
 * `Audit::record()` nào trong app/ hôm nay ghi `id_number` thô vào `properties` (đã rà lại toàn
 * bộ nơi gọi). Nhưng Controller decision của task này rất rõ: trang xem KHÔNG được lộ nó dù MỘT
 * lần — kể cả nếu một Action sau này (viết bởi ai đó không đọc lại docblock này) lỡ tay đưa
 * `id_number` vào properties. Đây là lớp phòng thủ ở TẦNG HIỂN THỊ, độc lập với kỷ luật ghi nhật
 * ký ở tầng ghi — hai lớp phòng thủ, không phải một lớp lặp lại.
 *
 * **Fix round 1 (I1, ruling):** `Client::getActivitylogOptions()->logOnly([..., 'phone', 'email',
 * 'address', ...])` — khác `id_number`, BA khoá này KHÔNG bị loại khỏi `logOnly()` (chúng không
 * phải bí mật tuyệt đối như CCCD, và một số màn hình khác cần đọc lại chúng), nên một lần sửa
 * thông tin khách hàng ghi THÔ chúng vào `properties.attributes`/`properties.old`
 * (`Spatie\LogsActivity`'s diff của một model `updated`). Trang xem vì vậy KHÔNG chặn hẳn ba khoá
 * này (chặn hẳn thì người rà soát mất hết ngữ cảnh "khách nào, sửa số gì"), mà CHE MỘT PHẦN —
 * người xem vẫn nhận ra ĐÚNG khách hàng và ĐẠI KHÁI đã đổi gì, nhưng không đọc được nguyên số/địa
 * chỉ.
 *
 * # Đệ quy, không chỉ tầng ngoài cùng
 *
 * `properties` của nhiều sự kiện (ví dụ `conflict_check_run`, xem `ConflictCheckResult::toArray()`,
 * hoặc diff `attributes`/`old` của một model `updated`) là mảng LỒNG NHAU tuỳ ý — `matches` là một
 * mảng các mảng, mỗi phần tử tự mang các khoá của chính nó; `attributes`/`old` cũng chỉ là hai
 * mảng con như bất kỳ mảng con nào khác. Một phép lọc chỉ xét tầng ngoài cùng sẽ bỏ lọt đúng những
 * trường hợp phức tạp nhất, nên `walk()` đệ quy vào MỌI mảng con — và vì `attributes`/`old` bản
 * thân chúng không phải tên khoá bị chặn/che, chúng không cần một nhánh xử lý RIÊNG: đệ quy chung
 * đã tự phủ tới bên trong.
 *
 * # So khớp tên khoá KHÔNG phân biệt hoa/thường (Fix round 1, ruling)
 *
 * Cột `properties` là JSON tự do — không gì đảm bảo mọi nơi ghi dùng đúng chữ thường
 * (`snake_case`) như quy ước của dự án. So khớp qua `mb_strtolower($key)` để một khoá viết
 * `ID_NUMBER`/`Phone` vẫn bị chặn/che đúng như `id_number`/`phone`.
 *
 * # Mọi `*_hash` cũng bị chặn (final review X8, thay luật cũ "hash thì được, miễn có nhãn")
 *
 * Một hash của số CCCD (12 chữ số) hay số điện thoại (10 chữ số) dò ngược được bằng vét cạn —
 * "một chiều" không có nghĩa là "không đọc được". Nên ngoài danh sách chặn đích danh (so khớp
 * BẰNG), MỌI khoá kết thúc bằng `_hash` cũng bị thay bằng câu "đã ẩn", ở mọi tầng, mọi kiểu chữ.
 * Tầng ghi cũng đã đổi sang băm có khoá (`Audit::identifierHash()`); hai lớp, không phải một.
 *
 * # Giá trị không phải chuỗi
 *
 * Khoá bị chặn: thay bằng câu "đã ẩn" bất kể kiểu giá trị. Khoá bị che: số che như chuỗi, mảng
 * che từng lá (xem `maskValue()`), bool/null giữ nguyên.
 */
final class SensitivePropertyFilter
{
    /**
     * Tên khoá bị chặn TUYỆT ĐỐI (thay bằng câu "đã ẩn"), dù nằm ở tầng nào của `properties`, so
     * khớp không phân biệt hoa/thường. `id_number` là khoá SPEC §11/R14 nêu đích danh ("không bao
     * giờ ghi số CCCD thô"); bốn khoá còn lại là lưới an toàn chung cho mọi bí mật xác thực —
     * không Action nào trong app/ ghi chúng vào properties hôm nay, nhưng nếu một lần sửa sau này
     * lỡ tay làm vậy, trang xem vẫn không lộ nó.
     *
     * @var list<string>
     */
    private const BLOCKED_KEYS = [
        'id_number',
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Tên khoá bị CHE MỘT PHẦN (giữ lại một chút để còn đọc được ngữ cảnh) — xem lý lẽ ở docblock
     * lớp. Chỉ ba khoá này: đây là ba trường liên lạc/định vị của `Client` mà `logOnly()` vẫn ghi
     * nguyên khi có sửa đổi.
     *
     * @var list<string>
     */
    private const MASKED_KEYS = [
        'phone',
        'email',
        'address',
    ];

    /** Ký hiệu che dùng chung cho cả ba phép che — cùng dấu chấm tròn với câu "đã ẩn" ở trên. */
    private const MASK = '•••';

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function filter(array $properties): array
    {
        return self::walk($properties);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function walk(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $normalizedKey = is_string($key) ? mb_strtolower($key) : null;

            if ($normalizedKey !== null && self::isBlocked($normalizedKey)) {
                $result[$key] = __('activity.page.properties.redacted');

                continue;
            }

            if ($normalizedKey !== null && in_array($normalizedKey, self::MASKED_KEYS, true)) {
                $result[$key] = self::maskValue($normalizedKey, $value);

                continue;
            }

            $result[$key] = is_array($value) ? self::walk($value) : $value;
        }

        return $result;
    }

    /**
     * Final review X8: ngoài danh sách đích danh, MỌI khoá kết thúc bằng `_hash` — hash của một
     * định danh 10–12 chữ số dò ngược được, có khoá hay không.
     */
    private static function isBlocked(string $normalizedKey): bool
    {
        return in_array($normalizedKey, self::BLOCKED_KEYS, true)
            || str_ends_with($normalizedKey, '_hash');
    }

    /**
     * Final review X1: giá trị dưới một khoá cần che không phải lúc nào cũng là chuỗi. Số (một số
     * điện thoại lưu dạng số) che như chuỗi; mảng (một danh sách email) che TỪNG LÁ theo cùng khoá
     * cha — riêng các khoá chuỗi con vẫn đi qua luật chặn/che của chính chúng trước; bool/null
     * không mang thông tin định danh nên giữ nguyên.
     */
    private static function maskValue(string $normalizedKey, mixed $value): mixed
    {
        if (is_string($value)) {
            return self::mask($normalizedKey, $value);
        }

        if (is_int($value) || is_float($value)) {
            return self::mask($normalizedKey, (string) $value);
        }

        if (is_array($value)) {
            $masked = [];

            foreach ($value as $childKey => $child) {
                $normalizedChild = is_string($childKey) ? mb_strtolower($childKey) : null;

                $masked[$childKey] = match (true) {
                    $normalizedChild !== null && self::isBlocked($normalizedChild) => __('activity.page.properties.redacted'),
                    $normalizedChild !== null && in_array($normalizedChild, self::MASKED_KEYS, true) => self::maskValue($normalizedChild, $child),
                    default => self::maskValue($normalizedKey, $child),
                };
            }

            return $masked;
        }

        return $value;
    }

    private static function mask(string $normalizedKey, string $value): string
    {
        return match ($normalizedKey) {
            'phone' => self::maskPhone($value),
            'email' => self::maskEmail($value),
            'address' => self::maskAddress($value),
            default => $value,
        };
    }

    /**
     * Ruling: "keep the first 2 and the last 3 digits". Đếm trên CHỮ SỐ của giá trị, không trên
     * ký tự thô — một số điện thoại người thật gõ mang dấu cách/ngoặc/dấu cộng
     * (`(+84) 912 345 678`, xem SPEC "dữ liệu người thật gõ", Review Focus mục 5), và giữ nguyên
     * các ký tự định dạng đó trong bản che sẽ lộ thêm cấu trúc số không cần thiết. Chuỗi che ở
     * giữa vì vậy KHÔNG giữ định dạng gốc, chỉ giữ đúng hai đầu chữ số.
     *
     * Dưới 6 chữ số (không đủ chỗ cho 2 đầu + 3 cuối mà không chồng lấn) thì che TOÀN BỘ — hé lộ
     * một phần của một số quá ngắn có thể suy ra hết phần còn lại.
     */
    private static function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (mb_strlen($digits) <= 5) {
            return self::MASK;
        }

        return mb_substr($digits, 0, 2).self::MASK.mb_substr($digits, -3);
    }

    /** Ruling: "keep the first letter and the domain". */
    private static function maskEmail(string $value): string
    {
        if (! str_contains($value, '@')) {
            return self::MASK;
        }

        [$local, $domain] = explode('@', $value, 2);

        if ($local === '') {
            return self::MASK.'@'.$domain;
        }

        return mb_substr($local, 0, 1).self::MASK.'@'.$domain;
    }

    /** Ruling: "keep the first word followed by "…"". */
    private static function maskAddress(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        $firstWord = (string) preg_replace('/\s.*$/us', '', $trimmed);

        return $firstWord.'…';
    }
}
