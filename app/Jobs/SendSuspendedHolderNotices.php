<?php

namespace App\Jobs;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\User\SuspendStaffAccess;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\HolderSuspendedAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lời báo của MỘT lần "Khoá truy cập ngay" ({@see SuspendStaffAccess}) cho người giao các việc người bị khoá đang giữ
 * (kiểm toán luồng Công việc 2026-10-21, Q4). Xếp `->afterCommit()`; payload chỉ mang id: người bị khoá, người bấm,
 * dòng `staff_access_suspended`.
 *
 * Trước bản sửa không ai được báo: lời nhắc chỉ tới người giao khi việc chờ nhận quá 24 giờ, sắp đến hạn hay quá hạn
 * (`RemindPendingWork`), nên người giao một việc ĐANG LÀM không hạn hay hạn xa không bao giờ biết, và ô trang chủ của
 * họ toàn số 0 nên ẩn.
 *
 * # Ai nhận, đọc lại lúc chạy
 *
 * Mỗi việc người bị khoá còn giữ (chờ nhận, đang làm — `Task::held()`), người bị khoá vẫn đang bị khoá: người nhận là
 * `ResolveStaffRecipients::forTaskAssigner(decisionNeeded: true)` — người giao, hay người nhận thay họ (luật sư phụ
 * trách, người quản lý của hồ sơ, trưởng phòng với việc chung), chỉ người mở được việc — trừ người bấm (họ vừa thấy
 * số việc trong hộp xác nhận) và người bị khoá. Cùng khuôn đường "Gỡ thành viên" (`SendTeamDepartureNotices`, chuông
 * `TASKS_HANDLED`): MỘT chuông {@see HolderSuspendedAlert} cho mỗi người, liệt kê mọi việc của họ.
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `suspended@<id dòng nhật ký>` ở `viewData.holder_suspended_key`.
 */
class SendSuspendedHolderNotices implements ShouldQueue
{
    use Queueable;
    use ReadsWithoutPortalScope;

    public int $tries = 5;

    public function __construct(
        public readonly int $holderId,
        public readonly int $actorId,
        public readonly int $eventId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $holder = User::query()->withTrashed()->find($this->holderId);

        // Đã mở lại trước khi job chạy: việc vẫn của người đó, không cần ai giao lại.
        if ($holder === null || $holder->is_active) {
            return;
        }

        $key = 'suspended@'.$this->eventId;

        /** @var array<int, array{user: User, tasks: list<Task>}> $byRecipient */
        $byRecipient = [];

        $tasks = $this->scopelessly(Task::query())
            ->where('assignee_id', $holder->getKey())
            ->held()
            ->with(['assigner', 'matter.leadLawyer'])
            ->orderedForWork()
            ->get();

        foreach ($tasks as $task) {
            foreach ($recipients->forTaskAssigner($task, decisionNeeded: true) as $user) {
                if (in_array($user->getKey(), [$this->actorId, $this->holderId], true)) {
                    continue;
                }

                $byRecipient[$user->getKey()] ??= ['user' => $user, 'tasks' => []];
                $byRecipient[$user->getKey()]['tasks'][] = $task;
            }
        }

        foreach ($byRecipient as $row) {
            $this->ring($row['user'], $holder, $row['tasks'], $key);
        }
    }

    /**
     * Trang Công việc lọc sẵn đúng các việc của chuông: "Cần giao lại", người nhận là người bị khoá, chờ nhận / đang làm —
     * tab "Tôi đã giao" khi mọi việc do chính `$user` giao, không thì tab "Tất cả" (việc nhận thay không ở "Tôi đã giao").
     *
     * @param  list<Task>  $tasks
     */
    public static function urlFor(User $user, User $holder, array $tasks): string
    {
        $own = collect($tasks)->every(fn (Task $task): bool => $task->assigned_by === $user->getKey());

        return TaskResource::getUrl('index', [
            'tab' => $own ? 'assigned' : 'all',
            'filters' => [
                'assignee_id' => ['value' => $holder->getKey()],
                'status' => ['values' => [TaskStatus::Pending->value, TaskStatus::Accepted->value]],
                'needs_reassignment' => ['isActive' => true],
            ],
        ], panel: 'admin');
    }

    /** @param  list<Task>  $tasks */
    private function ring(User $user, User $holder, array $tasks, string $key): void
    {
        $already = $user->notifications()
            ->where('type', HolderSuspendedAlert::class)
            ->where('data->viewData->holder_suspended_key', $key)
            ->exists();

        if ($already) {
            return;
        }

        $user->notify(new HolderSuspendedAlert(
            (string) $holder->name,
            array_map(fn (Task $task): string => (string) $task->title, $tasks),
            self::urlFor($user, $holder, $tasks),
            $key,
        ));
    }
}
