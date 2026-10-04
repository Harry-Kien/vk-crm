<?php

namespace App\Actions\Performance;

use App\Actions\Document\ChecklistProgress;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\ActivityOwningMatter;
use App\Support\MatterStaleness;
use App\Support\Performance\TeamRoster;
use App\Support\Performance\TeamWorkloadRow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Số "bây giờ" của trang "Theo dõi đội ngũ" (M13, R1, cột N1–N11): mỗi người được theo dõi đang giữ
 * gì, cái gì đang nguy hiểm — như `$viewer` đọc được. Trang `TeamOverview` (mọi người) và trang của
 * một người (Task 5, một người) cùng gọi đây; không màn hình nào tự đếm.
 *
 * # Mỗi chỉ số MỘT truy vấn gộp, gốc là `listableBy($viewer)` (R4, R11)
 *
 * Mỗi cột N1–N10 là một truy vấn `GROUP BY` cột quy người của R5 (`lead_lawyer_id`, ghế đội ngũ,
 * `responsible_user_id`, người giữ luồng), trên tập vụ `Matter::listableBy($viewer)` — KHÔNG lọc theo
 * tập người trong SQL: hàm giữ lại dòng của `$subjects` bằng PHP. Vì vậy số truy vấn
 * không đổi theo số người, và một con số về X là một phép ĐẾM trên phần giao `listableBy(V)` ∩ việc
 * của X, không bao giờ một phép trừ (R4). Không có tổng toàn văn phòng nào tính ngoài `listableBy`.
 *
 * # Không định nghĩa thứ hai
 *
 * Tệp này không viết điều kiện nghiệp vụ nào (`NoSecondDefinitionTest` quét nó): mỗi tập là một
 * scope hay một hàm đã có ở đúng lớp đang giữ luật, cùng hình dạng mà widget trang chủ dùng —
 * `Matter::scopeOpen()`/`scopeClosed()` (N1, N3; `LoadPerLawyerWidget`), `scopeWithSupportingMember()`
 * (N2), `MatterStaleness::scopeStale()`/`scopeNotMeasurable()` (N4; `StaleMattersWidget`),
 * `Deadline::scopeOverdue()`/`scopeDueWithin()` trên vụ `open()->listableBy()` (N5, N6;
 * `UpcomingDeadlinesWidget::rowsFor()`), `ChecklistProgress::mattersAwaitingClient()` (N7;
 * `MattersMissingDocumentsWidget::rowsFor()` cho số trong ngoặc), `MatterChecklistItem::scopeAwaitingReview()`
 * trên vụ `listableBy()` không lọc `open()` (N8; `PendingChecklistReviewsWidget::rowsFor()`),
 * `ClientRequest::scopeAwaitingOffice()` + `scopeWithHolder()` (N9; người mà đường thông báo báo),
 * `ChecklistProgress::totalsByLead()` (N10; thanh "Đã nộp X/Y"), `ActivityOwningMatter::scopeOwnedByVisibleMatters()`
 * (N11). `TeamWorkloadTest` ghim từng cột vào nguồn của nó, cho trưởng phòng và cho luật sư.
 *
 * # N11 chỉ khi được hỏi, và chỉ cho người được hỏi (phán quyết Task 4, 2026-10-04)
 *
 * Kế hoạch M13 (Task 4) đặt sẵn ngưỡng: truy vấn gộp N11 trên dữ liệu benchmark quá 150 ms thì cột
 * N11 rời trang tổng quan, chỉ còn trên trang của một người, tính cho một người. Đo trên MariaDB
 * (`tests/Benchmark/TeamPerformanceBenchmarkTest.php`, 150.000 dòng nhật ký): gộp cho cả 30 người mất
 * 297,6 ms — luật sở hữu dòng của `ActivityOwningMatter` là một chuỗi `OR` trên mọi loại chủ thể, phải
 * xét từng dòng nhật ký của mọi người. Vì vậy N11 chỉ tính khi `$withLastMatterActivity` (trang
 * `TeamMember`, Task 5), bằng MỘT truy vấn giới hạn ở `causer_id` của chính `$subjects` (index `causer`
 * của `activity_log`); trang tổng quan không hỏi, và trường `lastMatterActivityAt` của nó là `null`.
 * Ghi ở "Ghi chú M13" của PROGRESS.
 *
 * # "Không áp dụng" theo quyền (R6)
 *
 * Cột của người phụ trách vụ (N1, N3, N4, N7, N8, N10) là `null` khi và chỉ khi
 * {@see TeamRoster::leadsMatters()} sai — theo QUYỀN, không bao giờ theo "có vụ nào không": một luật
 * sư chỉ phụ trách vụ `restricted` nhận 0 với trưởng phòng, không phải "Không áp dụng" (R4).
 *
 * # Phòng thủ
 *
 * Hỏi `viewPerformance` cho từng `$subject` dù trang đã lọc qua `TeamRoster::subjectsFor()`; ném
 * {@see AuthorizationException} ở người đầu tiên không qua. `$subjects` của `TeamRoster` đã nạp sẵn
 * vai trò và quyền, nên các lần hỏi đó không thêm truy vấn (R11).
 */
final class BuildTeamWorkload
{
    /**
     * @param  Collection<int, User>  $subjects
     * @param  bool  $withLastMatterActivity  tính N11 cho `$subjects` (trang của một người); `false` thì
     *                                        `lastMatterActivityAt` là `null` (xem docblock lớp)
     * @return array<int, TeamWorkloadRow> khoá là `user_id`, cùng thứ tự với `$subjects`
     *
     * @throws AuthorizationException khi một `$subject` không qua `viewPerformance` — phòng thủ, trang đã lọc
     */
    public function handle(User $viewer, Collection $subjects, bool $withLastMatterActivity = false): array
    {
        $gate = Gate::forUser($viewer);

        foreach ($subjects as $subject) {
            $gate->authorize('viewPerformance', $subject);
        }

        if ($subjects->isEmpty()) {
            return [];
        }

        $matters = fn (): Builder => Matter::query()->listableBy($viewer);
        $openMatters = fn (Builder $matter): Builder => $matter->open()->listableBy($viewer);

        // N1, N3 — luật sư phụ trách hiện tại.
        $leadOpen = self::countPer($matters()->open(), 'matters.lead_lawyer_id');
        $leadClosed = self::countPer($matters()->closed(), 'matters.lead_lawyer_id');

        // N2 — ghế luật sư cộng sự, trợ lý trên vụ đang mở.
        $teamOpen = self::countPer($matters()->open()->withSupportingMember(), 'supporting_seat.user_id');

        // N4 — quá hạn cập nhật, và vụ luật đó không đo được.
        $stale = self::countPer(MatterStaleness::scopeStale($matters()), 'matters.lead_lawyer_id');
        $notMeasurable = self::countPer(MatterStaleness::scopeNotMeasurable($matters()), 'matters.lead_lawyer_id');

        // N5, N6 — người giữ mốc hiện tại, trên vụ đang mở.
        $overdue = self::countPer(
            Deadline::query()->overdue()->whereHas('matter', $openMatters),
            'deadlines.responsible_user_id',
        );
        $dueSoon = self::countPer(
            Deadline::query()->dueWithin(Deadline::UPCOMING_WINDOW_DAYS)->whereHas('matter', $openMatters),
            'deadlines.responsible_user_id',
        );

        // N7 — vụ chờ giấy tờ của khách; trong ngoặc, vụ đã chờ quá STUCK_AFTER_DAYS.
        $awaitingClient = self::countPer(ChecklistProgress::mattersAwaitingClient($matters()), 'matters.lead_lawyer_id');
        $awaitingClientStuck = self::countPer(
            ChecklistProgress::mattersAwaitingClient($matters(), ChecklistProgress::STUCK_AFTER_DAYS),
            'matters.lead_lawyer_id',
        );

        // N8 — đầu mục chờ duyệt, theo người phụ trách vụ; như widget, không lọc `open()`.
        $awaitingReview = self::countPer(
            MatterChecklistItem::query()
                ->awaitingReview()
                ->whereHas('matter', fn (Builder $matter): Builder => $matter->listableBy($viewer))
                ->join('matters as review_matter', 'review_matter.id', '=', 'matter_checklist_items.matter_id'),
            'review_matter.lead_lawyer_id',
        );

        // N9 — người đang giữ luồng (người được giao còn tài khoản, không thì luật sư phụ trách).
        $awaitingOffice = self::countPer(
            ClientRequest::query()->awaitingOffice()->whereHas('matter', $openMatters)->withHolder(),
            ClientRequest::holderIdSql(),
        );

        // N10 — Σ X / Σ Y trên các vụ đang mở.
        $checklist = ChecklistProgress::totalsByLead($matters()->open());

        // N11 — chỉ khi được hỏi, chỉ cho người được hỏi (xem docblock lớp).
        $lastActivity = $withLastMatterActivity ? self::lastMatterActivity($viewer, $subjects) : [];

        $rows = [];

        foreach ($subjects as $subject) {
            $id = (int) $subject->getKey();
            $leads = TeamRoster::leadsMatters($subject);
            $leadOnly = fn (array $counts): ?int => $leads ? ($counts[$id] ?? 0) : null;

            $rows[$id] = new TeamWorkloadRow(
                userId: $id,
                name: (string) $subject->name,
                isActive: (bool) $subject->is_active,
                leadsMatters: $leads,
                leadOpen: $leadOnly($leadOpen),
                teamOpen: $teamOpen[$id] ?? 0,
                leadClosed: $leadOnly($leadClosed),
                stale: $leadOnly($stale),
                notMeasurable: $leadOnly($notMeasurable),
                overdueDeadlines: $overdue[$id] ?? 0,
                deadlinesDueSoon: $dueSoon[$id] ?? 0,
                awaitingClientMatters: $leadOnly($awaitingClient),
                awaitingClientStuck: $leadOnly($awaitingClientStuck),
                awaitingReviewItems: $leadOnly($awaitingReview),
                awaitingOfficeRequests: $awaitingOffice[$id] ?? 0,
                checklistSettled: $leads ? ($checklist[$id]['settled'] ?? 0) : null,
                checklistTotal: $leads ? ($checklist[$id]['total'] ?? 0) : null,
                lastMatterActivityAt: isset($lastActivity[$id]) ? CarbonImmutable::parse($lastActivity[$id]) : null,
            );
        }

        return $rows;
    }

    /**
     * Đếm dòng của `$query` theo biểu thức quy người `$person` (R5) — một truy vấn `GROUP BY`. Thay
     * phần chọn của truy vấn (scope như `withHolder()`, `withSupportingMember()` tự chọn thêm cột) bằng
     * đúng người và số đếm; không thêm điều kiện nào.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<int, int> khoá là id người
     */
    private static function countPer(Builder $query, string $person): array
    {
        return $query
            ->select(DB::raw("{$person} as person_id"))
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy(DB::raw($person))
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->person_id => (int) $row->aggregate])
            ->all();
    }

    /**
     * N11: `MAX(created_at)` của dòng nhật ký do chính `$subjects` gây ra (`causer_type = user`,
     * `causer_id` thuộc `$subjects` — giới hạn theo người là phán quyết N11 ở docblock lớp), theo
     * `causer_id`, chỉ trên dòng quy được về một vụ trong `listableBy($viewer)` —
     * {@see ActivityOwningMatter::scopeOwnedByVisibleMatters()}: dòng không thuộc vụ nào (đăng nhập)
     * không bao giờ tính, kể cả với admin; dòng tiền chỉ khi người xem có `billing.view`.
     *
     * @param  Collection<int, User>  $subjects
     * @return array<int, string> khoá là id người, giá trị là thời điểm dạng chuỗi của CSDL
     */
    private static function lastMatterActivity(User $viewer, Collection $subjects): array
    {
        $rows = Activity::query()
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', $subjects->map(fn (User $subject): int => (int) $subject->getKey())->all());
        ActivityOwningMatter::scopeOwnedByVisibleMatters($rows, $viewer);

        return $rows
            ->select('causer_id')
            ->selectRaw('MAX('.$rows->qualifyColumn('created_at').') as last_at')
            ->groupBy('causer_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->causer_id => (string) $row->last_at])
            ->all();
    }
}
