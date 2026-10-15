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
 *  - **Việc hoàn thành đúng hạn** — trong số việc CÓ HẠN đã hoàn thành trong kỳ (`completed_at` trong
 *    kỳ), bao nhiêu xong không muộn hơn hết ngày hạn (`Task::completedOnTime()`); in bằng {@see Ratio}
 *    (`:percent% (:numerator/:denominator)`, dưới 5 việc "Chưa đủ dữ liệu"), cùng dạng mọi tỉ lệ M13. Quy
 *    về NGƯỜI NHẬN (người đã hoàn thành — việc đã hoàn thành không giao lại được, nên `assignee_id` là
 *    người làm xong). Tập: `Task::visibleTo($viewer)` — vụ hạn chế không lộ;
 *  - **Việc trả lại** — số lần người này bấm "Trả lại" trong kỳ, theo nhật ký
 *    ({@see TaskDeclineEvents}: dòng vẫn còn khi việc đã giao lại cho người khác).
 *
 * Không xếp hạng (phán quyết M13 R8): con số đi cùng tử và mẫu, không tô màu, không sắp xếp. Dòng "Chung"
 * (khi người xem có `performance.viewAny`) đếm mọi việc người xem thấy theo cùng công thức, không lọc người.
 *
 * Kỳ đã đóng không đổi số: việc đã hoàn thành là trạng thái cuối (không mở lại, không giao lại), và dòng
 * nhật ký không bao giờ sửa.
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
            ->get(['tasks.id', 'tasks.assignee_id', 'tasks.status', 'tasks.completed_at', 'tasks.due_date']);

        foreach ($completed as $task) {
            foreach (['all', (int) $task->assignee_id] as $bucket) {
                $done[$bucket] = ($done[$bucket] ?? 0) + 1;
                $onTime[$bucket] = ($onTime[$bucket] ?? 0) + ($task->completedOnTime() ? 1 : 0);
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
