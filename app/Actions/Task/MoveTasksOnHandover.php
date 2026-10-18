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
use App\Support\Tasks\ReassignmentDueDate;

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
 * "ai từng giữ việc này" liền một mạch. Người giao ban đầu giữ nguyên — trừ việc lead cũ TỰ GIỮ (người giao = người
 * giữ, việc riêng): {@see self::transferAssigner()} chạy ngay sau và đổi người giao của nó sang lead mới, nên nó thành
 * việc riêng của lead mới. Không chuông/thư từng việc: thư tổng hợp bàn giao ({@see SendReassignmentDigest}) liệt kê
 * các việc này cho lead mới, và chúng nằm ở "Chờ tôi nhận" của họ.
 *
 * Gộp integ-fa (rà soát chéo 2026-10-18):
 *  - **Người bấm tự nhận hồ sơ** (`$newLead` là `$actor` — trưởng phòng bàn giao cho chính mình): việc sang thẳng
 *    "Đang làm" (`accepted_at` = `assigned_at` = lúc bàn giao, dòng nhật ký `to = accepted`, `taken_over: true`) — cùng
 *    luật nhận thay của `ReassignTask` (`takeOver`) và của ghế (`Matter::seatAwaitsAcceptance()`: tự bàn giao cho mình
 *    thì ghế coi như đã nhận).
 *  - **Hạn đã qua** không đi theo việc: hạn mới theo đúng luật hạn gợi ý của "Giao lại"
 *    ({@see ReassignmentDueDate::suggest()} — hôm nay cộng số ngày người cũ đã có), dòng nhật ký ghi
 *    `due_date_from`/`due_date_to`. Hạn còn ở hôm nay hay tương lai giữ nguyên.
 *
 * # Việc lead cũ ĐÃ GIAO trên vụ — {@see self::transferAssigner()} (vòng sửa 1 của Task 2, I1)
 *
 * Lead cũ rời đội ngũ thì không còn ai theo dõi những việc họ đã giao cho người khác trên vụ: lời "bị trả
 * lại", "chưa được nhận", "quá hạn" cho người giao và tab "Tôi đã giao", ô "Việc tôi giao bị trả lại", số
 * "bị trả lại" trên Theo dõi đội ngũ đều đọc `assigned_by`. Nên việc còn mở (chờ nhận, đang làm, bị trả
 * lại) của vụ mà lead cũ giao đổi NGƯỜI GIAO sang lead mới — trạng thái, người nhận, hạn và lý do trả lại
 * giữ nguyên — kèm một dòng `task_assigner_changed` (`previous_assigner_id`, `assigner_id`, cùng
 * `reason = matter_reassigned`), nên "ai đã giao việc này" vẫn đọc được trong nhật ký. Không đổi: việc đã
 * xong hay đã huỷ (lịch sử), việc của vụ khác. Thư tổng hợp bàn giao liệt
 * kê các việc này thành một mục riêng.
 *
 * Gộp integ-fa (rà soát chéo 2026-10-18, mục 1): việc lead cũ đã giao mà LEAD MỚI đang giữ — và việc lead cũ tự giữ mà
 * {@see self::handle()} vừa chuyển — cũng đổi người giao sang lead mới: chúng thành việc RIÊNG của lead mới (người giao =
 * người giữ — cùng luật nhận thay của `ReassignTask`: không ở "Tôi đã giao", không "Trả lại", lời báo cho người giao về
 * chúng không rơi tới người quản lý). Dòng `task_assigner_changed` của chúng thêm `own_task: true`; chúng không vào
 * danh sách "theo dõi" của thư tổng hợp. Trước bản sửa, `assigned_by` của chúng ở lại với lead cũ, nên một lần bàn giao
 * xanh trọn vẫn để lead cũ "còn việc đã giao" (`OpenTaskWork`) và không cho nghỉ việc được.
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

        // Gộp integ-fa (rà soát chéo 2026-10-18): người bấm tự nhận hồ sơ thì NHẬN THAY — docblock lớp.
        $takingOver = $newLead->is($actor);
        $to = $takingOver ? TaskStatus::Accepted : TaskStatus::Pending;

        foreach ($tasks as $task) {
            $from = $task->status;
            $now = now();
            $oldDue = $task->due_date?->toDateString();
            // Gộp integ-fa (rà soát chéo, mục 2): hạn đã qua không đi theo việc — cùng luật hạn gợi ý của "Giao lại".
            $newDue = ReassignmentDueDate::suggest($task);

            $task->update([
                'assignee_id' => $newLead->getKey(),
                'status' => $to,
                'assigned_at' => $now,
                'accepted_at' => $takingOver ? $now : null,
                'declined_at' => null,
                'decline_reason' => null,
                'due_date' => $newDue,
            ]);

            Audit::record('task_reassigned', $task, [
                'matter_id' => $lockedMatter->getKey(),
                'from' => $from->value,
                'to' => $to->value,
                'previous_assignee_id' => $oldLead->getKey(),
                'assignee_id' => $newLead->getKey(),
                'reason' => self::HANDOVER_REASON,
                ...($newDue !== $oldDue ? ['due_date_from' => $oldDue, 'due_date_to' => $newDue] : []),
                ...($takingOver ? ['taken_over' => true] : []),
            ], causer: $actor);
        }

        return $tasks->modelKeys();
    }

    /**
     * Xem docblock lớp, mục "Việc lead cũ ĐÃ GIAO trên vụ". Cùng điều kiện gọi với {@see self::handle()}:
     * trong transaction của `ReassignMatter`, sau khoá dòng vụ, chỉ khi lead cũ rời đội ngũ. Gọi SAU `handle()`:
     * việc lead cũ tự giữ mà `handle()` vừa chuyển cũng đổi người giao ở đây (thành việc riêng của lead mới).
     *
     * @return list<int> id các việc đã đổi người giao mà NGƯỜI KHÁC lead mới đang giữ (lead mới theo dõi), theo thứ tự
     *                   id — việc thành việc riêng của lead mới không vào đây (chúng ở "Chờ tôi nhận" / "Đang làm" của họ)
     */
    public function transferAssigner(Matter $lockedMatter, User $oldLead, User $newLead, User $actor): array
    {
        $tasks = $this->scopelessly(Task::query())
            ->where('matter_id', $lockedMatter->getKey())
            ->where('assigned_by', $oldLead->getKey())
            ->whereIn('status', TaskStatus::openValues())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $followed = [];

        foreach ($tasks as $task) {
            $own = $task->assignee_id === $newLead->getKey();

            $task->update(['assigned_by' => $newLead->getKey()]);

            Audit::record('task_assigner_changed', $task, [
                'matter_id' => $lockedMatter->getKey(),
                'status' => $task->status->value,
                'previous_assigner_id' => $oldLead->getKey(),
                'assigner_id' => $newLead->getKey(),
                'reason' => self::HANDOVER_REASON,
                ...($own ? ['own_task' => true] : []),
            ], causer: $actor);

            if (! $own) {
                $followed[] = (int) $task->getKey();
            }
        }

        return $followed;
    }
}
