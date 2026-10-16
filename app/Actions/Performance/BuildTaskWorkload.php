<?php

namespace App\Actions\Performance;

use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Pages\TeamOverview;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Support\Performance\TaskWorkloadRow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Số "bây giờ" của luồng giao – nhận nội bộ cho "Theo dõi đội ngũ" và trang của một người (làn tk, Task
 * 2): mỗi người được theo dõi đang giữ bao nhiêu việc, bao nhiêu việc chờ họ nhận, quá hạn, bị trả lại,
 * và bao nhiêu hồ sơ chờ họ nhận hay họ đã trả lại — như `$viewer` đọc được. {@see TeamOverview} (mọi
 * người) và {@see TeamMember} (một người) cùng gọi đây; không màn hình nào tự đếm.
 *
 * # Gốc là tầm nhìn của người xem — vụ hạn chế không bao giờ lộ
 *
 *  - việc: `Task::visibleTo($viewer)` — cùng luật của danh sách "Công việc" (việc của một vụ chỉ khi
 *    người xem xem được vụ; vụ hạn chế chỉ với luật sư phụ trách và admin). Một con số về X là một phép
 *    ĐẾM trên phần giao (việc người xem thấy) ∩ (việc của X), không phép trừ — cùng R4 của M13;
 *  - hồ sơ: ghế của vụ ĐANG MỞ trong `Matter::listableBy($viewer)` (`MatterUser::scopeOnOpenMattersListableBy()`).
 *
 * # Mỗi cột MỘT truy vấn gộp
 *
 * `GROUP BY` cột quy người (`tasks.assignee_id` cho việc người đó giữ, `tasks.assigned_by` cho việc họ
 * giao bị trả lại, `matter_user.user_id` cho ghế) qua {@see BuildTeamWorkload::countPer()}, không lọc tập
 * người trong SQL — số truy vấn không đổi theo số người. Mọi tập là một scope có tên ở lớp giữ luật
 * (`Task::held()`/`pendingAcceptance()`/`inProgress()`/`returned()`/`overdueToHandle()`/`needsReassignment()`, `MatterUser::
 * awaitingAcceptance()`/`returned()`) — tệp này không viết điều kiện nghiệp vụ nào (`NoSecondDefinitionTest`).
 *
 * # Phòng thủ
 *
 * Hỏi `viewPerformance` cho từng `$subject` như {@see BuildTeamWorkload}; ném {@see AuthorizationException}
 * ở người đầu tiên không qua.
 */
final class BuildTaskWorkload
{
    /**
     * @param  Collection<int, User>  $subjects
     * @return array<int, TaskWorkloadRow> khoá là `user_id`, cùng thứ tự với `$subjects`
     *
     * @throws AuthorizationException
     */
    public function handle(User $viewer, Collection $subjects): array
    {
        $gate = Gate::forUser($viewer);

        foreach ($subjects as $subject) {
            $gate->authorize('viewPerformance', $subject);
        }

        if ($subjects->isEmpty()) {
            return [];
        }

        $tasks = fn () => Task::query()->visibleTo($viewer);
        $seats = fn () => MatterUser::query()->onOpenMattersListableBy($viewer);

        $inProgress = BuildTeamWorkload::countPer($tasks()->inProgress(), 'tasks.assignee_id');
        $awaiting = BuildTeamWorkload::countPer($tasks()->pendingAcceptance(), 'tasks.assignee_id');
        $overdue = BuildTeamWorkload::countPer($tasks()->overdueToHandle(), 'tasks.assignee_id');
        $returned = BuildTeamWorkload::countPer($tasks()->returned(), 'tasks.assigned_by');
        $seatsAwaiting = BuildTeamWorkload::countPer($seats()->awaitingAcceptance(), 'matter_user.user_id');
        $seatsReturned = BuildTeamWorkload::countPer($seats()->returned(), 'matter_user.user_id');

        $rows = [];

        foreach ($subjects as $subject) {
            $id = (int) $subject->getKey();

            $rows[$id] = new TaskWorkloadRow(
                userId: $id,
                tasksInProgress: $inProgress[$id] ?? 0,
                tasksAwaiting: $awaiting[$id] ?? 0,
                tasksOverdue: $overdue[$id] ?? 0,
                tasksReturned: $returned[$id] ?? 0,
                seatsAwaiting: $seatsAwaiting[$id] ?? 0,
                seatsReturned: $seatsReturned[$id] ?? 0,
            );
        }

        return $rows;
    }

    /**
     * "Việc cần giao lại: n" của Theo dõi đội ngũ (vòng sửa polish 1 — brief Task 1 §3: "vô hiệu một nhân sự
     * thì việc đang mở của họ hiện ở Theo dõi đội ngũ là cần giao lại"): số việc `Task::needsReassignment()`
     * trong `Task::visibleTo($viewer)` — ĐÚNG tập dòng của trang Công việc, tab "Tất cả", bộ lọc "Cần giao lại"
     * mà con số trỏ tới. Gồm việc của người đã bị vô hiệu hoá hay xoá (vế `inactive`), dù dòng của họ ẩn khỏi
     * bảng theo mặc định. Vụ hạn chế người xem không mở được không vào số.
     *
     * `null` khi `$viewer` không giao lại được mọi việc họ thấy — chỉ quản lý/quản trị (`Task::isSupervisor()`,
     * `TaskPolicy::reassign`); trang không in con số cho người khác.
     */
    public function needsReassignment(User $viewer): ?int
    {
        if (! Task::isSupervisor($viewer)) {
            return null;
        }

        return Task::query()->visibleTo($viewer)->needsReassignment()->count();
    }
}
