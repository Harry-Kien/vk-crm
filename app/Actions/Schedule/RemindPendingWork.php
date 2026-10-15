<?php

namespace App\Actions\Schedule;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\MatterAssignmentStatus;
use App\Enums\TaskStatus;
use App\Jobs\SendWorkReminder;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Tasks\AcceptanceClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Lịch nhắc của luồng giao – nhận nội bộ (làn tk, Task 2; tác vụ `tasks.remind` của `routes/console.php`,
 * mỗi 30 phút, chỉ trong giờ làm việc). Ba loại nhắc (năm lời nhắc), mỗi lời MỘT lần cho mỗi (việc hay hồ sơ, người
 * nhận, khoá nhắc):
 *
 *  1. **Chờ nhận quá lâu** — một việc "Chờ nhận", hay một hồ sơ (ghế trong đội ngũ vụ) chưa bấm "Đã nhận
 *     hồ sơ": sau {@see AcceptanceClock::REMIND_AFTER_HOURS} giờ làm việc nhắc người nhận
 *     (`reminder.awaiting`); sau {@see AcceptanceClock::ESCALATE_AFTER_HOURS} giờ làm việc vẫn chưa nhận
 *     thì báo người giao (`reminder.unaccepted`). Khoá: `awaiting@<assigned_at>` — giao lại là một lần
 *     giao mới, đồng hồ chạy lại.
 *  2. **Sắp đến hạn** — việc chờ nhận hay đang làm có hạn là hôm nay hoặc ngày mai: nhắc người nhận MỘT lần
 *     cho mỗi hạn (`reminder.due_soon`, khoá `due-soon@<hạn>`). Cửa sổ hai ngày, không đúng "ngày mai":
 *     tác vụ không chạy ngoài giờ làm việc, nên một hạn Thứ Hai vẫn được nhắc sáng Thứ Hai khi Chủ nhật
 *     không có lượt nào. Dời hạn là một hạn mới — nhắc lại cho hạn mới.
 *  3. **Quá hạn** — việc chờ nhận hay đang làm đã qua hạn: mỗi ngày làm việc một lần, nhắc người nhận
 *     (`reminder.overdue`) và báo người giao (`reminder.overdue_assigner`), khoá `overdue@<hôm nay>`.
 *
 * Việc "Bị trả lại" không nhắc (người giao đã được báo, nó nằm ở "Tôi đã giao"); việc của vụ đã kết thúc
 * hay đã huỷ không nhắc (không còn việc gì để làm trên vụ — người giao huỷ hay hoàn tất nó).
 *
 * # Người nhận — không bao giờ lộ vụ hạn chế
 *
 *  - việc: người nhận qua `ResolveStaffRecipients::forTask()` (đang hoạt động, chưa xoá, còn XEM ĐƯỢC
 *    việc — tức xem được vụ); lời báo cho người giao qua `ResolveStaffRecipients::forTaskAssigner()` (người
 *    giao đã nghỉ, rời vụ thì luật sư phụ trách hiện tại của vụ);
 *  - hồ sơ: người nhận qua `ResolveStaffRecipients::qualifies()`; lời báo người giao qua
 *    {@see self::seatAssignerAudience()} — người giao, hay chuỗi dự phòng của `ResolveStaffRecipients`,
 *    không bao giờ chính người được giao.
 *
 * # Chống trùng, chống chạy chồng
 *
 * Lượt này chỉ CHỌN và XẾP job ({@see SendWorkReminder}, `afterCommit`); job hỏi lại MỌI điều kiện lúc gửi
 * ({@see self::taskReminderHolds()}, {@see self::seatReminderHolds()} — MỘT định nghĩa cho cả hai) và chống
 * trùng bằng chuông đã có cùng khoá và sổ thư (`outbound_messages`, `payload.tier` = khoá, M6 R3). Lượt này
 * bỏ qua người đã có thư `sent` cùng khoá ({@see SendWorkReminder::alreadyMailed()}), nên chạy lại cả ngày
 * không xếp lại. `withoutOverlapping(30)` ở lịch. Mỗi việc/hồ sơ một `try`: một lỗi không dừng lượt nhắc.
 */
class RemindPendingWork
{
    public const SUBJECT_TASK = 'task';

    public const SUBJECT_SEAT = 'seat';

    public const AWAITING = 'reminder.awaiting';

    public const UNACCEPTED = 'reminder.unaccepted';

    public const DUE_SOON = 'reminder.due_soon';

    public const OVERDUE = 'reminder.overdue';

    public const OVERDUE_ASSIGNER = 'reminder.overdue_assigner';

    public function __invoke(): array
    {
        return $this->handle();
    }

    /** @return array{queued: int} số lời nhắc (người × việc/hồ sơ) lượt này đã xếp */
    public function handle(): array
    {
        $clock = AcceptanceClock::fromConfig();

        if (! $clock->hours->isOpen(now())) {
            return ['queued' => 0];
        }

        $queued = 0;

        foreach ($this->awaitingTasks($clock) as $task) {
            $queued += $this->guarded(fn (): int => $this->remindAwaitingTask($task, $clock));
        }

        foreach ($this->awaitingSeats($clock) as $seat) {
            $queued += $this->guarded(fn (): int => $this->remindAwaitingSeat($seat, $clock));
        }

        $dueSoon = self::activeTasks()
            ->whereDate('tasks.due_date', '>=', today()->toDateString())
            ->whereDate('tasks.due_date', '<=', today()->addDay()->toDateString());

        foreach ($dueSoon->get() as $task) {
            $queued += $this->guarded(fn (): int => $this->queueForTask($task, self::DUE_SOON, self::dueSoonKey($task), [$task->assignee]));
        }

        foreach (self::activeTasks()->overdue()->get() as $task) {
            $queued += $this->guarded(fn (): int => $this->queueForTask($task, self::OVERDUE, self::overdueKey(), [$task->assignee])
                + $this->queueForTask($task, self::OVERDUE_ASSIGNER, self::overdueKey(), $this->taskAssignerAudience($task)));
        }

        return ['queued' => $queued];
    }

    /**
     * Lời nhắc `$kind` với khoá `$key` còn đúng cho việc `$task` lúc `$now` không — MỘT định nghĩa cho lượt
     * chọn và cho lần hỏi lại lúc gửi ({@see SendWorkReminder}).
     */
    public static function taskReminderHolds(Task $task, string $kind, string $key, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now());

        if ($task->trashed() || ! self::onLiveMatter($task)) {
            return false;
        }

        return match ($kind) {
            self::AWAITING, self::UNACCEPTED => $task->status === TaskStatus::Pending
                && $task->assigned_at !== null
                && $key === self::awaitingKey($task->assigned_at)
                && AcceptanceClock::fromConfig()->reached($task->assigned_at, self::thresholdFor($kind), $now),
            self::DUE_SOON => self::isActive($task)
                && $task->due_date !== null
                && $key === self::dueSoonKey($task)
                && $task->due_date->toDateString() >= $now->toDateString(),
            self::OVERDUE, self::OVERDUE_ASSIGNER => self::isActive($task)
                && $task->isOverdue()
                && $key === self::overdueKey($now),
            default => false,
        };
    }

    /** Như {@see self::taskReminderHolds()}, cho một ghế trong đội ngũ vụ (chỉ hai loại chờ nhận). */
    public static function seatReminderHolds(MatterUser $seat, string $kind, string $key, ?CarbonInterface $now = null): bool
    {
        return in_array($kind, [self::AWAITING, self::UNACCEPTED], true)
            && $seat->assignmentStatus() === MatterAssignmentStatus::Pending
            && $seat->assigned_at !== null
            && $key === self::awaitingKey($seat->assigned_at)
            && AcceptanceClock::fromConfig()->reached($seat->assigned_at, self::thresholdFor($kind), $now);
    }

    public static function awaitingKey(CarbonInterface $assignedAt): string
    {
        return 'awaiting@'.$assignedAt->format('Y-m-d\TH:i:s');
    }

    public static function dueSoonKey(Task $task): string
    {
        return 'due-soon@'.$task->due_date?->toDateString();
    }

    public static function overdueKey(?CarbonInterface $now = null): string
    {
        return 'overdue@'.CarbonImmutable::instance($now ?? now())->toDateString();
    }

    /** Ngưỡng giờ làm việc của một loại nhắc chờ nhận. */
    public static function thresholdFor(string $kind): int
    {
        return $kind === self::UNACCEPTED ? AcceptanceClock::ESCALATE_AFTER_HOURS : AcceptanceClock::REMIND_AFTER_HOURS;
    }

    /**
     * Người nhận lời báo "cho người giao" của một việc — `ResolveStaffRecipients::forTaskAssigner()`: người
     * giao nếu còn hợp lệ, không thì luật sư phụ trách hiện tại của vụ.
     *
     * @return list<User>
     */
    private function taskAssignerAudience(Task $task): array
    {
        return app(ResolveStaffRecipients::class)->forTaskAssigner($task)->all();
    }

    /** @return Collection<int, Task> */
    private function awaitingTasks(AcceptanceClock $clock): Collection
    {
        return self::liveTasks()
            ->where('tasks.status', TaskStatus::Pending->value)
            ->where('tasks.assigned_at', '<=', $clock->coarseCutoff(AcceptanceClock::REMIND_AFTER_HOURS))
            ->get()
            ->filter(fn (Task $task): bool => $clock->reached($task->assigned_at, AcceptanceClock::REMIND_AFTER_HOURS));
    }

    private function remindAwaitingTask(Task $task, AcceptanceClock $clock): int
    {
        $key = self::awaitingKey($task->assigned_at);
        $queued = $this->queueForTask($task, self::AWAITING, $key, [$task->assignee]);

        if ($clock->reached($task->assigned_at, AcceptanceClock::ESCALATE_AFTER_HOURS)) {
            $queued += $this->queueForTask($task, self::UNACCEPTED, $key, $this->taskAssignerAudience($task));
        }

        return $queued;
    }

    /** @return Collection<int, MatterUser> */
    private function awaitingSeats(AcceptanceClock $clock): Collection
    {
        return MatterUser::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->awaitingAcceptance()
            ->whereNotNull('matter_user.assigned_at')
            ->where('matter_user.assigned_at', '<=', $clock->coarseCutoff(AcceptanceClock::REMIND_AFTER_HOURS))
            ->whereHas('matter', fn (Builder $matter): Builder => $matter->withoutGlobalScope(ClientPortalScope::class)->open())
            ->with(['matter.leadLawyer', 'user', 'assigner'])
            ->orderBy('matter_user.id')
            ->get()
            ->filter(fn (MatterUser $seat): bool => $clock->reached($seat->assigned_at, AcceptanceClock::REMIND_AFTER_HOURS));
    }

    private function remindAwaitingSeat(MatterUser $seat, AcceptanceClock $clock): int
    {
        $resolver = app(ResolveStaffRecipients::class);
        $matter = $seat->matter;
        $key = self::awaitingKey($seat->assigned_at);
        $queued = 0;

        if ($seat->user !== null && $resolver->qualifies($seat->user, $matter)) {
            $queued += $this->queue(self::SUBJECT_SEAT, (int) $seat->getKey(), self::AWAITING, $key, $seat->user, $matter);
        }

        if ($clock->reached($seat->assigned_at, AcceptanceClock::ESCALATE_AFTER_HOURS)) {
            foreach ($this->seatAssignerAudience($seat) as $user) {
                $queued += $this->queue(self::SUBJECT_SEAT, (int) $seat->getKey(), self::UNACCEPTED, $key, $user, $matter);
            }
        }

        return $queued;
    }

    /**
     * Người nhận lời báo "hồ sơ chưa được nhận" — không bao giờ chính người chưa nhận:
     * `ResolveStaffRecipients::handle()` với người giao làm người ưu tiên (đã nghỉ, mất quyền xem vụ thì
     * chuỗi dự phòng: luật sư phụ trách → quản lý → admin được xem vụ). Chuỗi dự phòng dừng ở người hợp lệ
     * ĐẦU TIÊN, và đó có thể là chính người đang chờ nhận (ghế luật sư phụ trách vừa bàn giao): khi đó người
     * giám sát vụ (`supervisorsFor()` — quản lý, hay admin với vụ hạn chế) nhận thay.
     *
     * @return Collection<int, User>
     */
    private function seatAssignerAudience(MatterUser $seat): Collection
    {
        $resolver = app(ResolveStaffRecipients::class);
        $notMember = fn (User $user): bool => $user->getKey() !== $seat->user_id;

        $audience = $resolver->handle($seat->matter, [$seat->assigner])->filter($notMember)->values();

        return $audience->isNotEmpty()
            ? $audience
            : $resolver->supervisorsFor($seat->matter)->filter($notMember)->values();
    }

    /**
     * @param  list<User|null>  $preferred
     */
    private function queueForTask(Task $task, string $kind, string $key, array $preferred): int
    {
        $queued = 0;

        foreach (app(ResolveStaffRecipients::class)->forTask($task, $preferred) as $user) {
            $queued += $this->queue(self::SUBJECT_TASK, (int) $task->getKey(), $kind, $key, $user, $task);
        }

        return $queued;
    }

    private function queue(string $subject, int $subjectId, string $kind, string $key, User $recipient, Task|Matter $related): int
    {
        if (SendWorkReminder::alreadyMailed($related, $recipient, $subject, $kind, $key)) {
            return 0;
        }

        SendWorkReminder::dispatch($subject, $subjectId, $kind, $recipient->getKey(), $key)->afterCommit();

        return 1;
    }

    /** @param  callable(): int  $step */
    private function guarded(callable $step): int
    {
        try {
            return $step();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** Việc chưa xoá của vụ còn mở (hay việc chung), kèm quan hệ lời nhắc cần đọc. */
    private static function liveTasks(): Builder
    {
        return Task::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where(fn (Builder $live): Builder => $live
                ->whereNull('tasks.matter_id')
                ->orWhereHas('matter', fn (Builder $matter): Builder => $matter->withoutGlobalScope(ClientPortalScope::class)->open()))
            ->with(['matter.leadLawyer', 'assignee', 'assigner'])
            ->orderBy('tasks.id');
    }

    /** Việc "Chờ nhận" hay "Đang làm" — hai trạng thái người nhận còn phải làm. */
    private static function activeTasks(): Builder
    {
        return self::liveTasks()->whereIn('tasks.status', [TaskStatus::Pending->value, TaskStatus::Accepted->value]);
    }

    private static function isActive(Task $task): bool
    {
        return in_array($task->status, [TaskStatus::Pending, TaskStatus::Accepted], true);
    }

    /** Việc chung, hay việc của một vụ chưa huỷ, chưa kết thúc. */
    private static function onLiveMatter(Task $task): bool
    {
        if ($task->matter_id === null) {
            return true;
        }

        $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->find($task->matter_id);

        return $matter !== null && $matter->isOpen();
    }
}
