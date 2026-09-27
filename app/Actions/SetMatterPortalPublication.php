<?php

namespace App\Actions;

use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Bật/tắt công tắc "công bố cho khách" của một vụ việc (SPEC §7.2, tab Tổng quan). Tách ra khỏi
 * `ViewMatter` (fix round 2 review, important finding: trang gọi thẳng
 * `$record->update(['is_published_to_portal' => ...])`, vi phạm CLAUDE.md "Filament
 * resource/controller/job chỉ gọi Action") để có một điểm duy nhất kiểm tra quyền qua Gate (Action
 * tự kiểm tra, không tin trang đã kiểm tra — cùng nguyên tắc với `TransitionMatterStage`), ghi
 * audit có cấu trúc thay vì chỉ dòng "updated" chung chung của spatie/laravel-activitylog, và —
 * quan trọng nhất — một SEAM cho M6.
 *
 * **Carry-forward M6 (docs/PROGRESS.md, "Ghi chú M3"):** bật lại công tắc này sau khi đã tắt có
 * thể lộ nguyên backlog các dòng `stage_logs.is_published = true` đã tích luỹ trong lúc tắt —
 * `notified_at` không tự reset, nên M6 cần cảnh báo hoặc chặn việc bật lại vô điều kiện. Action
 * này CHƯA cài cảnh báo đó (ngoài phạm vi M3), nhưng ghi sẵn số dòng đã công bố vào audit
 * properties ngay từ bây giờ (`published_stage_log_count`), để việc đó có dữ liệu kiểm chứng từ
 * ngày đầu thay vì phải suy luận ngược lại từ activity log chung.
 */
class SetMatterPortalPublication
{
    public function handle(Matter $matter, bool $publish, User $actor): Matter
    {
        // R5 (roles-05, M6.5 Task 10): 'setPortalPublication', không phải 'update' — xem docblock
        // MatterPolicy::setPortalPublication() cho lý do (đưa cả vụ việc ra khách đòi
        // stageLog.publish, không chỉ matter.update). Fix round 1 (ruling): $publish truyền kèm —
        // chỉ chiều BẬT đòi stageLog.publish, chiều TẮT chỉ cần matter.update.
        Gate::forUser($actor)->authorize('setPortalPublication', [$matter, $publish]);

        return DB::transaction(function () use ($matter, $publish, $actor): Matter {
            $publishedStageLogCount = $matter->stageLogs()->where('is_published', true)->count();

            // `blameOn()` trước `update()`: `HasBlameable::updating` ghi `updated_by` từ
            // `auth('web')` ambient nếu không ai tuyên bố actor, và Action đã biết actor là ai
            // (chính actor vừa qua Gate ở trên). Xem docblock của trait.
            $matter->blameOn($actor)->update(['is_published_to_portal' => $publish]);

            Audit::record('matter_portal_publication_set', $matter, [
                'publish' => $publish,
                'published_stage_log_count' => $publishedStageLogCount,
            ], $actor);

            return $matter;
        });
    }
}
