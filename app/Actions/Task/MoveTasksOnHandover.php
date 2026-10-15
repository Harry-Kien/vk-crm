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
 *
 * # Việc lead cũ ĐÃ GIAO trên vụ — {@see self::transferAssigner()} (vòng sửa 1 của Task 2, I1)
 *
 * Lead cũ rời đội ngũ thì không còn ai theo dõi những việc họ đã giao cho người khác trên vụ: lời "bị trả
 * lại", "chưa được nhận", "quá hạn" cho người giao và tab "Tôi đã giao", ô "Việc tôi giao bị trả lại", số
 * "bị trả lại" trên Theo dõi đội ngũ đều đọc `assigned_by`. Nên việc còn mở (chờ nhận, đang làm, bị trả
 * lại) của vụ mà lead cũ giao đổi NGƯỜI GIAO sang lead mới — trạng thái, người nhận, hạn và lý do trả lại
 * giữ nguyên — kèm một dòng `task_assigner_changed` (`previous_assigner_id`, `assigner_id`, cùng
 * `reason = matter_reassigned`), nên "ai đã giao việc này" vẫn đọc được trong nhật ký. Không đổi: việc đã
 * xong hay đã huỷ (lịch sử), việc lead cũ giao cho chính lead mới (người giao không tự theo dõi việc mình
 * giữ — lead mới quản lý nó bằng quyền luật sư phụ trách), việc của vụ khác. Thư tổng hợp bàn giao liệt
 * kê các việc này thành một mục riêng.
 */
class MoveTasksOnHandover
{
    use ReadsWithoutPortalScope;

    /** Giá trị `reason` của dòng `task_reassigned` và `task_assigner_changed` ghi khi bàn giao vụ. */
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

    /**
     * Xem docblock lớp, mục "Việc lead cũ ĐÃ GIAO trên vụ". Cùng điều kiện gọi với {@see self::handle()}:
     * trong transaction của `ReassignMatter`, sau khoá dòng vụ, chỉ khi lead cũ rời đội ngũ.
     *
     * @return list<int> id các việc đã đổi người giao, theo thứ tự id
     */
    public function transferAssigner(Matter $lockedMatter, User $oldLead, User $newLead, User $actor): array
    {
        $tasks = $this->scopelessly(Task::query())
            ->where('matter_id', $lockedMatter->getKey())
            ->where('assigned_by', $oldLead->getKey())
            ->whereIn('status', TaskStatus::openValues())
            ->where('assignee_id', '!=', $newLead->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($tasks as $task) {
            $task->update(['assigned_by' => $newLead->getKey()]);

            Audit::record('task_assigner_changed', $task, [
                'matter_id' => $lockedMatter->getKey(),
                'status' => $task->status->value,
                'previous_assigner_id' => $oldLead->getKey(),
                'assigner_id' => $newLead->getKey(),
                'reason' => self::HANDOVER_REASON,
            ], causer: $actor);
        }

        return $tasks->modelKeys();
    }
}
