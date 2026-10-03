<?php

namespace App\Actions\Schedule;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Jobs\SendUnansweredIntakeReminderMail;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Notifications\Staff\IntakeUnansweredAlert;
use App\Support\BusinessHours;
use App\Support\Intake\FirstResponseClock;
use App\Support\Scopes\ClientPortalScope;
use Throwable;

/**
 * M10 R5 — nhắc những lần có người liên hệ văn phòng mà chưa ai gọi lại quá ngưỡng phản hồi (mặc
 * định 4 giờ LÀM VIỆC, `INTAKE_RESPONSE_HOURS`). "Một người gọi tới mà không ai gọi lại là doanh thu
 * đã mất và không ai biết là đã mất" — tác vụ này là chỗ văn phòng biết.
 *
 * **Lịch.** `routes/console.php` gọi tác vụ mỗi 15 phút, cả ngày; trước mọi truy vấn, tác vụ hỏi
 * {@see BusinessHours::isOpen()} và không làm gì ngoài giờ làm việc (Thứ Hai–Thứ Sáu 08:00–17:30
 * theo `APP_TIMEZONE`, tính cả 17:30). Giờ làm việc có MỘT định nghĩa (`config('vkcrm.business_hours')`);
 * một cron gõ tay theo giờ là định nghĩa thứ hai, sẽ lệch khi văn phòng đổi lịch. Ngày lễ không mô hình
 * hoá ở M10: một ngày lễ giữa tuần vẫn có nhắc.
 *
 * **Chọn bản ghi.** {@see FirstResponseClock::overdue()}: còn ở `new`, chưa ẩn danh, chưa xoá mềm, và
 * `received_at` cộng ngưỡng GIỜ LÀM VIỆC đã qua — một cuộc gọi 16:00 Thứ Sáu tới hạn 09:30 Thứ Hai,
 * không phải 20:00 Thứ Sáu. Mỗi bản ghi xử lý riêng, và một lỗi ở bản này không dừng các bản sau
 * (`report()` rồi đi tiếp — dưới hàng đợi `sync` một transport hỏng có thể ném ngược lên tới đây). Tác
 * vụ không đọc lại bản ghi trước khi xử lý: một lần gọi lại xen giữa vòng lặp chỉ có thể làm một thông
 * báo trong hệ thống đi ra cho một bản ghi vừa được gọi lại vài giây trước — còn thư thì job hỏi lại
 * điều kiện lúc gửi.
 *
 * **Người nhận:** {@see ResolveStaffRecipients::forIntake()} — người được giao, rồi người có
 * `intake.viewAny`, rồi admin; không bao giờ im lặng.
 *
 * **Hai kênh, mỗi kênh một lần cho mỗi (người nhận, bản ghi):**
 *  - thông báo trong hệ thống ({@see IntakeUnansweredAlert}), bỏ qua người đã có
 *    ({@see self::alreadyAlerted()}). Gửi TRƯỚC thư: nó không phụ thuộc máy chủ thư.
 *  - thư `staff.intake_unanswered` qua {@see SendUnansweredIntakeReminderMail} — job xếp hàng
 *    (`afterCommit()`: nếu ai đó gọi tác vụ này trong một transaction, job chỉ vào hàng đợi sau khi nó
 *    commit), chỉ khi còn ít nhất một người nhận chưa có dòng `sent` trong nhật ký thư
 *    ({@see SendUnansweredIntakeReminderMail::alreadyDelivered()}, M6 R3), và không khi thư của bản ghi
 *    đã hỏng hẳn hôm nay ({@see SendUnansweredIntakeReminderMail::failedForGoodToday()}). Job là
 *    `ShouldBeUnique` theo bản ghi, nên hai lượt chạy liền nhau không xếp hai job. Người được giao đổi
 *    sang người mới thì người mới nhận một lần; người đã nhận không nhận lại.
 * Tác vụ này không ghi gì vào `intake_requests` (không có cột "đã nhắc" — M6 R3), nên không mở
 * transaction nào: không có gì để giữ nguyên tử, và mọi lời gọi `->notify()` nằm ngoài transaction.
 */
class RemindUnansweredIntakes
{
    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{reminded: int} `reminded`: số bản ghi mà lượt này đã yêu cầu xếp một job thư (một job
     *                              trùng với job còn chờ của cùng bản ghi bị khoá `ShouldBeUnique` bỏ qua).
     */
    public function handle(): array
    {
        $clock = FirstResponseClock::fromConfig();
        $reminded = 0;

        if (! $clock->hours->isOpen(now())) {
            return ['reminded' => 0];
        }

        $overdue = $clock->overdue(IntakeRequest::query()->withoutGlobalScope(ClientPortalScope::class))
            ->orderBy('received_at')
            ->get();

        foreach ($overdue as $intake) {
            try {
                $reminded += $this->processOne($intake);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['reminded' => $reminded];
    }

    /** Một bản ghi — trả 1 khi đã yêu cầu xếp một job thư cho nó, 0 khi không. */
    private function processOne(IntakeRequest $intake): int
    {
        $recipients = app(ResolveStaffRecipients::class)->forIntake($intake);

        foreach ($recipients as $recipient) {
            // Mỗi người một `try`: một lần ghi hỏng cho người này không làm người khác mất thông báo.
            try {
                if (! $this->alreadyAlerted($recipient, $intake)) {
                    $recipient->notify(new IntakeUnansweredAlert($intake));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $someoneWaiting = $recipients->contains(
            fn (User $recipient): bool => ! SendUnansweredIntakeReminderMail::alreadyDelivered($intake, $recipient),
        );

        if (! $someoneWaiting || SendUnansweredIntakeReminderMail::failedForGoodToday($intake->getKey())) {
            return 0;
        }

        SendUnansweredIntakeReminderMail::dispatch($intake->getKey())->afterCommit();

        return 1;
    }

    /** Người này đã có thông báo trong hệ thống cho ĐÚNG bản ghi này chưa — khoá `viewData.intake_id`. */
    private function alreadyAlerted(User $recipient, IntakeRequest $intake): bool
    {
        return $recipient->notifications()
            ->where('type', IntakeUnansweredAlert::class)
            ->where('data->viewData->intake_id', $intake->getKey())
            ->exists();
    }
}
