<?php

namespace App\Actions\Task;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\ReassignMatters;
use App\Enums\TaskStatus;
use App\Jobs\SendReassignmentDigest;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit;

/**
 * Bàn giao vụ (một vụ hay hàng loạt — {@see ReassignMatter}, {@see ReassignMatters}): việc nội bộ đang
 * mở mà luật sư phụ trách CŨ đang giữ trên CHÍNH vụ đó chuyển sang luật sư phụ trách MỚI, ở trạng thái
 * "Chờ nhận" (làn tk, Task 2). Chỉ gọi từ BÊN TRONG transaction của `ReassignMatter`, SAU khi dòng vụ đã
 * khoá (thứ tự toàn cục: vụ trước, việc sau) và chỉ khi lead cũ RỜI đội ngũ — lead cũ ở lại làm luật sư
 * cộng sự vẫn ngồi ghế giữ việc, nên việc của họ ở lại với họ.
 *
 * # Việc nào chuyển
 *
 * Việc của vụ, người nhận là lead cũ, đang "Chờ nhận" hay "Đang làm". Không chuyển:
 *  - việc "Bị trả lại": lead cũ đã buông nó; nó nằm ở "Tôi đã giao" của người giao, chờ giao lại;
 *  - việc chính lead MỚI đã giao cho lead cũ: chuyển thì người giao tự nhận việc của mình. Việc đó ở lại
 *    và hiện "Cần giao lại" (người nhận không còn trong đội ngũ) cho chính lead mới quyết;
 *  - việc chung (không thuộc vụ nào): không thuộc lần bàn giao một vụ — người nghỉ việc thì việc chung
 *    của họ hiện "Cần giao lại" cho quản lý (`Task::scopeNeedsReassignment()`).
 *
 * # Ghi gì
 *
 * Mỗi việc: người nhận mới, "Chờ nhận", mốc giao mới (mốc nhận/trả lại cũ xoá), và một dòng nhật ký
 * `task_reassigned` với `reason = matter_reassigned` — cùng khoá sự kiện của `ReassignTask`, nên lịch sử
 * "ai từng giữ việc này" liền một mạch. Người giao ban đầu giữ nguyên. Không chuông/thư từng việc: thư
 * tổng hợp bàn giao ({@see SendReassignmentDigest}) liệt kê các việc này cho lead mới, và chúng nằm ở
 * "Chờ tôi nhận" của họ.
 */
class MoveTasksOnHandover
{
    use ReadsWithoutPortalScope;

    /** Giá trị `reason` của dòng `task_reassigned` ghi khi bàn giao vụ. */
    public const HANDOVER_REASON = 'matter_reassigned';

    /** @return list<int> id các việc đã chuyển, theo thứ tự id */
    public function handle(Matter $lockedMatter, User $oldLead, User $newLead, User $actor): array
    {
        $tasks = $this->scopelessly(Task::query())
            ->where('matter_id', $lockedMatter->getKey())
            ->where('assignee_id', $oldLead->getKey())
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Accepted->value])
            ->where('assigned_by', '!=', $newLead->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($tasks as $task) {
            $from = $task->status;

            $task->update([
                'assignee_id' => $newLead->getKey(),
                'status' => TaskStatus::Pending,
                'assigned_at' => now(),
                'accepted_at' => null,
                'declined_at' => null,
                'decline_reason' => null,
            ]);

            Audit::record('task_reassigned', $task, [
                'matter_id' => $lockedMatter->getKey(),
                'from' => $from->value,
                'to' => TaskStatus::Pending->value,
                'previous_assignee_id' => $oldLead->getKey(),
                'assignee_id' => $newLead->getKey(),
                'reason' => self::HANDOVER_REASON,
            ], causer: $actor);
        }

        return $tasks->modelKeys();
    }
}
