<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Mail\Staff\TaskReminder;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskReminderAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * MỘT lời nhắc VIỆC GỘP của lịch {@see RemindPendingWork} (kiểm toán luồng Công việc 2026-10-21, Q3 — phán quyết làn tk
 * "nhắc/leo thang một thư mỗi người mỗi lượt", lời nhắc hồ sơ đã gộp từ vòng sửa polish 1 ở {@see SendSeatReminderDigest}):
 * cùng một loại nhắc cho NHIỀU việc của cùng một người nhận trong một lượt lịch — một chuông
 * ({@see TaskReminderAlert} với `$batch`) và một thư (cùng mẫu `staff.task.reminder.<loại>`, {@see TaskReminder} với
 * `$batch`) liệt kê tiêu đề, mã hồ sơ, hạn, người giữ của từng việc, thay vì một chuông và một thư cho mỗi việc.
 *
 * # Hỏi lại từng việc lúc gửi
 *
 * Cùng các lần lọc của {@see SendWorkReminder} cho từng việc: lời nhắc còn đúng với khoá của nó
 * ({@see RemindPendingWork::taskReminderHolds()}), người nhận còn xem được việc (`ResolveStaffRecipients::forTask()` —
 * một việc của vụ hạn chế không bao giờ hiện tiêu đề cho người không mở được vụ), còn là người lời nhắc nói tới
 * ({@see RemindPendingWork::stillAddressedTo()}), thư của việc đó chưa gửi ({@see SendWorkReminder::alreadyMailed()}).
 * Không còn việc nào: không gửi gì. Còn MỘT việc: chạy đúng lời nhắc một việc ({@see SendWorkReminder}, cùng khoá, cùng
 * câu chữ). Nhiều việc: chuông rồi thư gộp.
 *
 * # Chống trùng
 *
 * Chuông: `viewData.reminder_key` = `tasks:<loại>:<sha1 khoá thư>`. Thư: `payload.tier` = khoá đầy đủ của từng việc
 * (`SendWorkReminder::fullKey()`) nối bằng `|` ({@see SendSeatReminderDigest::ledgerKey()}); `SendWorkReminder::
 * alreadyMailed()` tìm khoá của một việc trong đó, nên lượt lịch sau không xếp lại việc nào đã nằm trong một thư gộp.
 *
 * # Hỏng hẳn
 *
 * {@see self::failed()} ghi `work_reminder_failed` cho TỪNG việc của job (`SendWorkReminder::failed()`), nên lịch bỏ qua
 * chúng tới hết ngày ({@see SendWorkReminder::failedForGoodToday()}) — một chuỗi thử mỗi ngày.
 *
 * Không thông báo đẩy: lời nhắc việc không đẩy (họ `staff.task.*`, M12).
 */
class SendTaskReminderDigest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Cùng lý do {@see SendWorkReminder::$uniqueFor}. */
    public int $uniqueFor = 7200;

    /**
     * @param  string  $kind  một loại nhắc việc của {@see RemindPendingWork}
     * @param  array<int, string>  $tasks  `id việc => khoá nhắc`
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $recipientId,
        public readonly array $tasks,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        $tasks = $this->tasks;
        ksort($tasks);

        return 'tasks:'.$this->kind.'#'.$this->recipientId.'#'.sha1((string) json_encode($tasks));
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $recipient = User::query()->find($this->recipientId);

        if ($recipient === null || $this->tasks === []) {
            return;
        }

        $rows = $this->rowsStillDue($recipient, $recipients);

        if ($rows->isEmpty()) {
            return;
        }

        if ($rows->count() === 1) {
            $row = $rows->first();
            (new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, (int) $row['task']->getKey(), $this->kind, $this->recipientId, $row['key']))
                ->handle($recipients);

            return;
        }

        $ledgerKey = SendSeatReminderDigest::ledgerKey($rows->map(fn (array $row): string => SendWorkReminder::fullKey(
            RemindPendingWork::SUBJECT_TASK, (int) $row['task']->getKey(), $this->kind, $row['key'],
        ))->all());
        $bellKey = 'tasks:'.$this->kind.':'.sha1($ledgerKey);
        $batch = $rows->map(fn (array $row): array => $this->line($row['task'], $recipient, $recipients))->all();
        /** @var Task $first */
        $first = $rows->first()['task'];

        $alerted = $recipient->notifications()
            ->where('type', TaskReminderAlert::class)
            ->where('data->viewData->reminder_key', $bellKey)
            ->exists();

        if (! $alerted) {
            $recipient->notify(new TaskReminderAlert($first, $this->kind, $bellKey, '', batch: $batch));
        }

        Mail::to($recipient->email)->send(new TaskReminder($first, $this->kind, $recipient, '', $ledgerKey, batch: $batch));
    }

    /** Xem docblock lớp, mục "Hỏng hẳn". */
    public function failed(?Throwable $exception): void
    {
        foreach ($this->tasks as $taskId => $key) {
            (new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, (int) $taskId, $this->kind, $this->recipientId, $key))
                ->failed($exception);
        }
    }

    /**
     * Các việc của job mà lời nhắc còn phải gửi lúc này — xem docblock lớp, mục "Hỏi lại từng việc lúc gửi" — theo hạn
     * (việc không hạn cuối), rồi tiêu đề.
     *
     * @return Collection<int, array{task: Task, key: string}>
     */
    private function rowsStillDue(User $recipient, ResolveStaffRecipients $recipients): Collection
    {
        return Task::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withLastRestoredAt()
            ->with(['matter', 'assignee'])
            ->whereKey(array_keys($this->tasks))
            ->get()
            ->map(function (Task $task) use ($recipient, $recipients): ?array {
                $key = $this->tasks[$task->getKey()];

                if (! RemindPendingWork::taskReminderHolds($task, $this->kind, $key)
                    || $recipients->forTask($task, [$recipient])->isEmpty()
                    || ! RemindPendingWork::stillAddressedTo($task, $this->kind, $recipient)
                    || SendWorkReminder::alreadyMailed($task, $recipient, RemindPendingWork::SUBJECT_TASK, $this->kind, $key)) {
                    return null;
                }

                return ['task' => $task, 'key' => $key];
            })
            ->filter()
            ->sortBy(fn (array $row): string => ($row['task']->due_date?->toDateString() ?? '9999-12-31').'|'.$row['task']->title)
            ->values();
    }

    /**
     * Một dòng của chuông và thư gộp — chỉ chữ của một việc người nhận đã qua `forTask()` (xem được việc).
     *
     * @return array{title: string, code: ?string, due: ?string, assignee: string, detail: string, assigner: ?string, reassign: bool}
     */
    private function line(Task $task, User $recipient, ResolveStaffRecipients $recipients): array
    {
        $assignerKind = RemindPendingWork::isAssignerKind($this->kind);

        return [
            'title' => (string) $task->title,
            'code' => $task->matter?->code,
            'due' => $task->due_date?->format('d/m/Y'),
            'assignee' => (string) $task->assignee?->name,
            'detail' => SendWorkReminder::detailFor($this->kind, $task),
            // Người nhận thay người giao (như `SendWorkReminder`): dòng ghi "do … giao".
            'assigner' => $assignerKind && $recipient->getKey() !== $task->assigned_by ? $recipients->assignerName($task) : null,
            'reassign' => $assignerKind && Task::query()->withoutGlobalScope(ClientPortalScope::class)
                ->whereKey($task->getKey())->needsReassignment()->exists(),
        ];
    }
}
