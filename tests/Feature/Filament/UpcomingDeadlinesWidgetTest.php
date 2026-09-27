<?php

use App\Enums\DeadlineSeverity;
use App\Enums\Role;
use App\Filament\Admin\Widgets\UpcomingDeadlinesWidget;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * SPEC §7.1 mục 2 ("Mốc thời hạn 7 ngày tới") — widget rơi mất giữa ba milestone (M6.5 Task 14,
 * `deadlines/F5`, `spec-gap-05`). Xem docblock của widget cho lý lẽ đầy đủ.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function upcomingDeadline(Matter $matter, array $attributes = []): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $matter->lead_lawyer_id,
        'is_completed' => false,
        ...$attributes,
    ]);
}

it('lists a deadline due within seven days, and one already overdue', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $dueSoon = upcomingDeadline($matter, ['due_date' => today()->addDays(5)]);
    $overdue = upcomingDeadline($matter, ['due_date' => today()->subDays(3)]);
    $farAway = upcomingDeadline($matter, ['due_date' => today()->addDays(40)]);
    $done = upcomingDeadline($matter, ['due_date' => today()->addDays(2), 'is_completed' => true, 'completed_at' => now()]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertCanSeeTableRecords([$dueSoon, $overdue])
        ->assertCanNotSeeTableRecords([$farAway, $done]);
});

/** Widget quan trọng: một luật sư chỉ thấy mốc của vụ việc mình được xem, không phải toàn văn phòng. */
it('shows a lawyer only the deadlines of matters they can see', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    $ownMatter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $colleagueMatter = Matter::factory()->create(['lead_lawyer_id' => $colleague->id]);

    $own = upcomingDeadline($ownMatter, ['due_date' => today()->addDays(3)]);
    $colleagues = upcomingDeadline($colleagueMatter, ['due_date' => today()->addDays(3)]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$colleagues]);
});

/** Cùng luật `Matter::scopeListableBy()` mà mọi widget khác dùng: một vụ `restricted` khép lại với trưởng phòng. */
it('does not list a deadline of a restricted matter to a manager', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);
    $deadline = upcomingDeadline($matter, ['due_date' => today()->addDays(3)]);

    $this->actingAs($manager, 'web');

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertCanNotSeeTableRecords([$deadline]);
});

/** Cặp dương: le lead lawyer của vụ restricted vẫn thấy được mốc của chính vụ đó. */
it('still lists a deadline of a restricted matter to its own lead lawyer', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);
    $deadline = upcomingDeadline($matter, ['due_date' => today()->addDays(3)]);

    $this->actingAs($lead, 'web');

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertCanSeeTableRecords([$deadline]);
});

/** Vụ việc đã đóng (R8) không còn "việc phải làm" — cùng luật `CheckDeadlines` dùng để thôi nhắc. */
it('does not list a deadline whose matter has been closed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()]);
    $deadline = upcomingDeadline($matter, ['due_date' => today()->addDays(3)]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertCanNotSeeTableRecords([$deadline]);
});

/** Một mốc đã gỡ (R14) biến mất khỏi widget — cùng `SoftDeletingScope` mọi nơi khác dùng. */
it('does not list a deadline that has been deleted', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $deadline = upcomingDeadline($matter, ['due_date' => today()->addDays(3)]);
    $deadline->delete();

    $this->actingAs($lawyer, 'web');

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertCanNotSeeTableRecords([$deadline]);
});

it('hides the widget from an accountant, who has no matter.view', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    expect(UpcomingDeadlinesWidget::canView())->toBeFalse();
});

it('shows the widget to a lawyer', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($lawyer, 'web');

    expect(UpcomingDeadlinesWidget::canView())->toBeTrue();
});

// =========================================================================================
// TÔ ĐỎ MỐC critical — SPEC §7.1 mục 2, và không có bước dựng CSS trong dự án
// =========================================================================================

it('paints a critical deadline red, using only registered colour variables, and no CSS class', function () {
    $critical = (string) UpcomingDeadlinesWidget::renderSeverity(DeadlineSeverity::Critical);
    $normal = (string) UpcomingDeadlinesWidget::renderSeverity(DeadlineSeverity::Normal);

    expect($critical)->toContain('var(--danger-600)')
        ->and($critical)->not->toContain('class=')
        ->and($normal)->not->toContain('var(--danger-600)')
        ->and(colourVariablesIn($critical.$normal))->not->toBeEmpty()
        ->and(unregisteredColourVariables($critical.$normal))->toBe([]);
});

/**
 * Brief Task 14: "mốc critical có màu đỏ TRÊN MARKUP" — đo trên HTML mà widget thật sự vẽ ra, không
 * chỉ trên hàm tĩnh ở test trên. Hai mốc đều còn 5 ngày (ô ngày của cả hai tô VÀNG, không đỏ), nên
 * `var(--danger-600)` trên trang chỉ có thể đến từ ô mức độ của mốc `critical`.
 */
it('paints the critical row red in the rendered widget, and the normal row not', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    upcomingDeadline($matter, ['due_date' => today()->addDays(5), 'severity' => DeadlineSeverity::Critical]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertSeeHtml((string) UpcomingDeadlinesWidget::renderSeverity(DeadlineSeverity::Critical))
        ->assertSeeHtml('var(--danger-600)');

    Deadline::query()->update(['severity' => DeadlineSeverity::Normal->value]);

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertSeeHtml((string) UpcomingDeadlinesWidget::renderSeverity(DeadlineSeverity::Normal))
        ->assertDontSeeHtml('var(--danger-600)');
});

/** SPEC §7.1 mục 2: "sắp xếp theo due_date" — quá hạn trên cùng, rồi gần nhất trước. */
it('orders the rows by due date, overdue first', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $inFive = upcomingDeadline($matter, ['due_date' => today()->addDays(5)]);
    $overdue = upcomingDeadline($matter, ['due_date' => today()->subDays(2)]);
    $inOne = upcomingDeadline($matter, ['due_date' => today()->addDay()]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertCanSeeTableRecords([$overdue, $inOne, $inFive], inOrder: true);
});
