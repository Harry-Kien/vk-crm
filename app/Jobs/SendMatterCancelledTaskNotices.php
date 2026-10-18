<?php

namespace App\Jobs;

use App\Actions\Matter\CancelMatter;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\MatterCancelledTasksAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lời báo "hồ sơ đã bị huỷ — việc của anh/chị trên hồ sơ này tạm ẩn" (gộp integ-fa, rà soát chéo 2026-10-18, mục 10).
 * {@see CancelMatter} xếp `->afterCommit()`; payload chỉ mang id: hồ sơ, người bấm, dòng `matter_cancelled`, và các việc
 * nội bộ còn mở của hồ sơ lúc huỷ.
 *
 * # Ai nhận, đọc lại lúc chạy
 *
 * Hồ sơ phải VẪN đang huỷ (đã khôi phục giữa lúc xếp và lúc chạy thì việc đã trở lại — không báo gì). Với mỗi việc trong
 * payload còn mở: người đang giữ (chờ nhận, đang làm) và người đã giao nó (việc riêng — người giao = người giữ — chỉ một
 * lần). Trừ người bấm (họ vừa đọc các con số trong hộp thoại huỷ), người đã nghỉ, đã bị vô hiệu hay đã xoá. MỘT chuông
 * mỗi người cho cả hồ sơ ({@see MatterCancelledTasksAlert}), với số việc họ đang giữ và số việc họ đã giao.
 *
 * Mã hồ sơ hiện khi người nhận MỞ ĐƯỢC hồ sơ trước khi nó bị huỷ (`Matter::isListableBy()` — vụ hạn chế: chỉ quản trị
 * viên và luật sư phụ trách); không thì câu chung "một hồ sơ" — một vụ hạn chế không bao giờ lộ mã cho người không mở
 * được nó (người còn sót ghế trong đội ngũ của vụ hạn chế).
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `cancelled@<id dòng nhật ký>` ở `viewData.matter_cancelled_key`.
 */
class SendMatterCancelledTaskNotices implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param  list<int>  $taskIds */
    public function __construct(
        public readonly int $matterId,
        public readonly int $actorId,
        public readonly int $eventId,
        public readonly array $taskIds,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->withTrashed()->find($this->matterId);

        if ($matter === null || ! $matter->trashed() || $this->taskIds === []) {
            return;
        }

        $key = 'cancelled@'.$this->eventId;
        $actorName = (string) User::query()->withTrashed()->whereKey($this->actorId)->value('name');

        /** @var array<int, array{held: int, assigned: int}> $counts */
        $counts = [];

        $tasks = Task::query()->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($this->taskIds)
            ->where('matter_id', $matter->getKey())
            ->open()
            ->orderBy('id')
            ->get(['id', 'assignee_id', 'assigned_by', 'status']);

        foreach ($tasks as $task) {
            if ($task->assignee_id !== null && in_array($task->status, [TaskStatus::Pending, TaskStatus::Accepted], true)) {
                $counts[$task->assignee_id]['held'] = ($counts[$task->assignee_id]['held'] ?? 0) + 1;
            }

            if ($task->assigned_by !== null && $task->assigned_by !== $task->assignee_id) {
                $counts[$task->assigned_by]['assigned'] = ($counts[$task->assigned_by]['assigned'] ?? 0) + 1;
            }
        }

        unset($counts[$this->actorId]);

        if ($counts === []) {
            return;
        }

        $people = User::query()->whereKey(array_keys($counts))->where('is_active', true)->get();

        foreach ($people as $user) {
            $already = $user->notifications()
                ->where('type', MatterCancelledTasksAlert::class)
                ->where('data->viewData->matter_cancelled_key', $key)
                ->exists();

            if ($already) {
                continue;
            }

            $user->notify(new MatterCancelledTasksAlert(
                $matter->isListableBy($user) ? (string) $matter->code : null,
                $actorName,
                $counts[$user->getKey()]['held'] ?? 0,
                $counts[$user->getKey()]['assigned'] ?? 0,
                $key,
            ));
        }
    }
}
