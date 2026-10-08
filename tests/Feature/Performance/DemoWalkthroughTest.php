<?php

use App\Actions\Deadline\SetDeadlineCompletion;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\RevenueDashboard;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Pages\TeamOverview;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\Deadline;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\TeamWorkloadRow;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TeamPerformanceSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 8 — đi hết luồng nghiệm thu của kế hoạch (Task 8, "Đi hết luồng trên dữ liệu seed"), sáu bước, trên
 * ĐÚNG dữ liệu mẫu `DatabaseSeeder` dựng (`TeamPerformanceSeeder` cùng các seeder M1–M10), qua Livewire và
 * HTTP như người dùng đi. Mỗi bước một test; số ở đây là số của dữ liệu mẫu, đọc trong docblock của
 * `TeamPerformanceSeeder`. Hàm toàn cục mang tiền tố `m13t8Walk`.
 */
beforeEach(function () {
    // MatterSeeder ghi tệp PDF thật (xem DemoDataSeederTest).
    Storage::fake('private');
    $this->seed(DatabaseSeeder::class);
    Filament::setCurrentPanel('admin');
});

function m13t8WalkUser(string $email): User
{
    return User::query()->where('email', $email)->firstOrFail();
}

/** Dòng số "bây giờ" ở đầu trang một người (`TeamMember::workload()`, riêng tư — đọc qua closure). */
function m13t8WalkWorkload(Testable $page): TeamWorkloadRow
{
    return (fn (): TeamWorkloadRow => $this->workload())->call($page->instance());
}

/** @return array<int|string, array<string, mixed>> dòng của "Hiệu suất theo kỳ", kỳ mặc định "tháng trước" */
function m13t8WalkPeriodRows(User $viewer): array
{
    test()->actingAs($viewer->fresh(), 'web');

    return Livewire::test(Performance::class)->instance()->getTableRecords()->all();
}

it('step 1 — the manager sorts the team overview by overdue deadlines, opens lawyer A, sees two matters overdue for a client update, and the activity log shows it', function () {
    $manager = m13t8WalkUser('quanly@luatvukhang.com');
    $lawyerA = m13t8WalkUser(TeamPerformanceSeeder::LAWYER_A_EMAIL);
    $this->actingAs($manager, 'web');

    $overview = Livewire::test(TeamOverview::class)->call('sortTable', 'overdueDeadlines', 'desc');
    $rows = $overview->instance()->getTableRecords();

    expect($rows->first()['userId'])->toBe($lawyerA->id)
        ->and($rows->first()['overdueDeadlines'])->toBe(2);

    $this->get(TeamMember::getUrl(['user' => $lawyerA->id], panel: 'admin'))->assertOk();
    $member = Livewire::test(TeamMember::class, ['user' => $lawyerA->id]);

    expect(m13t8WalkWorkload($member)->stale)->toBe(2)
        ->and(Activity::query()->where('event', 'performance_viewed')->where('causer_id', $manager->id)->whereNull('subject_id')->count())->toBeGreaterThanOrEqual(1)
        ->and(Activity::query()->where('event', 'performance_viewed')->where('causer_id', $manager->id)->where('subject_id', $lawyerA->id)->count())->toBeGreaterThanOrEqual(1);

    // Người thứ hai trong cùng một test: phiên HTTP của trưởng phòng còn đó, và một lần GET nữa với tài khoản khác
    // bị đẩy về trang đăng nhập (đã thử: 302 → /admin/login) — đọc trang nhật ký qua Livewire.
    $this->actingAs(m13t8WalkUser('admin@luatvukhang.com'), 'web');
    Livewire::test(ActivityLogPage::class)->assertSee(__('activity.events.performance_viewed'));
});

it('step 2 — lawyer A opens "Việc của tôi" and sees three matters overdue for a client update, the restricted one included, and cannot open lawyer B', function () {
    $lawyerA = m13t8WalkUser(TeamPerformanceSeeder::LAWYER_A_EMAIL);
    $lawyerB = m13t8WalkUser(TeamPerformanceSeeder::ON_TIME_EMAIL);
    $this->actingAs($lawyerA, 'web');

    expect(TeamMember::getNavigationLabel())->toBe('Việc của tôi')
        ->and(TeamMember::getNavigationUrl())->toBe(TeamMember::getUrl(['user' => $lawyerA->id]));

    $this->get(TeamMember::getUrl(['user' => $lawyerA->id], panel: 'admin'))->assertOk();
    $own = m13t8WalkWorkload(Livewire::test(TeamMember::class, ['user' => $lawyerA->id]));

    expect($own->stale)->toBe(3)
        ->and($own->overdueDeadlines)->toBeGreaterThan(2);

    $this->get(TeamMember::getUrl(['user' => $lawyerB->id], panel: 'admin'))->assertNotFound();
});

it('step 3 — on "Hiệu suất", last month, the receiver carries nothing of the departed lawyer, who keeps a row; closed-unanswered sits apart; no rank; the reference row leads; sorting a ratio changes nothing', function () {
    $manager = m13t8WalkUser('quanly@luatvukhang.com');
    $lawyerA = m13t8WalkUser(TeamPerformanceSeeder::LAWYER_A_EMAIL);
    $receiver = m13t8WalkUser(TeamPerformanceSeeder::RECEIVER_EMAIL);
    $departed = m13t8WalkUser(TeamPerformanceSeeder::DEPARTED_EMAIL);
    $onTime = m13t8WalkUser(TeamPerformanceSeeder::ON_TIME_EMAIL);
    $this->actingAs($manager, 'web');

    $page = Livewire::test(Performance::class);
    $rows = $page->instance()->getTableRecords()->all();

    expect($page->instance()->period()->key)->toBe(PerformancePeriod::LAST_MONTH)
        ->and(array_key_first($rows))->toBe(Performance::REFERENCE_KEY)
        // Người nhận bàn giao: không mốc lỡ, không luồng nào của người trước (R9, R18).
        ->and([$rows[$receiver->id]['deadlinesMissed'], $rows[$receiver->id]['requestsReceived'], $rows[$receiver->id]['requestsClosedUnanswered']])->toBe([0, 0, 0])
        // Người nghỉ việc vẫn có dòng của tháng trước (R3): 1 đúng hạn, 1 lỡ, 2/3 luồng đã trả lời.
        ->and($rows[$departed->id]['isActive'])->toBeFalse()
        ->and([$rows[$departed->id]['deadlinesOnTime'], $rows[$departed->id]['deadlinesMissed']])->toBe([1, 1])
        ->and([$rows[$departed->id]['requestsAnswered'], $rows[$departed->id]['requestsReceived']])->toBe([2, 3])
        // Luật sư A, như trưởng phòng đọc: 2 đúng hạn, 1 trễ, 2 lỡ; một yêu cầu đóng không trả lời ở cột riêng.
        ->and([$rows[$lawyerA->id]['deadlinesOnTime'], $rows[$lawyerA->id]['deadlinesLate'], $rows[$lawyerA->id]['deadlinesMissed']])->toBe([2, 1, 2])
        ->and([$rows[$lawyerA->id]['requestsClosedUnanswered'], $rows[$lawyerA->id]['requestsReceived']])->toBe([1, 1])
        // Luật sư đúng hạn đều.
        ->and([$rows[$onTime->id]['deadlinesOnTime'], $rows[$onTime->id]['deadlinesLate'], $rows[$onTime->id]['deadlinesMissed']])->toBe([5, 0, 0]);

    preg_match('/<thead\b.*?<\/thead>/s', $page->html(), $head);
    expect(mb_strtolower(strip_tags($head[0] ?? '')))->not->toContain('hạng')
        ->and(array_keys($page->instance()->getTable()->getColumns()))->not->toContain('rank');

    $order = array_keys($rows);

    expect(array_keys($page->call('sortTable', 'onTimeRatio', 'desc')->instance()->getTableRecords()->all()))->toBe($order)
        ->and(array_keys($page->call('sortTable', 'completionRatio', 'asc')->instance()->getTableRecords()->all()))->toBe($order);

    // A đọc dòng của chính mình: mốc lỡ của vụ `restricted` có trong đó, trưởng phòng thì không (R4).
    expect(m13t8WalkPeriodRows($lawyerA)[$lawyerA->id]['deadlinesMissed'])->toBe(3);
});

it('step 4 — the accountant gets 404 on the three pages, and the revenue page still filters by lawyer', function () {
    $accountant = m13t8WalkUser('ketoan@luatvukhang.com');
    $lawyerA = m13t8WalkUser(TeamPerformanceSeeder::LAWYER_A_EMAIL);
    $departed = m13t8WalkUser(TeamPerformanceSeeder::DEPARTED_EMAIL);
    $this->actingAs($accountant, 'web');

    $this->get(TeamOverview::getUrl(panel: 'admin'))->assertNotFound();
    $this->get(TeamMember::getUrl(['user' => $lawyerA->id], panel: 'admin'))->assertNotFound();
    $this->get(Performance::getUrl(panel: 'admin'))->assertNotFound();
    $this->get(RevenueDashboard::getUrl(panel: 'admin'))->assertOk();

    $lastMonth = PerformancePeriod::fromFilters(['period' => PerformancePeriod::LAST_MONTH]);
    $rows = Livewire::test(RevenueOverTimeWidget::class, ['pageFilters' => [
        'period' => 'custom',
        'date_from' => $lastMonth->from->toDateString(),
        'date_to' => $lastMonth->to->toDateString(),
        'lawyer_id' => $departed->id,
    ]])->instance()->numberTableRows();

    expect(collect($rows)->pluck('value')->all())->toContain(Money::format(30_000_000));
});

it('step 5 — an assistant sees only her own row, without revenue, with "Không áp dụng" for the lead-only columns, and the same on her own page', function () {
    $assistant = m13t8WalkUser('troly1@luatvukhang.com');
    $this->actingAs($assistant, 'web');

    $page = Livewire::test(Performance::class);
    $rows = $page->instance()->getTableRecords()->all();

    expect(array_keys($rows))->toBe([$assistant->id])
        ->and($page->instance()->revenueVisible())->toBeFalse()
        ->and($rows[$assistant->id]['stageEntries'])->toBeNull()
        ->and($rows[$assistant->id]['mattersClosed'])->toBeNull()
        ->and($rows[$assistant->id]['deadlinesOnTime'])->toBe(2);

    $page->assertSee(__('performance.not_applicable'))
        ->assertDontSee(__('performance.columns.p7'));

    $own = Livewire::test(TeamMember::class, ['user' => $assistant->id]);
    $workload = m13t8WalkWorkload($own);

    expect([$workload->leadOpen, $workload->leadClosed, $workload->stale, $workload->awaitingClientMatters, $workload->awaitingReviewItems, $workload->checklistTotal])
        ->toBe([null, null, null, null, null, null]);

    $own->assertSee(__('performance.not_applicable'));
});

it('step 6 — completing today a deadline missed last month leaves "Hiệu suất" for last month unchanged', function () {
    $manager = m13t8WalkUser('quanly@luatvukhang.com');
    $lawyerA = m13t8WalkUser(TeamPerformanceSeeder::LAWYER_A_EMAIL);
    $lastMonth = PerformancePeriod::fromFilters(['period' => PerformancePeriod::LAST_MONTH]);

    $before = m13t8WalkPeriodRows($manager);

    $missed = Deadline::query()
        ->where('responsible_user_id', $lawyerA->id)
        ->whereBetween('due_date', $lastMonth->bounds())
        ->where('is_completed', false)
        ->whereHas('matter', fn ($q) => $q->listableBy($manager))
        ->orderBy('due_date')
        ->firstOrFail();

    app(SetDeadlineCompletion::class)->handle($missed, true, $lawyerA);

    expect($missed->fresh()->is_completed)->toBeTrue()
        ->and(m13t8WalkPeriodRows($manager))->toEqual($before);
});
