<?php

use App\Enums\Confidentiality;
use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\IntakeReport;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Filament\Admin\Widgets\IntakeReport\IntakesByOwnerWidget;
use App\Filament\Admin\Widgets\MyLeadsWidget;
use App\Filament\Admin\Widgets\UnansweredIntakesWidget;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/*
 * Làn kd, Task 1 — "lead của mình": nhân viên kinh doanh thấy phiếu tiếp nhận do mình ghi HOẶC được giao
 * cho mình, ở mọi nơi phiếu hiện ra (danh sách, trang của phiếu, widget "Liên hệ chưa ai gọi lại"); trang
 * "Bức tranh đầu vào" (toàn văn phòng) là 404 với họ. Quản lý và quản trị thấy mọi lead. Hàm toàn cục mang
 * tiền tố `sls…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->sales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh Doanh Một']);
    $this->otherSales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh Doanh Hai']);
    $this->manager = User::factory()->withRole(Role::Manager)->create();

    $this->mine = IntakeRequest::factory()->create(['created_by' => $this->sales->id, 'contact_name' => 'Lead Tự Ghi']);
    $this->assignedToMe = IntakeRequest::factory()->create(['created_by' => $this->manager->id, 'assigned_to' => $this->sales->id, 'contact_name' => 'Lead Được Giao']);
    $this->theirs = IntakeRequest::factory()->create(['created_by' => $this->otherSales->id, 'contact_name' => 'Lead Của Người Khác']);
    $this->office = IntakeRequest::factory()->create(['created_by' => $this->manager->id, 'contact_name' => 'Lead Văn Phòng']);
});

it('lists only the leads a sales person recorded or was assigned, and every lead for a manager', function () {
    $this->actingAs($this->sales, 'web');

    $this->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords([$this->mine, $this->assignedToMe])
        ->assertCanNotSeeTableRecords([$this->theirs, $this->office])
        ->assertDontSee('Lead Của Người Khác')
        ->assertDontSee('Lead Văn Phòng');

    $this->actingAs($this->manager, 'web');

    $this->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords([$this->mine, $this->assignedToMe, $this->theirs, $this->office]);
});

it('opens the own lead and answers someone elses lead with the same 404 as a missing one', function () {
    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $this->mine->getRouteKey()])->assertOk();
    $this->livewire(EditIntakeRequest::class, ['record' => $this->assignedToMe->getRouteKey()])->assertOk();

    $edit = fn (int $id) => $this->get(route('filament.admin.resources.intake-requests.edit', ['record' => $id]));

    $denied = $edit($this->theirs->id);
    $missing = $edit(987654);

    expect($denied->getStatusCode())->toBe(404)
        ->and($missing->getStatusCode())->toBe(404);
});

it('shows a sales person only their own unanswered leads on the home widget', function () {
    $this->travelTo(now()->next('Monday')->setTime(16, 0));
    IntakeRequest::query()->update(['received_at' => now()->subDays(3), 'status' => IntakeStatus::New->value]);

    $this->actingAs($this->sales, 'web');

    expect(UnansweredIntakesWidget::canView())->toBeTrue();

    $this->livewire(UnansweredIntakesWidget::class)
        ->assertCanSeeTableRecords([$this->mine, $this->assignedToMe])
        ->assertCanNotSeeTableRecords([$this->theirs, $this->office]);
});

it('keeps the office-wide intake report, and its per-person widget, away from a sales person', function () {
    $this->actingAs($this->sales, 'web');

    expect(IntakeReport::canAccess())->toBeFalse()
        ->and(IntakesByOwnerWidget::canView())->toBeFalse();

    $this->get(route('filament.admin.pages.intake-report'))->assertNotFound();

    $this->livewire(IntakesByOwnerWidget::class)->assertNotFound();
});

it('drops a lead that became a restricted matter out of the sales persons view, and the guide says so', function () {
    // Làn kd, vòng sửa 1 (F5): QUY-TRINH từng hứa "trang lead chỉ ghi 'Đã chuyển thành vụ việc'". Với vụ mức
    // bảo mật "Hạn chế" thì lead BIẾN MẤT với nhân viên kinh doanh (vế `restricted` của `scopeVisibleTo`):
    // trang của nó là 404, nó rời danh sách và hàng ô "Lead của tôi".
    $open = IntakeRequest::factory()->status(IntakeStatus::Won)->create([
        'created_by' => $this->sales->id, 'contact_name' => 'Lead Thành Vụ Thường',
        'matter_id' => Matter::factory()->create()->id,
    ]);
    $secret = IntakeRequest::factory()->status(IntakeStatus::Won)->create([
        'created_by' => $this->sales->id, 'contact_name' => 'Lead Thành Vụ Hạn Chế',
        'matter_id' => Matter::factory()->restricted()->create()->id,
    ]);

    $this->actingAs($this->sales, 'web');

    $this->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$secret]);
    $this->get(route('filament.admin.resources.intake-requests.edit', ['record' => $open->id]))->assertOk()
        ->assertSee(__('leads.decision.converted'));
    $this->get(route('filament.admin.resources.intake-requests.edit', ['record' => $secret->id]))->assertNotFound();

    $stats = collect((fn () => $this->getStats())->call($this->livewire(MyLeadsWidget::class)->instance()))
        ->mapWithKeys(fn ($stat): array => [(string) $stat->getLabel() => (string) $stat->getValue()]);

    expect($stats[IntakeStatus::Won->label()])->toBe('1');

    // Hướng dẫn cho nhân viên kinh doanh (QUY-TRINH, giai đoạn 1) nói đúng điều đó, ở ngay bước mở vụ.
    $guide = (string) file_get_contents(base_path('docs/QUY-TRINH.md'));
    $step = substr($guide, (int) strpos($guide, '4. **Khi khách cần tư vấn chuyên môn hay sắp ký:**'), 900);

    expect($step)->toContain('"Đã chuyển thành vụ việc"')
        ->and($step)->toContain(Confidentiality::Restricted->label())
        ->and($step)->toContain('không còn thấy lead');
});
