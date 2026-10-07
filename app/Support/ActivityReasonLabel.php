<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Nhãn tiếng Việt cho khoá `reason` trong `properties` của một dòng nhật ký (M13 Task 3, R9) — cho
 * modal "Xem chi tiết" của trang Nhật ký hệ thống (`ActivityLogPage`) và của tab "Nhật ký" trên trang
 * vụ việc (`MatterActivityRelationManager`), hai nơi cùng dựng một view.
 *
 * Nhãn theo CẶP sự kiện + lý do: `activity.reasons.<sự kiện>.<lý do>`. Cùng một mã (`matter_reassigned`)
 * mang hai nghĩa dưới hai sự kiện (mốc được chuyển, luồng được chuyển), và cùng mã dưới một sự kiện
 * không có nhãn thì KHÔNG mượn nhãn của sự kiện khác. Có nhãn thì in nhãn thay mã; không có (lý do tự do
 * người dùng gõ của `matter_reassigned`, một mã chưa có nhãn) thì giữ nguyên giá trị đã ghi. Chỉ đổi
 * khoá `reason` ở tầng ngoài cùng; dòng nhật ký trong CSDL không đổi.
 *
 * Gọi SAU {@see SensitivePropertyFilter::filter()}: nhãn không phải dữ liệu nhạy cảm, và lớp lọc vẫn là
 * lớp đứng giữa CSDL và màn hình.
 */
final class ActivityReasonLabel
{
    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function apply(?string $event, array $properties): array
    {
        $reason = $properties['reason'] ?? null;

        if ($event === null || $event === '' || ! is_string($reason) || $reason === '') {
            return $properties;
        }

        $key = 'activity.reasons.'.$event.'.'.$reason;

        if (Lang::has($key) && is_string($label = __($key))) {
            $properties['reason'] = $label;
        }

        return $properties;
    }
}
