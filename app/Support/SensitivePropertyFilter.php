<?php

namespace App\Support;

/**
 * Lọc các khoá nhạy cảm ra khỏi `properties` của một dòng nhật ký trước khi hiện cho nhân sự xem
 * (SPEC §10.6, M6.5 Task 20 — trang Nhật ký hệ thống).
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
 * # Đệ quy, không chỉ tầng ngoài cùng
 *
 * `properties` của nhiều sự kiện (ví dụ `conflict_check_run`, xem `ConflictCheckResult::toArray()`)
 * là mảng LỒNG NHAU tuỳ ý — `matches` là một mảng các mảng, mỗi phần tử tự mang các khoá của
 * chính nó. Một phép lọc chỉ xét tầng ngoài cùng sẽ bỏ lọt đúng những trường hợp phức tạp nhất,
 * nên `walk()` đệ quy vào MỌI mảng con.
 *
 * # Hash thì được, miễn có nhãn
 *
 * `id_number_hash` (xem `App\Models\MatterParty`) KHÔNG nằm trong danh sách chặn: nó là một hash
 * một chiều, và chính cái tên `_hash` đã là "nhãn" mà Controller decision đòi — người xem biết
 * ngay đây không phải số CCCD thô. Danh sách chặn vì vậy liệt kê CHÍNH XÁC từng tên khoá, không
 * dùng một mẫu như "chứa id_number" (mẫu đó sẽ chặn nhầm `id_number_hash`).
 */
final class SensitivePropertyFilter
{
    /**
     * Tên khoá bị chặn TUYỆT ĐỐI, dù nằm ở tầng nào của `properties`. `id_number` là khoá SPEC
     * §11/R14 nêu đích danh ("không bao giờ ghi số CCCD thô"); bốn khoá còn lại là lưới an toàn
     * chung cho mọi bí mật xác thực — không Action nào trong app/ ghi chúng vào properties hôm
     * nay, nhưng nếu một lần sửa sau này lỡ tay làm vậy, trang xem vẫn không lộ nó.
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
            if (is_string($key) && in_array($key, self::BLOCKED_KEYS, true)) {
                $result[$key] = __('activity.page.properties.redacted');

                continue;
            }

            $result[$key] = is_array($value) ? self::walk($value) : $value;
        }

        return $result;
    }
}
