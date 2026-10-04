<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Pages\TeamOverview;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 4 — trang "Theo dõi đội ngũ" qua Livewire và HTTP: bảng `Table::records()` với các cột
 * N1–N10 (N11 chỉ ở trang của một người), "Không áp dụng" cho cột của người phụ trách (R6), chỉ cột
 * đếm việc đang tồn sắp xếp được (R8), màu theo ngưỡng tuyệt đối, công tắc người nghỉ việc (R3), câu R4, khối "Cách tính các con số"
 * (R6) và đúng một dòng `performance_viewed` mỗi lần mở trang (R14).
 *
 * Con số của từng cột được đo ở `TeamWorkloadTest`; tệp này đo thứ chỉ màn hình làm sai được. Hàm
 * toàn cục mang tiền tố `m13t4Page`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Trang']);
});

function m13t4PageOpen(User $viewer): Testable
{
    test()->actingAs($viewer, 'web');

    return Livewire::test(TeamOverview::class);
}

/** @return list<int> khoá các dòng của bảng, theo thứ tự hiện */
function m13t4PageOrder(Testable $page): array
{
    return $page->instance()->getTableRecords()->keys()->map(fn (mixed $key): int => (int) $key)->all();
}

/** HTML của đúng dòng bảng mang tên `$name`. */
function m13t4PageRow(string $html, string $name): string
{
    preg_match_all('/<tr\b.*?<\/tr>/s', $html, $rows);

    $matching = array_values(array_filter($rows[0], fn (string $row): bool => str_contains($row, e($name))));

    expect($matching)->toHaveCount(1);

    return $matching[0];
}

function m13t4PageOverdue(Matter $matter, User $holder, int $count): void
{
    foreach (range(1, $count) as $_) {
        Deadline::factory()->for($matter)->create(['responsible_user_id' => $holder->id, 'due_date' => today()->subDays(2)->toDateString()]);
    }
}

// =================================================================================================
// Cột, "Không áp dụng", màu
// =================================================================================================

it('prints "Không áp dụng" in the six lead-only columns of an assistant and numbers, zero included, in the others', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Bảng']);
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Bảng']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $html = m13t4PageOpen($this->manager)->html();
    $notApplicable = e(__('performance.not_applicable'));

    expect(substr_count(m13t4PageRow($html, 'Trợ Lý Bảng'), $notApplicable))->toBe(6)
        ->and(substr_count(m13t4PageRow($html, 'Luật Sư Bảng'), $notApplicable))->toBe(0)
        ->and(substr_count(m13t4PageRow($html, 'Trưởng Phòng Trang'), $notApplicable))->toBe(0)
        ->and(m13t4PageRow($html, 'Luật Sư Bảng'))->toContain('0/0')
        // N7 mang số vụ đã chờ quá 14 ngày trong ngoặc; N4 mang "chưa bật cổng" bên dưới.
        ->and(m13t4PageRow($html, 'Luật Sư Bảng'))->toContain('0 (0)')
        ->and(m13t4PageRow($html, 'Luật Sư Bảng'))->toContain(e(__('performance.team_overview.not_measurable', ['count' => 0])))
        ->and(m13t4PageRow($html, 'Trợ Lý Bảng'))->not->toContain('(0)')
        ->and(m13t4PageRow($html, 'Trợ Lý Bảng'))->not->toContain(e(__('performance.team_overview.not_measurable', ['count' => 0])));
});

/**
 * Phán quyết N11 của Task 4 (docblock `BuildTeamWorkload`): cột "Thao tác hồ sơ gần nhất" rời trang
 * tổng quan — không cột, không câu giải thích, không thời điểm nào của nó trên trang.
 */
it('shows no last matter activity column on the overview, nor its explanation', function () {
    $busy = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Bận']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $busy->id]);

    $this->travelTo(Carbon::parse('2026-10-13 09:05:00'));
    Audit::record('matter_reassigned', $matter, [], causer: $busy);
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    $page = m13t4PageOpen($this->manager);
    $html = $page->html();

    expect(array_keys($page->instance()->getTable()->getColumns()))->not->toContain('lastMatterActivityAt')
        ->and($html)->not->toContain(e(__('performance.columns.n11')))
        ->and($html)->not->toContain(e(__('performance.explain.n11')))
        ->and(m13t4PageRow($html, 'Luật Sư Bận'))->not->toContain('13/10/2026')
        ->and($page->instance()->getTableRecords()->first()['lastMatterActivityAt'])->toBeNull();
});

it('paints overdue deadlines and stale matters above zero in the danger colour, inline, and never a zero or a ratio', function () {
    $late = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Trễ']);
    $fine = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Ổn']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $late->id, 'last_client_update_at' => now()->subDays(20)]);
    m13t4PageOverdue($matter, $late, 2);
    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Accepted)->create(['is_required' => true]);
    Matter::factory()->create(['lead_lawyer_id' => $fine->id]);

    $html = m13t4PageOpen($this->manager)->html();

    expect(substr_count(m13t4PageRow($html, 'Luật Sư Trễ'), 'var(--danger-600)'))->toBe(2)
        ->and(m13t4PageRow($html, 'Luật Sư Ổn'))->not->toContain('var(--danger-600)')
        ->and(m13t4PageRow($html, 'Luật Sư Trễ'))->toContain('1/1')
        ->and(unregisteredColourVariables(m13t4PageRow($html, 'Luật Sư Trễ')))->toBe([]);
});

it('links every name to that person\'s own page', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Liên Kết']);

    expect(m13t4PageRow(m13t4PageOpen($this->manager)->html(), 'Luật Sư Liên Kết'))
        ->toContain(e(TeamMember::getUrl(['user' => $lawyer->id], panel: 'admin')));
});

// =================================================================================================
// Sắp xếp (R8)
// =================================================================================================

it('sorts by overdue deadlines in both directions, and keeps "Không áp dụng" after every number', function () {
    $few = User::factory()->withRole(Role::Lawyer)->create(['name' => 'A Ít Mốc']);
    $many = User::factory()->withRole(Role::Lawyer)->create(['name' => 'B Nhiều Mốc']);
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'C Trợ Lý']);
    $fewMatter = Matter::factory()->create(['lead_lawyer_id' => $few->id]);
    $manyMatter = Matter::factory()->create(['lead_lawyer_id' => $many->id]);
    $manyMatter->addTeamMember($assistant, MatterRole::Assistant);
    m13t4PageOverdue($fewMatter, $few, 1);
    m13t4PageOverdue($manyMatter, $many, 3);
    m13t4PageOverdue($manyMatter, $assistant, 2);

    $page = m13t4PageOpen($this->manager);

    $page->call('sortTable', 'overdueDeadlines', 'desc');
    expect(m13t4PageOrder($page))->toBe([$many->id, $assistant->id, $few->id, $this->manager->id]);

    $page->call('sortTable', 'overdueDeadlines', 'asc');
    expect(m13t4PageOrder($page))->toBe([$this->manager->id, $few->id, $assistant->id, $many->id]);

    // Cột của người phụ trách: trợ lý ("Không áp dụng") đứng sau mọi số, ở cả hai chiều.
    $page->call('sortTable', 'leadOpen', 'desc');
    expect(m13t4PageOrder($page))->toBe([$few->id, $many->id, $this->manager->id, $assistant->id]);

    $page->call('sortTable', 'leadOpen', 'asc');
    expect(last(m13t4PageOrder($page)))->toBe($assistant->id);
});

it('ignores a sort request on the X/Y column, on the last activity column and on an unknown column', function () {
    $an = User::factory()->withRole(Role::Lawyer)->create(['name' => 'A Danh Mục Ít']);
    $binh = User::factory()->withRole(Role::Lawyer)->create(['name' => 'B Danh Mục Nhiều']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $binh->id]);
    MatterChecklistItem::factory()->count(3)->for($matter)->status(ChecklistItemStatus::Accepted)->create(['is_required' => true]);
    Matter::factory()->create(['lead_lawyer_id' => $an->id]);

    $page = m13t4PageOpen($this->manager);
    $byName = m13t4PageOrder($page);

    expect($byName)->toBe([$an->id, $binh->id, $this->manager->id]);

    foreach (['checklist', 'checklistSettled', 'checklistTotal', 'lastMatterActivityAt', 'leadClosed', 'name; drop'] as $column) {
        foreach (['asc', 'desc'] as $direction) {
            $page->call('sortTable', $column, $direction);
            expect(m13t4PageOrder($page))->toBe($byName);
        }
    }
});

it('offers sorting only on the backlog columns and on the name', function () {
    $page = m13t4PageOpen($this->manager);
    $table = $page->instance()->getTable();

    $sortable = collect($table->getColumns())
        ->filter(fn ($column): bool => $column->isSortable())
        ->keys()
        ->values()
        ->all();

    expect($sortable)->toBe(['name', ...TeamOverview::SORTABLE_COLUMNS])
        ->and(TeamOverview::SORTABLE_COLUMNS)->toBe([
            'leadOpen', 'teamOpen', 'stale', 'overdueDeadlines', 'deadlinesDueSoon',
            'awaitingClientMatters', 'awaitingReviewItems', 'awaitingOfficeRequests',
        ]);
});

// =================================================================================================
// Người nghỉ việc (R3)
// =================================================================================================

it('lists a deactivated person only once the switch is on, and their name opens their page', function () {
    $active = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Đang Làm']);
    $gone = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Đã Nghỉ', 'is_active' => false]);
    $deleted = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Đã Xoá']);
    $deleted->delete();

    $page = m13t4PageOpen($this->manager);

    expect(m13t4PageOrder($page))->toBe([$active->id, $this->manager->id])
        ->and($page->html())->not->toContain('Luật Sư Đã Nghỉ');

    $page->set('tableFilters.include_inactive.isActive', true);

    $html = $page->html();

    expect(m13t4PageOrder($page))->toContain($gone->id)
        ->and(m13t4PageOrder($page))->not->toContain($deleted->id)
        ->and(m13t4PageRow($html, 'Luật Sư Đã Nghỉ'))->toContain(e(__('performance.team_overview.inactive')))
        ->and(m13t4PageRow($html, 'Luật Sư Đã Nghỉ'))->toContain(e(TeamMember::getUrl(['user' => $gone->id], panel: 'admin')));

    $this->get(TeamMember::getUrl(['user' => $gone->id], panel: 'admin'))
        ->assertOk()
        ->assertSee('Luật Sư Đã Nghỉ');
});

// =================================================================================================
// Câu R4, "Cách tính các con số" (R6)
// =================================================================================================

it('prints the fixed scope sentence and every explanation, each from an existing translation key', function () {
    $html = m13t4PageOpen($this->manager)->html();

    expect($html)->toContain(e(__('performance.scope_note')))
        ->and($html)->toContain(e(__('performance.how_computed')));

    foreach (TeamOverview::EXPLAINED_CODES as $code) {
        expect(Lang::has("performance.explain.{$code}"))->toBeTrue("thiếu khoá performance.explain.{$code}")
            ->and(Lang::has("performance.columns.{$code}") || $code === 'not_applicable')->toBeTrue("thiếu khoá performance.columns.{$code}")
            ->and($html)->toContain(e(__("performance.explain.{$code}")));
    }

    expect(TeamOverview::EXPLAINED_CODES)->toBe(['n1', 'n2', 'n3', 'n4', 'n5', 'n6', 'n7', 'n8', 'n9', 'n10', 'not_applicable'])
        // N11 rời trang này (phán quyết N11) nhưng câu và tên cột còn cho trang của một người.
        ->and(Lang::has('performance.explain.n11') && Lang::has('performance.columns.n11'))->toBeTrue()
        ->and($html)->not->toMatch('/performance\.(explain|columns|team_overview)\./')
        ->and($html)->not->toContain('performance.not_applicable')
        ->and($html)->not->toContain('performance.how_computed');
});

/**
 * `lang/vi/performance.php` có hai làn M13 cùng thêm khoá theo khối (`// Task N`). Một khoá cấp một
 * khai báo hai lần thì PHP lặng lẽ giữ cái sau — một lần gộp hai khối `columns`/`explain` làm mất
 * nửa số câu. Test đỏ ngay khi điều đó xảy ra.
 */
it('declares every top-level key of lang/vi/performance.php once', function () {
    preg_match_all("/^    '([a-z_]+)' =>/m", (string) file_get_contents(lang_path('vi/performance.php')), $keys);

    expect($keys[1])->toBe(array_values(array_unique($keys[1])))
        ->and($keys[1])->toContain('columns')
        ->and($keys[1])->toContain('explain');
});

// =================================================================================================
// Nhật ký (R14)
// =================================================================================================

it('writes exactly one performance_viewed row per mount, and none for the switch or a sort', function () {
    $page = m13t4PageOpen($this->manager);

    $rows = fn () => Activity::query()->where('event', 'performance_viewed')->get();

    expect($rows())->toHaveCount(1)
        ->and($rows()->first()->causer_id)->toBe($this->manager->id)
        ->and($rows()->first()->subject_id)->toBeNull()
        ->and($rows()->first()->properties->get('page'))->toBe('team_overview');

    $page->set('tableFilters.include_inactive.isActive', true);
    $page->call('sortTable', 'overdueDeadlines', 'desc');
    $page->set('tableFilters.include_inactive.isActive', false);

    expect($rows())->toHaveCount(1);

    m13t4PageOpen($this->manager);

    expect($rows())->toHaveCount(2);
});
