<?php

use App\Actions\Performance\BuildMatterTypeMix;
use App\Actions\Performance\BuildTeamWorkload;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\UpcomingDeadlinesWidget;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Audit;
use App\Support\Performance\TeamWorkloadRow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 5 — "Trang của một người" (`/team/{user}`) qua Livewire và HTTP: đầu trang (tên, chức
 * danh, trạng thái; đúng `TeamWorkloadRow` của `BuildTeamWorkload` cho một người; cơ cấu lĩnh vực),
 * bảng "Vụ việc" trên `listableBy(V) ∩ workedOnBy(X)`, ba danh sách dựng từ truy vấn của widget trang
 * chủ cộng scope người, chỗ cho xu hướng (Task 7), `#[Locked]` + 404 giữa chừng, và nhật ký
 * `performance_viewed` (R14).
 *
 * Con số của từng cột đã đo ở `TeamWorkloadTest` (Task 4); tệp này đo thứ chỉ trang này làm sai
 * được: nó in đúng dòng đó, đúng nhãn cho đúng khoá, và mọi danh sách là phần giao `listableBy(V) ∩
 * việc của X`. Quét rò rỉ cả trang (chữ hiện ra) với trưởng phòng nằm ở `RestrictedLeakSweepTest`.
 * Hàm toàn cục mang tiền tố `m13t5`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    $this->manager = User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Xem']);
    $this->admin = User::factory()->admin()->create(['name' => 'Admin Xem']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Được Xem']);
});

/** Khoá các con số đầu trang, theo thứ tự in, và mã câu giải thích (`performance.columns.<mã>`) của từng khoá. */
const M13T5_METRICS = [
    'leadOpen' => 'n1',
    'teamOpen' => 'n2',
    'leadClosed' => 'n3',
    'stale' => 'n4',
    'overdueDeadlines' => 'n5',
    'deadlinesDueSoon' => 'n6',
    'awaitingClientMatters' => 'n7',
    'awaitingReviewItems' => 'n8',
    'awaitingOfficeRequests' => 'n9',
    'checklist' => 'n10',
    'lastMatterActivityAt' => 'n11',
];

/** Sáu con số chỉ dành cho người phụ trách vụ (R6). */
const M13T5_LEAD_ONLY = ['leadOpen', 'leadClosed', 'stale', 'awaitingClientMatters', 'awaitingReviewItems', 'checklist'];

function m13t5Open(User $viewer, User $subject): Testable
{
    test()->actingAs($viewer->fresh(), 'web');

    return Livewire::test(TeamMember::class, ['user' => $subject->getKey()]);
}

/** Dòng mà `BuildTeamWorkload` dựng cho đúng một người, như `$viewer` đọc — nguồn sự thật của đầu trang. */
function m13t5Row(User $viewer, User $subject): TeamWorkloadRow
{
    return app(BuildTeamWorkload::class)
        ->handle($viewer->fresh(), collect([$subject->fresh()]), withLastMatterActivity: true)[$subject->getKey()];
}

/** Chữ người xem đọc được trong một mẩu HTML. */
function m13t5Text(string $html): string
{
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/si', ' ', $html) ?? $html;

    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}

/** HTML của khối `<section …$marker…>` (các khối không lồng nhau). */
function m13t5Block(string $html, string $marker): string
{
    $start = strpos($html, $marker);

    expect($start)->not->toBeFalse("Không có khối {$marker} trên trang.");

    return substr($html, $start, strpos($html, '</section>', $start) - $start);
}

/**
 * Các con số đầu trang, theo thứ tự in.
 *
 * @return array<string, array{label: string, value: string, note: ?string, html: string}>
 */
function m13t5Metrics(string $html): array
{
    preg_match_all(
        '/<div data-vk-metric="(\w+)"[^>]*>\s*<dt[^>]*>(.*?)<\/dt>\s*<dd[^>]*>(.*?)<\/dd>(.*?)<\/div>/s',
        m13t5Block($html, 'data-vk-member-workload'),
        $matches,
        PREG_SET_ORDER,
    );

    $metrics = [];

    foreach ($matches as [$whole, $key, $label, $value, $rest]) {
        $metrics[$key] = [
            'label' => m13t5Text($label),
            'value' => m13t5Text($value),
            'note' => m13t5Text($rest) === '' ? null : m13t5Text($rest),
            'html' => $whole,
        ];
    }

    return $metrics;
}

/** @return list<int> id các dòng của một danh sách ngắn, theo thứ tự in */
function m13t5ListIds(string $html, string $list): array
{
    preg_match_all('/data-vk-row="(\d+)"/', m13t5Block($html, 'data-vk-member-list="'.$list.'"'), $matches);

    return array_map(intval(...), $matches[1]);
}

/** @return list<array{name: string, matters: int}> các dòng của bảng cơ cấu lĩnh vực, theo thứ tự in */
function m13t5Mix(string $html): array
{
    preg_match_all(
        '/<tr data-vk-mix-row[^>]*>\s*<td[^>]*>(.*?)<\/td>\s*<td[^>]*>(.*?)<\/td>/s',
        m13t5Block($html, 'data-vk-member-mix'),
        $matches,
        PREG_SET_ORDER,
    );

    return array_map(fn (array $row): array => ['name' => m13t5Text($row[1]), 'matters' => (int) m13t5Text($row[2])], $matches);
}

/** HTML của một ô bảng Filament (`wire:key` mang khoá bản ghi và tên cột), cắt tới `</td>`. */
function m13t5Cell(string $html, Matter $matter, string $column): string
{
    $start = strpos($html, 'table.record.'.$matter->getKey().'.column.'.$column);

    expect($start)->not->toBeFalse("Không có ô {$column} của vụ {$matter->getKey()}.");

    return substr($html, $start, strpos($html, '</td>', $start) - $start);
}

/**
 * @param  iterable<Model>  $models
 * @return list<int>
 */
function m13t5Ids(iterable $models): array
{
    $ids = [];

    foreach ($models as $model) {
        $ids[] = (int) $model->getKey();
    }

    sort($ids);

    return $ids;
}

/** @param  list<int>  $ids */
function m13t5Sorted(array $ids): array
{
    sort($ids);

    return $ids;
}

function m13t5Closed(Matter $matter): Matter
{
    $matter->forceFill(['closed_at' => now()])->save();

    return $matter;
}

/** Snapshot Livewire của trang `TeamMember` trong HTML của một lần tải trang. */
function m13t5Snapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === TeamMember::class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('Không có snapshot Livewire của TeamMember trong HTML.');
}

/** Một request cập nhật Livewire THẬT (đường `/livewire-…/update`), không qua `Livewire::test()`. */
function m13t5PostUpdate(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => []]],
    ]);
}

// =================================================================================================
// Đầu trang
// =================================================================================================

it('heads the page with the person\'s name, position and status, "Đã nghỉ việc" included', function () {
    $assistant = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Đầu Trang']);
    $left = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Đã Nghỉ', 'is_active' => false]);

    $active = m13t5Text(m13t5Block(m13t5Open($this->manager, $assistant)->html(), 'data-vk-member-profile'));
    $inactive = m13t5Text(m13t5Block(m13t5Open($this->manager, $left)->html(), 'data-vk-member-profile'));

    expect($active)->toContain('Trợ Lý Đầu Trang')
        ->toContain(UserPosition::Assistant->label())
        ->toContain(__('performance.team_member.active'))
        ->not->toContain(__('performance.team_overview.inactive'))
        ->and($inactive)->toContain('Luật Sư Đã Nghỉ')
        ->toContain(UserPosition::Lawyer->label())
        ->toContain(__('performance.team_overview.inactive'))
        ->not->toContain(__('performance.team_member.active'));
});

/**
 * Đầu trang in ĐÚNG dòng `BuildTeamWorkload` dựng cho người đó (cùng người xem), mỗi con số dưới đúng
 * nhãn của nó. Fixture cho mỗi con số một giá trị khác nhau (5, 4, 2, 1, 6, 7, 8, 9 cùng "2 (1)",
 * "X/Y" và một thời điểm), để một lần tráo hai nguồn hay hai nhãn không thể xanh.
 */
it('prints exactly the TeamWorkloadRow of that person, each number under its own label, in the danger colour above zero where Task 4 does', function () {
    $colleague = User::factory()->withRole(Role::Lawyer)->create();
    $lead = ['lead_lawyer_id' => $this->lawyer->id];

    $stale = Matter::factory()->create([...$lead, 'last_client_update_at' => now()->subDays(20)]);
    $fresh = Matter::factory()->create([...$lead, 'last_client_update_at' => now()->subDays(2)]);
    $unpublished = Matter::factory()->count(3)->unpublished()->create($lead)->values();
    m13t5Closed(Matter::factory()->create($lead));
    m13t5Closed(Matter::factory()->create($lead));
    Matter::factory()->count(4)->create(['lead_lawyer_id' => $colleague->id])->each->addTeamMember($this->lawyer, MatterRole::Associate);

    Deadline::factory()->count(6)->for($unpublished[0])->create(['responsible_user_id' => $this->lawyer->id, 'due_date' => today()->subDays(2)->toDateString()]);
    Deadline::factory()->count(7)->for($unpublished[0])->create(['responsible_user_id' => $this->lawyer->id, 'due_date' => today()->addDays(3)->toDateString()]);

    $waiting = MatterChecklistItem::factory()->for($stale)->create(['is_required' => true]);
    $waiting->forceFill(['created_at' => now()->subDays(20)])->save();
    MatterChecklistItem::factory()->for($fresh)->create(['is_required' => true]);
    MatterChecklistItem::factory()->count(8)->for($unpublished[1])->status(ChecklistItemStatus::PendingReview)->create();
    MatterChecklistItem::factory()->count(3)->for($unpublished[1])->status(ChecklistItemStatus::Accepted)->create();

    ClientRequest::factory()->count(9)->for($fresh)->create(['status' => ClientRequestStatus::New]);

    $this->travelTo(Carbon::parse('2026-10-13 16:45:00'));
    Audit::record('matter_reassigned', $fresh, [], causer: $this->lawyer);
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    $row = m13t5Row($this->manager, $this->lawyer);

    expect([$row->leadOpen, $row->teamOpen, $row->leadClosed, $row->stale, $row->notMeasurable, $row->overdueDeadlines,
        $row->deadlinesDueSoon, $row->awaitingClientMatters, $row->awaitingClientStuck, $row->awaitingReviewItems, $row->awaitingOfficeRequests])
        ->toBe([5, 4, 2, 1, 3, 6, 7, 2, 1, 8, 9]);

    $expected = [
        'leadOpen' => (string) $row->leadOpen,
        'teamOpen' => (string) $row->teamOpen,
        'leadClosed' => (string) $row->leadClosed,
        'stale' => (string) $row->stale,
        'overdueDeadlines' => (string) $row->overdueDeadlines,
        'deadlinesDueSoon' => (string) $row->deadlinesDueSoon,
        'awaitingClientMatters' => "{$row->awaitingClientMatters} ({$row->awaitingClientStuck})",
        'awaitingReviewItems' => (string) $row->awaitingReviewItems,
        'awaitingOfficeRequests' => (string) $row->awaitingOfficeRequests,
        'checklist' => "{$row->checklistSettled}/{$row->checklistTotal}",
        'lastMatterActivityAt' => '16:45 13/10/2026',
    ];

    $metrics = m13t5Metrics(m13t5Open($this->manager, $this->lawyer)->html());

    expect(array_keys($metrics))->toBe(array_keys(M13T5_METRICS));

    foreach (M13T5_METRICS as $key => $code) {
        expect($metrics[$key]['label'])->toBe(__("performance.columns.{$code}"), "nhãn của {$key}")
            ->and($metrics[$key]['value'])->toBe($expected[$key], "giá trị của {$key}");
    }

    expect($metrics['stale']['note'])->toBe(__('performance.team_overview.not_measurable', ['count' => 3]))
        ->and($metrics['stale']['html'])->toContain('var(--danger-600)')
        ->and($metrics['overdueDeadlines']['html'])->toContain('var(--danger-600)')
        ->and($metrics['deadlinesDueSoon']['html'])->not->toContain('var(--danger-600)')
        ->and($metrics['checklist']['html'])->not->toContain('var(--danger-600)')
        ->and(unregisteredColourVariables(m13t5Block(m13t5Open($this->manager, $this->lawyer)->html(), 'data-vk-member-workload')))->toBe([]);
});

it('prints "Không áp dụng" for every lead-only number of an assistant and for the review list, never 0', function () {
    $assistant = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Được Xem']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $assistant->id, 'due_date' => today()->subDay()->toDateString()]);
    ClientRequest::factory()->for($matter)->create(['assigned_to' => $assistant->id]);
    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    $html = m13t5Open($this->manager, $assistant)->html();
    $metrics = m13t5Metrics($html);
    $notApplicable = __('performance.not_applicable');

    foreach (M13T5_LEAD_ONLY as $key) {
        expect($metrics[$key]['value'])->toBe($notApplicable, $key)
            ->and($metrics[$key]['note'])->toBeNull();
    }

    expect($metrics['teamOpen']['value'])->toBe('1')
        ->and($metrics['overdueDeadlines']['value'])->toBe('1')
        ->and($metrics['deadlinesDueSoon']['value'])->toBe('0')
        ->and($metrics['awaitingOfficeRequests']['value'])->toBe('1')
        ->and($metrics['lastMatterActivityAt']['value'])->toBe(__('performance.team_member.no_activity'))
        ->and(substr_count(m13t5Text(m13t5Block($html, 'data-vk-member-workload')), $notApplicable))->toBe(6)
        ->and(m13t5Text(m13t5Block($html, 'data-vk-member-workload')))->not->toContain('chưa bật cổng')
        ->and(m13t5Text(m13t5Block($html, 'data-vk-member-list="reviews"')))->toContain($notApplicable)
        ->and(m13t5ListIds($html, 'reviews'))->toBe([]);
});

/** Cặp dương của test trên: người phụ trách được mà không có vụ nào nhận 0, không phải "Không áp dụng". */
it('prints zero, not "Không áp dụng", for a lawyer without any matter', function () {
    $html = m13t5Open($this->manager, $this->lawyer)->html();
    $metrics = m13t5Metrics($html);

    expect($metrics['leadOpen']['value'])->toBe('0')
        ->and($metrics['leadClosed']['value'])->toBe('0')
        ->and($metrics['stale']['value'])->toBe('0')
        ->and($metrics['stale']['note'])->toBe(__('performance.team_overview.not_measurable', ['count' => 0]))
        ->and($metrics['awaitingClientMatters']['value'])->toBe('0 (0)')
        ->and($metrics['awaitingReviewItems']['value'])->toBe('0')
        ->and($metrics['checklist']['value'])->toBe('0/0')
        ->and(m13t5Text(m13t5Block($html, 'data-vk-member-workload')))->not->toContain(__('performance.not_applicable'))
        ->and(m13t5Text(m13t5Block($html, 'data-vk-member-list="reviews"')))->not->toContain(__('performance.not_applicable'))
        ->toContain(__('performance.team_member.lists.empty'));
});

// =================================================================================================
// Cơ cấu lĩnh vực
// =================================================================================================

it('breaks the open matters a lawyer leads down by matter type, inside what the viewer may list', function () {
    $criminal = MatterType::factory()->withStages()->create(['name' => 'Hình sự']);
    $civil = MatterType::factory()->withStages()->create(['name' => 'Dân sự']);
    $land = MatterType::factory()->withStages()->create(['name' => 'Đất đai']);
    $lead = ['lead_lawyer_id' => $this->lawyer->id];

    Matter::factory()->count(2)->create([...$lead, 'matter_type_id' => $criminal->id]);
    Matter::factory()->create([...$lead, 'matter_type_id' => $civil->id]);
    Matter::factory()->restricted()->create([...$lead, 'matter_type_id' => $civil->id]);
    m13t5Closed(Matter::factory()->create([...$lead, 'matter_type_id' => $land->id]));
    Matter::factory()->create(['matter_type_id' => $land->id])->addTeamMember($this->lawyer, MatterRole::Associate);

    $managerHtml = m13t5Open($this->manager, $this->lawyer)->html();
    $ownHtml = m13t5Open($this->lawyer, $this->lawyer)->html();

    expect(m13t5Mix($managerHtml))->toBe([['name' => 'Hình sự', 'matters' => 2], ['name' => 'Dân sự', 'matters' => 1]])
        ->and(array_sum(array_column(m13t5Mix($managerHtml), 'matters')))->toBe(m13t5Row($this->manager, $this->lawyer)->leadOpen)
        ->and(m13t5Mix($ownHtml))->toBe([['name' => 'Dân sự', 'matters' => 2], ['name' => 'Hình sự', 'matters' => 2]])
        ->and(array_sum(array_column(m13t5Mix($ownHtml), 'matters')))->toBe(m13t5Row($this->lawyer, $this->lawyer)->leadOpen)
        ->and(m13t5Text(m13t5Block($managerHtml, 'data-vk-member-mix')))->toContain(__('performance.team_member.mix.heading_lead'))
        ->not->toContain(__('performance.team_member.mix.heading_supporting'));
});

it('breaks the open matters an assistant sits on down by matter type, and says so in the heading', function () {
    $assistant = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create();
    $family = MatterType::factory()->withStages()->create(['name' => 'Hôn nhân gia đình']);
    $labour = MatterType::factory()->withStages()->create(['name' => 'Lao động']);

    Matter::factory()->count(2)->create(['matter_type_id' => $family->id])->each->addTeamMember($assistant, MatterRole::Assistant);
    Matter::factory()->create(['matter_type_id' => $labour->id])->addTeamMember($assistant, MatterRole::Observer);
    m13t5Closed(Matter::factory()->create(['matter_type_id' => $labour->id]))->addTeamMember($assistant, MatterRole::Assistant);

    $html = m13t5Open($this->manager, $assistant)->html();

    expect(m13t5Mix($html))->toBe([['name' => 'Hôn nhân gia đình', 'matters' => 2]])
        ->and(m13t5Row($this->manager, $assistant)->teamOpen)->toBe(2)
        ->and(m13t5Text(m13t5Block($html, 'data-vk-member-mix')))->toContain(__('performance.team_member.mix.heading_supporting'))
        ->not->toContain(__('performance.team_member.mix.heading_lead'));
});

it('says so when the person has no matter in the breakdown', function () {
    expect(m13t5Mix(m13t5Open($this->manager, $this->lawyer)->html()))->toBe([])
        ->and(m13t5Text(m13t5Block(m13t5Open($this->manager, $this->lawyer)->html(), 'data-vk-member-mix')))->toContain(__('performance.team_member.mix.empty'));
});

// =================================================================================================
// Bảng "Vụ việc"
// =================================================================================================

it('lists exactly the matters the viewer may list and the person works on, never an observer seat', function () {
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $led = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closedLed = m13t5Closed(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]));
    $associate = Matter::factory()->create(['lead_lawyer_id' => $other->id]);
    $associate->addTeamMember($this->lawyer, MatterRole::Associate);
    $assistantSeat = Matter::factory()->create(['lead_lawyer_id' => $other->id]);
    $assistantSeat->addTeamMember($this->lawyer, MatterRole::Assistant);
    $observer = Matter::factory()->create(['lead_lawyer_id' => $other->id]);
    $observer->addTeamMember($this->lawyer, MatterRole::Observer);
    $notOnIt = Matter::factory()->create(['lead_lawyer_id' => $other->id]);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $cancelled = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $cancelled->delete();

    $visible = [$led, $closedLed, $associate, $assistantSeat];

    m13t5Open($this->manager, $this->lawyer)
        ->assertCanSeeTableRecords($visible)
        ->assertCanNotSeeTableRecords([$observer, $notOnIt, $restricted, $cancelled])
        ->assertCountTableRecords(4);

    foreach ([$this->lawyer, $this->admin] as $viewer) {
        m13t5Open($viewer, $this->lawyer)
            ->assertCanSeeTableRecords([...$visible, $restricted])
            ->assertCanNotSeeTableRecords([$observer, $notOnIt, $cancelled])
            ->assertCountTableRecords(5);
    }
});

it('filters the matters by open or closed and by lead or supporting seat', function () {
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $led = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closedLed = m13t5Closed(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]));
    $associate = Matter::factory()->create(['lead_lawyer_id' => $other->id]);
    $associate->addTeamMember($this->lawyer, MatterRole::Associate);
    $closedAssistantSeat = m13t5Closed(Matter::factory()->create(['lead_lawyer_id' => $other->id]));
    $closedAssistantSeat->addTeamMember($this->lawyer, MatterRole::Assistant);

    $page = m13t5Open($this->manager, $this->lawyer);

    $page->filterTable('state', 'open')
        ->assertCanSeeTableRecords([$led, $associate])
        ->assertCountTableRecords(2);
    $page->filterTable('state', 'closed')
        ->assertCanSeeTableRecords([$closedLed, $closedAssistantSeat])
        ->assertCountTableRecords(2);
    $page->filterTable('state', null)->filterTable('role', 'lead')
        ->assertCanSeeTableRecords([$led, $closedLed])
        ->assertCountTableRecords(2);
    $page->filterTable('role', 'supporting')
        ->assertCanSeeTableRecords([$associate, $closedAssistantSeat])
        ->assertCountTableRecords(2);
    $page->filterTable('role', null)->assertCountTableRecords(4);
});

it('shows code, client, title, stage, the person\'s role and the last client update coloured by MatterStaleness, and opens the matter', function () {
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $stale = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'last_client_update_at' => now()->subDays(20), 'title' => 'Tranh chấp quá hạn cập nhật']);
    $fresh = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'last_client_update_at' => now()->subDays(2)]);
    $closedOld = m13t5Closed(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'last_client_update_at' => now()->subDays(20)]));
    $associate = Matter::factory()->create(['lead_lawyer_id' => $other->id]);
    $associate->addTeamMember($this->lawyer, MatterRole::Associate);
    // Ghế của người khác có id NHỎ hơn người được xem (admin tạo trước): cột vai phải đọc đúng ghế của
    // người này, không phải ghế đầu tiên của đội ngũ theo thứ tự CSDL.
    $associate->addTeamMember($this->admin, MatterRole::Observer);
    $underManager = Matter::factory()->create(['lead_lawyer_id' => $this->manager->id]);
    $underManager->addTeamMember($this->lawyer, MatterRole::Assistant);

    $html = m13t5Open($this->manager, $this->lawyer)->html();

    expect(m13t5Text(m13t5Cell($html, $associate, 'subject_role')))->toContain(MatterRole::Associate->label())
        ->not->toContain(MatterRole::Observer->label())
        ->not->toContain(MatterRole::Lead->label())
        ->and(m13t5Text(m13t5Cell($html, $underManager, 'subject_role')))->toContain(MatterRole::Assistant->label())
        ->not->toContain(MatterRole::Lead->label())
        ->and(m13t5Cell($html, $stale, 'last_client_update_at'))->toContain('fi-color-danger')
        ->and(m13t5Cell($html, $fresh, 'last_client_update_at'))->not->toContain('fi-color-danger')->not->toContain('fi-color-warning')
        ->and(m13t5Cell($html, $closedOld, 'last_client_update_at'))->not->toContain('fi-color-danger')
        ->and(m13t5Text(m13t5Cell($html, $stale, 'subject_role')))->toContain(MatterRole::Lead->label())
        ->and(m13t5Text(m13t5Cell($html, $associate, 'subject_role')))->toContain(MatterRole::Associate->label())
        ->and(m13t5Text(m13t5Cell($html, $stale, 'code')))->toContain($stale->code)
        ->and(m13t5Text(m13t5Cell($html, $stale, 'client.name')))->toContain($stale->client->name)
        ->and(m13t5Text(m13t5Cell($html, $stale, 'title')))->toContain('Tranh chấp quá hạn cập nhật')
        ->and(m13t5Text(m13t5Cell($html, $stale, 'stage')))->toContain($stale->currentStage()->label)
        ->and($html)->toContain(e(MatterResource::getUrl('view', ['record' => $stale], panel: 'admin')));
});

it('shows a lawyer their own restricted matter, in the table and in the numbers on top', function () {
    $secret = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
    Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $own = m13t5Open($this->lawyer, $this->lawyer)->assertCanSeeTableRecords([$secret])->assertCountTableRecords(2);
    $seenByManager = m13t5Open($this->manager, $this->lawyer)->assertCanNotSeeTableRecords([$secret])->assertCountTableRecords(1);

    expect(m13t5Metrics($own->html())['leadOpen']['value'])->toBe('2')
        ->and(m13t5Metrics($seenByManager->html())['leadOpen']['value'])->toBe('1');
});

// =================================================================================================
// Ba danh sách ngắn
// =================================================================================================

it('lists the deadlines the person holds among the homepage widget rows of the same viewer, overdue and the next seven days', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closed = m13t5Closed(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]));
    $elsewhere = Matter::factory()->create(['lead_lawyer_id' => $other->id]);
    $elsewhere->addTeamMember($this->lawyer, MatterRole::Associate);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $held = fn (Matter $on, int $days, array $extra = []): Deadline => Deadline::factory()->for($on)->create([
        'responsible_user_id' => $this->lawyer->id, 'due_date' => today()->addDays($days)->toDateString(), ...$extra,
    ]);

    $overdue = $held($matter, -3);
    $today = $held($matter, 0);
    $daySeven = $held($matter, 7);
    $held($matter, 8);
    $held($matter, -1, ['is_completed' => true, 'completed_at' => now()]);
    $held($closed, -1);
    $onElsewhere = $held($elsewhere, 2);
    $secret = $held($restricted, -2);
    $assistantHolds = Deadline::factory()->for($matter)->create(['responsible_user_id' => $assistant->id, 'due_date' => today()->addDay()->toDateString()]);

    foreach ([
        [$this->manager, [$overdue, $today, $daySeven, $onElsewhere]],
        [$this->lawyer, [$overdue, $today, $daySeven, $onElsewhere, $secret]],
        [$this->admin, [$overdue, $today, $daySeven, $onElsewhere, $secret]],
    ] as [$viewer, $expected]) {
        $listed = m13t5ListIds(m13t5Open($viewer, $this->lawyer)->html(), 'deadlines');
        $row = m13t5Row($viewer, $this->lawyer);

        expect(m13t5Sorted($listed))->toBe(m13t5Ids($expected))
            ->and(array_values(array_diff($listed, m13t5Ids(UpcomingDeadlinesWidget::rowsFor($viewer->fresh())->get()))))->toBe([])
            ->and(count($listed))->toBe($row->overdueDeadlines + $row->deadlinesDueSoon);
    }

    expect(m13t5ListIds(m13t5Open($this->manager, $assistant)->html(), 'deadlines'))->toBe([$assistantHolds->getKey()]);
});

it('lists the requests the person holds awaiting the office, the very threads N9 counts, a soft-deleted assignee\'s thread on the lead\'s page', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $departed = User::factory()->withRole(Role::Assistant)->create();
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closed = m13t5Closed(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]));
    $elsewhere = Matter::factory()->create(['lead_lawyer_id' => $other->id]);
    $elsewhere->addTeamMember($this->lawyer, MatterRole::Associate);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $thread = fn (Matter $on, array $attributes = []): ClientRequest => ClientRequest::factory()->for($on)->create(['status' => ClientRequestStatus::New, ...$attributes]);

    $unassigned = $thread($matter);
    $inProgress = $thread($matter, ['status' => ClientRequestStatus::InProgress]);
    $toAssistant = $thread($matter, ['assigned_to' => $assistant->id]);
    $toDeparted = $thread($matter, ['assigned_to' => $departed->id]);
    $departed->delete();
    $thread($matter, ['status' => ClientRequestStatus::Answered, 'answered_at' => now()->subDay()]);
    $thread($matter, ['status' => ClientRequestStatus::Closed]);
    $thread($closed);
    $assignedElsewhere = $thread($elsewhere, ['assigned_to' => $this->lawyer->id]);
    $secret = $thread($restricted);

    foreach ([
        [$this->manager, [$unassigned, $inProgress, $toDeparted, $assignedElsewhere]],
        [$this->lawyer, [$unassigned, $inProgress, $toDeparted, $assignedElsewhere, $secret]],
    ] as [$viewer, $expected]) {
        $listed = m13t5ListIds(m13t5Open($viewer, $this->lawyer)->html(), 'requests');

        expect(m13t5Sorted($listed))->toBe(m13t5Ids($expected))
            ->and(count($listed))->toBe(m13t5Row($viewer, $this->lawyer)->awaitingOfficeRequests);
    }

    expect(m13t5ListIds(m13t5Open($this->manager, $assistant)->html(), 'requests'))->toBe([$toAssistant->getKey()])
        ->and(m13t5Row($this->manager, $assistant)->awaitingOfficeRequests)->toBe(1);
});

it('lists the documents awaiting review on matters the person leads among the homepage widget rows of the same viewer', function () {
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closed = m13t5Closed(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]));
    $elsewhere = Matter::factory()->create(['lead_lawyer_id' => $other->id]);
    $elsewhere->addTeamMember($this->lawyer, MatterRole::Associate);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $item = fn (Matter $on, ChecklistItemStatus $status = ChecklistItemStatus::PendingReview): MatterChecklistItem => MatterChecklistItem::factory()->for($on)->status($status)->create();

    $pending = $item($matter);
    $pendingOnClosed = $item($closed);
    $item($matter, ChecklistItemStatus::Accepted);
    $item($matter, ChecklistItemStatus::Missing);
    $item($elsewhere);
    $secret = $item($restricted);

    foreach ([
        [$this->manager, [$pending, $pendingOnClosed]],
        [$this->lawyer, [$pending, $pendingOnClosed, $secret]],
        [$this->admin, [$pending, $pendingOnClosed, $secret]],
    ] as [$viewer, $expected]) {
        $listed = m13t5ListIds(m13t5Open($viewer, $this->lawyer)->html(), 'reviews');

        expect(m13t5Sorted($listed))->toBe(m13t5Ids($expected))
            ->and(array_values(array_diff($listed, m13t5Ids(PendingChecklistReviewsWidget::rowsFor($viewer->fresh())->get()))))->toBe([])
            ->and(count($listed))->toBe(m13t5Row($viewer, $this->lawyer)->awaitingReviewItems);
    }
});

/**
 * "Ba danh sách ngắn": mỗi danh sách in tối đa `TeamMember::LIST_LIMIT` việc gấp nhất (mốc quá hạn lâu
 * nhất, luồng chờ lâu nhất, giấy tờ nộp sớm nhất) và nói tổng số — tổng đó là chính con số đầu trang.
 */
it('keeps each list short, the most urgent first, and says how many there are in all', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $limit = TeamMember::LIST_LIMIT;
    $total = $limit + 2;

    // Đến hạn từ ngày +2 lùi dần về quá hạn: ba mốc ở N6, phần còn lại ở N5 — tổng là N5 + N6.
    $deadlines = collect(range(1, $total))->map(fn (int $n): Deadline => Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $this->lawyer->id, 'due_date' => today()->addDays(3 - $n)->toDateString(),
    ]));
    $requests = collect(range(1, $total))->map(function (int $hoursWaiting) use ($matter): ClientRequest {
        $request = ClientRequest::factory()->for($matter)->create();
        $request->forceFill(['created_at' => now()->subHours($hoursWaiting)])->save();

        return $request;
    });
    $items = MatterChecklistItem::factory()->count($total)->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    $html = m13t5Open($this->manager, $this->lawyer)->html();
    $row = m13t5Row($this->manager, $this->lawyer);
    $more = __('performance.team_member.lists.more', ['shown' => $limit, 'total' => $total]);

    expect([$row->overdueDeadlines, $row->deadlinesDueSoon])->toBe([$total - 3, 3])
        ->and($row->awaitingOfficeRequests)->toBe($total)
        ->and($row->awaitingReviewItems)->toBe($total)
        ->and(m13t5ListIds($html, 'deadlines'))->toBe($deadlines->reverse()->take($limit)->map->getKey()->values()->all())
        ->and(m13t5ListIds($html, 'requests'))->toBe($requests->reverse()->take($limit)->map->getKey()->values()->all())
        ->and(m13t5ListIds($html, 'reviews'))->toHaveCount($limit)
        ->and(array_diff(m13t5ListIds($html, 'reviews'), $items->map->getKey()->all()))->toBe([]);

    foreach (['deadlines', 'requests', 'reviews'] as $list) {
        expect(m13t5Text(m13t5Block($html, 'data-vk-member-list="'.$list.'"')))->toContain($more);
    }
});

it('prints the matter code, the item and a link to the matter on each list row, and never a restricted one to the manager', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);

    foreach ([$matter, $restricted] as $on) {
        Deadline::factory()->for($on)->create(['responsible_user_id' => $this->lawyer->id, 'due_date' => today()->subDay()->toDateString(), 'name' => "Mốc của {$on->code}"]);
        ClientRequest::factory()->for($on)->create(['subject' => "Hỏi về {$on->code}"]);
        MatterChecklistItem::factory()->for($on)->status(ChecklistItemStatus::PendingReview)->create(['name' => "Giấy tờ của {$on->code}"]);
    }

    $managerHtml = m13t5Open($this->manager, $this->lawyer)->html();
    $ownHtml = m13t5Open($this->lawyer, $this->lawyer)->html();

    foreach (['deadlines' => 'Mốc của', 'requests' => 'Hỏi về', 'reviews' => 'Giấy tờ của'] as $list => $prefix) {
        $managerList = m13t5Block($managerHtml, 'data-vk-member-list="'.$list.'"');
        $ownList = m13t5Text(m13t5Block($ownHtml, 'data-vk-member-list="'.$list.'"'));

        expect(m13t5Text($managerList))->toContain("{$prefix} {$matter->code}")
            ->toContain($matter->code)
            ->not->toContain($restricted->code)
            // Cặp âm của "nói tổng số": danh sách đủ thì không có câu "hiện … trên tổng …".
            ->not->toContain(__('performance.team_member.lists.more', ['shown' => 1, 'total' => 1]))
            ->and($managerList)->toContain(e(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin')))
            ->and($ownList)->toContain("{$prefix} {$restricted->code}");
    }

    expect($managerHtml)->not->toContain($restricted->code)
        ->not->toContain(e($restricted->title))
        ->not->toContain(e($restricted->client->name));
});

/**
 * Mỗi danh sách hiện với đúng người thấy widget trang chủ tương ứng (`canView()`), và cột tiêu đề với
 * người có `matter.view` (như `MattersTable`): nhân chứng có `performance.viewAny` nhưng không có
 * `matter.view` hay `checklist.review` đọc số, không đọc nội dung hồ sơ.
 */
it('shows the lists and the title column only to a viewer who may read them on the homepage', function () {
    $witness = User::factory()->create();
    $witness->givePermissionTo([Permission::PerformanceViewAny->value, Permission::MatterViewAny->value]);

    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'title' => 'Tiêu đề không cho nhân chứng']);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $this->lawyer->id, 'due_date' => today()->subDay()->toDateString(), 'name' => 'Mốc không cho nhân chứng']);
    ClientRequest::factory()->for($matter)->create(['subject' => 'Yêu cầu không cho nhân chứng']);
    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create(['name' => 'Giấy tờ không cho nhân chứng']);

    $witnessPage = m13t5Open($witness, $this->lawyer)->assertTableColumnHidden('title')->assertCanSeeTableRecords([$matter]);
    $managerPage = m13t5Open($this->manager, $this->lawyer)->assertTableColumnVisible('title');

    expect($witnessPage->html())->not->toContain('data-vk-member-list=')
        ->not->toContain('không cho nhân chứng')
        ->and(m13t5Metrics($witnessPage->html())['overdueDeadlines']['value'])->toBe('1')
        ->and($managerPage->html())->toContain('data-vk-member-list="deadlines"')
        ->toContain('data-vk-member-list="requests"')
        ->toContain('data-vk-member-list="reviews"')
        ->toContain('Mốc không cho nhân chứng')
        ->toContain('Yêu cầu không cho nhân chứng')
        ->toContain('Giấy tờ không cho nhân chứng')
        ->toContain('Tiêu đề không cho nhân chứng');
});

/**
 * Lượt quét trước bản 1.0 (minor T5 m2, m3 của rà soát cuối M13): cột khách hàng là nội dung hồ sơ cùng
 * lý lẽ R2 với cột tiêu đề — người có `performance.viewAny` mà không có `matter.view` không đọc tên khách;
 * và khối "Cách tính" không giải thích ba danh sách mà trang không có.
 */
it('hides the client column and the lists explanation from a viewer without matter.view', function () {
    $witness = User::factory()->create();
    $witness->givePermissionTo([Permission::PerformanceViewAny->value, Permission::MatterViewAny->value]);

    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $listsSentence = __('performance.team_member.lists.explain', ['limit' => TeamMember::LIST_LIMIT]);

    $witnessPage = m13t5Open($witness, $this->lawyer)
        ->assertCanSeeTableRecords([$matter])
        ->assertTableColumnHidden('client.name');
    $managerPage = m13t5Open($this->manager, $this->lawyer)->assertTableColumnVisible('client.name');

    expect($witnessPage->html())->not->toContain(e($matter->client->name))
        ->not->toContain(e($listsSentence))
        ->and($managerPage->html())->toContain(e($matter->client->name))
        ->toContain(e($listsSentence));
});

/** Mỗi danh sách tách riêng: chỉ có `matter.view` thì thấy mốc và yêu cầu, không thấy hàng chờ duyệt. */
it('decides each list by the permission of its own homepage widget', function () {
    $witness = User::factory()->create();
    $witness->givePermissionTo([Permission::PerformanceViewAny->value, Permission::MatterViewAny->value, Permission::MatterView->value]);

    $html = m13t5Open($witness, $this->lawyer)->html();

    expect($html)->toContain('data-vk-member-list="deadlines"')
        ->toContain('data-vk-member-list="requests"')
        ->not->toContain('data-vk-member-list="reviews"');
});

// =================================================================================================
// Livewire: khoá, 404 giữa chừng, xu hướng (Task 7)
// =================================================================================================

it('keeps the subject locked while the table is filtered', function () {
    $page = m13t5Open($this->manager, $this->lawyer)->filterTable('state', 'open');

    expect(fn () => $page->set('subjectId', $this->admin->getKey()))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and($page->instance()->subjectId)->toBe($this->lawyer->getKey());
});

it('answers 404 to a table filter request once the viewer may no longer see this person, and serves it otherwise', function () {
    $closed = m13t5Closed(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]));
    $open = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $snapshot = m13t5Snapshot($this->actingAs($this->manager, 'web')
        ->get(TeamMember::getUrl(['user' => $this->lawyer->getKey()], panel: 'admin'))
        ->assertOk()
        ->getContent());

    $served = m13t5PostUpdate($snapshot, ['tableFilters.state.value' => 'closed'])->assertOk();
    $html = (string) $served->json('components.0.effects.html');

    expect($html)->toContain($closed->code)->not->toContain($open->code);

    $this->manager->syncRoles([Role::Lawyer->value]);
    $this->actingAs($this->manager->fresh(), 'web');

    $refused = m13t5PostUpdate($snapshot, ['tableFilters.state.value' => 'closed']);

    $refused->assertNotFound();
    expect($refused->getContent())->not->toContain($closed->code)->not->toContain(e($this->lawyer->name));
});

it('hands the person\'s id to the trend widgets through getWidgetData', function () {
    expect(m13t5Open($this->manager, $this->lawyer)->instance()->getWidgetData())->toBe(['subjectId' => $this->lawyer->getKey()]);
});

it('explains every number on the page in the collapsible block, under the fixed scope sentence', function () {
    $html = m13t5Open($this->manager, $this->lawyer)->html();
    $start = strpos($html, 'data-vk-performance-explain');
    $block = m13t5Text(substr($html, $start, strpos($html, '</details>', $start) - $start));

    foreach ([...array_values(M13T5_METRICS), 'not_applicable'] as $code) {
        expect($block)->toContain(__("performance.explain.{$code}"));
    }

    expect($block)->toContain(__('performance.team_member.mix.explain'))
        ->toContain(__('performance.team_member.lists.explain', ['limit' => TeamMember::LIST_LIMIT]))
        ->and(m13t5Text($html))->toContain(__('performance.scope_note'))
        ->and($html)->not->toMatch('/performance\.(?:team_member|explain|columns)\./');
});

// =================================================================================================
// Nhật ký `performance_viewed` (R14)
// =================================================================================================

it('writes one performance_viewed line each time someone else\'s page is opened, with that person as subject', function () {
    m13t5Open($this->manager, $this->lawyer);
    m13t5Open($this->manager, $this->lawyer);

    $rows = Activity::query()->where('event', 'performance_viewed')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('causer_id')->unique()->values()->all())->toBe([$this->manager->getKey()])
        ->and($rows->pluck('subject_id')->unique()->values()->all())->toBe([$this->lawyer->getKey()])
        ->and($rows->first()->subject_type)->toBe($this->lawyer->getMorphClass())
        ->and($rows->first()->properties->all())->toBe([]);
});

it('writes no performance_viewed line when a person opens their own page', function (Role $role) {
    $self = User::factory()->withRole($role)->create();

    m13t5Open($self, $self)->assertOk();

    expect(Activity::query()->where('event', 'performance_viewed')->count())->toBe(0);
})->with([Role::Lawyer, Role::Assistant, Role::Manager]);

it('writes no further line when the table is filtered, sorted or refreshed', function () {
    Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    m13t5Open($this->manager, $this->lawyer)
        ->filterTable('state', 'open')
        ->filterTable('role', 'lead')
        ->call('sortTable', 'code', 'desc')
        ->call('$refresh');

    expect(Activity::query()->where('event', 'performance_viewed')->count())->toBe(1);
});

it('writes no line for someone turned away from another person\'s page', function () {
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($this->lawyer, 'web')
        ->get(TeamMember::getUrl(['user' => $colleague->getKey()], panel: 'admin'))
        ->assertNotFound();

    expect(Activity::query()->where('event', 'performance_viewed')->count())->toBe(0);
});

it('shows the Vietnamese label of performance_viewed on the activity log page', function () {
    m13t5Open($this->manager, $this->lawyer);

    $this->actingAs($this->admin->fresh(), 'web')
        ->get(ActivityLogPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('activity.events.performance_viewed'))
        ->assertDontSee('activity.events.performance_viewed');
});

// =================================================================================================
// Định nghĩa: `Matter::supportedBy()` (lọc "tham gia", cơ cấu của trợ lý)
// =================================================================================================

it('reads supportedBy() as exactly the supporting seats withSupportingMember() counts for that person, and workedOnBy() as ledBy() or supportedBy()', function () {
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $led = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $seat = fn (MatterRole $role): Matter => tap(Matter::factory()->create(['lead_lawyer_id' => $other->id]))->addTeamMember($this->lawyer, $role);

    $associate = $seat(MatterRole::Associate);
    $assistantSeat = $seat(MatterRole::Assistant);
    $seat(MatterRole::Observer);
    $closedAssociate = m13t5Closed($seat(MatterRole::Associate));
    Matter::factory()->create(['lead_lawyer_id' => $other->id]);

    $seats = Matter::query()->withSupportingMember()->get()
        ->filter(fn (Matter $matter): bool => (int) $matter->getAttribute('member_id') === $this->lawyer->getKey());

    expect(m13t5Ids(Matter::query()->supportedBy($this->lawyer)->get()))->toBe(m13t5Ids($seats))
        ->toBe(m13t5Ids([$associate, $assistantSeat, $closedAssociate]))
        ->and(m13t5Ids(Matter::query()->workedOnBy($this->lawyer)->get()))->toBe(m13t5Ids([$led, $associate, $assistantSeat, $closedAssociate]))
        ->and(Matter::query()->supportedBy($other)->count())->toBe(0);
});

/** Phòng thủ của Action (trang đã hỏi trước): không dựng cơ cấu cho người mà người xem không được xem. */
it('refuses to build the breakdown for someone the viewer may not see', function () {
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => app(BuildMatterTypeMix::class)->handle($this->lawyer->fresh(), $colleague->fresh()))
        ->toThrow(AuthorizationException::class)
        ->and(app(BuildMatterTypeMix::class)->handle($this->lawyer->fresh(), $this->lawyer->fresh())->byLead)->toBeTrue();
});
