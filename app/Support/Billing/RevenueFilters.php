<?php

namespace App\Support\Billing;

use Illuminate\Support\Carbon;

/**
 * Đọc MỘT lần bộ lọc trang doanh thu (`RevenueDashboard::filtersForm()`, `HasFiltersForm`) từ
 * mảng `$this->pageFilters` mà mỗi widget nhận qua `InteractsWithPageFilters` — MỘT nơi diễn
 * dịch bốn trường lọc thành kiểu dữ liệu widget dùng được, để sáu widget không viết lại phép
 * tính khoảng ngày hay ép kiểu id sáu lần khác nhau.
 *
 * **Phím tắt kỳ = một ô chọn, không phải hai ô ngày luôn bật** (kế hoạch M9, "Bộ lọc"): `period`
 * là `this_month` (mặc định)/`this_quarter`/`this_year`/`custom`; hai ô `date_from`/`date_to` chỉ
 * có nghĩa (và chỉ hiện trên form) khi `period = custom`. Ba phím tắt còn lại tính khoảng ngày từ
 * chính `today()`, không đọc hai ô ngày đó.
 *
 * **Không có bộ lọc nào ép "của ai"** — đó là việc của `Matter::scopeListableBy($user)`, chạy Ở
 * TẦNG TRUY VẤN của từng widget (P3), không phải của lớp này. `RevenueFilters` chỉ đọc Ý MUỐN
 * của người xem (đã chọn kỳ nào, luật sư nào, lĩnh vực nào, đếm theo gì); nó không biết và không
 * cần biết người xem là ai.
 */
final class RevenueFilters
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $lawyerId,
        public readonly ?int $practiceAreaId,
        public readonly bool $byCount,
        private readonly string $period = 'this_month',
    ) {}

    /** @param  array<string, mixed>|null  $pageFilters */
    public static function fromPageFilters(?array $pageFilters): self
    {
        $pageFilters ??= [];

        $period = (string) ($pageFilters['period'] ?? 'this_month');
        [$from, $to] = self::period($period, $pageFilters);

        return new self(
            from: $from->startOfDay(),
            to: $to->endOfDay(),
            lawyerId: filled($pageFilters['lawyer_id'] ?? null) ? (int) $pageFilters['lawyer_id'] : null,
            practiceAreaId: filled($pageFilters['practice_area_id'] ?? null) ? (int) $pageFilters['practice_area_id'] : null,
            byCount: (bool) ($pageFilters['by_count'] ?? false),
            period: in_array($period, ['this_quarter', 'this_year', 'custom'], true) ? $period : 'this_month',
        );
    }

    /**
     * Kỳ để SO SÁNH của hàng ô số đầu trang (làn rv, 2026-10-15: "▲ 12 % so với kỳ trước"), cùng luật sư
     * và lĩnh vực, chỉ khác khoảng ngày:
     *
     *  - **Tháng/quý/năm này: CÙNG KHOẢNG ĐÃ QUA của kỳ liền trước.** Hôm nay 15/10 thì "tháng này" so với
     *    01/09 – 15/09, không với cả tháng 9 — nửa tháng đang chạy so với một tháng đủ thì lúc nào cũng
     *    "giảm". Kỳ đã kết thúc (hôm nay ngoài kỳ — không xảy ra với ba phím tắt, nhưng giữ cho đúng) thì
     *    so với cả kỳ trước.
     *  - **Tuỳ chọn: khoảng dài BẰNG NHAU liền trước** (01/06 – 10/06 so với 22/05 – 31/05).
     *
     * Dời bằng `sub…NoOverflow()`: 31/03 lùi một tháng là 28/02 (hay 29/02), không tràn sang tháng 3.
     */
    public function previousPeriod(): self
    {
        if ($this->period === 'custom') {
            $days = (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1;
            $from = $this->from->copy()->subDays($days);
            $to = $this->from->copy()->subDay();
        } else {
            $shift = fn (Carbon $date): Carbon => match ($this->period) {
                'this_quarter' => $date->copy()->subQuarterNoOverflow(),
                'this_year' => $date->copy()->subYearNoOverflow(),
                default => $date->copy()->subMonthNoOverflow(),
            };

            $from = $shift($this->from);
            $periodEnd = $shift($this->to);
            $periodEnd = match ($this->period) {
                'this_quarter' => $periodEnd->endOfQuarter(),
                'this_year' => $periodEnd->endOfYear(),
                default => $periodEnd->endOfMonth(),
            };
            $elapsedEnd = today()->between($this->from, $this->to) ? $shift(today()) : $periodEnd;
            $to = $elapsedEnd->lessThan($periodEnd) ? $elapsedEnd : $periodEnd;
        }

        return new self(
            from: $from->startOfDay(),
            to: $to->endOfDay(),
            lawyerId: $this->lawyerId,
            practiceAreaId: $this->practiceAreaId,
            byCount: $this->byCount,
            period: $this->period,
        );
    }

    /**
     * @param  array<string, mixed>  $pageFilters
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function period(string $period, array $pageFilters): array
    {
        return match ($period) {
            'this_quarter' => [today()->startOfQuarter(), today()->endOfQuarter()],
            'this_year' => [today()->startOfYear(), today()->endOfYear()],
            'custom' => [
                self::parseDate($pageFilters['date_from'] ?? null) ?? today()->startOfMonth(),
                self::parseDate($pageFilters['date_to'] ?? null) ?? today()->endOfMonth(),
            ],
            default => [today()->startOfMonth(), today()->endOfMonth()],
        };
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** Nhãn tiếng Việt của khoảng ngày đang lọc — để mỗi widget in lên chính nó (P2/M9 Task 9, điểm 4). */
    public function rangeLabel(): string
    {
        return $this->from->format('d/m/Y').' – '.$this->to->format('d/m/Y');
    }

    /**
     * Hai cận của kỳ cho `whereBetween()` trên một cột `date` (`contracts.signed_at`,
     * `payments.paid_on`) — MỘT chỗ cho cả bốn widget lọc theo kỳ.
     *
     * **Mốc thời gian đủ giờ (`00:00:00` … `23:59:59`), không phải ngày trần** (M9 Task 13). Cast
     * `date` của Eloquent ghi giá trị theo định dạng ngày-giờ của kết nối: MariaDB cắt về `DATE`,
     * nhưng SQLite lưu chuỗi `2026-09-30 00:00:00` — lớn hơn cận trên `2026-09-30` khi so chuỗi, nên
     * tiền ký/về ĐÚNG ngày cuối kỳ rơi khỏi kỳ (21 test đỏ vào ngày cuối tháng). Hai cận đủ giờ đúng
     * trên cả hai: MariaDB so `DATE` với `DATETIME` bằng cách nâng cột lên nửa đêm của ngày đó, vẫn
     * dùng được chỉ mục của cột (không bọc cột trong `DATE()` như `whereDate()`).
     *
     * @return array{0: string, 1: string}
     */
    public function bounds(): array
    {
        return [$this->from->toDateTimeString(), $this->to->toDateTimeString()];
    }
}
