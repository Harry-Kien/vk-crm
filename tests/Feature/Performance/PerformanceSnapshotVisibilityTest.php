<?php

use App\Enums\Confidentiality;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\PerformanceSnapshot;
use App\Models\User;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 7 — ai đọc được dòng ảnh chụp nào (R4): `PerformanceSnapshot::visibleLevels()` là hàm quyết
 * định DUY NHẤT; `scopeVisibleTo()` và `scopeVisibleToMany()` chỉ dịch nó sang SQL. Đóng khi không chắc:
 * dòng `normal` (tổng toàn văn phòng của một người, tính NGOÀI `listableBy`) chỉ khi người xem có
 * `matter.viewAny` hoặc là chính người đó với `matter.view`; dòng `restricted` suy từ `Matter::isListableBy()`
 * trên một vụ `restricted` GIẢ do người đó phụ trách — ma trận dưới so nó với một vụ `restricted` THẬT
 * qua `Matter::listableBy()` ở từng ô.
 *
 * Ô "kế toán" ghim hành vi thật của chữ R4: kế toán có `matter.viewAny`, nên được dòng `normal`. Đó
 * không phải rò rỉ — kế toán không bao giờ tới chỗ đọc ảnh chụp: `viewPerformance` chặn họ ở cả ba trang
 * và ở hai widget (`PerformanceTrendWidgetAccessTest`).
 *
 * Hàm toàn cục mang tiền tố `m13t7Vis`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-04 10:00:00'));

    $this->subject = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư X']);
    $this->other = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Y']);

    // Một vụ restricted THẬT của X: nhân chứng của nhánh restricted trong listableBy().
    $this->secret = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->subject->id]);

    foreach ([$this->subject, $this->other] as $person) {
        foreach (Confidentiality::cases() as $level) {
            m13t7VisRow($person, $level);
        }
    }
});

function m13t7VisRow(User $person, Confidentiality $level, string $day = '2026-10-03'): PerformanceSnapshot
{
    return PerformanceSnapshot::query()->create([
        'captured_on' => $day,
        'user_id' => $person->id,
        'confidentiality' => $level,
        'open_lead_matters' => 1,
        'stale_matters' => 1,
        'overdue_deadlines' => 1,
        'checklist_settled' => 1,
        'checklist_total' => 2,
    ]);
}

/** Người xem của ma trận, dựng theo tên ô. */
function m13t7VisViewer(string $cell, User $subject): User
{
    $witness = function (Permission ...$permissions): User {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (Permission $p): string => $p->value, $permissions));

        return $user->fresh();
    };

    return match ($cell) {
        'admin' => User::factory()->withRole(Role::Admin)->create(),
        'manager' => User::factory()->withRole(Role::Manager)->create(),
        'the subject' => $subject->fresh(),
        'the subject without matter.view' => tap($subject->fresh(), fn (User $x) => $x->syncRoles([]))->fresh(),
        'another lawyer' => User::factory()->withRole(Role::Lawyer)->create(),
        'an assistant' => User::factory()->withRole(Role::Assistant)->create(),
        'the accountant' => User::factory()->withRole(Role::Accountant)->create(),
        'performance.viewAny alone' => $witness(Permission::PerformanceViewAny),
        'performance.viewAny with matter.view' => $witness(Permission::PerformanceViewAny, Permission::MatterView),
        'matter.viewAny alone' => $witness(Permission::MatterViewAny),
    };
}

/** @return list<string> */
function m13t7VisLevels(array $levels): array
{
    return array_map(fn (Confidentiality $level): string => $level->value, $levels);
}

it('decides which snapshot rows each viewer reads about X, closed when unsure, and agrees with listableBy on the restricted row', function (string $cell, array $expected) {
    $viewer = m13t7VisViewer($cell, $this->subject);
    $subject = $this->subject->fresh();

    $levels = m13t7VisLevels(PerformanceSnapshot::visibleLevels($viewer, $subject));

    $rows = PerformanceSnapshot::query()
        ->visibleTo($viewer, $subject)
        ->get()
        ->map(fn (PerformanceSnapshot $row): string => $row->user_id === $subject->id ? $row->confidentiality->value : 'someone else')
        ->sort()
        ->values()
        ->all();

    $seesRealRestricted = Matter::query()->listableBy($viewer)->whereKey($this->secret->id)->exists();

    expect($levels)->toBe($expected)
        ->and($rows)->toBe($expected)
        ->and(in_array('restricted', $levels, true))->toBe($seesRealRestricted);
})->with([
    'admin' => ['admin', ['normal', 'restricted']],
    'manager' => ['manager', ['normal']],
    'the subject' => ['the subject', ['normal', 'restricted']],
    'the subject without matter.view' => ['the subject without matter.view', []],
    'another lawyer' => ['another lawyer', []],
    'an assistant' => ['an assistant', []],
    'the accountant' => ['the accountant', ['normal']],
    'performance.viewAny alone' => ['performance.viewAny alone', []],
    'performance.viewAny with matter.view' => ['performance.viewAny with matter.view', []],
    'matter.viewAny alone' => ['matter.viewAny alone', ['normal']],
]);

it('runs no query to decide (roles loaded, the restricted matter is a fake)', function () {
    $viewer = User::factory()->withRole(Role::Manager)->create()->load(['roles.permissions', 'permissions']);
    $subject = $this->subject->fresh()->load(['roles.permissions', 'permissions']);
    PerformanceSnapshot::visibleLevels($viewer, $subject);

    DB::flushQueryLog();
    DB::enableQueryLog();
    PerformanceSnapshot::visibleLevels($viewer, $subject);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

it('gives the whole page the same rows as the union of the per-person scopes, in one query', function (string $cell) {
    $viewer = m13t7VisViewer($cell, $this->subject);
    $subjects = User::query()->whereKey([$this->subject->id, $this->other->id])->with(['roles.permissions', 'permissions'])->get();

    $union = $subjects
        ->flatMap(fn (User $subject) => PerformanceSnapshot::query()->visibleTo($viewer, $subject)->pluck('id'))
        ->sort()->values()->all();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $many = PerformanceSnapshot::query()->visibleToMany($viewer, $subjects)->pluck('id')->sort()->values()->all();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($union)
        ->and($queries)->toBe(1);
})->with(['admin', 'manager', 'the subject', 'another lawyer', 'the accountant', 'performance.viewAny alone']);

it('reads nothing for an empty page', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(PerformanceSnapshot::query()->visibleToMany($admin, collect())->count())->toBe(0)
        ->and(PerformanceSnapshot::query()->count())->toBe(4);
});

it('never lets the client portal read a snapshot', function () {
    $clientUser = ClientUser::factory()->activated()->create();

    expect(ClientPortalScope::actingAs($clientUser, fn (): int => PerformanceSnapshot::query()->count()))->toBe(0)
        ->and(PerformanceSnapshot::query()->count())->toBe(4);
});

it('lets the policy refuse every client and every write, and read a row only through visibleLevels()', function () {
    $clientUser = ClientUser::factory()->activated()->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $normal = PerformanceSnapshot::query()->where('user_id', $this->subject->id)->where('confidentiality', 'normal')->sole();
    $restricted = PerformanceSnapshot::query()->where('user_id', $this->subject->id)->where('confidentiality', 'restricted')->sole();

    foreach (['viewAny', 'create'] as $ability) {
        expect(Gate::forUser($clientUser)->allows($ability, PerformanceSnapshot::class))->toBeFalse();
    }

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($clientUser)->allows($ability, $normal))->toBeFalse("client {$ability}");
    }

    foreach (['update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($admin)->allows($ability, $normal))->toBeFalse("admin {$ability}");
    }

    expect(Gate::forUser($admin)->allows('create', PerformanceSnapshot::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $restricted))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('view', $normal))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('view', $restricted))->toBeFalse()
        ->and(Gate::forUser($this->other->fresh())->allows('view', $normal))->toBeFalse();
});

it('can be the subject of an audit line (strict morph map)', function () {
    $row = PerformanceSnapshot::query()->firstOrFail();

    Audit::record('performance_viewed', $row, [], causer: $this->subject);

    expect(Activity::query()->where('subject_type', 'performance_snapshot')->where('subject_id', $row->id)->count())->toBe(1);
});

it('stores the day, the person, the level and five counts, and refuses a second row for the same day, person and level', function () {
    $row = PerformanceSnapshot::query()->where('user_id', $this->other->id)->where('confidentiality', 'normal')->sole();

    expect($row->captured_on->toDateString())->toBe('2026-10-03')
        ->and($row->confidentiality)->toBe(Confidentiality::Normal)
        ->and($row->user->is($this->other))->toBeTrue()
        ->and($row->checklist_total)->toBe(2);

    expect(fn () => m13t7VisRow($this->other, Confidentiality::Normal))->toThrow(UniqueConstraintViolationException::class);
});
