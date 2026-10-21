<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Push\SendPushAlert;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
use App\Enums\TaskStatus;
use App\Mail\Staff\TaskNotice;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Support\ActivityPeople;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Tasks\TaskChangeSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Lời báo của MỘT lần chuyển trạng thái của một việc nội bộ (làn tk, Task 1) cho MỘT người: chuông
 * ({@see TaskAlert}) rồi thư ({@see TaskNotice}) — chỉ các loại {@see self::MAILED_KINDS} có thư; "huỷ", "đã giao
 * cho người khác" và bốn lời báo người giao của làn fa1 ("người nhận tự mở lại", "người khác giao lại / huỷ / khôi
 * phục việc anh/chị giao") chỉ là chuông. Các Action xếp job này `->afterCommit()` (M6.5 R2: thư không bao giờ gửi trong
 * transaction).
 *
 * # Đọc lại mọi thứ khi chạy
 *
 * Job nhận id, không nhận model: khi nó chạy, việc có thể đã đổi. Ba lần lọc, theo thứ tự:
 *  1. việc còn (chưa xoá mềm) và dòng nhật ký của lần chuyển còn;
 *  2. lời báo còn ĐÚNG: lời "được giao" (và "được khôi phục", làn fa1) chỉ tới người ĐANG giữ việc khi việc còn
 *     chờ nhận (một việc vừa giao rồi giao lại ngay cho người khác không báo người đầu); lời "đã sửa" chỉ tới
 *     người đang giữ khi việc còn chờ nhận hay đang làm, lời "mở lại" khi việc còn đang làm; lời "đã giao cho
 *     người khác" chỉ tới người KHÔNG còn giữ việc (việc đã quay về họ trước khi job chạy thì họ đã có lời
 *     "được giao" mới). Các loại còn lại không cần lần lọc này: chúng báo một sự kiện đã xảy ra, cho người giao
 *     (`assigned_by` không đổi khi giao lại; chỉ đổi khi bàn giao vụ — `MoveTasksOnHandover::
 *     transferAssigner()` — và lời "bị trả lại" vì vậy đọc người giao HIỆN TẠI ở bước 3) hay cho người
 *     giữ một việc đã huỷ (trạng thái cuối, không giao lại được);
 *  3. người nhận qua `ResolveStaffRecipients::forTask()` — đang hoạt động, chưa xoá, còn xem được việc;
 *     riêng lời "đã giao cho người khác" qua `ResolveStaffRecipients::forFormerTaskHolder()` — đang hoạt
 *     động, chưa xoá (người cũ không còn xem được việc, và chuông của họ không mang gì của việc); và lời
 *     "bị trả lại" — từ làn fa1 cả "đã nhận", "đã hoàn thành", "người nhận tự mở lại" ({@see TaskAlert::ON_BEHALF_KINDS})
 *     — qua `ResolveStaffRecipients::forTaskAssigner()` (làn tk, Task 2) trên người giao LÚC
 *     CHẠY, không phải `$recipientId` lúc xếp: bàn giao vụ giữa lúc xếp và lúc chạy đã chuyển việc sang
 *     lead mới; người giao đã rời vụ, nghỉ việc hay bị vô hiệu thì luật sư phụ trách hiện tại của vụ nhận
 *     thay — một việc bị trả lại cần một người giao lại, không được rơi vào khoảng trống. Ba loại chỉ là tin
 *     ({@see TaskAlert::INFO_ONLY_KINDS}) dừng ở luật sư phụ trách, không rơi tới người quản lý của vụ
 *     (`forTaskAssigner(decisionNeeded: false)` — làn fa1, vòng sửa 1, m5).
 *
 * # Người nhận thay người giao (vòng sửa 1 của Task 2 — I1)
 *
 * Người nhận một lời báo {@see TaskAlert::ON_BEHALF_KINDS} không phải người giao (luật sư phụ trách nhận thay): chuông và thư mang tên
 * người giao (`ResolveStaffRecipients::assignerName()`) và dùng câu chữ, đường dẫn của người nhận thay
 * (`TaskAlert`/`TaskNotice`, `*_on_behalf`, tab "Công việc" của vụ) — họ không giao việc đó, và nó không
 * nằm ở "Tôi đã giao" của họ.
 *
 * # Chống trùng khi thử lại
 *
 * Khoá là id dòng nhật ký: chuông đã có `viewData.task_event_id` đó thì không gửi lại; thư đã có dòng
 * nhật ký thư `sent` cùng mẫu, cùng người, cùng `payload.tier` = {@see TaskNotice::ledgerKey()} thì không
 * gửi lại. Chuông đi TRƯỚC thư: thư hỏng không làm mất chuông.
 *
 * # Lý do của lần đó (vòng sửa polish 1)
 *
 * Lời "bị trả lại", "huỷ" — và từ làn fa1 "mở lại", "khôi phục", "người khác huỷ / khôi phục việc anh/chị giao" — mang lý
 * do của CHÍNH lần chuyển đó — đọc từ dòng nhật ký `$eventId` (`properties.reason`, `DeclineTask`/`CancelTask`/
 * `ReopenTask`/`RestoreTask` ghi), không từ dòng việc lúc job chạy: người giao có thể đã bấm "Giao lại" trước
 * khi job chạy, và `ReassignTask` xoá `decline_reason` trên dòng việc.
 *
 * # Điều đã đổi (làn fa1)
 *
 * Lời "đã sửa" nêu điều đã đổi của CHÍNH lần sửa đó (`TaskChangeSummary::fromEvent()` trên `properties.old`/
 * `new`/`changed` của dòng `task_updated`), không so dòng việc lúc chạy. Lời báo người giao gốc về một lần giao
 * lại mang tên người nhận mới của lần đó (`properties.assignee_id`).
 *
 * # Thông báo đẩy (vòng sửa 1, I2 — M12 R10)
 *
 * Chỉ lời "được giao" đẩy lên điện thoại (`PushTopic::StaffTaskAssigned`, một câu chung — không tiêu đề
 * việc, không mã vụ). {@see SendPushAlert} nhận ĐÚNG người mà CHÍNH lượt chạy này vừa gửi thư thành công
 * (cùng luật `SendHandoverPackageReady`): sau thư, nên thư ném lỗi thì không đẩy (lượt thử lại gửi thư rồi
 * mới đẩy); thư đã có trong sổ thì lượt này dừng trước cả thư lẫn push — chống trùng của push là sổ thư.
 * `SendPushAlert` không ném vì lỗi lúc chạy. Ba loại báo người giao không đẩy (SPEC §9, bảng R10).
 */
class SendTaskNotice implements ShouldQueue
{
    use Queueable;

    /** Các loại có thư (`staff.task.<loại>`); mọi loại khác chỉ là chuông. */
    public const MAILED_KINDS = [
        TaskAlert::ASSIGNED, TaskAlert::ACCEPTED, TaskAlert::DECLINED, TaskAlert::COMPLETED,
        TaskAlert::UPDATED, TaskAlert::REOPENED, TaskAlert::RESTORED,
    ];

    /** Các loại mang lý do của chính lần chuyển (`properties.reason` của dòng nhật ký). */
    private const REASON_KINDS = [
        TaskAlert::DECLINED, TaskAlert::CANCELLED, TaskAlert::CANCELLED_BY_OTHER, TaskAlert::REOPENED, TaskAlert::RESTORED,
        TaskAlert::RESTORED_BY_OTHER,
    ];

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
        // `withTrashed()`: người giao đã bị xoá mềm vẫn cần được nhận ra, để lời "bị trả lại" đi tới người
        // thay họ (bước 3). Ba bộ lọc dưới đây đều bỏ người đã xoá mềm.
        $recipient = User::query()->withTrashed()->find($this->recipientId);

        if ($task === null || $event === null || $recipient === null || ! $this->stillRelevant($task)) {
            return;
        }

        // Kiểm toán 2026-10-21 (N1): MỌI người của `forTaskAssigner()` — cùng tập với lời nhắc người giao của lịch
        // (`RemindPendingWork::taskAssignerAudience()`). Trước bản sửa `->first()`: "bị trả lại" rơi tới người quản lý của
        // vụ chỉ tới trưởng phòng đầu tiên, trong khi lời nhắc "chưa được nhận"/"quá hạn" của cùng việc tới cả nhóm. Các
        // tập khác có tối đa một người (`forTask([$recipient])`, `forFormerTaskHolder([$recipient])`).
        $users = match (true) {
            $this->kind === TaskAlert::REASSIGNED_AWAY => $recipients->forFormerTaskHolder([$recipient]),
            in_array($this->kind, TaskAlert::ON_BEHALF_KINDS, true) => $recipients->forTaskAssigner(
                $task,
                decisionNeeded: ! in_array($this->kind, TaskAlert::INFO_ONLY_KINDS, true),
            ),
            default => $recipients->forTask($task, [$recipient]),
        };

        $actorName = ActivityPeople::name($event->causer) ?? '';

        foreach ($users as $user) {
            $this->deliver($task, $event, $user, $actorName, $recipients);
        }
    }

    /** Chuông rồi thư (và push của lời "được giao") cho MỘT người — xem docblock lớp. */
    private function deliver(Task $task, Activity $event, User $user, string $actorName, ResolveStaffRecipients $recipients): void
    {
        // Xem docblock lớp, mục "Người nhận thay người giao".
        $onBehalfOf = in_array($this->kind, TaskAlert::ON_BEHALF_KINDS, true) && $user->getKey() !== $task->assigned_by
            ? $recipients->assignerName($task)
            : null;

        // Xem docblock lớp, mục "Lý do của lần đó".
        $reason = in_array($this->kind, self::REASON_KINDS, true)
            ? (string) $event->getExtraProperty('reason', '')
            : null;

        $details = $this->details($event);

        if (! $this->alreadyAlerted($user)) {
            $user->notify(new TaskAlert($task, $this->kind, $actorName, $this->eventId, $onBehalfOf, $reason, $details));
        }

        if (! in_array($this->kind, self::MAILED_KINDS, true) || $this->alreadyDelivered($task, $user)) {
            return;
        }

        Mail::to($user->email)->send(new TaskNotice($task, $this->kind, $user, $actorName, $this->eventId, $onBehalfOf, $reason, $details));

        // Xem docblock lớp, mục "Thông báo đẩy".
        if ($this->kind === TaskAlert::ASSIGNED) {
            app(SendPushAlert::class)->handle([$user], PushTopic::StaffTaskAssigned, $task);
        }
    }

    /** Xem docblock lớp, mục "Đọc lại mọi thứ khi chạy", bước 2. */
    private function stillRelevant(Task $task): bool
    {
        $holds = fn (TaskStatus ...$states): bool => $task->assignee_id === $this->recipientId && in_array($task->status, $states, true);

        return match ($this->kind) {
            TaskAlert::ASSIGNED, TaskAlert::RESTORED => $holds(TaskStatus::Pending),
            TaskAlert::UPDATED => $holds(TaskStatus::Pending, TaskStatus::Accepted),
            TaskAlert::REOPENED => $holds(TaskStatus::Accepted),
            TaskAlert::REASSIGNED_AWAY => $task->assignee_id !== $this->recipientId,
            default => true,
        };
    }

    /**
     * Chữ thêm của lần đó cho chuông và thư (xem docblock lớp, mục "Điều đã đổi"): điều đã đổi của một lần
     * "Sửa"; tên người nhận mới của một lần giao lại báo người giao gốc.
     *
     * @return array<string, string>
     */
    private function details(Activity $event): array
    {
        return match ($this->kind) {
            TaskAlert::UPDATED, TaskAlert::UPDATED_BY_OTHER => ['changes' => TaskChangeSummary::fromEvent($event)],
            TaskAlert::REASSIGNED_BY_OTHER => ['assignee' => (string) User::query()->withTrashed()
                ->whereKey((int) $event->getExtraProperty('assignee_id'))->value('name')],
            default => [],
        };
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
