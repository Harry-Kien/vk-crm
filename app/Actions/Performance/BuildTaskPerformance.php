<?php

namespace App\Actions\Performance;

use App\Enums\Permission;
use App\Filament\Admin\Pages\Performance;
use App\Models\Task;
use App\Models\User;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\Ratio;
use App\Support\Tasks\TaskDeclineEvents;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Hai con số giao – nhận nội bộ TRONG MỘT KỲ cho "Hiệu suất theo kỳ" (làn tk, Task 2; trang
 * {@see Performance}), như `$viewer` đọc được:
 *
 *  - **Việc hoàn thành đúng hạn** — tử: số việc CÓ HẠN đã hoàn thành trong kỳ (`completed_at` trong kỳ) mà
 *    xong không muộn hơn hết ngày hạn (`Task::completedOnTime()`); mẫu (làn fa1, phán quyết 6 — kiểm tra
 *    luồng nội bộ 2026-10-16): mọi việc có hạn đã hoàn thành trong kỳ CỘNG mọi việc người nhận còn giữ đã quá
 *    hạn mà hạn rơi trong kỳ (`Task::scopeOverdueWithDueDateBetween()`, cùng định nghĩa "quá hạn" của ô bảng
 *    điều khiển và Theo dõi đội ngũ) — một việc bỏ dở quá hạn tính là trễ, không biến khỏi số như trước. Việc
 *    đã huỷ, việc bị trả lại (không còn trong tay người nhận), việc của vụ đã kết thúc và việc "Cần giao lại" vì
 *    người nhận không vào mẫu. Gộp integ-fa (rà soát chéo 2026-10-18, mục 2): việc người đang giữ nhận SAU ngày hạn
 *    (`Task::scopeAssignedByDueDate()` — giao lại khi hạn đã qua) không vào cả tử lẫn mẫu: người đó chưa bao giờ có
 *    cơ hội làm kịp. In bằng {@see Ratio} (`:percent% (:numerator/:denominator)`, dưới 5 việc "Chưa
 *    đủ dữ liệu"), cùng dạng mọi tỉ lệ M13. Quy về NGƯỜI NHẬN (`assignee_id`: người đã hoàn thành, hay người
 *    đang giữ việc quá hạn). Tập: `Task::visibleTo($viewer)` — vụ hạn chế không lộ;
 *  - **Việc trả lại** — số lần người này bấm "Trả lại" trong kỳ, theo nhật ký
 *    ({@see TaskDeclineEvents}: dòng vẫn còn khi việc đã giao lại cho người khác).
 *
 * Không xếp hạng (phán quyết M13 R8): con số đi cùng tử và mẫu, không tô màu, không sắp xếp. Dòng "Chung"
 * (khi người xem có `performance.viewAny`) đếm mọi việc người xem thấy theo cùng công thức, không lọc người.
 *
 * Số của một kỳ đã đóng CÓ THỂ đổi (làn fa1): phần "việc còn giữ đã quá hạn" đọc trạng thái HIỆN TẠI — việc đó
 * xong sau kỳ thì ra khỏi mẫu của kỳ cũ (và vào tử/mẫu của kỳ nó xong, như một việc trễ), bị huỷ hay giao lại
 * thì cũng ra; một việc đã hoàn thành được "Mở lại" (`ReopenTask`) mất `completed_at` nên ra khỏi tập đã hoàn
 * thành cho tới khi xong lần nữa. Gộp integ-fa: một việc quá hạn được giao lại (hạn cũ giữ nguyên) ra khỏi mẫu của
 * người cũ (không còn giữ) và không vào mẫu của người mới (nhận sau hạn). Số lần trả lại đọc nhật ký, không bao giờ đổi.
 */
final class BuildTaskPerformance
{
    /**
     * @param  Collection<int, User>  $subjects
     * @return array{rows: array<int, array{taskOnTime: Ratio, tasksDeclined: int}>, reference: array{taskOnTime: Ratio, tasksDeclined: int}|null}
     *
     * @throws AuthorizationException khi một `$subject` không qua `viewPerformance` — phòng thủ, trang đã lọc
     */
    public function handle(User $viewer, Collection $subjects, PerformancePeriod $period): array
    {
        $gate = Gate::forUser($viewer);

        foreach ($subjects as $subject) {
            $gate->authorize('viewPerformance', $subject);
        }

        $onTime = [];
        $done = [];

        $completed = Task::query()
            ->visibleTo($viewer)
            ->completedWithDueDateBetween($period->bounds())
            ->assignedByDueDate()
            ->get(['tasks.id', 'tasks.assignee_id', 'tasks.status', 'tasks.completed_at', 'tasks.due_date']);

        foreach ($completed as $task) {
            foreach (['all', (int) $task->assignee_id] as $bucket) {
                $done[$bucket] = ($done[$bucket] ?? 0) + 1;
                $onTime[$bucket] = ($onTime[$bucket] ?? 0) + ($task->completedOnTime() ? 1 : 0);
            }
        }

        // Xem docblock lớp: việc còn giữ đã quá hạn, hạn trong kỳ — vào mẫu, không vào tử.
        $stillOverdue = BuildTeamWorkload::countPer(
            Task::query()->visibleTo($viewer)->overdueWithDueDateBetween($period->from->toDateString(), $period->to->toDateString())->assignedByDueDate(),
            'tasks.assignee_id',
        );

        foreach ($stillOverdue as $assignee => $count) {
            foreach (['all', $assignee] as $bucket) {
                $done[$bucket] = ($done[$bucket] ?? 0) + $count;
            }
        }

        $declined = ['all' => 0];

        $rows = TaskDeclineEvents::within($viewer, $period->bounds())
            ->select('activity_log.causer_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('activity_log.causer_id')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $declined['all'] += (int) $row->aggregate;

            if ($row->causer_id !== null) {
                $declined[(int) $row->causer_id] = (int) $row->aggregate;
            }
        }

        $line = fn (int|string $bucket): array => [
            'taskOnTime' => new Ratio($onTime[$bucket] ?? 0, $done[$bucket] ?? 0),
            'tasksDeclined' => $declined[$bucket] ?? 0,
        ];

        $result = [];

        foreach ($subjects as $subject) {
            $result[(int) $subject->getKey()] = $line((int) $subject->getKey());
        }

        return [
            'rows' => $result,
            'reference' => $gate->allows(Permission::PerformanceViewAny->value) ? $line('all') : null,
        ];
    }
}
