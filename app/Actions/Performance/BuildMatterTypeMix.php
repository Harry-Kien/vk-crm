<?php

namespace App\Actions\Performance;

use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Performance\MatterTypeMix;
use App\Support\Performance\TeamRoster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Bảng cơ cấu lĩnh vực của trang của một người (M13 Task 5; R8: "trang của một người có bảng cơ cấu
 * đủ — N1 theo `matter_type_id`, số 'bây giờ'"): số vụ đang mở của `$subject` theo loại vụ việc, như
 * `$viewer` đọc được.
 *
 * # Đúng tập của N1 (hay N2), chỉ chia theo loại
 *
 * Gốc là `Matter::listableBy($viewer)->open()` — cùng gốc và cùng "đang mở" với cột N1/N2 của
 * {@see BuildTeamWorkload} — rồi đúng một scope người có tên:
 *  - `ledBy($subject)` khi `TeamRoster::leadsMatters($subject)`: vụ người đó đang phụ trách (N1);
 *  - `supportedBy($subject)` khi không (trợ lý): vụ người đó giữ ghế luật sư cộng sự hoặc trợ lý (N2).
 *
 * Tập chọn theo QUYỀN, không theo dữ liệu (R6) — xem {@see MatterTypeMix}. Tổng các dòng bằng N1 (hay
 * N2) cho cùng người xem; `TeamMemberPageTest` ghim cả hai. Không điều kiện nghiệp vụ nào viết ở đây
 * (`NoSecondDefinitionTest` quét tệp này): một phép đếm `GROUP BY matter_type_id`.
 *
 * Tên loại đọc cả loại đã xoá mềm: một loại bị xoá mềm khi còn vụ đang mở (`MatterTypePolicy::delete()`
 * chặn, nhưng dữ liệu cũ có thể có) vẫn là lĩnh vực của những vụ đó.
 *
 * Hỏi `viewPerformance` trước mọi truy vấn (phòng thủ, như `BuildTeamWorkload`): trang đã hỏi rồi.
 */
final class BuildMatterTypeMix
{
    /**
     * @throws AuthorizationException khi `$viewer` không được xem số của `$subject`
     */
    public function handle(User $viewer, User $subject): MatterTypeMix
    {
        Gate::forUser($viewer)->authorize('viewPerformance', $subject);

        $byLead = TeamRoster::leadsMatters($subject);

        $matters = Matter::query()->listableBy($viewer)->open();

        if ($byLead) {
            $matters->ledBy($subject);
        } else {
            $matters->supportedBy($subject);
        }

        $counts = $matters
            ->select('matters.matter_type_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('matters.matter_type_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->matter_type_id => (int) $row->aggregate])
            ->all();

        $names = MatterType::withTrashed()->whereKey(array_keys($counts))->pluck('name', 'id');

        $rows = [];

        foreach ($counts as $typeId => $count) {
            $rows[] = ['matterTypeId' => $typeId, 'name' => (string) ($names[$typeId] ?? ''), 'matters' => $count];
        }

        usort($rows, fn (array $a, array $b): int => [$b['matters'], $a['name']] <=> [$a['matters'], $b['name']]);

        return new MatterTypeMix(byLead: $byLead, rows: $rows);
    }
}
