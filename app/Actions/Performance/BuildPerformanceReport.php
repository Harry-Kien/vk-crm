<?php

namespace App\Actions\Performance;

use App\Actions\Document\ReviewChecklistItem;
use App\Enums\DeadlineOutcome;
use App\Enums\Permission;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use App\Support\ActivityOwningMatter;
use App\Support\Billing\CollectedRevenue;
use App\Support\BusinessHours;
use App\Support\Performance\DeadlineHolderAtDue;
use App\Support\Performance\LeadAt;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\PerformanceReport;
use App\Support\Performance\PerformanceRow;
use App\Support\Performance\Ratio;
use App\Support\Performance\RequestHolderAt;
use App\Support\Performance\ResponseTime;
use App\Support\Performance\TeamRoster;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Số TRONG MỘT KỲ của trang "Hiệu suất theo kỳ" (M13, R1, cột P1–P7, P9, P10, "Lĩnh vực chính", dòng
 * "Chung"): mỗi người được theo dõi đã làm đúng hạn tới đâu — như `$viewer` đọc được. Trang `Performance`
 * gọi đây; không màn hình nào tự đếm. Cột P8 (xu hướng đầu kỳ → cuối kỳ, Task 7) không tính ở đây mà đọc
 * từ ảnh chụp qua {@see BuildPerformanceTrend::endpoints()} — luật xem của ảnh chụp là
 * `PerformanceSnapshot::visibleLevels()`, không phải `listableBy()`.
 *
 * # Gốc là `listableBy($viewer)`: phần giao, không phép trừ (R4)
 *
 * Mọi tập (mốc, yêu cầu, dòng tiến độ, vụ kết thúc, dòng duyệt, khoản thu) nằm trong
 * `Matter::listableBy($viewer)`; một con số về X là một phép ĐẾM trên `listableBy(V)` ∩ việc của X. Không
 * tổng toàn văn phòng nào tính ngoài tập đó, không dòng "đã ẩn N vụ".
 *
 * # Hai cách quy người, mỗi cách một luật (R5, R11)
 *
 * - **Theo một cột**, một truy vấn `GROUP BY` cột quy người, KHÔNG lọc tập người trong SQL: P2
 *   (`deadlines.responsible_user_id` của mốc đã gỡ — mốc đã gỡ không bao giờ đổi người giữ), P4
 *   (`stage_logs.created_by`), P6 (`activity_log.causer_id`), P7 (`payments.attributed_lawyer_id`).
 * - **Theo lịch sử**, nạp TẬP CỦA KỲ không lọc người rồi dựng người giữ MỘT lần cho cả lô: P1 qua
 *   {@see DeadlineHolderAtDue} (người giữ mốc vào ngày đến hạn, R9), P3/P10 qua {@see RequestHolderAt}
 *   (người giữ luồng lúc trả lời lần đầu, hoặc lúc mốc cắt nếu chưa trả lời, R18), P5 qua {@see LeadAt}.
 *   **Không** nạp mốc hay luồng bằng `responsible_user_id IN (tập người)`: nạp như vậy làm rơi đúng mốc đã
 *   bị chuyển đi SAU khi lỡ — người lỡ mốc không bao giờ thấy nó, R9 bị vô hiệu lặng lẽ.
 *
 * Rồi giữ dòng của `$subjects` bằng PHP. Số truy vấn không đổi theo số người (`PerformancePageTest`, 3 ↔ 12).
 *
 * **P5 hỏi `LeadAt` tại HẾT NGÀY kết thúc (23:59:59).** `closed_at` là cột `date`: cast đọc về 00:00 của
 * ngày kết thúc trên SQLite lẫn MariaDB, nên giờ đóng thật không còn. Hỏi lúc 00:00 thì một lần bàn giao
 * buổi sáng rồi người nhận đóng vụ buổi chiều tính cho người CŨ; hỏi lúc 23:59:59 — cùng hình dạng "người
 * giữ mốc vào ngày đến hạn" — tính cho người đang phụ trách vào cuối ngày kết thúc. Giới hạn: vụ đóng rồi
 * mới bàn giao TRONG CÙNG NGÀY tính cho người nhận; câu giải thích của P5 nói điều này.
 *
 * # Không định nghĩa thứ hai
 *
 * Tệp này không viết điều kiện nghiệp vụ nào (`NoSecondDefinitionTest` quét nó). Mỗi tập là một scope ở
 * đúng lớp giữ luật: `Deadline::dueBetween()`/`removedBetween()`/`outcomeAt()` (P1, P2),
 * `ClientRequest::createdBetween()`/`isClosedWithoutAnswer()`/`answeredBy()` (P3, P10),
 * `StageLog::entries()`/`occurredBetween()` (P4), `Matter::closedWithin()` (P5),
 * `ActivityOwningMatter::scopeEventsWithin()`/`scopeOwnedByVisibleMatters()` (P6),
 * `CollectedRevenue::query()` (P7, cùng truy vấn của trang Doanh thu). Hai cận và mốc cắt từ
 * {@see PerformancePeriod}.
 *
 * # Dòng "Chung" (R8), "Không áp dụng" (R6), doanh thu (R2)
 *
 * - Dòng "Chung" chỉ khi người xem có `performance.viewAny`: mọi việc trong `listableBy(V)` theo cùng công
 *   thức, KHÔNG lọc người giữ — gồm việc của admin, người ngoài danh sách, người đã xoá mềm, và việc không
 *   quy được về ai (người giữ `null`). Vì vậy nó không bằng tổng các dòng.
 * - Cột của người phụ trách vụ (P4, P5, P7) là `null` khi và chỉ khi `TeamRoster::leadsMatters()` sai —
 *   theo QUYỀN, không theo "có vụ nào không".
 * - P7 chỉ được tính khi `PerformanceReport::$revenueVisible`: người xem được đọc tiền trên MỌI dòng của
 *   trang — dòng của mỗi người qua `UserPolicy::viewPerformanceRevenue()` (có `billing.view` và
 *   `revenue.viewAny`, hoặc là chính người đó), dòng "Chung" khi có `billing.view` và `revenue.viewAny`
 *   — và trang có ít nhất một dòng. Không thì không truy vấn tiền nào chạy.
 *
 * # Phòng thủ
 *
 * Hỏi `viewPerformance` cho từng `$subject` dù trang đã lọc qua `TeamRoster::subjectsForPeriod()`; ném
 * {@see AuthorizationException} ở người đầu tiên không qua. `TeamRoster` nạp sẵn vai trò và quyền, nên
 * các lần hỏi đó không thêm truy vấn (R11).
 */
final class BuildPerformanceReport
{
    /** Khoá của dòng "Chung" trong bộ đếm nội bộ (id người là số nguyên dương). */
    private const ALL = 'all';

    /** @var array<int|string, array<string, int>> người (hoặc `ALL`) => chỉ số => số đếm */
    private array $counts = [];

    /** @var array<int|string, list<float>> người (hoặc `ALL`) => giờ LÀM VIỆC phản hồi của từng luồng đã trả lời (R17) */
    private array $hours = [];

    /** @var array<int|string, array<int, true>> người (hoặc `ALL`) => vụ có việc trong kỳ ("Lĩnh vực chính") */
    private array $worked = [];

    /** @var array<int|string, array<int, true>> người (hoặc `ALL`) => vụ được đưa sang giai đoạn mới (P4) */
    private array $moved = [];

    /**
     * @param  Collection<int, User>  $subjects
     *
     * @throws AuthorizationException khi một `$subject` không qua `viewPerformance` — phòng thủ, trang đã lọc
     */
    public function handle(User $viewer, Collection $subjects, PerformancePeriod $period): PerformanceReport
    {
        $gate = Gate::forUser($viewer);

        foreach ($subjects as $subject) {
            $gate->authorize('viewPerformance', $subject);
        }

        $withReference = $gate->allows(Permission::PerformanceViewAny->value);

        // Cột doanh thu có trên trang khi và chỉ khi người xem được đọc tiền trên MỌI dòng của trang: dòng
        // của một người qua `UserPolicy::viewPerformanceRevenue()` (R2), dòng "Chung" khi có `billing.view`
        // và `revenue.viewAny` (R8). Trang không có dòng nào thì không có cột.
        $mayReadRevenue = $subjects->map(fn (User $subject): bool => $gate->allows('viewPerformanceRevenue', $subject));

        if ($withReference) {
            $mayReadRevenue->push($gate->allows(Permission::BillingView->value) && $gate->allows(Permission::RevenueViewAny->value));
        }

        $revenueVisible = $mayReadRevenue->isNotEmpty() && ! $mayReadRevenue->contains(false);

        $this->counts = $this->hours = $this->worked = $this->moved = [];

        $visible = fn (Builder $matters): Builder => $matters->listableBy($viewer);

        $this->countDeadlines($visible, $period);
        $this->countRequests($visible, $period);
        $this->countStageEntries($visible, $period);
        $this->countClosedMatters($viewer, $period);
        $this->countReviews($viewer, $period);

        if ($revenueVisible) {
            $this->countRevenue($viewer, $period);
        }

        $areas = $this->mainPracticeAreas();

        // P8 (Task 7) — đầu kỳ → cuối kỳ từ ảnh chụp, MỘT truy vấn cho cả trang; dòng "Chung" không có (PerformanceRow).
        $trend = app(BuildPerformanceTrend::class)->endpoints($viewer, $subjects, $period);

        $rows = [];

        foreach ($subjects as $subject) {
            $id = (int) $subject->getKey();
            $rows[$id] = $this->row($id, (string) $subject->name, (bool) $subject->is_active, TeamRoster::leadsMatters($subject), $areas[$id] ?? [], $revenueVisible, $trend[$id]);
        }

        $reference = $withReference
            ? $this->row(null, __('performance.period_page.reference_name'), true, true, $areas[self::ALL] ?? [], $revenueVisible, null)
            : null;

        return new PerformanceReport($reference, $rows, $revenueVisible);
    }

    /**
     * P1 (đúng hạn, trễ, lỡ — người giữ vào ngày đến hạn) và P2 (mốc đã gỡ trong kỳ).
     *
     * @param  Closure(Builder): Builder  $visible
     */
    private function countDeadlines(Closure $visible, PerformancePeriod $period): void
    {
        $deadlines = Deadline::query()
            ->dueBetween($period->bounds())
            ->whereHas('matter', $visible)
            ->with('matter')
            ->get();

        $holders = DeadlineHolderAtDue::resolve($deadlines);
        $cutoff = $period->cutoff();

        foreach ($deadlines as $deadline) {
            $metric = match ($deadline->outcomeAt($cutoff)) {
                DeadlineOutcome::OnTime => 'onTime',
                DeadlineOutcome::Late => 'late',
                DeadlineOutcome::Missed => 'missed',
                null => null,
            };

            if ($metric !== null) {
                $this->add($holders[$deadline->getKey()], $metric, 1, (int) $deadline->matter_id);
            }
        }

        $removed = Deadline::query()
            ->removedBetween($period->bounds())
            ->whereHas('matter', $visible)
            ->select('deadlines.responsible_user_id as person_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('deadlines.responsible_user_id')
            ->toBase()
            ->get();

        foreach ($removed as $row) {
            $this->add(self::personIn($row->person_id), 'removed', (int) $row->aggregate);
        }
    }

    /**
     * P3 (trả lời, thời gian phản hồi theo GIỜ LÀM VIỆC — R17) và P10 (đóng không trả lời) — người giữ luồng lúc trả lời lần đầu,
     * hoặc lúc mốc cắt với luồng chưa trả lời và luồng đóng không trả lời (R18).
     *
     * @param  Closure(Builder): Builder  $visible
     */
    private function countRequests(Closure $visible, PerformancePeriod $period): void
    {
        $cutoff = $period->cutoff();
        $businessHours = BusinessHours::fromConfig();

        $requests = ClientRequest::query()
            ->createdBetween($period->bounds())
            ->whereHas('matter', $visible)
            ->with('matter')
            ->get();

        $holders = RequestHolderAt::resolve(
            $requests,
            fn (ClientRequest $request): CarbonInterface => $request->answeredBy($cutoff) ? $request->answered_at : $cutoff,
        );

        foreach ($requests as $request) {
            $holder = $holders[$request->getKey()];
            $matterId = (int) $request->matter_id;

            if ($request->isClosedWithoutAnswer()) {
                $this->add($holder, 'closedUnanswered', 1, $matterId);

                continue;
            }

            $this->add($holder, 'received', 1, $matterId);

            if ($request->answeredBy($cutoff)) {
                $this->add($holder, 'answered', 1);

                // R17 — GIỜ LÀM VIỆC, qua ĐÚNG định nghĩa của M10 (`App\Support\BusinessHours`, cấu hình
                // `vkcrm.business_hours`); không viết định nghĩa thứ hai ở đây. Phút trọn vẹn, ra giờ.
                $hours = $businessHours->minutesBetween($request->created_at, $request->answered_at) / 60;

                foreach (array_unique([self::ALL, $holder ?? self::ALL]) as $bucket) {
                    $this->hours[$bucket][] = $hours;
                }
            }
        }
    }

    /**
     * P4 — dòng đưa một vụ VÀO giai đoạn mới (`StageLog::entries()`) có ngày ghi trong kỳ, theo người ghi:
     * số dòng và số vụ khác nhau. Một truy vấn, gộp theo (người ghi, vụ).
     *
     * @param  Closure(Builder): Builder  $visible
     */
    private function countStageEntries(Closure $visible, PerformancePeriod $period): void
    {
        $entries = StageLog::query()
            ->entries()
            ->occurredBetween($period->bounds())
            ->whereHas('matter', $visible)
            ->select('stage_logs.created_by as person_id', 'stage_logs.matter_id as matter_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('stage_logs.created_by', 'stage_logs.matter_id')
            ->toBase()
            ->get();

        foreach ($entries as $row) {
            $person = self::personIn($row->person_id);
            $matterId = (int) $row->matter_id;

            $this->add($person, 'stageEntries', (int) $row->aggregate, $matterId);

            foreach (array_unique([self::ALL, $person ?? self::ALL]) as $bucket) {
                $this->moved[$bucket][$matterId] = true;
            }
        }
    }

    /** P5 — vụ kết thúc trong kỳ, quy về luật sư phụ trách vào cuối ngày kết thúc (xem docblock lớp). */
    private function countClosedMatters(User $viewer, PerformancePeriod $period): void
    {
        $closed = Matter::query()
            ->listableBy($viewer)
            ->closedWithin($period->from, $period->to)
            ->get(['matters.id', 'matters.closed_at', 'matters.lead_lawyer_id']);

        $leads = LeadAt::resolve($closed, fn (Matter $matter): CarbonInterface => $matter->closed_at->copy()->endOfDay());

        foreach ($closed as $matter) {
            $this->add($leads[$matter->getKey()], 'mattersClosed', 1, (int) $matter->getKey());
        }
    }

    /** P6 — dòng `checklist_item_reviewed` trong kỳ, trên vụ người xem thấy được, theo người bấm. */
    private function countReviews(User $viewer, PerformancePeriod $period): void
    {
        $reviews = Activity::query();
        ActivityOwningMatter::scopeEventsWithin($reviews, ReviewChecklistItem::AUDIT_EVENT, $period->bounds());
        ActivityOwningMatter::scopeOwnedByVisibleMatters($reviews, $viewer);

        $staff = (new User)->getMorphClass();

        $rows = $reviews
            ->select('activity_log.causer_type', 'activity_log.causer_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('activity_log.causer_type', 'activity_log.causer_id')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $this->add($row->causer_type === $staff ? self::personIn($row->causer_id) : null, 'itemsReviewed', (int) $row->aggregate);
        }
    }

    /** P7 — tiền đã thu trong kỳ qua `CollectedRevenue` (cùng truy vấn trang Doanh thu), theo luật sư lúc thu. */
    private function countRevenue(User $viewer, PerformancePeriod $period): void
    {
        $collected = CollectedRevenue::query($viewer, $period->bounds())
            ->select('payments.attributed_lawyer_id as person_id')
            ->selectRaw('SUM(payments.amount) as aggregate')
            ->groupBy('payments.attributed_lawyer_id')
            ->toBase()
            ->get();

        foreach ($collected as $row) {
            $this->add(self::personIn($row->person_id), 'revenue', (int) $row->aggregate);
        }
    }

    /**
     * "Lĩnh vực chính": với mỗi người (và dòng "Chung"), hai loại vụ có nhiều vụ nhất trong số vụ có việc
     * của kỳ đã quy về người đó (mốc P1 vào tập, luồng P3/P10, dòng P4, vụ P5), kèm số vụ; bằng nhau thì
     * theo tên. MỘT truy vấn `matter_type_id` cho hợp các vụ đó — mọi id đã đến từ các tập trên, tức đã
     * nằm trong `listableBy($viewer)`, nên truy vấn này không hỏi lại tầm nhìn.
     *
     * @return array<int|string, list<array{name: string, matters: int}>>
     */
    private function mainPracticeAreas(): array
    {
        $types = Matter::query()
            ->whereIntegerInRaw('matters.id', array_keys($this->worked[self::ALL] ?? []))
            ->join('matter_types', 'matter_types.id', '=', 'matters.matter_type_id')
            ->toBase()
            ->get(['matters.id as matter_id', 'matter_types.id as type_id', 'matter_types.name as type_name'])
            ->keyBy(fn (object $row): int => (int) $row->matter_id);

        $areas = [];

        foreach ($this->worked as $bucket => $matters) {
            $perType = [];

            foreach (array_keys($matters) as $matterId) {
                $type = $types[$matterId];
                $perType[(int) $type->type_id] ??= ['name' => (string) $type->type_name, 'matters' => 0];
                $perType[(int) $type->type_id]['matters']++;
            }

            usort($perType, fn (array $a, array $b): int => [$b['matters'], $a['name']] <=> [$a['matters'], $b['name']]);

            $areas[$bucket] = array_slice($perType, 0, 2);
        }

        return $areas;
    }

    /** Cộng `$by` vào chỉ số `$metric` của dòng "Chung" và — khi việc quy được về ai — của người đó. */
    private function add(?int $person, string $metric, int $by, ?int $matterId = null): void
    {
        foreach (array_unique([self::ALL, $person ?? self::ALL]) as $bucket) {
            $this->counts[$bucket][$metric] = ($this->counts[$bucket][$metric] ?? 0) + $by;

            if ($matterId !== null) {
                $this->worked[$bucket][$matterId] = true;
            }
        }
    }

    /**
     * @param  list<array{name: string, matters: int}>  $areas
     * @param  array{staleStart: ?int, staleEnd: ?int, overdueStart: ?int, overdueEnd: ?int}|null  $trend  P8; `null` ở dòng "Chung"
     */
    private function row(?int $userId, string $name, bool $isActive, bool $leadsMatters, array $areas, bool $revenueVisible, ?array $trend): PerformanceRow
    {
        $bucket = $userId ?? self::ALL;
        $count = fn (string $metric): int => $this->counts[$bucket][$metric] ?? 0;
        $leadOnly = fn (int $value): ?int => $leadsMatters ? $value : null;
        $hours = $this->hours[$bucket] ?? [];
        $deadlines = $count('onTime') + $count('late') + $count('missed');

        return new PerformanceRow(
            userId: $userId,
            name: $name,
            isActive: $isActive,
            leadsMatters: $leadsMatters,
            deadlinesOnTime: $count('onTime'),
            deadlinesLate: $count('late'),
            deadlinesMissed: $count('missed'),
            deadlinesRemoved: $count('removed'),
            onTimeRatio: new Ratio($count('onTime'), $deadlines),
            requestsReceived: $count('received'),
            requestsAnswered: $count('answered'),
            requestsClosedUnanswered: $count('closedUnanswered'),
            responseMedianHours: ResponseTime::median($hours),
            responseMeanHours: ResponseTime::mean($hours),
            stageEntries: $leadOnly($count('stageEntries')),
            mattersMoved: $leadOnly(count($this->moved[$bucket] ?? [])),
            mattersClosed: $leadOnly($count('mattersClosed')),
            itemsReviewed: $count('itemsReviewed'),
            revenueCollected: $revenueVisible ? $leadOnly($count('revenue')) : null,
            completionRatio: new Ratio($count('onTime') + $count('late') + $count('answered'), $deadlines + $count('received')),
            mainPracticeAreas: $areas,
            staleStart: $trend['staleStart'] ?? null,
            staleEnd: $trend['staleEnd'] ?? null,
            overdueStart: $trend['overdueStart'] ?? null,
            overdueEnd: $trend['overdueEnd'] ?? null,
        );
    }

    /** Id người của một cột quy người đọc qua `toBase()` (số hoặc chuỗi chữ số); rỗng là không ai. */
    private static function personIn(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
