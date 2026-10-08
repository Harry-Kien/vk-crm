<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\Search;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Pages\TeamOverview;
use App\Models\ClientUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * M13 Task 1 — quyền `performance.viewAny` (R2, SPEC §5 đính chính 2026-10-04),
 * `UserPolicy::viewPerformance()`/`viewPerformanceRevenue()`, và khung ba trang: "Theo dõi đội ngũ"
 * (`/team`), "Trang của một người" (`/team/{user}`), "Hiệu suất theo kỳ" (`/performance`).
 *
 * Mọi hành vi màn hình đo qua HTTP hoặc Livewire. Từ chối luôn là 404 (SPEC §10.10), và người
 * không tồn tại, người đã xoá mềm, người ngoài danh sách R3 cùng một response từng byte. Nhân chứng
 * "không vai trò, được cấp thẳng quyền" tách từng vế của mỗi điều kiện, để một mutation probe trên
 * MỘT vế có test đỏ riêng.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function m13Staff(Role $role, array $attributes = []): User
{
    return User::factory()->withRole($role)->create($attributes);
}

/** Người không vai trò, chỉ mang đúng các quyền nêu tên. */
function m13Witness(Permission ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));

    return $user->fresh();
}

function m13Get(User $viewer, string $url): TestResponse
{
    return test()->actingAs($viewer, 'web')->get($url);
}

function m13OverviewUrl(): string
{
    return TeamOverview::getUrl(panel: 'admin');
}

function m13PeriodUrl(): string
{
    return Performance::getUrl(panel: 'admin');
}

function m13MemberUrl(int|string $id): string
{
    return TeamMember::getUrl(['user' => $id], panel: 'admin');
}

/** Snapshot Livewire của đúng component `$class` trong HTML của một lần tải trang. */
function m13Snapshot(string $html, string $class): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $class) {
            return $snapshot;
        }
    }

    throw new RuntimeException("Không có snapshot Livewire của {$class} trong HTML.");
}

/** Một request cập nhật Livewire THẬT (đường `/livewire-…/update`), không qua `Livewire::test()`. */
function m13PostUpdate(string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
    ]);
}

// ---------------------------------------------------------------------------------------------
// Ma trận năm vai trò × ba trang
// ---------------------------------------------------------------------------------------------

it('opens all three pages for admin and manager, each with the fixed scope sentence', function (Role $role) {
    $viewer = m13Staff($role);
    $lawyer = m13Staff(Role::Lawyer);

    m13Get($viewer, m13OverviewUrl())->assertOk()
        ->assertSee(__('performance.pages.team_overview.title'))
        ->assertSee(__('performance.scope_note'));
    m13Get($viewer, m13PeriodUrl())->assertOk()
        ->assertSee(__('performance.pages.performance.title'))
        ->assertSee(__('performance.scope_note'));
    m13Get($viewer, m13MemberUrl($lawyer->getKey()))->assertOk()
        ->assertSee($lawyer->name)
        ->assertSee(__('performance.scope_note'));
})->with([Role::Admin, Role::Manager]);

it('lets lawyers and assistants open their own page and the period page, and nothing else', function (Role $role) {
    $viewer = m13Staff($role);
    $colleague = m13Staff(Role::Lawyer);

    m13Get($viewer, m13OverviewUrl())->assertNotFound();
    m13Get($viewer, m13PeriodUrl())->assertOk()->assertSee(__('performance.scope_note'));
    m13Get($viewer, m13MemberUrl($viewer->getKey()))->assertOk()
        ->assertSee(__('performance.pages.team_member.title_self'))
        ->assertSee(__('performance.scope_note'));
    m13Get($viewer, m13MemberUrl($colleague->getKey()))->assertNotFound()->assertDontSee($colleague->name);
})->with([Role::Lawyer, Role::Assistant]);

it('answers 404 to the accountant on all three pages, own page included', function () {
    $accountant = m13Staff(Role::Accountant);
    $lawyer = m13Staff(Role::Lawyer);

    m13Get($accountant, m13OverviewUrl())->assertNotFound();
    m13Get($accountant, m13PeriodUrl())->assertNotFound();
    m13Get($accountant, m13MemberUrl($accountant->getKey()))->assertNotFound();
    m13Get($accountant, m13MemberUrl($lawyer->getKey()))->assertNotFound()->assertDontSee($lawyer->name);
});

/**
 * Tách từng vế của `canAccess()`: người chỉ có `performance.viewAny` (không `matter.view`) mở được
 * cả ba trang; người chỉ có `matter.view` (không vai trò nào nên ngoài danh sách R3) mở được trang
 * hiệu suất nhưng không mở được "Theo dõi đội ngũ" và không có trang của chính mình.
 */
it('opens every page for a direct performance.viewAny holder without matter.view', function () {
    $witness = m13Witness(Permission::PerformanceViewAny);
    $lawyer = m13Staff(Role::Lawyer);

    m13Get($witness, m13OverviewUrl())->assertOk();
    m13Get($witness, m13PeriodUrl())->assertOk();
    m13Get($witness, m13MemberUrl($lawyer->getKey()))->assertOk()->assertSee($lawyer->name);
});

it('opens only the period page for a direct matter.view holder who is not on the roster', function () {
    $witness = m13Witness(Permission::MatterView);

    m13Get($witness, m13PeriodUrl())->assertOk();
    m13Get($witness, m13OverviewUrl())->assertNotFound();
    m13Get($witness, m13MemberUrl($witness->getKey()))->assertNotFound();
});

it('keeps the team overview closed to someone who can list every matter but lacks performance.viewAny', function () {
    $witness = m13Witness(Permission::MatterViewAny, Permission::MatterView);

    m13Get($witness, m13OverviewUrl())->assertNotFound();
});

// ---------------------------------------------------------------------------------------------
// /team/{id}: một 404 duy nhất
// ---------------------------------------------------------------------------------------------

it('gives the same 404 for an admin, an accountant, a soft-deleted person, an unknown id and a padded id', function () {
    $manager = m13Staff(Role::Manager);
    $admin = m13Staff(Role::Admin);
    $accountant = m13Staff(Role::Accountant);
    $gone = m13Staff(Role::Lawyer);
    $gone->delete();
    $lawyer = m13Staff(Role::Lawyer);
    $unknown = (int) User::withTrashed()->max('id') + 1000;

    $bodies = collect([$admin->getKey(), $accountant->getKey(), $gone->getKey(), $unknown, $lawyer->getKey().'abc'])
        ->map(fn (int|string $id): string => m13Get($manager, m13MemberUrl($id))->assertNotFound()->getContent());

    // Cùng câu với một luật sư mở trang của đồng nghiệp.
    $bodies->push(m13Get($lawyer, m13MemberUrl($admin->getKey()))->assertNotFound()->getContent());

    expect($bodies->unique()->values()->all())->toHaveCount(1)
        ->and($bodies->first())->not->toContain(e($admin->name))
        ->and($bodies->first())->not->toContain(e($gone->name));

    // Cặp dương: cùng người xem, cùng đường dẫn, với một người trong danh sách.
    m13Get($manager, m13MemberUrl($lawyer->getKey()))->assertOk()->assertSee($lawyer->name);
});

it('opens the page of a deactivated (not deleted) lawyer for the manager', function () {
    $manager = m13Staff(Role::Manager);
    $left = m13Staff(Role::Lawyer, ['is_active' => false]);

    m13Get($manager, m13MemberUrl($left->getKey()))->assertOk()->assertSee($left->name);
});

// ---------------------------------------------------------------------------------------------
// Mất quyền giữa mount() và request Livewire kế tiếp: hook boot() trả 404
// ---------------------------------------------------------------------------------------------

it('answers 404 to the next Livewire request on the team overview once performance.viewAny is gone', function () {
    $manager = m13Staff(Role::Manager);

    $snapshot = m13Snapshot(m13Get($manager, m13OverviewUrl())->assertOk()->getContent(), TeamOverview::class);

    $manager->syncRoles([Role::Lawyer->value]);
    $this->actingAs($manager->fresh(), 'web');

    m13PostUpdate($snapshot, [], [['path' => '', 'method' => '$refresh', 'params' => []]])->assertNotFound();
});

it('answers 404 to the next Livewire request on the period page once the viewer lost every role', function () {
    $lawyer = m13Staff(Role::Lawyer);

    $snapshot = m13Snapshot(m13Get($lawyer, m13PeriodUrl())->assertOk()->getContent(), Performance::class);

    $lawyer->syncRoles([]);
    $this->actingAs($lawyer->fresh(), 'web');

    m13PostUpdate($snapshot, [], [['path' => '', 'method' => '$refresh', 'params' => []]])->assertNotFound();
});

it('answers 404 to the next Livewire request on a member page once the viewer lost every role', function () {
    $lawyer = m13Staff(Role::Lawyer);

    $snapshot = m13Snapshot(m13Get($lawyer, m13MemberUrl($lawyer->getKey()))->assertOk()->getContent(), TeamMember::class);

    $lawyer->syncRoles([]);
    $this->actingAs($lawyer->fresh(), 'web');

    m13PostUpdate($snapshot, [], [['path' => '', 'method' => '$refresh', 'params' => []]])->assertNotFound();
});

/**
 * Người xem vẫn mở được trang (còn `matter.view`) nhưng không còn được xem NGƯỜI NÀY: chỉ lần hỏi
 * lại `viewPerformance` trên chủ thể trong `boot()` trả 404 — `canAccess()` của trang vẫn đúng.
 */
it('answers 404 to the next Livewire request once the viewer may no longer see this person', function (string $how) {
    $manager = m13Staff(Role::Manager);
    $lawyer = m13Staff(Role::Lawyer);

    $snapshot = m13Snapshot(m13Get($manager, m13MemberUrl($lawyer->getKey()))->assertOk()->getContent(), TeamMember::class);

    match ($how) {
        'viewer demoted' => $manager->syncRoles([Role::Lawyer->value]),
        'subject left the roster' => $lawyer->syncRoles([Role::Accountant->value]),
        'subject deleted' => $lawyer->delete(),
    };

    $this->actingAs($manager->fresh(), 'web');

    $response = m13PostUpdate($snapshot, [], [['path' => '', 'method' => '$refresh', 'params' => []]]);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain(e($lawyer->name));
})->with(['viewer demoted', 'subject left the roster', 'subject deleted']);

/** Cặp dương của ba test trên: không ai mất quyền thì request cập nhật đi qua. */
it('lets the next Livewire request through when nothing changed', function () {
    $manager = m13Staff(Role::Manager);
    $lawyer = m13Staff(Role::Lawyer);

    $snapshot = m13Snapshot(m13Get($manager, m13MemberUrl($lawyer->getKey()))->assertOk()->getContent(), TeamMember::class);

    m13PostUpdate($snapshot, [], [['path' => '', 'method' => '$refresh', 'params' => []]])->assertOk();
});

it('refuses to retarget a member page through its locked subject id', function () {
    $lawyer = m13Staff(Role::Lawyer);
    $colleague = m13Staff(Role::Lawyer);

    $this->actingAs($lawyer, 'web');

    expect(fn () => Livewire::test(TeamMember::class, ['user' => $lawyer->getKey()])->set('subjectId', $colleague->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

// ---------------------------------------------------------------------------------------------
// Nhật ký (R14) và thanh điều hướng
// ---------------------------------------------------------------------------------------------

it('writes one performance_viewed line when the team overview is opened, and none on later requests', function () {
    $manager = m13Staff(Role::Manager);
    $this->actingAs($manager, 'web');

    Livewire::test(TeamOverview::class)->call('$refresh')->call('$refresh');

    $rows = Activity::query()->where('event', 'performance_viewed')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->causer_id)->toBe($manager->getKey())
        ->and($rows->first()->subject_id)->toBeNull()
        ->and($rows->first()->properties->all())->toBe(['page' => 'team_overview'])
        ->and(__('activity.events.performance_viewed'))->not->toBe('activity.events.performance_viewed');
});

it('writes no performance_viewed line for someone turned away', function () {
    m13Get(m13Staff(Role::Lawyer), m13OverviewUrl())->assertNotFound();

    expect(Activity::query()->where('event', 'performance_viewed')->count())->toBe(0);
});

it('shows the team overview and the period page in the navigation of a performance.viewAny holder', function () {
    $html = m13Get(m13Staff(Role::Manager), m13PeriodUrl())->assertOk()->getContent();

    expect($html)->toContain('href="'.e(m13OverviewUrl()).'"')
        ->and($html)->toContain('href="'.e(m13PeriodUrl()).'"')
        ->and($html)->toContain(e(__('performance.pages.team_overview.navigation_label')))
        ->and($html)->not->toContain(e(__('performance.pages.team_member.navigation_label')));
});

it('shows "my work" pointing at the viewer\'s own page and the period page to lawyers and assistants', function (Role $role) {
    $viewer = m13Staff($role);

    $html = m13Get($viewer, m13PeriodUrl())->assertOk()->getContent();

    expect($html)->toContain('href="'.e(m13MemberUrl($viewer->getKey())).'"')
        ->and($html)->toContain(e(__('performance.pages.team_member.navigation_label')))
        ->and($html)->toContain('href="'.e(m13PeriodUrl()).'"')
        ->and($html)->not->toContain('href="'.e(m13OverviewUrl()).'"');
})->with([Role::Lawyer, Role::Assistant]);

/**
 * Vế "trang của chính mình mở được" của `TeamMember::shouldRegisterNavigation()`: người có
 * `matter.view` mà không vai trò nào (ngoài danh sách R3) mở được trang hiệu suất nhưng không có
 * trang của chính mình — mục "Việc của tôi" không được dẫn tới một 404.
 */
it('hides "my work" from someone whose own page would answer 404', function () {
    $witness = m13Witness(Permission::MatterView);

    $html = m13Get($witness, m13PeriodUrl())->assertOk()->getContent();

    expect($html)->toContain('href="'.e(m13PeriodUrl()).'"')
        ->and($html)->not->toContain('href="'.e(m13MemberUrl($witness->getKey())).'"')
        ->and($html)->not->toContain(e(__('performance.pages.team_member.navigation_label')));
});

it('shows none of the three pages in the accountant\'s navigation', function () {
    $html = m13Get(m13Staff(Role::Accountant), Search::getUrl(panel: 'admin'))->assertOk()->getContent();

    expect($html)->not->toContain('href="'.e(m13OverviewUrl()).'"')
        ->and($html)->not->toContain('href="'.e(m13PeriodUrl()).'"')
        ->and($html)->not->toContain(e(__('performance.pages.team_member.navigation_label')));
});

// ---------------------------------------------------------------------------------------------
// Policy và bảng vai trò
// ---------------------------------------------------------------------------------------------

it('decides viewPerformance from performance.viewAny or self with matter.view, always on a tracked person', function () {
    $manager = m13Staff(Role::Manager);
    $lawyer = m13Staff(Role::Lawyer);
    $colleague = m13Staff(Role::Lawyer);
    $admin = m13Staff(Role::Admin);
    $client = ClientUser::factory()->create();

    expect(Gate::forUser($manager)->allows('viewPerformance', $lawyer))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('viewPerformance', $manager))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('viewPerformance', $admin))->toBeFalse()
        ->and(Gate::forUser($lawyer)->allows('viewPerformance', $lawyer))->toBeTrue()
        ->and(Gate::forUser($lawyer)->allows('viewPerformance', $colleague))->toBeFalse()
        ->and(Gate::forUser($client)->allows('viewPerformance', $lawyer))->toBeFalse();
});

it('refuses a lawyer their own numbers once the lawyer role loses matter.view', function () {
    $lawyer = m13Staff(Role::Lawyer);
    SpatieRole::findByName(Role::Lawyer->value, 'web')->revokePermissionTo(Permission::MatterView->value);

    expect(Gate::forUser($lawyer->fresh())->allows('viewPerformance', $lawyer->fresh()))->toBeFalse();
});

it('shows the revenue column only with billing.view and either revenue.viewAny or one\'s own row', function () {
    $manager = m13Staff(Role::Manager);
    $lawyer = m13Staff(Role::Lawyer);
    $colleague = m13Staff(Role::Lawyer);
    $assistant = m13Staff(Role::Assistant);
    $accountant = m13Staff(Role::Accountant);
    $client = ClientUser::factory()->create();
    // performance.viewAny + billing.view, KHÔNG revenue.viewAny: thấy trang của người khác, không thấy tiền của họ.
    $noRevenue = m13Witness(Permission::PerformanceViewAny, Permission::BillingView);

    expect(Gate::forUser($manager)->allows('viewPerformanceRevenue', $lawyer))->toBeTrue()
        ->and(Gate::forUser($lawyer)->allows('viewPerformanceRevenue', $lawyer))->toBeTrue()
        ->and(Gate::forUser($lawyer)->allows('viewPerformanceRevenue', $colleague))->toBeFalse()
        ->and(Gate::forUser($assistant)->allows('viewPerformanceRevenue', $assistant))->toBeFalse()
        ->and(Gate::forUser($noRevenue)->allows('viewPerformance', $lawyer))->toBeTrue()
        ->and(Gate::forUser($noRevenue)->allows('viewPerformanceRevenue', $lawyer))->toBeFalse()
        // Kế toán có billing.view VÀ revenue.viewAny nhưng "không gì cả" ở M13 (R2).
        ->and(Gate::forUser($accountant)->allows('viewPerformanceRevenue', $lawyer))->toBeFalse()
        ->and(Gate::forUser($client)->allows('viewPerformanceRevenue', $lawyer))->toBeFalse();
});

/**
 * R2, test cấu trúc: tương đương ảnh chụp ở R10 dựa vào việc người xem có `performance.viewAny`
 * cũng có `matter.viewAny`. Đọc thẳng `Role::permissions()` (nguồn của seeder), không đọc CSDL.
 */
it('gives matter.viewAny to every role that has performance.viewAny', function () {
    $holders = array_filter(Role::cases(), fn (Role $role): bool => in_array(Permission::PerformanceViewAny, $role->permissions(), true));

    expect($holders)->not->toBeEmpty();

    foreach ($holders as $role) {
        expect(in_array(Permission::MatterViewAny, $role->permissions(), true))
            ->toBeTrue("vai trò {$role->value} có performance.viewAny mà không có matter.viewAny");
    }
});
