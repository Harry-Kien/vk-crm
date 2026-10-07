<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindUnansweredIntakes;
use App\Enums\OutboundStatus;
use App\Mail\Staff\IntakeUnanswered;
use App\Models\IntakeRequest;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Support\Audit;
use App\Support\Intake\FirstResponseClock;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Gửi thư `staff.intake_unanswered` cho MỘT bản ghi tiếp nhận quá hạn phản hồi (M10 R5) — tách khỏi
 * {@see RemindUnansweredIntakes} theo M6.5 R2 ("mọi thư qua hàng đợi, sau khi commit"), cùng khuôn
 * `SendDeadlineReminderMail`.
 *
 * **Payload chỉ mang id bản ghi** (SPEC §10.5). Lúc THẬT SỰ chạy, job đọc lại mọi thứ: bản ghi còn chờ
 * phản hồi và đã quá hạn không ({@see FirstResponseClock::isOverdue()} — được gọi lại, từ chối, chuyển
 * đổi, gộp, ẩn danh hay xoá giữa lúc xếp hàng và lúc chạy thì không gửi gì), rồi tính lại TOÀN BỘ người
 * nhận ({@see ResolveStaffRecipients::forIntake()}) — không tin ảnh chụp nào lúc xếp hàng.
 *
 * **Chống trùng: nhật ký thư là trí nhớ (M6 R3).** Mỗi người nhận một thư cho mỗi bản ghi, trọn đời
 * bản ghi ở `new`: {@see self::alreadyDelivered()} hỏi `outbound_messages` theo mẫu + bản ghi liên quan
 * + người nhận, CHỈ dòng `sent` — một thư hỏng vẫn là một dòng (`failed`) và không được chặn lần thử
 * sau. Nhờ vậy job thử lại sau khi người thứ hai hỏng không gửi lại cho người thứ nhất, và chạy tác vụ
 * hai lần không sinh thư thứ hai (M6 R4). Không có cột "đã nhắc" nào trên `intake_requests`.
 *
 * **`ShouldBeUnique` theo id bản ghi.** Tác vụ nhắc chạy mỗi 15 phút, còn một job hỏng thoáng qua thử
 * lại tới năm lần trong khoảng 80 phút (`backoff()`): không có khoá này, mỗi lượt chạy trong lúc
 * máy chủ thư chập chờn xếp thêm một job cho cùng bản ghi (chưa có dòng `sent` nào để chặn), và các
 * job đó cùng thử lại. Khoá giữ từ lúc xếp hàng tới lúc job xong hoặc hỏng hẳn (Laravel nhả nó ở cả
 * hai đường, và nhả khi transaction bao lần xếp hàng rollback), tối đa `$uniqueFor` giây nếu tiến
 * trình chết giữa chừng.
 *
 * **Hỏng hẳn** (hết `$tries`): {@see self::failed()} ghi dòng nhật ký `intake_reminder_failed`, và
 * {@see self::failedForGoodToday()} để tác vụ nhắc không xếp lại bản ghi đó trong ngày — lượt đầu
 * của ngày làm việc hôm sau thử lại (cùng luật `SendDeadlineReminderMail::failedForGoodToday()`). Người
 * nhận không bị bỏ trong bóng tối: thông báo trong hệ thống (`IntakeUnansweredAlert`) đã được gửi lúc
 * chọn bản ghi, không phụ thuộc thư, và widget "Liên hệ chưa ai gọi lại" vẫn hiện bản ghi.
 */
class SendUnansweredIntakeReminderMail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Hai giờ — dài hơn trọn chuỗi thử lại của `backoff()` (~81 phút) cộng thời gian chạy. */
    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $intakeId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->intakeId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $intake = IntakeRequest::query()->withoutGlobalScope(ClientPortalScope::class)->find($this->intakeId);

        if ($intake === null || ! FirstResponseClock::fromConfig()->isOverdue($intake)) {
            return;
        }

        $failure = null;

        foreach (app(ResolveStaffRecipients::class)->forIntake($intake) as $recipient) {
            if (self::alreadyDelivered($intake, $recipient)) {
                continue;
            }

            // Một người nhận hỏng không chặn những người sau; giữ lỗi ĐẦU TIÊN, thử hết, rồi ném lại để
            // hàng đợi thấy job hỏng (thử lại, rồi `failed()`) — cùng hình dạng `SendDeadlineReminderMail`.
            try {
                Mail::to($recipient->email)->send(new IntakeUnanswered($intake, $recipient));
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Người này đã nhận thư nhắc cho ĐÚNG bản ghi này chưa — dòng `outbound_messages` mẫu
     * `staff.intake_unanswered`, bản ghi liên quan là bản ghi tiếp nhận, người nhận là địa chỉ của họ,
     * trạng thái `sent` (M6 R3). Tác vụ nhắc hỏi cùng hàm này để khỏi xếp một job không còn gì để gửi.
     */
    public static function alreadyDelivered(IntakeRequest $intake, User $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('template', IntakeUnanswered::TEMPLATE)
            ->where('related_type', $intake->getMorphClass())
            ->where('related_id', $intake->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }

    /**
     * Chạy đúng một lần, khi mọi lượt thử đều hỏng. Dòng nhật ký chỉ mang mẫu thư — không địa chỉ,
     * không gì của người liên hệ, không văn bản lỗi (SPEC §10.5; lý do lỗi đã nằm ở
     * `outbound_messages.error` của lượt thử cuối).
     */
    public function failed(?Throwable $exception): void
    {
        $intake = IntakeRequest::query()->withoutGlobalScope(ClientPortalScope::class)->withTrashed()->find($this->intakeId);

        Audit::record('intake_reminder_failed', $intake, ['template' => IntakeUnanswered::TEMPLATE]);
    }

    /**
     * Thư nhắc của bản ghi này đã hỏng hẳn trong NGÀY HÔM NAY chưa — đọc dòng `intake_reminder_failed`
     * mà {@see self::failed()} ghi. Tác vụ nhắc chạy mỗi 15 phút; không có câu hỏi này, một máy chủ thư
     * chết cả ngày sinh một chuỗi năm lượt thử cho mỗi bản ghi cứ sau mỗi lần chuỗi trước hỏng hẳn.
     */
    public static function failedForGoodToday(int $intakeId): bool
    {
        return Activity::query()
            ->where('event', 'intake_reminder_failed')
            ->where('subject_type', (new IntakeRequest)->getMorphClass())
            ->where('subject_id', $intakeId)
            ->where('created_at', '>=', today()->startOfDay())
            ->exists();
    }
}
