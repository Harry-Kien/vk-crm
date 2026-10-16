<?php

namespace App\Support\Tasks;

use App\Actions\Performance\BuildTaskPerformance;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityOwningMatter;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Dòng nhật ký "Trả lại việc được giao" (`task_declined`) ghi trong một kỳ mà một người xem được — tập của
 * "số việc trả lại" trên "Hiệu suất theo kỳ" (làn tk, Task 2; {@see BuildTaskPerformance}). Đếm theo NHẬT
 * KÝ, không theo bảng việc: một việc bị trả lại rồi giao lại cho người khác mất mốc trả lại trên dòng việc
 * (`ReassignTask` xoá nó), nhưng dòng nhật ký còn — lần trả lại vẫn được tính cho người đã trả lại.
 *
 * Tầm nhìn — vụ hạn chế không bao giờ lộ:
 *  - dòng của việc trên một vụ: `ActivityOwningMatter::scopeOwnedByVisibleMatters()` (dòng việc mang
 *    `properties.matter_id`, nên chỉ vụ trong `Matter::listableBy($viewer)` được tính);
 *  - dòng của một VIỆC CHUNG (không `matter_id`): không thuộc vụ nào để giấu; trang chỉ in dòng của người
 *    mà người xem được xem số (`viewPerformance`), và dòng "Chung" chỉ cho quản lý/quản trị — những người
 *    vốn thấy mọi việc chung (`Task::isSupervisor()`).
 */
final class TaskDeclineEvents
{
    public const EVENT = 'task_declined';

    /**
     * @param  array{0: string, 1: string}  $bounds  hai cận đủ giờ của `PerformancePeriod::bounds()`
     * @return Builder<Activity>
     */
    public static function within(User $viewer, array $bounds): Builder
    {
        $rows = Activity::query();
        ActivityOwningMatter::scopeEventsWithin($rows, self::EVENT, $bounds);

        $general = (new Task)->getMorphClass();

        return $rows->where(function (Builder $visible) use ($viewer, $general): void {
            $visible->where(fn (Builder $onMatter) => ActivityOwningMatter::scopeOwnedByVisibleMatters($onMatter, $viewer))
                ->orWhere(fn (Builder $generalTask): Builder => $generalTask
                    ->where('activity_log.subject_type', $general)
                    ->whereNull('activity_log.properties->matter_id'));
        });
    }
}
