<?php

namespace App\Actions\Schedule;

use App\Actions\Document\ChecklistProgress;
use App\Actions\Performance\BuildPerformanceTrend;
use App\Actions\Performance\BuildTeamWorkload;
use App\Enums\Confidentiality;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\PerformanceSnapshot;
use App\Models\User;
use App\Support\MatterStaleness;
use App\Support\Performance\TeamRoster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ảnh chụp cuối ngày của số "bây giờ" theo người (M13 R10), 23:50 hằng ngày (`performance.snapshot`,
 * `routes/console.php`): N1, N4, N5 và X/Y của N10 của mọi người đang được theo dõi, để
 * {@see BuildPerformanceTrend} vẽ xu hướng mà không dựng lại lịch sử — "số vụ quá hạn cập nhật tuần
 * trước" không tính lại được từ cột hiện tại (`last_client_update_at` bị ghi đè), và dựng lại nó từ
 * `stage_logs` là viết luật quá hạn lần thứ hai.
 *
 * # Đúng các luật của trang "Theo dõi đội ngũ", không lần thứ hai
 *
 * Mỗi số là ĐÚNG scope mà {@see BuildTeamWorkload} đếm, bằng CÙNG phép đếm
 * ({@see BuildTeamWorkload::countPer()}): `Matter::scopeOpen()` theo người phụ trách (N1),
 * {@see MatterStaleness::scopeStale()} (N4), `Deadline::scopeOverdue()` trên vụ `open()` theo người giữ
 * mốc (N5), {@see ChecklistProgress::totalsByLead()} trên vụ `open()` (N10). Chỉ khác GỐC: tác vụ chạy
 * không người đăng nhập, nên gốc là `Matter::query()` (mọi vụ chưa huỷ), không phải `listableBy()`, tách
 * hai nửa bằng `Matter::scopeOfConfidentiality()` — tệp này không tự viết điều kiện nào
 * (`NoSecondDefinitionTest` quét nó). `CapturePerformanceSnapshotsTest` so dòng `normal` của X với số trực
 * tiếp trưởng phòng đọc về X, và `normal` + `restricted` với số X và admin đọc, ở cùng thời điểm.
 *
 * # Ai, và mấy dòng
 *
 * Người của {@see TeamRoster::members()} (trackable, ĐANG hoạt động — tác vụ không tự viết điều kiện trên
 * `is_active`): người nghỉ việc không có dòng mới, dòng cũ còn. Mỗi người một dòng `normal`, LUÔN ghi kể
 * cả toàn số 0 ("một ngày bị lỡ" là không có dòng `normal` của ngày đó), và một dòng `restricted` chỉ khi
 * có ít nhất một số > 0. Ai đọc được dòng nào: `PerformanceSnapshot::visibleLevels()` (R4).
 *
 * # Chạy lại trong ngày, ngày theo múi giờ văn phòng, và dọn dòng cũ
 *
 * `captured_on` = hôm nay theo `APP_TIMEZONE`. Ghi bằng `upsert` theo khoá (ngày, người, loại): chạy lại
 * thì số mới hơn thắng, số dòng không đổi; dòng `restricted` của hôm nay của một người nay đã về 0 ở mọi
 * số thì bị xoá, để hôm nay không mang số của lần chụp trước. Cùng lượt xoá mọi dòng cũ hơn
 * `PerformanceSnapshot::KEEP_MONTHS` tháng (`delete()`, không `forceDelete()`). Một transaction, không thư,
 * không thông báo (R12).
 *
 * Số truy vấn không đổi theo số người (R11): bốn truy vấn đếm cho mỗi loại, một `upsert`, hai câu xoá.
 */
final class CapturePerformanceSnapshots
{
    /** Gọi bởi `Schedule::call(new CapturePerformanceSnapshots)`. */
    public function __invoke(): void
    {
        $this->handle();
    }

    /** @return int số dòng đã ghi (chèn hoặc ghi đè) của hôm nay */
    public function handle(): int
    {
        $day = today();
        $people = TeamRoster::members()->map(fn (User $person): int => (int) $person->getKey())->all();

        $rows = [];
        $withRestricted = [];

        foreach (Confidentiality::cases() as $level) {
            $counts = $this->countsOf($level);

            foreach ($people as $person) {
                $row = [
                    'open_lead_matters' => $counts['open'][$person] ?? 0,
                    'stale_matters' => $counts['stale'][$person] ?? 0,
                    'overdue_deadlines' => $counts['overdue'][$person] ?? 0,
                    'checklist_settled' => $counts['checklist'][$person]['settled'] ?? 0,
                    'checklist_total' => $counts['checklist'][$person]['total'] ?? 0,
                ];

                if ($level === Confidentiality::Restricted && max($row) === 0) {
                    continue;
                }

                if ($level === Confidentiality::Restricted) {
                    $withRestricted[] = $person;
                }

                $rows[] = [
                    'captured_on' => PerformanceSnapshot::storedDay($day),
                    'user_id' => $person,
                    'confidentiality' => $level->value,
                    ...$row,
                ];
            }
        }

        DB::transaction(function () use ($rows, $day, $people, $withRestricted): void {
            if ($rows !== []) {
                PerformanceSnapshot::query()->upsert(
                    $rows,
                    ['captured_on', 'user_id', 'confidentiality'],
                    ['open_lead_matters', 'stale_matters', 'overdue_deadlines', 'checklist_settled', 'checklist_total', 'updated_at'],
                );
            }

            PerformanceSnapshot::query()
                ->restrictedOf($day, array_values(array_diff($people, $withRestricted)))
                ->delete();

            PerformanceSnapshot::query()->expired()->delete();
        });

        return count($rows);
    }

    /**
     * N1, N4, N5, N10 của mọi người trên một nửa (thường hoặc `restricted`) của các vụ chưa huỷ.
     *
     * @return array{open: array<int, int>, stale: array<int, int>, overdue: array<int, int>, checklist: array<int, array{settled: int, total: int}>}
     */
    private function countsOf(Confidentiality $level): array
    {
        $matters = fn (): Builder => Matter::query()->ofConfidentiality($level);

        return [
            'open' => BuildTeamWorkload::countPer($matters()->open(), 'matters.lead_lawyer_id'),
            'stale' => BuildTeamWorkload::countPer(MatterStaleness::scopeStale($matters()), 'matters.lead_lawyer_id'),
            'overdue' => BuildTeamWorkload::countPer(
                Deadline::query()->overdue()->whereHas('matter', fn (Builder $matter): Builder => $matter->open()->ofConfidentiality($level)),
                'deadlines.responsible_user_id',
            ),
            'checklist' => ChecklistProgress::totalsByLead($matters()->open()),
        ];
    }
}
