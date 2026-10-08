<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use App\Support\Performance\TeamRoster;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * M13 Task 1 — R3: `App\Support\Performance\TeamRoster` là định nghĩa DUY NHẤT của "ai được theo
 * dõi". Vai trò thuộc `TRACKED_ROLES` (luật sư, trợ lý, quản lý) và chưa xoá mềm; người nghỉ việc
 * vẫn trackable; danh sách không phụ thuộc vụ việc (R4); mọi người trả về đã nạp sẵn vai trò và
 * quyền (R11). R6: `leadsMatters()` theo quyền `matter.transitionStage`, không theo vụ.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
});

/** @return list<int> id đã sắp, để so tập không phụ thuộc thứ tự tên (SQLite và MariaDB sắp tên tiếng Việt khác nhau). */
function m13Ids(Collection $users): array
{
    return $users->map(fn (User $user): int => $user->getKey())->sort()->values()->all();
}

it('tracks lawyers, assistants and managers, and neither admins nor accountants', function () {
    $noRole = User::factory()->create();

    expect(m13Ids(TeamRoster::members()))->toBe(m13Ids(collect([$this->manager, $this->lawyer, $this->assistant])))
        ->and(TeamRoster::isTrackable($this->lawyer))->toBeTrue()
        ->and(TeamRoster::isTrackable($this->assistant))->toBeTrue()
        ->and(TeamRoster::isTrackable($this->manager))->toBeTrue()
        ->and(TeamRoster::isTrackable($this->admin))->toBeFalse()
        ->and(TeamRoster::isTrackable($this->accountant))->toBeFalse()
        ->and(TeamRoster::isTrackable($noRole))->toBeFalse()
        ->and(TeamRoster::TRACKED_ROLES)->toBe([Role::Lawyer, Role::Assistant, Role::Manager]);
});

it('keeps a deactivated person trackable, and lists them only when the switch asks for it', function () {
    $left = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);

    expect(TeamRoster::isTrackable($left))->toBeTrue()
        ->and(m13Ids(TeamRoster::members()))->not->toContain($left->getKey())
        ->and(m13Ids(TeamRoster::members(includeInactive: true)))->toContain($left->getKey())
        ->and(m13Ids(TeamRoster::subjectsFor($this->manager)))->not->toContain($left->getKey())
        ->and(m13Ids(TeamRoster::subjectsFor($this->manager, includeInactive: true)))->toContain($left->getKey());
});

it('never lists a soft-deleted person, switch or not, and does not call them trackable', function () {
    $gone = User::factory()->withRole(Role::Lawyer)->create();
    $gone->delete();
    $goneAndLeft = User::factory()->withRole(Role::Assistant)->create(['is_active' => false]);
    $goneAndLeft->delete();

    expect(TeamRoster::isTrackable($gone))->toBeFalse()
        ->and(TeamRoster::isTrackable($goneAndLeft))->toBeFalse()
        ->and(m13Ids(TeamRoster::members(includeInactive: true)))->not->toContain($gone->getKey())
        ->and(m13Ids(TeamRoster::members(includeInactive: true)))->not->toContain($goneAndLeft->getKey())
        ->and(m13Ids(TeamRoster::subjectsFor($this->manager, includeInactive: true)))->not->toContain($gone->getKey())
        ->and(m13Ids(TeamRoster::subjectsFor($this->manager, includeInactive: true)))->not->toContain($goneAndLeft->getKey());
});

/**
 * `members()` (SQL) và `isTrackable()` (bộ nhớ) là hai hình dạng của MỘT luật R3: trên một quần thể
 * trộn đủ vai trò, đang làm/nghỉ việc/xoá mềm, không vai trò và hai vai trò, tập `members()` khi bật
 * công tắc bằng đúng tập người (kể cả đã xoá mềm) mà `isTrackable()` nói "có". Một bên đổi mà bên
 * kia không đổi thì test này đỏ.
 */
it('agrees with isTrackable on who is on the roster, person by person', function () {
    User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    User::factory()->withRole(Role::Manager)->create(['is_active' => false]);
    User::factory()->withRole(Role::Assistant)->create()->delete();
    User::factory()->withRole(Role::Admin)->create(['is_active' => false]);
    User::factory()->create();
    $twoRoles = User::factory()->withRole(Role::Accountant)->create();
    $twoRoles->assignRole(Role::Lawyer->value);

    $trackable = User::withTrashed()->get()->filter(fn (User $user): bool => TeamRoster::isTrackable($user));

    expect(m13Ids(TeamRoster::members(includeInactive: true)))->toBe(m13Ids($trackable))
        ->and(m13Ids($trackable))->toContain($twoRoles->getKey());
});

it('gives a performance.viewAny holder the whole roster and everyone else only themselves', function () {
    $members = m13Ids(collect([$this->manager, $this->lawyer, $this->assistant]));

    expect(m13Ids(TeamRoster::subjectsFor($this->admin)))->toBe($members)
        ->and(m13Ids(TeamRoster::subjectsFor($this->manager)))->toBe($members)
        ->and(m13Ids(TeamRoster::subjectsFor($this->lawyer)))->toBe([$this->lawyer->getKey()])
        ->and(m13Ids(TeamRoster::subjectsFor($this->assistant)))->toBe([$this->assistant->getKey()])
        ->and(TeamRoster::subjectsFor($this->accountant)->all())->toBe([]);
});

/**
 * R3, "Không lọc danh sách theo 'có vụ mà người xem thấy được'": một luật sư chỉ phụ trách vụ
 * `restricted` biến mất khỏi danh sách của trưởng phòng thì chính sự biến mất đó xác nhận rằng có
 * vụ trưởng phòng không thấy. Danh sách chỉ phụ thuộc vai trò.
 */
it('keeps a lawyer who only leads restricted matters on the manager\'s roster', function () {
    $secretive = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $secretive->getKey(),
        'confidentiality' => Confidentiality::Restricted,
    ]);

    expect(Matter::query()->listableBy($this->manager)->whereKey($matter->getKey())->exists())->toBeFalse()
        ->and(m13Ids(TeamRoster::subjectsFor($this->manager)))->toContain($secretive->getKey());
});

it('says lawyers and managers lead matters and assistants do not, by permission and not by matters', function () {
    $lawyerWithoutMatters = User::factory()->withRole(Role::Lawyer)->create();
    $assistantOnATeam = User::factory()->withRole(Role::Assistant)->create();
    Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->getKey()])
        ->addTeamMember($assistantOnATeam, MatterRole::Assistant);

    // Nhân chứng không vai trò, được cấp thẳng quyền: chỉ quyền quyết định, không phải tên vai trò.
    $witness = User::factory()->create();
    $witness->givePermissionTo(Permission::MatterTransitionStage->value);

    expect(TeamRoster::leadsMatters($this->lawyer))->toBeTrue()
        ->and(TeamRoster::leadsMatters($lawyerWithoutMatters))->toBeTrue()
        ->and(TeamRoster::leadsMatters($this->manager))->toBeTrue()
        ->and(TeamRoster::leadsMatters($this->assistant))->toBeFalse()
        ->and(TeamRoster::leadsMatters($assistantOnATeam))->toBeFalse()
        ->and(TeamRoster::leadsMatters($witness->fresh()))->toBeTrue();
});

it('stops calling a lawyer a lead once the lawyer role loses matter.transitionStage', function () {
    SpatieRole::findByName(Role::Lawyer->value, 'web')->revokePermissionTo(Permission::MatterTransitionStage->value);

    expect(TeamRoster::leadsMatters($this->lawyer->fresh()))->toBeFalse();
});

it('returns people with roles and permissions already loaded', function () {
    $subjects = TeamRoster::subjectsFor($this->manager);

    expect($subjects)->not->toBeEmpty();

    foreach ($subjects as $subject) {
        expect($subject->relationLoaded('roles'))->toBeTrue()
            ->and($subject->relationLoaded('permissions'))->toBeTrue()
            ->and($subject->roles->every(fn ($role): bool => $role->relationLoaded('permissions')))->toBeTrue();
    }
});

/**
 * R11: hỏi Gate theo từng người không được sinh một truy vấn spatie cho mỗi người. Đếm truy vấn
 * quanh vòng `viewPerformance` (cộng `leadsMatters`, cái R6 hỏi theo từng dòng) trên 3 rồi 12 người
 * trả về: hai con số phải bằng nhau.
 */
it('asks the gate about 3 or 12 people with the same number of queries', function () {
    $count = function (User $viewer): int {
        $subjects = TeamRoster::subjectsFor($viewer);
        Gate::forUser($viewer)->allows(Permission::PerformanceViewAny->value); // nạp quyền của người xem trước

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($subjects as $subject) {
            Gate::forUser($viewer)->allows('viewPerformance', $subject);
            TeamRoster::leadsMatters($subject);
        }

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect(TeamRoster::subjectsFor($this->manager))->toHaveCount(3);
    $withThree = $count($this->manager->fresh());

    User::factory()->withRole(Role::Lawyer)->count(5)->create();
    User::factory()->withRole(Role::Assistant)->count(4)->create();
    expect(TeamRoster::subjectsFor($this->manager))->toHaveCount(12);
    $withTwelve = $count($this->manager->fresh());

    expect($withTwelve)->toBe($withThree);
});
