<?php

use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Widgets\Performance\OverdueTrendWidget;
use App\Filament\Admin\Widgets\Performance\StaleTrendWidget;
use App\Models\PerformanceSnapshot;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * M13 Task 7 — hai widget xu hướng tự kiểm quyền (Review Focus 4). Một widget là một component Livewire
 * riêng: request của nó không đi qua `boot()` của trang `TeamMember`, và `getWidgetData()` chỉ là giá trị
 * khởi đầu. Vì vậy mỗi widget mang `#[Locked] public int $subjectId` và tự hỏi
 * `UserPolicy::viewPerformance` ở `mount()` VÀ ở hook `boot` của trait `AuthorizesPerformanceSubject`;
 * từ chối là 404, và không truy vấn ảnh chụp nào chạy. Không thăm dò (`$pollingInterval = null`), không có
 * trên trang chủ.
 *
 * Hàm toàn cục mang tiền tố `m13t7Wa`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-04 10:00:00'));

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Widget']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Widget']);
    $this->colleague = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đồng Nghiệp Widget']);

    foreach ([$this->lawyer, $this->colleague] as $person) {
        PerformanceSnapshot::query()->create([
            'captured_on' => '2026-10-03', 'user_id' => $person->id, 'confidentiality' => Confidentiality::Normal,
            'open_lead_matters' => 4, 'stale_matters' => 7, 'overdue_deadlines' => 9, 'checklist_settled' => 3, 'checklist_total' => 8,
        ]);
    }
});

const M13T7WA_WIDGETS = ['stale' => [StaleTrendWidget::class], 'overdue' => [OverdueTrendWidget::class]];

/** Số truy vấn đọc bảng ảnh chụp trong `$callback`. */
function m13t7WaSnapshotQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        $log = DB::getQueryLog();
        DB::disableQueryLog();
    }

    return count(array_filter($log, fn (array $query): bool => str_contains($query['query'], 'performance_snapshots')));
}

it('answers 404 to a lawyer who mounts a colleague\'s widget directly, without reading a single snapshot', function (string $widget) {
    $this->actingAs($this->lawyer->fresh(), 'web');

    $queries = m13t7WaSnapshotQueries(fn () => Livewire::test($widget, ['subjectId' => $this->colleague->id])->assertStatus(404));

    expect($queries)->toBe(0);

    // Cặp dương: widget của chính mình mở được và đọc ảnh chụp.
    $queries = m13t7WaSnapshotQueries(fn () => Livewire::test($widget, ['subjectId' => $this->lawyer->id])->assertOk());

    expect($queries)->toBeGreaterThan(0);
})->with(M13T7WA_WIDGETS);

it('answers the same 404 for an unknown id, a soft-deleted person, an admin and an accountant as subject', function (string $widget) {
    $this->actingAs($this->manager->fresh(), 'web');
    $deleted = User::factory()->withRole(Role::Lawyer)->create();
    $deleted->delete();

    foreach ([999_999, $deleted->id, User::factory()->withRole(Role::Admin)->create()->id, User::factory()->withRole(Role::Accountant)->create()->id] as $id) {
        Livewire::test($widget, ['subjectId' => $id])->assertStatus(404);
    }

    Livewire::test($widget, ['subjectId' => $this->lawyer->id])->assertOk();
})->with(M13T7WA_WIDGETS);

it('refuses to retarget a widget through its locked subject id', function (string $widget) {
    $this->actingAs($this->lawyer->fresh(), 'web');

    expect(fn () => Livewire::test($widget, ['subjectId' => $this->lawyer->id])->set('subjectId', $this->colleague->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(M13T7WA_WIDGETS);

it('answers 404 to the widget\'s own next request once the manager lost performance.viewAny', function (string $widget) {
    $this->actingAs($this->manager->fresh(), 'web');
    $component = Livewire::test($widget, ['subjectId' => $this->lawyer->id])->assertOk();

    $this->manager->syncRoles([Role::Lawyer->value]);
    $this->actingAs($this->manager->fresh(), 'web');

    $component->call('$refresh')->assertStatus(404);
})->with(M13T7WA_WIDGETS);

it('lets the widget\'s next request through when nothing changed', function (string $widget) {
    $this->actingAs($this->manager->fresh(), 'web');

    Livewire::test($widget, ['subjectId' => $this->lawyer->id])->assertOk()->call('$refresh')->assertOk();
})->with(M13T7WA_WIDGETS);

it('answers 404 to the accountant, whose own id is not on the roster either', function (string $widget) {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->actingAs($accountant, 'web');

    expect($widget::canView())->toBeFalse();

    Livewire::test($widget, ['subjectId' => $this->lawyer->id])->assertStatus(404);
    Livewire::test($widget, ['subjectId' => $accountant->id])->assertStatus(404);
})->with(M13T7WA_WIDGETS);

it('opens the outer gate to anyone with matter.view or performance.viewAny, and only to them', function (string $widget) {
    foreach ([Role::Admin, Role::Manager, Role::Lawyer, Role::Assistant] as $role) {
        $this->actingAs(User::factory()->withRole($role)->create(), 'web');
        expect($widget::canView())->toBeTrue($role->value);
    }

    $witness = User::factory()->create();
    $witness->givePermissionTo('performance.viewAny');
    $this->actingAs($witness->fresh(), 'web');
    expect($widget::canView())->toBeTrue('performance.viewAny');

    $this->actingAs(User::factory()->create(), 'web');
    expect($widget::canView())->toBeFalse('không quyền nào');
})->with(M13T7WA_WIDGETS);

it('never polls', function (string $widget) {
    $this->actingAs($this->manager->fresh(), 'web');
    $component = Livewire::test($widget, ['subjectId' => $this->lawyer->id]);

    expect((new ReflectionMethod($component->instance(), 'getPollingInterval'))->invoke($component->instance()))->toBeNull()
        ->and($component->html())->not->toContain('wire:poll');
})->with(M13T7WA_WIDGETS);

it('stays off the home dashboard', function (string $widget) {
    $this->actingAs($this->manager->fresh(), 'web');

    expect((new ReflectionProperty($widget, 'isDiscovered'))->getValue())->toBeFalse()
        ->and(Filament::getPanel('admin')->getWidgets())->not->toContain($widget)
        ->and((new Dashboard)->getWidgets())->not->toContain($widget);

    $this->get(Filament::getPanel('admin')->getUrl())->assertOk()->assertDontSee(__('performance.trend.stale_heading'))->assertDontSee(__('performance.trend.overdue_heading'));
})->with(M13T7WA_WIDGETS);

it('puts both widgets under the member page, handed the subject of the page', function () {
    $this->actingAs($this->manager->fresh(), 'web');

    $page = Livewire::test(TeamMember::class, ['user' => $this->lawyer->id]);

    expect((new ReflectionMethod($page->instance(), 'getFooterWidgets'))->invoke($page->instance()))->toBe([StaleTrendWidget::class, OverdueTrendWidget::class])
        ->and($page->instance()->getWidgetData())->toBe(['subjectId' => $this->lawyer->id]);

    $html = $this->get(TeamMember::getUrl(['user' => $this->lawyer->id], panel: 'admin'))->assertOk()->getContent();

    foreach ([StaleTrendWidget::class, OverdueTrendWidget::class] as $widget) {
        expect($html)->toContain(e(app('livewire.factory')->resolveComponentName($widget)));
    }
});
