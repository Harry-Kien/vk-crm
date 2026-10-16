<?php

namespace App\Support\Tasks;

use App\Actions\Schedule\RemindPendingWork;
use App\Jobs\SendWorkReminder;
use App\Support\BusinessHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Đồng hồ "chờ nhận" của luồng giao – nhận nội bộ (làn tk, Task 2): một việc hay một hồ sơ (ghế trong đội
 * ngũ vụ) giao từ `assigned_at` mà người nhận chưa bấm "Đã nhận" — tính bằng GIỜ LÀM VIỆC của văn phòng
 * ({@see BusinessHours}, cùng định nghĩa của `App\Support\Intake\FirstResponseClock`), không bằng giờ đồng
 * hồ: một việc giao 16:00 Thứ Sáu không "quá 24 giờ" vào 16:00 Thứ Bảy.
 *
 * Hai bậc, MỘT định nghĩa cho lịch nhắc ({@see RemindPendingWork}) và cho lần hỏi lại lúc gửi
 * ({@see SendWorkReminder}):
 *  - {@see self::REMIND_AFTER_HOURS} giờ làm việc: nhắc NGƯỜI NHẬN;
 *  - {@see self::ESCALATE_AFTER_HOURS} giờ làm việc: vẫn chưa nhận thì báo NGƯỜI GIAO ("sau đó báo người
 *    giao" — người nhận có một ngưỡng nữa để tự xử lý trước khi người giao được báo).
 */
final readonly class AcceptanceClock
{
    /** Bậc 1 — nhắc người nhận. */
    public const REMIND_AFTER_HOURS = 24;

    /** Bậc 2 — báo người giao. */
    public const ESCALATE_AFTER_HOURS = 48;

    public function __construct(public BusinessHours $hours) {}

    public static function fromConfig(): self
    {
        return new self(BusinessHours::fromConfig());
    }

    /** Lúc một lần giao `$assignedAt` chạm ngưỡng `$hours` giờ làm việc. */
    public function dueAt(CarbonInterface $assignedAt, int $hours): CarbonImmutable
    {
        return $this->hours->addHours($assignedAt, $hours);
    }

    /** Lần giao `$assignedAt` đã chờ tới (hay quá) ngưỡng `$hours` giờ làm việc lúc `$now`. */
    public function reached(CarbonInterface $assignedAt, int $hours, ?CarbonInterface $now = null): bool
    {
        return $this->dueAt($assignedAt, $hours)->lessThanOrEqualTo($now ?? now());
    }

    /**
     * Cận thô bằng giờ ĐỒNG HỒ cho SQL: mọi lần giao đã chạm ngưỡng `$hours` giờ làm việc đều có
     * `assigned_at` không muộn hơn lúc này trừ `$hours` giờ (giờ làm việc trôi chậm hơn hoặc bằng giờ đồng
     * hồ). Lọc thô rồi {@see self::reached()} giữ lại bản đã chạm thật — cùng cách `FirstResponseClock`.
     */
    public function coarseCutoff(int $hours, ?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now())->subHours($hours);
    }

    /** Số phút LÀM VIỆC đã chờ, từ `$assignedAt` tới `$now`. */
    public function waitedMinutes(CarbonInterface $assignedAt, ?CarbonInterface $now = null): int
    {
        return $this->hours->minutesBetween($assignedAt, $now ?? now());
    }
}
