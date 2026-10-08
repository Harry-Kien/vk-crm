<?php

namespace App\Actions\Performance;

use App\Actions\Schedule\CapturePerformanceSnapshots;
use App\Models\PerformanceSnapshot;
use App\Models\User;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\Ratio;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Xu hướng (M13 P8, R10): số vụ quá hạn cập nhật (N4), số mốc quá hạn (N5) và mức hoàn thiện danh mục
 * (X/Y của N10) vào cuối mỗi ngày, đọc từ ảnh chụp của {@see CapturePerformanceSnapshots}. Hai hình dạng:
 * {@see self::handle()} — chuỗi ngày của MỘT người (hai widget trên trang của một người, 90 ngày kết thúc
 * hôm qua); {@see self::endpoints()} — đầu kỳ → cuối kỳ của cả trang "Hiệu suất theo kỳ" (cột P8), MỘT
 * truy vấn cho cả trang (R11).
 *
 * # Ảnh chụp là bản ghi lịch sử của một luật, không phải một luật
 *
 * - **Hôm nay không bao giờ là một điểm**: số hôm nay luôn tính trực tiếp trên mọi trang; mọi khoảng bị cắt
 *   ở HÔM QUA, kể cả khi tác vụ đã chụp hôm nay.
 * - **Ngày không có dòng `normal` để trống (`null`), không vẽ thành 0.** Cron trên shared hosting có thể lỡ
 *   một ngày; vẽ 0 là nói dối rằng hôm đó không có vụ nào quá hạn. Ngày chỉ có dòng `restricted` (dữ liệu
 *   hỏng) cũng là ngày bị lỡ.
 * - **Ai đọc được dòng nào** là việc của `PerformanceSnapshot::visibleLevels()` (R4), dịch sang SQL bởi
 *   `scopeVisibleTo()`/`scopeVisibleToMany()`: dòng không được thấy không bao giờ được TRUY VẤN (không nạp
 *   rồi lọc). Khi người xem được thấy cả hai, ngày đó là `normal` + `restricted` (`scopeDailyTotals()` cộng
 *   trong SQL). Tệp này không viết điều kiện nào trên cột nghiệp vụ (`NoSecondDefinitionTest` quét nó).
 *
 * # Phòng thủ
 *
 * Hỏi `viewPerformance` cho từng người dù trang (hoặc widget) đã hỏi; ném {@see AuthorizationException}.
 */
final class BuildPerformanceTrend
{
    /** Khoảng cố định của trang một người: 90 ngày, kết thúc hôm qua (R10: hôm nay luôn tính trực tiếp, không từ ảnh chụp). */
    public const MEMBER_PAGE_DAYS = 90;

    /**
     * Chuỗi ngày của `$subject` trong `$period`, cắt ở hôm qua.
     *
     * @return array{dates: list<string>, stale: list<int|null>, overdue: list<int|null>, checklist: list<?Ratio>} null = ngày không có ảnh chụp
     *
     * @throws AuthorizationException khi `$viewer` không được xem số của `$subject` — phòng thủ
     */
    public function handle(User $viewer, User $subject, PerformancePeriod $period): array
    {
        Gate::forUser($viewer)->authorize('viewPerformance', $subject);

        $dates = self::dates($period);
        $trend = ['dates' => $dates, 'stale' => [], 'overdue' => [], 'checklist' => []];

        $days = $dates === [] ? collect() : PerformanceSnapshot::query()
            ->visibleTo($viewer, $subject)
            ->capturedBetween(CarbonImmutable::parse($dates[0]), CarbonImmutable::parse(end($dates)))
            ->dailyTotals()
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => self::day($row->captured_on));

        foreach ($dates as $date) {
            $row = self::captured($days->get($date));

            $trend['stale'][] = $row === null ? null : (int) $row->stale_matters;
            $trend['overdue'][] = $row === null ? null : (int) $row->overdue_deadlines;
            $trend['checklist'][] = $row === null ? null : new Ratio((int) $row->checklist_settled, (int) $row->checklist_total);
        }

        return $trend;
    }

    /**
     * Cột P8 của trang "Hiệu suất theo kỳ" (trang `Performance` của panel admin): ảnh chụp của ngày đầu kỳ và của ngày cuối
     * kỳ (không muộn hơn hôm qua) cho mỗi người, MỘT truy vấn cho cả trang. Ngày không có ảnh chụp —
     * hoặc chưa tới (kỳ bắt đầu hôm nay) — là `null`.
     *
     * @param  Collection<int, User>  $subjects
     * @return array<int, array{staleStart: ?int, staleEnd: ?int, overdueStart: ?int, overdueEnd: ?int}> theo user_id
     *
     * @throws AuthorizationException khi một người không qua `viewPerformance` — phòng thủ
     */
    public function endpoints(User $viewer, Collection $subjects, PerformancePeriod $period): array
    {
        $gate = Gate::forUser($viewer);

        foreach ($subjects as $subject) {
            $gate->authorize('viewPerformance', $subject);
        }

        if ($subjects->isEmpty()) {
            return [];
        }

        $start = $period->from->toDateString();
        $end = self::lastDay($period)->toDateString();
        $readable = $start <= $end;

        $rows = ! $readable ? collect() : PerformanceSnapshot::query()
            ->visibleToMany($viewer, $subjects)
            ->capturedOn($period->from, self::lastDay($period))
            ->dailyTotals()
            ->toBase()
            ->get()
            ->groupBy(fn (object $row): int => (int) $row->user_id)
            ->map(fn (Collection $days): Collection => $days->keyBy(fn (object $row): string => self::day($row->captured_on)));

        $endpoints = [];

        foreach ($subjects as $subject) {
            $id = (int) $subject->getKey();
            $first = $readable ? self::captured($rows->get($id)?->get($start)) : null;
            $last = $readable ? self::captured($rows->get($id)?->get($end)) : null;

            $endpoints[$id] = [
                'staleStart' => $first === null ? null : (int) $first->stale_matters,
                'staleEnd' => $last === null ? null : (int) $last->stale_matters,
                'overdueStart' => $first === null ? null : (int) $first->overdue_deadlines,
                'overdueEnd' => $last === null ? null : (int) $last->overdue_deadlines,
            ];
        }

        return $endpoints;
    }

    /** @return list<string> mọi ngày `Y-m-d` từ đầu kỳ tới ngày cuối đọc được (rỗng khi kỳ bắt đầu hôm nay) */
    private static function dates(PerformancePeriod $period): array
    {
        $dates = [];

        for ($day = $period->from; $day->lte(self::lastDay($period)); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /** Ngày cuối đọc được: ngày cuối kỳ, nhưng không muộn hơn hôm qua. */
    private static function lastDay(PerformancePeriod $period): CarbonImmutable
    {
        $yesterday = today()->toImmutable()->subDay();

        return $period->to->lt($yesterday) ? $period->to : $yesterday;
    }

    /** Dòng tổng của một ngày, hoặc `null` khi ngày đó không có dòng `normal` (tác vụ không chạy). */
    private static function captured(?object $row): ?object
    {
        return $row === null || (int) $row->normal_rows === 0 ? null : $row;
    }

    /** `Y-m-d` của cột `captured_on` đọc qua `toBase()`: SQLite trả `Y-m-d 00:00:00`, MariaDB trả `Y-m-d`. */
    private static function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
