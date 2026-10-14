<?php

use App\Actions\User\SuspendStaffAccess;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Models\User;
use App\Support\ActivityOwningMatter;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Rà soát gộp làn fb vào main (2026-10-14), phát hiện xác nhận: dòng nhật ký "Khoá truy cập nhân sự ngay"
| mang LÝ DO do quản trị viên gõ tự do (ít nhất 20 ký tự — vd. "Nghi lộ hồ sơ HS-2026-014 (vụ hạn chế) của
| khách Trần Thị C …"). Chủ thể là một User, không có `matter_id`, nên luật "dòng không thuộc vụ nào" thả nó
| cho MỌI người có `auditLog.view` — tức cả trưởng phòng, người mà vụ hạn chế cố tình không cho biết mã vụ
| và tên khách, và có thể chính là người đang bị nghi. Nút khoá chỉ quản trị viên bấm được; lý do khoá nay
| cũng chỉ quản trị viên đọc được.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create();
    $this->lawyer = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create();

    app(SuspendStaffAccess::class)->handle(
        $this->admin,
        $this->lawyer,
        'Nghi lộ hồ sơ HS-2026-014 (vụ hạn chế) của khách Trần Thị C cho bên đối lập.',
    );

    $this->suspendRow = Activity::query()->where('event', 'staff_access_suspended')->sole();

    // Một dòng nhân sự KHÁC không thuộc vụ nào: vẫn phải hiện cho trưởng phòng như trước.
    $this->otherStaffRow = Audit::record('staff_login_unlocked', $this->lawyer, [], $this->admin);
});

it('chỉ quản trị viên đọc được dòng khoá truy cập khẩn cấp và lý do của nó', function () {
    expect(ActivityOwningMatter::canView($this->admin, $this->suspendRow))->toBeTrue()
        ->and(ActivityOwningMatter::canView($this->manager, $this->suspendRow))->toBeFalse()
        ->and(ActivityOwningMatter::canViewMany($this->manager, [$this->suspendRow, $this->otherStaffRow]))
        ->toBe([$this->suspendRow->getKey() => false, $this->otherStaffRow->getKey() => true]);

    $visibleToManager = Activity::query()->tap(fn ($q) => ActivityOwningMatter::scopeVisibleTo($q, $this->manager))->pluck('id');

    expect($visibleToManager)->not->toContain($this->suspendRow->getKey())
        ->and($visibleToManager)->toContain($this->otherStaffRow->getKey());
});

it('trang Nhật ký hệ thống: trưởng phòng không thấy dòng đó, quản trị viên thấy', function () {
    $this->actingAs($this->manager, 'web');

    Livewire::test(ActivityLogPage::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$this->suspendRow])
        ->assertCanSeeTableRecords([$this->otherStaffRow])
        ->assertDontSee('HS-2026-014');

    $this->actingAs($this->admin, 'web');

    Livewire::test(ActivityLogPage::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->suspendRow, $this->otherStaffRow]);
});
