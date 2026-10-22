<?php

use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Filament\Admin\Widgets\LeadFollowUpsDueWidget;
use App\Filament\Admin\Widgets\MatterCountsWidget;
use App\Filament\Admin\Widgets\MattersByStageWidget;
use App\Filament\Admin\Widgets\MattersMissingDocumentsWidget;
use App\Filament\Admin\Widgets\MyLeadsWidget;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\StaleMattersWidget;
use App\Filament\Admin\Widgets\SystemHealthWidget;
use App\Filament\Admin\Widgets\UnansweredIntakesWidget;
use App\Filament\Admin\Widgets\UnseenUpdatesWidget;
use App\Filament\Admin\Widgets\UpcomingDeadlinesWidget;
use App\Models\IntakeRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/*
 * Làn kd, Task 1 — bảng điều khiển của nhân viên kinh doanh: chỉ widget về lead của mình (số lead theo
 * trạng thái, lead chưa ai gọi lại, lead đến hẹn liên hệ lại); không widget vụ việc hay tiền. Hai widget
 * mới chỉ hiện với vai nhân viên kinh doanh — các vai khác giữ trang chủ như cũ. Hàm toàn cục mang tiền tố
 * `sdb…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(now()->setTime(10, 0));

    $this->sales = User::factory()->withRole(Role::Sales)->create();
    $this->other = User::factory()->withRole(Role::Sales)->create();
});

it('puts only lead widgets on a sales persons dashboard, never a matter or money widget', function () {
    $this->actingAs($this->sales, 'web');

    expect(MyLeadsWidget::canView())->toBeTrue()
        ->and(LeadFollowUpsDueWidget::canView())->toBeTrue()
        ->and(UnansweredIntakesWidget::canView())->toBeTrue();

    foreach ([
        MatterCountsWidget::class, MattersByStageWidget::class, MattersMissingDocumentsWidget::class,
        PendingChecklistReviewsWidget::class, StaleMattersWidget::class, UnseenUpdatesWidget::class,
        UpcomingDeadlinesWidget::class,
    ] as $widget) {
        expect($widget::canView())->toBeFalse("{$widget} hiện với nhân viên kinh doanh");
    }

    $this->get(route('filament.admin.pages.dashboard'))
        ->assertOk()
        ->assertSeeLivewire(MyLeadsWidget::class)
        ->assertSeeLivewire(LeadFollowUpsDueWidget::class)
        ->assertSeeLivewire(UnansweredIntakesWidget::class)
        ->assertDontSeeLivewire(MatterCountsWidget::class)
        ->assertDontSeeLivewire(StaleMattersWidget::class);
});

it('keeps the two sales widgets off every other dashboard', function (Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create(), 'web');

    expect(MyLeadsWidget::canView())->toBeFalse()
        ->and(LeadFollowUpsDueWidget::canView())->toBeFalse();

    $this->get(route('filament.admin.pages.dashboard'))
        ->assertOk()
        ->assertDontSeeLivewire(MyLeadsWidget::class)
        ->assertDontSeeLivewire(LeadFollowUpsDueWidget::class);
})->with([Role::Admin, Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant]);

it('answers 404 when another role mounts a sales widget directly', function () {
    $this->actingAs(User::factory()->withRole(Role::Lawyer)->create(), 'web');

    $this->livewire(MyLeadsWidget::class)->assertStatus(404);
    $this->livewire(LeadFollowUpsDueWidget::class)->assertStatus(404);
});

it('counts only the own leads, by status', function () {
    IntakeRequest::factory()->count(2)->status(IntakeStatus::New)->create(['created_by' => $this->sales->id]);
    IntakeRequest::factory()->status(IntakeStatus::Quoted)->create(['created_by' => $this->other->id, 'assigned_to' => $this->sales->id]);
    IntakeRequest::factory()->count(3)->status(IntakeStatus::Quoted)->create(['created_by' => $this->other->id]);
    IntakeRequest::factory()->status(IntakeStatus::Won)->create(['created_by' => $this->sales->id]);

    $this->actingAs($this->sales, 'web');

    $stats = collect((fn () => $this->getStats())->call($this->livewire(MyLeadsWidget::class)->instance()))
        ->mapWithKeys(fn ($stat): array => [(string) $stat->getLabel() => (string) $stat->getValue()]);

    expect($stats[IntakeStatus::New->label()])->toBe('2')
        ->and($stats[IntakeStatus::Quoted->label()])->toBe('1')
        ->and($stats[IntakeStatus::Won->label()])->toBe('1')
        ->and($stats[IntakeStatus::Contacted->label()])->toBe('0')
        ->and($stats->has(IntakeStatus::Merged->label()))->toBeFalse();
});

it('lists the own open leads whose next contact is due today or overdue, earliest first', function () {
    $overdue = IntakeRequest::factory()->status(IntakeStatus::Contacted)->create(['created_by' => $this->sales->id, 'next_follow_up_at' => now()->subDay()]);
    $today = IntakeRequest::factory()->status(IntakeStatus::Quoted)->create(['created_by' => $this->sales->id, 'next_follow_up_at' => now()->setTime(16, 0)]);
    $later = IntakeRequest::factory()->status(IntakeStatus::Contacted)->create(['created_by' => $this->sales->id, 'next_follow_up_at' => now()->addDays(2)]);
    $none = IntakeRequest::factory()->status(IntakeStatus::Contacted)->create(['created_by' => $this->sales->id]);
    $closed = IntakeRequest::factory()->status(IntakeStatus::Lost)->create(['created_by' => $this->sales->id, 'next_follow_up_at' => now()->subDay()]);
    $theirs = IntakeRequest::factory()->status(IntakeStatus::Contacted)->create(['created_by' => $this->other->id, 'next_follow_up_at' => now()->subDay()]);

    $this->actingAs($this->sales, 'web');

    $this->livewire(LeadFollowUpsDueWidget::class)
        ->assertCanSeeTableRecords([$overdue, $today], inOrder: true)
        ->assertCanNotSeeTableRecords([$later, $none, $closed, $theirs]);
});

it('puts the system-health band first and the two sales widgets in fixed slots right under it', function () {
    // Làn kd, vòng sửa 1 (F3): "Lead của tôi" từng đứng TRÊN dải "Tình trạng hệ thống" (-7 < -6) và "Lead đến
    // hẹn" trùng số với dải (-6) — thứ tự của hai cái trùng là thứ tự Filament tình cờ nạp lớp.
    $this->actingAs($this->sales, 'web');

    $visible = collect(Filament::getWidgets())
        ->map(fn ($widget): string => is_string($widget) ? $widget : $widget->widget)
        ->filter(fn (string $widget): bool => $widget::canView());
    $sorts = $visible->mapWithKeys(fn (string $widget): array => [$widget => $widget::getSort()]);

    // Mọi widget nhân viên kinh doanh thấy có một số riêng: không thứ tự nào do tình cờ.
    expect($sorts->values()->all())->toBe($sorts->unique()->values()->all())
        ->and($sorts->keys()->take(4)->all())->toBe([
            SystemHealthWidget::class, MyLeadsWidget::class, LeadFollowUpsDueWidget::class, UnansweredIntakesWidget::class,
        ]);

    // Và đúng thứ đó trên trang thật: vị trí của từng widget trong HTML trang chủ.
    $html = $this->get(route('filament.admin.pages.dashboard'))->assertOk()->getContent();
    $at = fn (string $widget): int|false => strpos($html, trim(htmlspecialchars(json_encode(['name' => app('livewire.factory')->resolveComponentName($widget)])), '{}'));

    expect($at(SystemHealthWidget::class))->toBeInt()->toBeLessThan($at(MyLeadsWidget::class))
        ->and($at(MyLeadsWidget::class))->toBeLessThan($at(LeadFollowUpsDueWidget::class))
        ->and($at(LeadFollowUpsDueWidget::class))->toBeLessThan($at(UnansweredIntakesWidget::class));
});
