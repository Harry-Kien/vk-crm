<?php

namespace App\Support\Billing;

/**
 * Ba màu của tiền trên trang Doanh thu — MỘT chỗ cho lát của vành khuyên (`ReceivablesDonutWidget`) và
 * chấm màu của ô số đầu trang (`RevenueKpiWidget`, làn rv): Đã thu xanh lá, Còn phải thu xanh dương,
 * Quá hạn đỏ. Bộ ba đã kiểm chứng máy ở kế hoạch M9 (phân biệt được trên nền sáng lẫn tối).
 *
 * Mã hex, không biến CSS: Chart.js vẽ lên canvas, nơi biến CSS không tới được — và ô số dùng CHÍNH mã
 * đó để hai nơi không lệch nhau một sắc độ.
 */
final class RevenueColours
{
    public const COLLECTED = '#0ca30c';

    public const OUTSTANDING = '#4a73bd';

    public const OVERDUE = '#d03b3b';

    /**
     * Biểu đồ SO SÁNH người với người ("Doanh thu theo người", "Tải theo luật sư", 2026-10-21): cột của người
     * đang chọn ở bộ lọc trang giữ màu chuỗi của trang (`#4a73bd`); khi đang chọn một người, cột của những người
     * khác nhạt đi (cùng sắc, nhạt hơn — vẫn đọc được trên nền sáng lẫn tối). Không chọn ai thì mọi cột một màu.
     */
    public const PERSON_SELECTED = '#4a73bd';

    public const PERSON_OTHER = '#b4c3e3';

    /** Cột "không thuộc người nào" (hồ sơ không do nhân viên kinh doanh nào đem về) — xám trung tính. */
    public const PERSON_NONE = '#94a3b8';

    /**
     * Màu từng cột theo thứ tự `$ids`: không chọn ai thì mọi cột {@see self::PERSON_SELECTED}; chọn một người thì
     * cột của người đó {@see self::PERSON_SELECTED}, cột người khác {@see self::PERSON_OTHER}; id `null` (không
     * thuộc ai) luôn {@see self::PERSON_NONE}.
     *
     * @param  list<int|null>  $ids
     * @return list<string>
     */
    public static function forPeople(array $ids, ?int $selected): array
    {
        return array_map(fn (?int $id): string => match (true) {
            $id === null => self::PERSON_NONE,
            $selected === null, $id === $selected => self::PERSON_SELECTED,
            default => self::PERSON_OTHER,
        }, $ids);
    }
}
