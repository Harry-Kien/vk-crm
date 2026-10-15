<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\OutboundStatus;
use App\Enums\TaskStatus;
use App\Mail\Staff\TaskNotice;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Support\ActivityPeople;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Lời báo của MỘT lần chuyển trạng thái của một việc nội bộ (làn tk, Task 1) cho MỘT người: chuông
 * ({@see TaskAlert}) rồi thư ({@see TaskNotice}, trừ loại "huỷ" — chỉ chuông). Các Action xếp job này
 * `->afterCommit()` (M6.5 R2: thư không bao giờ gửi trong transaction).
 *
 * # Đọc lại mọi thứ khi chạy
 *
 * Job nhận id, không nhận model: khi nó chạy, việc có thể đã đổi. Ba lần lọc, theo thứ tự:
 *  1. việc còn (chưa xoá mềm) và dòng nhật ký của lần chuyển còn;
 *  2. lời "được giao" còn ĐÚNG: chỉ tới người ĐANG giữ việc khi việc còn chờ nhận (một việc vừa giao rồi
 *     giao lại ngay cho người khác không báo người đầu). Bốn loại còn lại không cần lần lọc này: chúng báo
 *     một sự kiện đã xảy ra, cho người giao (`assigned_by` không bao giờ đổi, kể cả khi giao lại) hay cho
 *     người giữ một việc đã huỷ (trạng thái cuối, không giao lại được);
 *  3. người nhận qua `ResolveStaffRecipients::forTask()` — đang hoạt động, chưa xoá, còn xem được việc.
 *
 * # Chống trùng khi thử lại
 *
 * Khoá là id dòng nhật ký: chuông đã có `viewData.task_event_id` đó thì không gửi lại; thư đã có dòng
 * nhật ký thư `sent` cùng mẫu, cùng người, cùng `payload.tier` = {@see TaskNotice::ledgerKey()} thì không
 * gửi lại. Chuông đi TRƯỚC thư: thư hỏng không làm mất chuông.
 */
class SendTaskNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $taskId,
        public readonly string $kind,
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
        $task = Task::query()->withoutGlobalScope(ClientPortalScope::class)->with('matter')->find($this->taskId);
        $event = Activity::query()->find($this->eventId);
        $recipient = User::query()->find($this->recipientId);

        if ($task === null || $event === null || $recipient === null || ! $this->stillRelevant($task)) {
            return;
        }

        $user = $recipients->forTask($task, [$recipient])->first();

        if ($user === null) {
            return;
        }

        $actorName = ActivityPeople::name($event->causer) ?? '';

        if (! $this->alreadyAlerted($user)) {
            $user->notify(new TaskAlert($task, $this->kind, $actorName, $this->eventId));
        }

        if ($this->kind === TaskAlert::CANCELLED || $this->alreadyDelivered($task, $user)) {
            return;
        }

        Mail::to($user->email)->send(new TaskNotice($task, $this->kind, $user, $actorName, $this->eventId));
    }

    /** Xem docblock lớp, mục "Đọc lại mọi thứ khi chạy", bước 2. */
    private function stillRelevant(Task $task): bool
    {
        return $this->kind !== TaskAlert::ASSIGNED
            || ($task->assignee_id === $this->recipientId && $task->status === TaskStatus::Pending);
    }

    private function alreadyAlerted(User $user): bool
    {
        return $user->notifications()
            ->where('type', TaskAlert::class)
            ->where('data->viewData->task_event_id', $this->eventId)
            ->exists();
    }

    private function alreadyDelivered(Task $task, User $user): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $task->getMorphClass())
            ->where('related_id', $task->getKey())
            ->where('template', TaskNotice::TEMPLATE_PREFIX.$this->kind)
            ->where('recipient', $user->email)
            ->where('payload->tier', TaskNotice::ledgerKey($this->eventId))
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }
}
