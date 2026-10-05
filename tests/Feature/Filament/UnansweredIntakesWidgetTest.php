<?php

use App\Actions\Intake\RecordIntake;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Filament\Admin\Widgets\UnansweredIntakesWidget;
use App\Models\IntakeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/*
 * M10 Task 5 (R5), SPEC §7.1 đính chính M10 — widget "Liên hệ chưa ai gọi lại": các bản ghi còn ở
 * `new` quá ngưỡng phản hồi (4 giờ làm việc), trong phạm vi `IntakeRequest::scopeVisibleTo()` của
 * người xem; mỗi dòng mã, nguồn, thời gian đã chờ — không tên, không số điện thoại. Ẩn với người
 * không có quyền `intake.*` nào (kế toán).
 *
 * Hàm toàn cục mang tiền tố `uiw…`. 2026-10-07 là Thứ Tư.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:30', 'Asia/Ho_Chi_Minh'));
});

function uiwStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

function uiwRecord(User $actor, array $overrides = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0901000001',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides])->intake;
}

function uiwNow(string $local): void
{
    test()->travelTo(CarbonImmutable::parse($local, 'Asia/Ho_Chi_Minh'));
}

it('lists the records still in new past four working hours, and not the ones within it or already answered', function () {
    $manager = uiwStaff(Role::Manager);
    $overdue = uiwRecord($manager, ['received_at' => '2026-10-07 08:30:00']);
    $answered = uiwRecord($manager, ['contact_phone' => '0901000002', 'received_at' => '2026-10-07 08:30:00']);
    $answered->forceFill(['status' => IntakeStatus::Contacted])->save();
    uiwNow('2026-10-07 10:00');
    $recent = uiwRecord($manager, ['contact_phone' => '0901000003']);

    uiwNow('2026-10-07 13:00');
    $this->actingAs($manager, 'web');

    $this->livewire(UnansweredIntakesWidget::class)
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$answered, $recent]);

    uiwNow('2026-10-07 14:00');

    $this->livewire(UnansweredIntakesWidget::class)
        ->assertCanSeeTableRecords([$overdue, $recent]);
});

it('counts the threshold in working hours, so a call from Friday afternoon is not overdue on Monday at opening', function () {
    $manager = uiwStaff(Role::Manager);
    uiwNow('2026-10-09 15:00');
    $friday = uiwRecord($manager);

    uiwNow('2026-10-12 08:00');
    $this->actingAs($manager, 'web');
    $this->livewire(UnansweredIntakesWidget::class)->assertCanNotSeeTableRecords([$friday]);

    uiwNow('2026-10-12 09:30');
    $this->livewire(UnansweredIntakesWidget::class)->assertCanSeeTableRecords([$friday]);
});

it('shows the code, the source, the time waited and the assignee, and never the name or the phone of the contact', function () {
    $manager = uiwStaff(Role::Manager);
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Được Giao']);
    $intake = uiwRecord($manager, [
        'contact_name' => 'ZZTEN Người Gọi',
        'contact_phone' => '0987 654 321',
        'source' => IntakeSource::WalkIn,
        'assigned_to' => $lawyer->id,
    ]);

    uiwNow('2026-10-07 13:15');
    $this->actingAs($manager, 'web');

    $this->livewire(UnansweredIntakesWidget::class)
        ->assertSee($intake->code)
        ->assertSee(IntakeSource::WalkIn->label())
        ->assertSee(__('intake.reminder.duration.hours_minutes', ['hours' => 4, 'minutes' => 45]))
        ->assertSee('Luật Sư Được Giao')
        ->assertDontSee('ZZTEN')
        ->assertDontSee('0987 654 321')
        ->assertDontSee('987654321')
        ->assertTableActionHasUrl('open', IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'), $intake);
});

it('shows an assistant only the records they took or were given, and a manager all of them', function () {
    $assistant = uiwStaff();
    $otherAssistant = uiwStaff();
    $manager = uiwStaff(Role::Manager);
    $mine = uiwRecord($assistant);
    $given = uiwRecord($otherAssistant, ['contact_phone' => '0901000002', 'assigned_to' => $assistant->id]);
    $theirs = uiwRecord($otherAssistant, ['contact_phone' => '0901000003']);

    uiwNow('2026-10-07 13:00');

    $this->actingAs($assistant, 'web');
    $this->livewire(UnansweredIntakesWidget::class)
        ->assertCanSeeTableRecords([$mine, $given])
        ->assertCanNotSeeTableRecords([$theirs]);

    $this->actingAs($manager, 'web');
    $this->livewire(UnansweredIntakesWidget::class)
        ->assertCanSeeTableRecords([$mine, $given, $theirs]);
});

it('does not list a record anonymised while still in new', function () {
    $manager = uiwStaff(Role::Manager);
    $intake = uiwRecord($manager);
    $intake->forceFill(['anonymised_at' => now()])->save();

    uiwNow('2026-10-07 13:00');
    $this->actingAs($manager, 'web');

    $this->livewire(UnansweredIntakesWidget::class)->assertCanNotSeeTableRecords([$intake]);
});

it('puts the record that has waited longest first', function () {
    $manager = uiwStaff(Role::Manager);
    uiwNow('2026-10-06 09:00');
    $older = uiwRecord($manager);
    uiwNow('2026-10-06 08:15');
    $oldest = uiwRecord($manager, ['contact_phone' => '0901000002']);
    uiwNow('2026-10-06 10:00');
    $newer = uiwRecord($manager, ['contact_phone' => '0901000003']);

    uiwNow('2026-10-07 13:00');
    $this->actingAs($manager, 'web');

    $this->livewire(UnansweredIntakesWidget::class)
        ->assertCanSeeTableRecords([$oldest, $older, $newer], inOrder: true);
});

it('hides the widget from someone with no intake permission, and shows it to everyone who takes calls', function (Role $role, bool $visible) {
    $this->actingAs(uiwStaff($role), 'web');

    expect(UnansweredIntakesWidget::canView())->toBe($visible);
})->with([
    'kế toán' => [Role::Accountant, false],
    'trợ lý' => [Role::Assistant, true],
    'luật sư' => [Role::Lawyer, true],
    'trưởng phòng' => [Role::Manager, true],
    'quản trị' => [Role::Admin, true],
]);

/** Widget nạp lười (mặc định của Filament): trang chủ chỉ mang ảnh chụp của component, nên đo theo tên component. */
it('puts the widget on the dashboard of a lawyer and keeps it off the dashboard of an accountant', function () {
    $this->actingAs(uiwStaff(Role::Lawyer), 'web')
        ->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(UnansweredIntakesWidget::class);

    $this->actingAs(uiwStaff(Role::Accountant), 'web')
        ->get('/admin')
        ->assertOk()
        ->assertDontSeeLivewire(UnansweredIntakesWidget::class);
});
