<?php

namespace App\Support\Performance;

/**
 * Một tỉ lệ của M13 (R7) — một phép ĐẾM, không phải một điểm số: tử và mẫu luôn đi cùng nhau, và dưới
 * {@see self::MIN_SAMPLE} việc thì không có tỉ lệ. Một người có 2 mốc, lỡ 1, không phải "50%".
 *
 * Cột P1 ("Mốc đúng hạn": đúng hạn / mốc của kỳ) và P9 ("Hoàn thành việc đến hạn", R7) dùng lớp này;
 * mọi tỉ lệ khác của M13 sau này cũng vậy. In bằng {@see self::label()}: dấu phẩy thập phân, một chữ số
 * sau dấu phẩy, không `intl` — "87,5% (35/40)", hoặc "Chưa đủ dữ liệu (n = 3)". Không bao giờ tô màu
 * (R8).
 */
final readonly class Ratio
{
    /** Số việc tối thiểu để có tỉ lệ — áp cho mọi tỉ lệ của M13 (R7). */
    public const MIN_SAMPLE = 5;

    public function __construct(public int $numerator, public int $denominator) {}

    public function isMeasurable(): bool
    {
        return $this->denominator >= self::MIN_SAMPLE;
    }

    public function label(): string
    {
        if (! $this->isMeasurable()) {
            return __('performance.ratio.insufficient', ['n' => $this->denominator]);
        }

        $percent = number_format($this->numerator * 100 / $this->denominator, 1, ',', '.');

        return __('performance.ratio.label', [
            'percent' => str_ends_with($percent, ',0') ? substr($percent, 0, -2) : $percent,
            'numerator' => $this->numerator,
            'denominator' => $this->denominator,
        ]);
    }
}
