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
