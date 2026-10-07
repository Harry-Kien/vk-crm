<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
     */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
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
