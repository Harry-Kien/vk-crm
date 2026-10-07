<?php

namespace App\Support\Performance;

/**
 * Thời gian phản hồi yêu cầu của khách (M13, cột P3; R17): trung vị, trung bình, và cách in.
 *
 * Đo bằng GIỜ LỊCH (kể cả đêm và ngày nghỉ) — nhánh dự phòng của R17 khi `App\Support\BusinessHours`
 * (M10) chưa có trên nhánh. Trang ghi rõ "giờ lịch". Không lớp nào của M13 viết định nghĩa "giờ làm
 * việc"; xem TODO ở `BuildPerformanceReport`.
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

    /** "3,5 giờ" dưới một ngày; "2 ngày 4 giờ" từ một ngày trở lên (giờ làm tròn); "2 ngày" khi tròn ngày. */
    public static function label(float $hours): string
    {
        if (round($hours, 1) < 24) {
            $text = number_format($hours, 1, ',', '.');

            return __('performance.duration.hours', ['hours' => str_ends_with($text, ',0') ? substr($text, 0, -2) : $text]);
        }

        $total = (int) round($hours);
        $days = intdiv($total, 24);
        $rest = $total % 24;

        return $rest === 0
            ? __('performance.duration.days', ['days' => $days])
            : __('performance.duration.days_hours', ['days' => $days, 'hours' => $rest]);
    }
}
