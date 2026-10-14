<?php

use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\IntakeRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fa, mục A2 và A3 của đợt kiểm tra nghiệp vụ 2026-10-09 — hai người cùng làm trên MỘT bản ghi tiếp
 * nhận. Trước bản sửa: form của người mở trang trước, khi lưu, âm thầm GỠ bên đối lập người kia vừa
 * thêm (dòng mất hẳn, kiểm tra lại ra Xanh) và ghi đè người phụ trách hay phí người kia vừa đổi; còn
 * câu chuyện thì "người lưu sau thắng", phần của người kia mất vĩnh viễn. Nay mỗi form mang phiên bản
 * của thứ nó đã nạp, và Action từ chối một lần lưu trên phiên bản đã cũ. Mọi thứ đi qua màn hình.
 *
 * Hàm toàn cục mang tiền tố `icc…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function iccStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

/** Một bản ghi Xanh đủ định danh, thông báo đã ghi nhận — ô câu chuyện mở. */
function iccRecord(User $actor): IntakeRequest
{
    $intake = app(RecordIntake::class)->handle($actor, [
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], [['name' => 'Bị Đơn Một', 'role' => PartyRole::Defendant, 'phone' => '0977000111']])->intake;

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    return $intake->fresh();
}

function iccEdit(IntakeRequest $intake)
{
    return test()->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

function iccSaveSummary(): TestAction
{
    return TestAction::make('saveSummary')->schemaComponent('storyActions');
}

it('refuses a stale identity form instead of deleting the opposing party a colleague added after it was opened', function () {
    $assistant = iccStaff();
    $lawyer = iccStaff(Role::Lawyer);
    $intake = iccRecord($assistant);
    $intake->forceFill(['assigned_to' => $lawyer->id])->save();

    // 9:00 — trợ lý B mở trang.
    $this->actingAs($assistant, 'web');
    $stale = iccEdit($intake);

    // 9:10 — luật sư A thêm Công ty Y.
    $this->actingAs($lawyer, 'web');
    $first = $intake->parties()->sole();
    iccEdit($intake)
        ->fillForm(['parties' => [
            ['id' => $first->id, 'role' => PartyRole::Defendant->value, 'name' => 'Bị Đơn Một', 'phone' => null, 'id_number' => null],
            ['id' => null, 'role' => PartyRole::Defendant->value, 'name' => 'Công ty Y', 'phone' => '0966111222', 'id_number' => null],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($intake->parties()->count())->toBe(2);

    // 9:15 — B sửa chính tả tên trên form cũ và bấm Lưu.
    $this->actingAs($assistant, 'web');
    $stale->fillForm(['contact_name' => 'Người Gọi Mẫu Sửa'])
        ->call('save')
        ->assertNotified(__('actions.failed_title'));

    $intake->refresh();

    expect($intake->parties()->pluck('name')->sort()->values()->all())->toBe(['Bị Đơn Một', 'Công ty Y'])
        ->and($intake->contact_name)->toBe('Người Gọi Mẫu');

    // Tải lại trang thì lưu được, và Công ty Y vẫn còn.
    iccEdit($intake)
        ->fillForm(['contact_name' => 'Người Gọi Mẫu Sửa'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu Sửa')
        ->and($intake->parties()->count())->toBe(2);
});

it('refuses a stale identity form instead of overwriting the fee a colleague just changed', function () {
    $assistant = iccStaff();
    $manager = iccStaff(Role::Manager);
    $intake = iccRecord($assistant);

    $this->actingAs($assistant, 'web');
    $stale = iccEdit($intake);

    $this->actingAs($manager, 'web');
    iccEdit($intake)->fillForm(['quoted_amount' => '20.000.000'])->call('save')->assertHasNoFormErrors();

    $this->actingAs($assistant, 'web');
    $stale->fillForm(['referred_by' => 'Chị Tư'])->call('save')->assertNotified(__('actions.failed_title'));

    expect($intake->fresh()->quoted_amount)->toBe(20_000_000)
        ->and($intake->fresh()->referred_by)->toBeNull();
});

it('lets the same page save its identity twice in a row', function () {
    $assistant = iccStaff();
    $intake = iccRecord($assistant);

    $this->actingAs($assistant, 'web');
    iccEdit($intake)
        ->fillForm(['referred_by' => 'Lần một'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->fillForm(['referred_by' => 'Lần hai'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified(__('actions.failed_title'));

    expect($intake->fresh()->referred_by)->toBe('Lần hai');
});

it('names the opposing parties a save removed in the audit row', function () {
    $assistant = iccStaff();
    $intake = iccRecord($assistant);
    $removed = $intake->parties()->sole();

    $this->actingAs($assistant, 'web');
    iccEdit($intake)->fillForm(['parties' => []])->call('save')->assertHasNoFormErrors();

    $row = Activity::query()->where('event', 'intake_identity_updated')->latest('id')->firstOrFail();

    expect($row->properties['removed_party_ids'])->toBe([$removed->id]);
});

it('refuses a stale story instead of overwriting what a colleague saved, and keeps what was typed', function () {
    $assistant = iccStaff();
    $lawyer = iccStaff(Role::Lawyer);
    $intake = iccRecord($assistant);
    $intake->forceFill(['assigned_to' => $lawyer->id])->save();

    $this->actingAs($assistant, 'web');
    $stale = iccEdit($intake);

    $this->actingAs($lawyer, 'web');
    iccEdit($intake)
        ->set('data.summary', 'Biên bản tư vấn dài của luật sư A.')
        ->callAction(iccSaveSummary())
        ->assertHasNoErrors();

    $this->actingAs($assistant, 'web');
    $stale->set('data.summary', 'Một dòng trợ lý gõ thêm.')
        ->callAction(iccSaveSummary())
        ->assertHasErrors(['data.summary'])
        ->assertSet('data.summary', 'Một dòng trợ lý gõ thêm.');

    expect($intake->fresh()->summary)->toBe('Biên bản tư vấn dài của luật sư A.');
});

it('lets the same page save its story twice, and save the identity in between', function () {
    $assistant = iccStaff();
    $intake = iccRecord($assistant);

    $this->actingAs($assistant, 'web');
    iccEdit($intake)
        ->set('data.summary', 'Lần một.')
        ->callAction(iccSaveSummary())
        ->assertHasNoErrors()
        ->fillForm(['referred_by' => 'Chị Tư'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->set('data.summary', 'Lần một. Lần hai.')
        ->callAction(iccSaveSummary())
        ->assertHasNoErrors();

    expect($intake->fresh()->summary)->toBe('Lần một. Lần hai.');
});
