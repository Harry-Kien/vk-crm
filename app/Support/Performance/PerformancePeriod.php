<?php

namespace App\Support\Performance;

use App\Support\Billing\RevenueFilters;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Validation\ValidationException;

/**
 * Kỳ của trang "Hiệu suất theo kỳ" (M13, R16) — và kỳ cố định 90 ngày của trang một người (Task 7,
 * {@see self::trailingDays()}). Một kỳ là hai NGÀY theo `APP_TIMEZONE`; mọi con số "trong kỳ" lấy hai
 * cận từ {@see self::bounds()} và mốc cắt từ {@see self::cutoff()}, không chỗ nào tự tính lại.
 *
 * # Lựa chọn
 *
 * `last_month` (mặc định: đánh giá trên kỳ ĐÃ ĐÓNG mới công bằng — kỳ đang chạy phạt người có nhiều mốc
 * đến hạn cuối tháng), `this_month`, `last_quarter`, `this_quarter`, `custom` (`date_from`, `date_to`
 * dạng `Y-m-d`; dài tối đa {@see self::MAX_CUSTOM_DAYS} ngày tính cả hai đầu, ngày cuối không quá hôm
 * nay, ngày đầu không sau ngày cuối). {@see self::fromFilters()} là cổng DUY NHẤT nhận ý muốn của người
 * xem: kỳ không đọc được là `ValidationException` tiếng Việt trên đúng trường, không bao giờ một kỳ
 * "gần đúng" lặng lẽ thay vào. "Tháng trước" ngày 31 không lật sang chính tháng này
 * (`subMonthNoOverflow()`).
 *
 * # Bộ đọc kỳ thứ hai, có chủ đích
 *
 * {@see RevenueFilters} (M9) đọc kỳ của trang Doanh thu; `period()` của nó là `private` và không có
 * `last_month`/`last_quarter`. Sửa nó để dùng chung sẽ đổi ô chọn kỳ của trang Doanh thu, ngoài phạm vi
 * M13. Vì vậy `PerformancePeriodTest` ghim hai bên vào nhau: `bounds()` bằng `RevenueFilters::bounds()`
 * từng giây cho `this_month`, `this_quarter`, `custom`; `last_month`, `last_quarter` so qua `custom` cùng
 * hai ngày. Trang Doanh thu cần "tháng trước" thì chuyển `RevenueFilters` sang gọi lớp này (ghi PROGRESS),
 * không viết bộ đọc thứ ba.
 *
 * # Hai cận đủ giờ, và mốc cắt (R19)
 *
 * {@see self::bounds()} = 00:00:00 ngày đầu … 23:59:59 ngày cuối, như `RevenueFilters::bounds()` (M9
 * Task 13): cast `date` trên SQLite lưu `Y-m-d 00:00:00`, nên cận trên là ngày trần làm rơi cả ngày cuối
 * kỳ. {@see self::cutoff()} = min(23:59:59 ngày cuối kỳ, now()): kỳ đã đóng cắt ở cuối kỳ, nên hoàn thành
 * và trả lời SAU kỳ không làm đổi số của kỳ đó; kỳ đang chạy cắt ở lúc xem.
 *
 * Lớp bất biến; trang giữ {@see self::toFilters()} trong một thuộc tính Livewire `#[Locked]` và đọc lại qua
 * `fromFilters()` ở mỗi request.
 */
final class PerformancePeriod
{
    public const LAST_MONTH = 'last_month';

    public const THIS_MONTH = 'this_month';

    public const LAST_QUARTER = 'last_quarter';

    public const THIS_QUARTER = 'this_quarter';

    public const CUSTOM = 'custom';

    /** Kỳ cố định của trang một người ({@see self::trailingDays()}); không có trên ô chọn. */
    public const TRAILING = 'trailing';

    /** Các lựa chọn của ô chọn kỳ, theo thứ tự hiện. */
    public const CHOICES = [self::LAST_MONTH, self::THIS_MONTH, self::LAST_QUARTER, self::THIS_QUARTER, self::CUSTOM];

    public const DEFAULT = self::LAST_MONTH;

    /** Độ dài tối đa của kỳ `custom`, tính cả ngày đầu và ngày cuối. */
    public const MAX_CUSTOM_DAYS = 366;

    private function __construct(
        public readonly string $key,
        /** Ngày đầu kỳ, 00:00:00. */
        public readonly CarbonImmutable $from,
        /** Ngày cuối kỳ, 00:00:00 (giờ cuối ngày là việc của {@see PerformancePeriod::bounds()}). */
        public readonly CarbonImmutable $to,
    ) {}

    /**
     * Kỳ người xem chọn (R16). Không có `period` (hoặc rỗng): {@see self::DEFAULT}.
     *
     * @param  array<string, mixed>|null  $filters  `period`, và với `custom`: `date_from`, `date_to` (`Y-m-d`)
     *
     * @throws ValidationException khoá `period`, `date_from` hoặc `date_to`, thông điệp tiếng Việt
     */
    public static function fromFilters(?array $filters): self
    {
        $key = $filters['period'] ?? null;
        $key = is_string($key) && $key !== '' ? $key : self::DEFAULT;
        $today = today()->toImmutable();

        return match ($key) {
            self::LAST_MONTH => new self($key, $today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            self::THIS_MONTH => new self($key, $today->startOfMonth(), $today->endOfMonth()->startOfDay()),
            self::LAST_QUARTER => new self($key, $today->startOfQuarter()->subQuarterNoOverflow(), $today->startOfQuarter()->subQuarterNoOverflow()->endOfQuarter()->startOfDay()),
            self::THIS_QUARTER => new self($key, $today->startOfQuarter(), $today->endOfQuarter()->startOfDay()),
            self::CUSTOM => self::custom($filters ?? [], $today),
            default => throw ValidationException::withMessages(['period' => __('performance.period.errors.unknown')]),
        };
    }

    /** `$days` ngày gần nhất, kết thúc HÔM QUA: [hôm nay − `$days`, hôm qua] — kỳ đã đóng (trang một người, Task 7). */
    public static function trailingDays(int $days): self
    {
        $today = today()->toImmutable();

        return new self(self::TRAILING, $today->subDays($days), $today->subDay());
    }

    /**
     * Hai cận đủ giờ cho `whereBetween()` trên cột `date` hoặc `datetime` — như `RevenueFilters::bounds()`.
     *
     * @return array{0: string, 1: string}
     */
    public function bounds(): array
    {
        return [$this->from->startOfDay()->toDateTimeString(), $this->to->endOfDay()->toDateTimeString()];
    }

    /** Mốc cắt của R19: min(23:59:59 ngày cuối kỳ, now()). */
    public function cutoff(): CarbonImmutable
    {
        $end = $this->to->endOfDay();
        $now = now()->toImmutable();

        return $now->lt($end) ? $now : $end;
    }

    /** Kỳ chưa hết: số còn thay đổi (R7, nhãn "kỳ đang chạy"). */
    public function isRunning(): bool
    {
        return now()->lt($this->to->endOfDay());
    }

    /** "Tháng trước (01/09/2026 – 30/09/2026)". */
    public function label(): string
    {
        $name = $this->key === self::TRAILING
            ? __('performance.period.trailing', ['days' => (int) $this->from->diffInDays($this->to) + 1])
            : __("performance.period.options.{$this->key}");

        return __('performance.period.label', [
            'name' => $name,
            'from' => $this->from->format('d/m/Y'),
            'to' => $this->to->format('d/m/Y'),
        ]);
    }

    /**
     * Bộ lọc đọc lại được bằng {@see self::fromFilters()}: chỉ `period` với kỳ đặt sẵn (tính lại từ hôm
     * nay ở mỗi request), thêm hai ngày với `custom`.
     *
     * @return array{period: string, date_from?: string, date_to?: string}
     */
    public function toFilters(): array
    {
        return $this->key === self::CUSTOM
            ? ['period' => self::CUSTOM, 'date_from' => $this->from->toDateString(), 'date_to' => $this->to->toDateString()]
            : ['period' => $this->key];
    }

    /** @param  array<string, mixed>  $filters */
    private static function custom(array $filters, CarbonImmutable $today): self
    {
        $from = self::date($filters['date_from'] ?? null);
        $to = self::date($filters['date_to'] ?? null);

        $errors = array_filter([
            'date_from' => is_string($from) ? __("performance.period.errors.{$from}") : null,
            'date_to' => is_string($to) ? __("performance.period.errors.{$to}") : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var CarbonImmutable $from */
        /** @var CarbonImmutable $to */
        if ($from->gt($to)) {
            throw ValidationException::withMessages(['date_from' => __('performance.period.errors.order')]);
        }

        if ($to->gt($today)) {
            throw ValidationException::withMessages(['date_to' => __('performance.period.errors.future')]);
        }

        $days = (int) $from->diffInDays($to) + 1;

        if ($days > self::MAX_CUSTOM_DAYS) {
            throw ValidationException::withMessages([
                'date_to' => __('performance.period.errors.too_long', ['max' => self::MAX_CUSTOM_DAYS, 'days' => $days]),
            ]);
        }

        return new self(self::CUSTOM, $from, $to);
    }

    /**
     * Một ngày `Y-m-d` có thật (31/02 không qua) — đúng dạng trạng thái của `DatePicker`; lỗi thì mã lỗi
     * (`dates_required`, `invalid_date`) thay vì một ngày đoán.
     */
    private static function date(mixed $value): CarbonImmutable|string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'dates_required';
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));
        } catch (InvalidFormatException) {
            return 'invalid_date';
        }

        // `createFromFormat()` lật 31/02 sang tháng 3 mà không báo lỗi: đọc lại để bắt ngày không có thật.
        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === trim($value) ? $date : 'invalid_date';
    }
}
