<?php

use App\Actions\Performance\BuildPerformanceTrend;
use App\Actions\Schedule\CapturePerformanceSnapshots;
use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Widgets\Performance\OverdueTrendWidget;
use App\Filament\Admin\Widgets\Performance\StaleTrendWidget;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\PerformanceSnapshot;
use App\Models\User;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\Ratio;
use App\Support\Performance\TeamRoster;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * M13 Task 7 — xu hướng (P8, R10): `BuildPerformanceTrend` đọc ảnh chụp qua `PerformanceSnapshot::visibleTo()`
 * (R4), cộng dòng `normal` với dòng `restricted` khi người xem được thấy dòng `restricted`, để TRỐNG ngày
 * không có dòng `normal` (không vẽ 0), không bao giờ có điểm của hôm nay (hôm nay luôn tính trực tiếp).
 * Trang một người: 90 ngày kết thúc hôm qua; trang "Hiệu suất theo kỳ": cột P8 đầu kỳ → cuối kỳ (không muộn
 * hơn hôm qua), một truy vấn cho cả trang.
 *
 * Quét rò rỉ (Review Focus 1): trưởng phòng đọc xu hướng của L — qua Action, qua cột P8 và qua hai widget
 * gọi thẳng bằng Livewire — y hệt khi dòng `restricted` của L không tồn tại, và dòng đó không bao giờ được
 * truy vấn. Hàm toàn cục mang tiền tố `m13t7Tr`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-04 10:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản Trị Xu Hướng']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Xu Hướng']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư L']);
});

function m13t7TrSnap(User $person, string $day, Confidentiality $level, int $stale, int $overdue, int $settled = 0, int $total = 0): void
{
    PerformanceSnapshot::query()->create([
        'captured_on' => $day,
        'user_id' => $person->id,
        'confidentiality' => $level,
        'open_lead_matters' => 1,
        'stale_matters' => $stale,
        'overdue_deadlines' => $overdue,
        'checklist_settled' => $settled,
        'checklist_total' => $total,
    ]);
}

/** Dòng `restricted` của L ở mọi ngày có dòng normal — dữ liệu "vụ mật" của quét rò rỉ. */
function m13t7TrRestricted(User $lawyer): void
{
    foreach (['2026-07-06', '2026-09-01', '2026-09-30', '2026-10-02', '2026-10-03'] as $day) {
        m13t7TrSnap($lawyer, $day, Confidentiality::Restricted, 5, 7, 2, 9);
    }

    // Ngày chỉ có dòng restricted (tác vụ lỡ dòng normal không bao giờ xảy ra, nhưng dữ liệu hỏng thì có).
    m13t7TrSnap($lawyer, '2026-10-01', Confidentiality::Restricted, 5, 7, 2, 9);
}

/** Dòng normal của L ở vài ngày của 90 ngày trước 04/10/2026; ngày 01/10 thiếu (tác vụ lỡ). */
function m13t7TrNormal(User $lawyer): void
{
    m13t7TrSnap($lawyer, '2026-07-06', Confidentiality::Normal, 1, 2, 3, 4);
    m13t7TrSnap($lawyer, '2026-09-01', Confidentiality::Normal, 2, 0, 3, 5);
    m13t7TrSnap($lawyer, '2026-09-30', Confidentiality::Normal, 4, 1, 4, 5);
    m13t7TrSnap($lawyer, '2026-10-02', Confidentiality::Normal, 0, 3, 5, 5);
    m13t7TrSnap($lawyer, '2026-10-03', Confidentiality::Normal, 3, 0, 1, 6);
    // Hôm nay đã có ảnh chụp (chạy tay lúc 09:00): KHÔNG BAO GIỜ thành một điểm — hôm nay tính trực tiếp.
    m13t7TrSnap($lawyer, '2026-10-04', Confidentiality::Normal, 9, 9, 9, 9);
}

/** @return array{dates: list<string>, stale: list<int|null>, overdue: list<int|null>, checklist: list<string|null>} */
function m13t7TrMember(User $viewer, User $subject): array
{
    $trend = app(BuildPerformanceTrend::class)->handle(
        $viewer->fresh(),
        $subject->fresh(),
        PerformancePeriod::trailingDays(BuildPerformanceTrend::MEMBER_PAGE_DAYS),
    );

    $trend['checklist'] = array_map(fn (?Ratio $ratio): ?string => $ratio === null ? null : "{$ratio->numerator}/{$ratio->denominator}", $trend['checklist']);

    return $trend;
}

/** @return array<string, int|string|null> ngày => giá trị, chỉ những ngày có số */
function m13t7TrPoints(array $trend, string $series): array
{
    return array_filter(array_combine($trend['dates'], $trend[$series]), fn ($value): bool => $value !== null);
}

/** Chữ trong ô P8 (cột cuối) của dòng bảng mang tên `$name` trên trang "Hiệu suất theo kỳ". */
function m13t7TrCell(string $html, string $name): string
{
    preg_match_all('/<tr\b.*?<\/tr>/s', $html, $rows);
    $row = array_values(array_filter($rows[0], fn (string $row): bool => str_contains($row, e($name))));

    expect($row)->toHaveCount(1);

    preg_match_all('/<td\b.*?<\/td>/s', $row[0], $cells);

    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) end($cells[0])), ENT_QUOTES)));
}

/** Dữ liệu biểu đồ đã tính của một widget (`ChartWidget::getCachedData()`, protected). */
function m13t7TrData(Testable $widget): array
{
    return (new ReflectionMethod($widget->instance(), 'getCachedData'))->invoke($widget->instance());
}

/** Tuỳ chọn Chart.js của một widget (`ChartWidget::getOptions()`, protected). */
function m13t7TrOptions(Testable $widget): array
{
    return (new ReflectionMethod($widget->instance(), 'getOptions'))->invoke($widget->instance());
}

it('covers the 90 days ending yesterday on the member page, never today', function () {
    m13t7TrNormal($this->lawyer);

    $trend = m13t7TrMember($this->manager, $this->lawyer);

    expect(BuildPerformanceTrend::MEMBER_PAGE_DAYS)->toBe(90)
        ->and($trend['dates'])->toHaveCount(90)
        ->and($trend['dates'][0])->toBe('2026-07-06')
        ->and($trend['dates'][89])->toBe('2026-10-03')
        ->and($trend['dates'])->not->toContain('2026-10-04')
        ->and($trend['stale'])->toHaveCount(90)
        ->and($trend['overdue'])->toHaveCount(90)
        ->and($trend['checklist'])->toHaveCount(90);
});

it('leaves a day without a normal row empty, never zero, and keeps a real zero as zero', function () {
    m13t7TrNormal($this->lawyer);

    $trend = m13t7TrMember($this->manager, $this->lawyer);
    $day = array_flip($trend['dates']);

    expect($trend['stale'][$day['2026-10-01']])->toBeNull()
        ->and($trend['overdue'][$day['2026-10-01']])->toBeNull()
        ->and($trend['checklist'][$day['2026-10-01']])->toBeNull()
        ->and($trend['stale'][$day['2026-08-15']])->toBeNull()
        ->and($trend['stale'][$day['2026-10-02']])->toBe(0)
        ->and($trend['overdue'][$day['2026-09-01']])->toBe(0)
        ->and(m13t7TrPoints($trend, 'stale'))->toBe(['2026-07-06' => 1, '2026-09-01' => 2, '2026-09-30' => 4, '2026-10-02' => 0, '2026-10-03' => 3])
        ->and(m13t7TrPoints($trend, 'checklist'))->toBe(['2026-07-06' => '3/4', '2026-09-01' => '3/5', '2026-09-30' => '4/5', '2026-10-02' => '5/5', '2026-10-03' => '1/6']);
});

it('adds the restricted row for the lead and the admin, and never for the manager, whose numbers stay those of a world without it', function () {
    m13t7TrNormal($this->lawyer);
    $before = m13t7TrMember($this->manager, $this->lawyer);

    m13t7TrRestricted($this->lawyer);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $after = m13t7TrMember($this->manager, $this->lawyer);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $snapshotQueries = array_values(array_filter($log, fn (array $query): bool => str_contains($query['query'], 'performance_snapshots')));

    expect($after)->toBe($before)
        ->and($snapshotQueries)->toHaveCount(1)
        ->and($snapshotQueries[0]['bindings'])->not->toContain('restricted')
        ->and($snapshotQueries[0]['bindings'])->toContain('normal');

    foreach ([$this->lawyer, $this->admin] as $viewer) {
        $trend = m13t7TrMember($viewer, $this->lawyer);

        expect(m13t7TrPoints($trend, 'stale'))->toBe(['2026-07-06' => 6, '2026-09-01' => 7, '2026-09-30' => 9, '2026-10-02' => 5, '2026-10-03' => 8])
            ->and(m13t7TrPoints($trend, 'overdue'))->toBe(['2026-07-06' => 9, '2026-09-01' => 7, '2026-09-30' => 8, '2026-10-02' => 10, '2026-10-03' => 7])
            ->and(m13t7TrPoints($trend, 'checklist')['2026-10-03'])->toBe('3/15')
            // Ngày chỉ có dòng restricted vẫn là ngày bị lỡ: trống, kể cả với người thấy dòng đó.
            ->and(m13t7TrPoints($trend, 'stale'))->not->toHaveKey('2026-10-01');
    }
});

it('gives a performance.viewAny holder without matter.viewAny no number at all', function () {
    m13t7TrNormal($this->lawyer);
    $witness = User::factory()->create();
    $witness->givePermissionTo('performance.viewAny');

    $trend = m13t7TrMember($witness, $this->lawyer);

    expect($trend['dates'])->toHaveCount(90)
        ->and(array_filter($trend['stale'], fn ($v) => $v !== null))->toBe([])
        ->and(array_filter($trend['overdue'], fn ($v) => $v !== null))->toBe([])
        ->and(array_filter($trend['checklist'], fn ($v) => $v !== null))->toBe([]);
});

it('refuses, defensively, a trend of someone the viewer may not see', function () {
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => app(BuildPerformanceTrend::class)->handle($colleague, $this->lawyer->fresh(), PerformancePeriod::trailingDays(90)))
        ->toThrow(AuthorizationException::class);
});

it('reads the trend of a closed period from its first day to its last, and of a running one up to yesterday', function () {
    m13t7TrNormal($this->lawyer);

    $september = app(BuildPerformanceTrend::class)->handle($this->manager->fresh(), $this->lawyer->fresh(), PerformancePeriod::fromFilters(['period' => 'last_month']));
    $october = app(BuildPerformanceTrend::class)->handle($this->manager->fresh(), $this->lawyer->fresh(), PerformancePeriod::fromFilters(['period' => 'this_month']));

    expect($september['dates'])->toHaveCount(30)
        ->and($september['dates'][0])->toBe('2026-09-01')
        ->and($september['dates'][29])->toBe('2026-09-30')
        ->and($october['dates'])->toBe(['2026-10-01', '2026-10-02', '2026-10-03']);
});

// =================================================================================================
// Cột P8 của trang "Hiệu suất theo kỳ": đầu kỳ → cuối kỳ, một truy vấn cho cả trang
// =================================================================================================

it('gives each row of the period page the snapshot of the first day and of the last day, empty when that day has none', function () {
    m13t7TrNormal($this->lawyer);
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Xu Hướng']);
    m13t7TrSnap($assistant, '2026-09-01', Confidentiality::Normal, 0, 4);

    $this->actingAs($this->manager, 'web');
    $page = Livewire::test(Performance::class);
    $rows = $page->instance()->getTableRecords()->all();

    expect([$rows[$this->lawyer->id]['staleStart'], $rows[$this->lawyer->id]['staleEnd'], $rows[$this->lawyer->id]['overdueStart'], $rows[$this->lawyer->id]['overdueEnd']])
        ->toBe([2, 4, 0, 1])
        ->and([$rows[$assistant->id]['overdueStart'], $rows[$assistant->id]['overdueEnd']])->toBe([4, null])
        ->and([$rows[Performance::REFERENCE_KEY]['staleStart'], $rows[Performance::REFERENCE_KEY]['overdueEnd']])->toBe([null, null]);

    $html = $page->html();

    expect($html)->toContain(e(__('performance.columns.p8')))
        ->and($html)->toContain(e(__('performance.period_page.p8_overdue', ['start' => '0', 'end' => '1'])))
        ->and($html)->toContain(e(__('performance.period_page.p8_stale', ['start' => '2', 'end' => '4'])))
        ->and($html)->toContain(e(__('performance.period_page.p8_overdue', ['start' => '4', 'end' => '—'])))
        ->and($html)->toContain(e(__('performance.period_page.p8_stale_not_applicable')))
        ->and($html)->toContain(e(__('performance.explain.p8')))
        ->and(m13t7TrCell($html, __('performance.period_page.reference_name')))->toBe('—')
        ->and(m13t7TrCell($html, 'Luật Sư L'))->toBe(__('performance.period_page.p8_overdue', ['start' => '0', 'end' => '1']).' '.__('performance.period_page.p8_stale', ['start' => '2', 'end' => '4']));
});

it('ends the P8 column of a running period at yesterday', function () {
    m13t7TrNormal($this->lawyer);

    $this->actingAs($this->manager, 'web');
    $page = Livewire::test(Performance::class)
        ->fillForm(['period' => 'this_month'])
        ->call('applyPeriod');

    $row = $page->instance()->getTableRecords()->all()[$this->lawyer->id];

    // 01/10 không có dòng normal; 03/10 là hôm qua; 04/10 (hôm nay) không bao giờ được đọc.
    expect([$row['staleStart'], $row['staleEnd'], $row['overdueStart'], $row['overdueEnd']])->toBe([null, 3, null, 0]);
});

it('reads P8 for the manager without the restricted rows, and for the admin with them', function () {
    m13t7TrNormal($this->lawyer);
    $this->actingAs($this->manager, 'web');
    $before = Livewire::test(Performance::class)->instance()->getTableRecords()->all()[$this->lawyer->id];

    m13t7TrRestricted($this->lawyer);
    $after = Livewire::test(Performance::class)->instance()->getTableRecords()->all()[$this->lawyer->id];

    $this->actingAs($this->admin, 'web');
    $admin = Livewire::test(Performance::class)->instance()->getTableRecords()->all()[$this->lawyer->id];

    expect([$after['staleStart'], $after['staleEnd'], $after['overdueStart'], $after['overdueEnd']])
        ->toBe([$before['staleStart'], $before['staleEnd'], $before['overdueStart'], $before['overdueEnd']])
        ->and([$admin['staleStart'], $admin['staleEnd'], $admin['overdueStart'], $admin['overdueEnd']])->toBe([7, 9, 7, 8]);
});

it('reads P8 for the whole page in one query, the same for 3 and for 12 people', function () {
    $count = function (): int {
        // Một người xem cho cả hai lần gọi: lần đầu làm nóng quyền của người xem (spatie), như trang.
        $viewer = $this->manager->fresh();
        $subjects = TeamRoster::subjectsFor($viewer);
        $period = PerformancePeriod::fromFilters(['period' => 'last_month']);
        app(BuildPerformanceTrend::class)->endpoints($viewer, $subjects, $period);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(BuildPerformanceTrend::class)->endpoints($viewer, $subjects, $period);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    m13t7TrNormal($this->lawyer);
    $three = $count();

    foreach (range(1, 9) as $i) {
        m13t7TrNormal(User::factory()->withRole(Role::Lawyer)->create(['name' => "Luật Sư {$i}"]));
    }

    expect($three)->toBe(1)
        ->and($count())->toBe($three);
});

// =================================================================================================
// Hai widget, gọi thẳng qua Livewire: cùng chuỗi số cho trưởng phòng dù có hay không dòng restricted
// =================================================================================================

it('gives the manager, through both widgets mounted directly, the same series whether or not the restricted rows exist', function (string $widget) {
    m13t7TrNormal($this->lawyer);
    $this->actingAs($this->manager, 'web');

    $before = Livewire::test($widget, ['subjectId' => $this->lawyer->id]);
    $beforeData = m13t7TrData($before);
    $beforeTable = $before->instance()->numberTableRows();

    m13t7TrRestricted($this->lawyer);

    $after = Livewire::test($widget, ['subjectId' => $this->lawyer->id]);

    expect(m13t7TrData($after))->toBe($beforeData)
        ->and($after->instance()->numberTableRows())->toBe($beforeTable);

    $this->actingAs($this->admin, 'web');

    expect(m13t7TrData(Livewire::test($widget, ['subjectId' => $this->lawyer->id])))->not->toBe($beforeData);
})->with(['stale' => [StaleTrendWidget::class], 'overdue' => [OverdueTrendWidget::class]]);

it('draws one series in one colour, with no legend and a gap (null) on a missed day, and prints "—" for that day in the number table', function (string $widget, string $series) {
    m13t7TrNormal($this->lawyer);
    $this->actingAs($this->manager, 'web');

    $component = Livewire::test($widget, ['subjectId' => $this->lawyer->id]);
    $data = m13t7TrData($component);
    $options = m13t7TrOptions($component);
    $table = collect($component->instance()->numberTableRows())->keyBy('label');

    expect($data['datasets'])->toHaveCount(1)
        ->and($data['datasets'][0]['borderColor'])->toBe('#4a73bd')
        ->and($data['labels'])->toHaveCount(90)
        ->and($data['labels'][89])->toBe('03/10')
        ->and($data['datasets'][0]['data'][87])->toBeNull()
        ->and($data['datasets'][0]['data'][88])->toBe($series === 'stale' ? 0 : 3)
        ->and($options['plugins']['legend']['display'])->toBeFalse()
        ->and(array_keys($options['scales']))->toBe(['y'])
        ->and($table['01/10/2026']['value'])->toBe('—')
        ->and($table)->toHaveCount(90);
})->with([
    'stale' => [StaleTrendWidget::class, 'stale'],
    'overdue' => [OverdueTrendWidget::class, 'overdue'],
]);

it('keeps the checklist level in the number table of the stale widget only, never drawn', function () {
    m13t7TrNormal($this->lawyer);
    $this->actingAs($this->manager, 'web');

    $stale = Livewire::test(StaleTrendWidget::class, ['subjectId' => $this->lawyer->id]);
    $table = collect($stale->instance()->numberTableRows())->keyBy('label');

    expect($table['03/10/2026']['value'])->toBe(__('performance.trend.stale_row', ['stale' => 3, 'checklist' => '1/6']))
        ->and(m13t7TrData($stale)['datasets'])->toHaveCount(1)
        ->and(json_encode(Livewire::test(OverdueTrendWidget::class, ['subjectId' => $this->lawyer->id])->instance()->numberTableRows()))->not->toContain('1/6');
});

it('shows "Không áp dụng" instead of a stale chart for someone who cannot lead matters, and still draws their overdue deadlines', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Biểu Đồ']);
    m13t7TrSnap($assistant, '2026-10-03', Confidentiality::Normal, 0, 2);
    $this->actingAs($this->manager, 'web');

    $stale = Livewire::test(StaleTrendWidget::class, ['subjectId' => $assistant->id]);
    $overdue = Livewire::test(OverdueTrendWidget::class, ['subjectId' => $assistant->id]);

    expect($stale->instance()->isEmpty())->toBeTrue()
        ->and($stale->html())->toContain(e(__('performance.not_applicable')))
        ->and($overdue->instance()->isEmpty())->toBeFalse()
        ->and(last(m13t7TrData($overdue)['datasets'][0]['data']))->toBe(2);
});

/**
 * Đầu cuối: ảnh chụp do CHÍNH tác vụ ghi. Ngày 01/10 L chỉ có vụ thường; ngày 02/10 thêm một vụ restricted
 * quá hạn cập nhật với một mốc quá hạn. Trưởng phòng đọc hai ngày như nhau; L và admin thấy ngày 02/10 tăng.
 */
it('keeps a restricted matter captured by the task itself out of the manager\'s trend, and in the lead\'s and the admin\'s', function () {
    $normal = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'last_client_update_at' => '2026-09-01 09:00:00']);
    Deadline::factory()->for($normal)->create(['responsible_user_id' => $this->lawyer->id, 'due_date' => '2026-09-20']);

    $this->travelTo(Carbon::parse('2026-10-01 23:50:00'));
    app(CapturePerformanceSnapshots::class)->handle();

    $secret = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id, 'last_client_update_at' => '2026-09-01 09:00:00']);
    Deadline::factory()->for($secret)->create(['responsible_user_id' => $this->lawyer->id, 'due_date' => '2026-09-21']);

    $this->travelTo(Carbon::parse('2026-10-02 23:50:00'));
    app(CapturePerformanceSnapshots::class)->handle();

    $this->travelTo(Carbon::parse('2026-10-04 10:00:00'));

    $manager = m13t7TrMember($this->manager, $this->lawyer);
    $lead = m13t7TrMember($this->lawyer, $this->lawyer);
    $admin = m13t7TrMember($this->admin, $this->lawyer);

    expect(m13t7TrPoints($manager, 'stale'))->toBe(['2026-10-01' => 1, '2026-10-02' => 1])
        ->and(m13t7TrPoints($manager, 'overdue'))->toBe(['2026-10-01' => 1, '2026-10-02' => 1])
        ->and(m13t7TrPoints($lead, 'stale'))->toBe(['2026-10-01' => 1, '2026-10-02' => 2])
        ->and(m13t7TrPoints($lead, 'overdue'))->toBe(['2026-10-01' => 1, '2026-10-02' => 2])
        ->and(m13t7TrPoints($admin, 'overdue'))->toBe(m13t7TrPoints($lead, 'overdue'));
});

it('refuses, defensively, P8 for a page holding someone the viewer may not see', function () {
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => app(BuildPerformanceTrend::class)->endpoints($colleague, collect([$this->lawyer->fresh()]), PerformancePeriod::fromFilters(['period' => 'last_month'])))
        ->toThrow(AuthorizationException::class);
});

/** Kỳ bắt đầu hôm nay: chưa ngày nào của kỳ đã qua — không đọc ảnh chụp của ngày trước kỳ (hôm qua). */
it('leaves P8 empty on the first day of a running period, even when yesterday has a snapshot', function () {
    m13t7TrNormal($this->lawyer);
    $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
    m13t7TrSnap($this->lawyer, '2026-09-30', Confidentiality::Restricted, 1, 1);

    $endpoints = app(BuildPerformanceTrend::class)->endpoints($this->admin->fresh(), collect([$this->lawyer->fresh()]), PerformancePeriod::fromFilters(['period' => 'this_month']));
    $trend = app(BuildPerformanceTrend::class)->handle($this->admin->fresh(), $this->lawyer->fresh(), PerformancePeriod::fromFilters(['period' => 'this_month']));

    expect($endpoints[$this->lawyer->id])->toBe(['staleStart' => null, 'staleEnd' => null, 'overdueStart' => null, 'overdueEnd' => null])
        ->and($trend['dates'])->toBe([]);
});
