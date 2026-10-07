<?php

namespace App\Support\Performance;

use App\Actions\Performance\BuildPerformanceReport;
use App\Support\BusinessHours;

/**
 * Thời gian phản hồi yêu cầu của khách (M13, cột P3; R17): trung vị, trung bình, và cách in.
 *
 * Đo bằng GIỜ LÀM VIỆC của văn phòng qua ĐÚNG {@see BusinessHours} của M10 (`vkcrm.business_hours`, theo
 * `APP_TIMEZONE`): {@see BuildPerformanceReport} gọi `minutesBetween()` rồi chia 60. Không lớp nào của M13
 * viết định nghĩa "giờ làm việc" thứ hai. Ngày lễ chưa được trừ (M10 chưa mô hình hoá ngày lễ).
 *
 * Trung vị và trung bình tính bằng PHP trên số dòng có chặn (R11): SQL không làm trung vị một cách khả
 * chuyển giữa SQLite và MariaDB.
 */
final class ResponseTime
{
    /** @param  list<float>  $hours */
    public static function median(array $hours): ?float
    {
        if ($hours === []) {
            return null;
        }

        sort($hours);
        $middle = intdiv(count($hours), 2);

        return count($hours) % 2 === 1
            ? $hours[$middle]
            : ($hours[$middle - 1] + $hours[$middle]) / 2;
    }

    /** @param  list<float>  $hours */
    public static function mean(array $hours): ?float
    {
        return $hours === [] ? null : array_sum($hours) / count($hours);
    }

    /**
     * "3,5 giờ", "52 giờ", "1.234,5 giờ": giờ làm việc, dấu phẩy thập phân, một chữ số sau dấu phẩy (bỏ ",0").
     * KHÔNG gộp thành "ngày": một ngày làm việc không phải 24 giờ, nên "2 ngày 4 giờ" tính theo 24 giờ sẽ đọc
     * thành hai ngày làm việc trong khi là hơn năm ngày làm việc.
     */
    public static function label(float $hours): string
    {
        $text = number_format($hours, 1, ',', '.');

        return __('performance.duration.hours', ['hours' => str_ends_with($text, ',0') ? substr($text, 0, -2) : $text]);
    }
}
