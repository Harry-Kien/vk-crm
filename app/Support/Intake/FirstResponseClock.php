<?php

namespace App\Support\Intake;

use App\Models\IntakeRequest;
use App\Support\BusinessHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Đồng hồ phản hồi lần đầu (M10 R5): một lần có người liên hệ phải được phản hồi — rời `new`, xem
 * `first_response_at` — trong `config('vkcrm.intake_response_hours')` GIỜ LÀM VIỆC
 * ({@see BusinessHours}) kể từ `received_at`. MỘT định nghĩa "quá hạn phản hồi" cho ba nơi:
 * `RemindUnansweredIntakes` (chọn bản ghi để nhắc), `SendUnansweredIntakeReminderMail` (hỏi lại lúc
 * gửi — bản ghi được phản hồi giữa chừng thì không gửi), và widget "Liên hệ chưa ai gọi lại".
 *
 * "Còn chờ phản hồi" là {@see IntakeRequest::scopeAwaitingFirstResponse()} /
 * {@see IntakeRequest::isAwaitingFirstResponse()} — còn ở `new`, chưa ẩn danh. Đồng hồ không đọc
 * `first_response_at`: một bản ghi không quay lại `new` được, nên rời `new` là đã dừng đồng hồ.
 */
final readonly class FirstResponseClock
{
    public function __construct(
        public BusinessHours $hours,
        public int $thresholdHours,
    ) {}

    public static function fromConfig(): self
    {
        return new self(BusinessHours::fromConfig(), (int) config('vkcrm.intake_response_hours'));
    }

    /** Thời điểm bản ghi hết hạn phản hồi: `received_at` cộng ngưỡng, chỉ đếm giờ làm việc. */
    public function dueAt(IntakeRequest $intake): CarbonImmutable
    {
        return $this->hours->addHours($intake->received_at, $this->thresholdHours);
    }

    /** Bản ghi còn chờ phản hồi và đã tới hạn (đúng lúc tới hạn là quá hạn). */
    public function isOverdue(IntakeRequest $intake, ?CarbonInterface $now = null): bool
    {
        return $intake->isAwaitingFirstResponse()
            && $this->dueAt($intake)->lessThanOrEqualTo($now ?? now());
    }

    /**
     * Thu hẹp `$query` về các bản ghi còn chờ phản hồi và đã quá hạn (lúc `$now`). Giờ làm việc không
     * tính được bằng SQL, nên hai bước: SQL lọc thô theo giờ ĐỒNG HỒ (`received_at` cách đây ít nhất
     * ngưỡng — đúng với mọi bản quá hạn, vì giờ làm việc trôi chậm hơn hoặc bằng giờ đồng hồ, và dùng
     * được index `(status, received_at)`), rồi PHP giữ lại những bản đã quá hạn thật. Phạm vi người
     * xem (nếu có) là việc của `$query` truyền vào — hàm này không nới, chỉ thu hẹp.
     *
     * @template TModel of IntakeRequest
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function overdue(Builder $query, ?CarbonInterface $now = null): Builder
    {
        // Múi giờ văn phòng: `received_at` lưu theo `APP_TIMEZONE`, và tham số ngày giờ đi vào SQL
        // không được đổi múi.
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($this->hours->timezone);

        $ids = (clone $query)
            ->awaitingFirstResponse()
            ->where($query->getModel()->qualifyColumn('received_at'), '<=', $now->subHours($this->thresholdHours))
            ->get([$query->getModel()->qualifyColumn('id'), $query->getModel()->qualifyColumn('received_at')])
            ->filter(fn (IntakeRequest $intake): bool => $this->dueAt($intake)->lessThanOrEqualTo($now))
            ->modelKeys();

        return $query->whereKey($ids);
    }

    /** Số phút LÀM VIỆC bản ghi đã chờ, từ `received_at` tới `$now`. */
    public function waitedMinutes(IntakeRequest $intake, ?CarbonInterface $now = null): int
    {
        return $this->hours->minutesBetween($intake->received_at, $now ?? now());
    }

    /** "4 giờ 20 phút" / "4 giờ" / "35 phút" — cách thư, thông báo và widget nói một khoảng chờ. */
    public static function formatMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => __('intake.reminder.duration.minutes', ['minutes' => $rest]),
            $rest === 0 => __('intake.reminder.duration.hours', ['hours' => $hours]),
            default => __('intake.reminder.duration.hours_minutes', ['hours' => $hours, 'minutes' => $rest]),
        };
    }
}
