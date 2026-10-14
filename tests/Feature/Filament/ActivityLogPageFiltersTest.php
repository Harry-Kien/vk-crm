<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, mục B) — trang Nhật ký hệ thống tra cứu được
|--------------------------------------------------------------------------
| Trước đây chỉ lọc được theo nhóm và kênh AI, cột Đối tượng in mã tiếng Anh. Bốn bộ lọc mới (người,
| sự kiện, khoảng ngày, hồ sơ) chỉ THU HẸP tập dòng đã qua `ActivityOwningMatter::scopeVisibleTo()`.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->alice = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư A']);
    $this->bob = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư B']);

    $this->matterA = Matter::factory()->create(['lead_lawyer_id' => $this->alice->id]);
    $this->matterB = Matter::factory()->create(['lead_lawyer_id' => $this->bob->id]);
    $this->secret = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->bob->id]);

    Activity::query()->delete();

    $this->rowA = fbAuditRow('matter_details_updated', $this->matterA, $this->alice, '2026-10-01 09:00:00');
    $this->rowB = fbAuditRow('matter_details_updated', $this->matterB, $this->bob, '2026-10-05 09:00:00');
    $this->rowLogin = fbAuditRow('login_success', $this->alice, $this->alice, '2026-10-07 09:00:00');
    $this->rowSecret = fbAuditRow('matter_details_updated', $this->secret, $this->bob, '2026-10-06 09:00:00');
});

function fbAuditRow(string $event, mixed $subject, User $causer, string $at): Activity
{
    Audit::record($event, $subject, [], $causer);

    $row = Activity::query()->latest('id')->firstOrFail();
    $row->forceFill(['created_at' => Carbon::parse($at)])->save();

    return $row;
}

it('filters the activity log by who did it, including a staff member who has since left', function () {
    $this->bob->delete();

    $this->actingAs($this->admin, 'web');

    Livewire::test(ActivityLogPage::class)
        ->assertTableFilterExists('causer', fn (SelectFilter $filter): bool => array_key_exists($this->bob->id, $filter->getOptions()))
        ->filterTable('causer', $this->bob->id)
        ->assertCanSeeTableRecords([$this->rowB, $this->rowSecret])
        ->assertCanNotSeeTableRecords([$this->rowA, $this->rowLogin]);
});

it('filters the activity log by event, offering the Vietnamese event names', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(ActivityLogPage::class)
        ->assertTableFilterExists('event', fn (SelectFilter $filter): bool => ($filter->getOptions()['login_success'] ?? null) === __('activity.events.login_success'))
        ->filterTable('event', 'login_success')
        ->assertCanSeeTableRecords([$this->rowLogin])
        ->assertCanNotSeeTableRecords([$this->rowA, $this->rowB]);
});

it('filters the activity log by a date range', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(ActivityLogPage::class)
        ->filterTable('created_between', ['from' => '2026-10-04', 'until' => '2026-10-06'])
        ->assertCanSeeTableRecords([$this->rowB, $this->rowSecret])
        ->assertCanNotSeeTableRecords([$this->rowA, $this->rowLogin]);
});

it('filters the activity log by matter, and never offers a manager the code of a restricted matter outside his reach', function () {
    $this->actingAs($this->manager, 'web');

    Livewire::test(ActivityLogPage::class)
        ->assertTableFilterExists('matter', fn (SelectFilter $filter): bool => array_key_exists($this->matterA->id, $filter->getOptions())
            && ! array_key_exists($this->secret->id, $filter->getOptions())
            && ! in_array($this->secret->code, $filter->getOptions(), true))
        ->filterTable('matter', $this->matterA->id)
        ->assertCanSeeTableRecords([$this->rowA])
        ->assertCanNotSeeTableRecords([$this->rowB, $this->rowLogin, $this->rowSecret]);
});

it('shows nothing when a manager forces the id of a restricted matter outside his reach into the matter filter', function () {
    $this->actingAs($this->manager, 'web');

    Livewire::test(ActivityLogPage::class)
        ->set('tableFilters.matter.value', $this->secret->id)
        ->assertCanNotSeeTableRecords([$this->rowA, $this->rowB, $this->rowLogin, $this->rowSecret])
        ->assertDontSee($this->secret->code);
});

it('names the kind of subject in Vietnamese instead of the English morph alias', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(ActivityLogPage::class)
        ->assertTableColumnFormattedStateSet('subject_type', __('audit_trail.subjects.matter'), $this->rowA)
        ->assertTableColumnFormattedStateSet('subject_type', __('audit_trail.subjects.user'), $this->rowLogin);
});
