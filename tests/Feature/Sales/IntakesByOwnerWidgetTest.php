<?php

use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\IntakeReport;
use App\Filament\Admin\Widgets\IntakeReport\IntakesByOwnerWidget;
use App\Models\IntakeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Làn kd, Task 1 — báo cáo tiếp nhận theo NGƯỜI PHỤ TRÁCH lead (người được giao; chưa giao ai thì người đã
 * ghi): số lead và tỉ lệ thành vụ việc của từng nhân viên kinh doanh, chỉ quản lý/quản trị
 * (`intake.viewAny`, cùng cổng trang "Bức tranh đầu vào"). Cùng tập bản ghi với các widget khác của trang
 * (`ReadsIntakeReport::contactsInReport()`: trong kỳ, thấy được, trừ bản đã gộp). Hàm toàn cục mang tiền tố
 * `ibo…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Ho_Chi_Minh'));

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng']);
    $this->an = User::factory()->withRole(Role::Sales)->create(['name' => 'An Kinh Doanh']);
    $this->binh = User::factory()->withRole(Role::Sales)->create(['name' => 'Bình Kinh Doanh']);
});

function iboLead(User $owner, IntakeStatus $status, array $extra = []): IntakeRequest
{
    return IntakeRequest::factory()->status($status)->create([
        'created_by' => $owner->id,
        'received_at' => '2026-10-05 09:00',
        ...$extra,
    ]);
}

/** @return list<array{label: string, value: string}> */
function iboRows(array $pageFilters = []): array
{
    return Livewire::test(IntakesByOwnerWidget::class, ['pageFilters' => $pageFilters])->instance()->numberTableRows();
}

it('counts leads and conversions per person in charge, assignee first and recorder otherwise', function () {
    iboLead($this->an, IntakeStatus::Won);
    iboLead($this->an, IntakeStatus::Lost);
    iboLead($this->an, IntakeStatus::Contacted);
    iboLead($this->an, IntakeStatus::Merged);
    // Bình ghi, nhưng giao cho An: tính cho An.
    iboLead($this->binh, IntakeStatus::Won, ['assigned_to' => $this->an->id]);
    iboLead($this->binh, IntakeStatus::New);
    // Ngoài kỳ "tháng này".
    iboLead($this->binh, IntakeStatus::Won, ['received_at' => '2026-09-05 09:00']);

    $this->actingAs($this->manager, 'web');

    expect(iboRows())->toBe([
        ['label' => 'An Kinh Doanh', 'value' => '2/4 (50,0 %)'],
        ['label' => 'Bình Kinh Doanh', 'value' => '0/1 (0,0 %)'],
    ]);

    expect(Livewire::test(IntakeReport::class)->instance()->getWidgets())->toContain(IntakesByOwnerWidget::class);
});

it('says so when nobody had a lead in the period', function () {
    $this->actingAs($this->manager, 'web');

    expect(iboRows())->toBe([])
        ->and((string) Livewire::test(IntakesByOwnerWidget::class)->instance()->getDescription())->toContain('01/10/2026 – 31/10/2026');
});
