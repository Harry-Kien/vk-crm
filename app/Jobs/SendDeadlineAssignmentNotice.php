<?php

namespace App\Jobs;

use App\Actions\Deadline\AddMatterDeadline;
use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Deadline\UpdateDeadline;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\OutboundStatus;
use App\Mail\Staff\DeadlineAssigned;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\DeadlineAssignedAlert;
use App\Support\ActivityPeople;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * "Anh/chị được giao một mốc thời hạn" (làn fa2, phán quyết 6) — chuông ({@see DeadlineAssignedAlert}) rồi thư
 * ({@see DeadlineAssigned}) cho người giữ mốc MỚI, khi người KHÁC giao mốc cho họ: {@see AddMatterDeadline} (người
 * phụ trách khác người thêm), {@see UpdateDeadline} (sửa mốc đổi người phụ trách), {@see ChangeDeadlineResponsible}.
 * Ba Action xếp job này `->afterCommit()`; payload chỉ mang id (mốc, người nhận, dòng nhật ký của lần giao).
 *
 * # Đọc lại mọi thứ khi chạy
 *
 * Mốc còn, chưa xong, vụ còn mở (`Matter::scopeOpen()` — cùng tập `CheckDeadlines` nhắc), người phụ trách VẪN là
 * người nhận (đã đổi tiếp thì lời báo của lần sau thay lời này), và người nhận qua
 * `ResolveStaffRecipients::qualifies()` — đang hoạt động, chưa xoá, còn mở được hồ sơ: vụ hạn chế không bao giờ
 * tới người không mở được nó. Không chuỗi dự phòng: lời báo nói với đúng một người.
 *
 * # Bậc nhắc của người giữ cũ
 *
 * `reminders_sent` thuộc về MỐC, không thuộc người: bậc đã gửi cho người cũ không gửi lại cho người mới ở lượt
 * {@see CheckDeadlines} kế tiếp. Lời báo này vì vậy mang đúng điều bậc gần nhất nói — hạn và số ngày còn lại tính
 * lúc gửi — và gửi NGAY một lần; các bậc đã qua không gửi lại, các bậc sau vẫn tới người mới như thường.
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `event@<id dòng nhật ký>`: chuông đã có `viewData.deadline_assignment_key` đó thì không gửi lại; thư đã có
 * dòng sổ thư `sent` cùng mẫu, cùng người, cùng `payload.tier` thì không gửi lại. Chuông đi trước thư.
 */
class SendDeadlineAssignmentNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $deadlineId,
        public readonly int $recipientId,
        public readonly int $eventId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $deadline = Deadline::query()->withoutGlobalScope(ClientPortalScope::class)->find($this->deadlineId);
        $recipient = User::query()->find($this->recipientId);
        $event = Activity::query()->find($this->eventId);

        if ($deadline === null || $recipient === null || $event === null || $deadline->is_completed) {
            return;
        }

        $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->open()->find($deadline->matter_id);

        if ($matter === null || $deadline->responsible_user_id !== $recipient->getKey() || ! $recipients->qualifies($recipient, $matter)) {
            return;
        }

        $deadline->setRelation('matter', $matter);
        $key = 'event@'.$this->eventId;
        $actorName = ActivityPeople::name($event->causer) ?? '';

        $bellSent = $recipient->notifications()
            ->where('type', DeadlineAssignedAlert::class)
            ->where('data->viewData->deadline_assignment_key', $key)
            ->exists();

        if (! $bellSent) {
            $recipient->notify(new DeadlineAssignedAlert($deadline, $actorName, $key));
        }

        $mailed = OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $deadline->getMorphClass())
            ->where('related_id', $deadline->getKey())
            ->where('template', 'staff.deadline_assigned')
            ->where('recipient', $recipient->email)
            ->where('payload->tier', $key)
            ->where('status', OutboundStatus::Sent)
            ->exists();

        if (! $mailed) {
            // Vòng sửa 1 (minor 6): mốc vừa THÊM (`deadline_added`) chưa có lời nhắc nào — thư không nói tới lời nhắc cũ.
            Mail::to($recipient->email)->send(new DeadlineAssigned($deadline, $recipient, $actorName, $key, $event->event === 'deadline_added'));
        }
    }
}
