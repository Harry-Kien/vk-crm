<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

/**
 * Giờ làm việc của văn phòng (M10 R5): các ngày làm việc trong tuần và MỘT khung giờ mỗi ngày, theo
 * một múi giờ. MỘT định nghĩa cho ngưỡng phản hồi lần đầu (`App\Support\Intake\FirstResponseClock`),
 * cho cổng giờ của tác vụ nhắc (`App\Actions\Schedule\RemindUnansweredIntakes`), và cho "thời gian đã
 * chờ" trên thư, thông báo và widget. Cấu hình: `config('vkcrm.business_hours')` + `APP_TIMEZONE`
 * ({@see self::fromConfig()}).
 *
 * **Ngày lễ không mô hình hoá ở M10** (kế hoạch M10, mục 5 "Còn cần xác nhận"): một ngày lễ rơi vào
 * ngày làm việc vẫn được tính là giờ làm việc. Không nghỉ trưa: khung giờ là một đoạn liền.
 *
 * Mọi phép tính đọc thời điểm ở múi giờ văn phòng ({@see self::local()}), bất kể thời điểm truyền
 * vào mang múi nào, và trả kết quả ở múi giờ văn phòng. Khung giờ tính cả hai đầu; với phép cộng và
 * phép đếm thì hai đầu chỉ là hai điểm, không đổi kết quả.
 */
final readonly class BusinessHours
{
    /** Trần số ngày lịch mà một phép cộng/đếm duyệt qua — lưới chống vòng lặp vô hạn, không phải luật. */
    private const MAX_DAYS = 3660;

    /** @var list<int> */
    public array $days;

    /**
     * @param  array<int, int>  $days  Ngày làm việc theo ISO-8601 (1 = Thứ Hai … 7 = Chủ nhật), ít nhất một.
     * @param  string  $opensAt  Giờ mở cửa `HH:MM`.
     * @param  string  $closesAt  Giờ đóng cửa `HH:MM`, sau giờ mở cửa.
     *
     * @throws InvalidArgumentException khi lịch không dùng được — một lịch sai cấu hình phải hỏng thành
     *                                  tiếng, không lặng lẽ thành "không bao giờ trong giờ làm việc".
     */
    public function __construct(
        array $days,
        public string $opensAt,
        public string $closesAt,
        public string $timezone,
    ) {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        if ($days === [] || min($days) < 1 || max($days) > 7) {
            throw new InvalidArgumentException('Giờ làm việc cần ít nhất một ngày, đánh số 1 (Thứ Hai) tới 7 (Chủ nhật).');
        }

        if (self::minuteOfDay($opensAt) >= self::minuteOfDay($closesAt)) {
            throw new InvalidArgumentException('Giờ làm việc phải đóng cửa sau giờ mở cửa.');
        }

        $this->days = $days;
    }

    public static function fromConfig(): self
    {
        $config = (array) config('vkcrm.business_hours');

        return new self(
            (array) ($config['days'] ?? []),
            (string) ($config['opens_at'] ?? ''),
            (string) ($config['closes_at'] ?? ''),
            (string) config('app.timezone'),
        );
    }

    /** `$at` có nằm trong giờ làm việc không — ngày làm việc, từ giờ mở cửa tới giờ đóng cửa, tính cả hai đầu. */
    public function isOpen(CarbonInterface $at): bool
    {
        $local = $this->local($at);
        $window = $this->windowOn($local);

        return $window !== null && $local->betweenIncluded($window[0], $window[1]);
    }

    /**
     * Thời điểm `$hours` giờ làm việc sau `$start`. Chỉ thời gian nằm trong khung giờ của ngày làm việc
     * được đếm: bắt đầu ngoài giờ (tối, cuối tuần) thì đồng hồ chạy từ lần mở cửa kế tiếp. Giữ nguyên
     * giây của `$start`.
     */
    public function addHours(CarbonInterface $start, int $hours): CarbonImmutable
    {
        $remaining = $hours * 3600;
        $cursor = $this->local($start);

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $window = $this->windowOn($cursor);

            if ($window !== null) {
                $from = $cursor->max($window[0]);
                $available = $window[1]->getTimestamp() - $from->getTimestamp();

                if ($available > 0) {
                    if ($remaining <= $available) {
                        return $from->addSeconds($remaining);
                    }

                    $remaining -= $available;
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        throw new LogicException('Không tính được hạn trong giờ làm việc.');
    }

    /**
     * Số PHÚT làm việc trọn vẹn giữa `$from` và `$to` (phần lẻ dưới một phút bỏ đi). `$to` không sau
     * `$from` thì 0.
     *
     * **Hai cách tính, một đáp án** (rà soát 2026-10-22, làn perf2 — trang "Hiệu suất theo kỳ" gọi hàm này
     * cho mọi luồng đã trả lời của kỳ, khoảng 200 ms một quý trên dữ liệu đo M13 khi mỗi ngày dựng bốn,
     * năm đối tượng Carbon). Khi múi giờ văn phòng giữ MỘT độ lệch suốt khoảng cần tính (Asia/Ho_Chi_Minh
     * từ 1975, mọi múi giờ không đổi giờ mùa hè trong khoảng đó), ngày làm việc và khung giờ tính bằng số
     * nguyên trên dấu thời gian ({@see self::minutesBetweenAtFixedOffset()}): cùng từng bước của cách tính
     * theo lịch (cùng ngày bắt đầu, cùng trần {@see self::MAX_DAYS} ngày, cùng phép bỏ phần lẻ dưới giây
     * của `getTimestamp()` ở hai đầu mỗi khúc). Khoảng có đổi giờ, hay múi giờ dạng độ lệch (`+07:00`, không
     * có bảng chuyển đổi), đi đường lịch cũ ({@see self::minutesBetweenByCalendar()}).
     * `BusinessHoursFastPathParityTest` so hai cách trên hàng nghìn cặp mốc ngẫu nhiên và các biên.
     */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $fromSecond = $from->getTimestamp();
        $toSecond = $to->getTimestamp();

        if ($toSecond <= $fromSecond) {
            // Cách tính theo lịch cũng ra 0: cùng một giây (dù khác phần lẻ) không có giây trọn nào ở giữa.
            return 0;
        }

        $offset = $this->fixedOffsetBetween($fromSecond, $toSecond);

        return $offset === null
            ? $this->minutesBetweenByCalendar($from, $to)
            : $this->minutesBetweenAtFixedOffset($fromSecond, $toSecond, $offset);
    }

    /**
     * Độ lệch (giây) của múi giờ văn phòng nếu nó không đổi suốt khoảng từ hai ngày trước `$fromSecond` tới
     * hai ngày sau `$toSecond`; `null` khi có lần chuyển giờ trong khoảng đó hoặc múi giờ không có bảng
     * chuyển đổi.
     */
    private function fixedOffsetBetween(int $fromSecond, int $toSecond): ?int
    {
        $transitions = (new DateTimeZone($this->timezone))->getTransitions($fromSecond - 2 * 86400, $toSecond + 2 * 86400);

        return is_array($transitions) && count($transitions) === 1 ? (int) $transitions[0]['offset'] : null;
    }

    /**
     * {@see self::minutesBetweenByCalendar()} khi độ lệch múi giờ không đổi: nửa đêm giờ văn phòng của ngày
     * thứ `n` (tính từ 1970-01-01, một Thứ Năm) là `n * 86400 - $offset`. `$toSecond > $fromSecond`.
     */
    private function minutesBetweenAtFixedOffset(int $fromSecond, int $toSecond, int $offset): int
    {
        $workingDays = array_fill_keys($this->days, true);
        $opens = self::minuteOfDay($this->opensAt) * 60;
        $closes = self::minuteOfDay($this->closesAt) * 60;
        $day = intdiv($fromSecond + $offset - (($fromSecond + $offset) % 86400 + 86400) % 86400, 86400);
        $cursor = $fromSecond;
        $seconds = 0;

        for ($i = 0; $i < self::MAX_DAYS && $cursor < $toSecond; $i++, $day++) {
            $midnight = $day * 86400 - $offset;

            if (isset($workingDays[(($day % 7) + 10) % 7 + 1])) {
                $seconds += max(0, min($toSecond, $midnight + $closes) - max($cursor, $midnight + $opens));
            }

            $cursor = $midnight + 86400;
        }

        return intdiv($seconds, 60);
    }

    /** Cách tính theo lịch, từng ngày bằng Carbon — xem {@see self::minutesBetween()}. */
    private function minutesBetweenByCalendar(CarbonInterface $from, CarbonInterface $to): int
    {
        $from = $this->local($from);
        $to = $this->local($to);
        $seconds = 0;
        $cursor = $from;

        for ($i = 0; $i < self::MAX_DAYS && $cursor->lessThan($to); $i++) {
            $window = $this->windowOn($cursor);

            if ($window !== null) {
                $start = $cursor->max($window[0]);
                $end = $to->min($window[1]);
                $seconds += max(0, $end->getTimestamp() - $start->getTimestamp());
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return intdiv($seconds, 60);
    }

    /**
     * NGÀY làm việc đầu tiên SAU ngày chứa `$day` (ở múi giờ văn phòng), lúc nửa đêm — Thứ Sáu → Thứ Hai, Thứ Bảy
     * → Thứ Hai. Cửa sổ "sắp đến hạn" của lịch nhắc việc (`RemindPendingWork`, kiểm toán 2026-10-21, Q2) đi tới hết
     * ngày này, nên một hạn Thứ Hai (hay Chủ nhật) được nhắc trong ngày làm việc trước đó.
     */
    public function nextWorkingDay(CarbonInterface $day): CarbonImmutable
    {
        $cursor = $this->local($day)->startOfDay();

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $cursor = $cursor->addDay();

            if (in_array($cursor->dayOfWeekIso, $this->days, true)) {
                return $cursor;
            }
        }

        throw new LogicException('Không tìm được ngày làm việc kế tiếp.');
    }

    /**
     * Khung giờ làm việc của ngày chứa `$day` (ở múi giờ văn phòng), hoặc null nếu ngày đó không làm
     * việc.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function windowOn(CarbonImmutable $day): ?array
    {
        if (! in_array($day->dayOfWeekIso, $this->days, true)) {
            return null;
        }

        $midnight = $day->startOfDay();

        return [
            $midnight->addMinutes(self::minuteOfDay($this->opensAt)),
            $midnight->addMinutes(self::minuteOfDay($this->closesAt)),
        ];
    }

    private function local(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($this->timezone);
    }

    /** `HH:MM` → số phút kể từ nửa đêm; chuỗi không đúng dạng thì `InvalidArgumentException`. */
    private static function minuteOfDay(string $time): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts) !== 1) {
            throw new InvalidArgumentException("Giờ làm việc \"{$time}\" không đúng dạng HH:MM.");
        }

        return (int) $parts[1] * 60 + (int) $parts[2];
    }
}
