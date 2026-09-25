<?php

use App\Enums\ClientRequestStatus;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('lets an admin open every staff administration page', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->create();

    $this->actingAs($admin, 'web')->get(UserResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->actingAs($admin, 'web')->get(UserResource::getUrl('create', panel: 'admin'))->assertOk();
    $this->actingAs($admin, 'web')->get(UserResource::getUrl('edit', ['record' => $staff], panel: 'admin'))->assertOk();
});

/** settings.manage chỉ admin có (SPEC §5): quản lý cũng không được ghi hồ sơ nhân sự. */
it('hides the staff write pages from a manager', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $staff = User::factory()->create();

    // Panel từ chối bằng 404 (SPEC §10.10, xem DenialCodeTest và
    // AnswerDeniedPanelRequestsWithNotFound).
    $this->actingAs($manager, 'web')->get(UserResource::getUrl('create', panel: 'admin'))->assertNotFound();
    $this->actingAs($manager, 'web')->get(UserResource::getUrl('edit', ['record' => $staff], panel: 'admin'))->assertNotFound();
});

it('never renders the password hash or two-factor secret in the staff table response', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->create([
        'two_factor_secret' => 'super-secret-otp-seed',
    ]);

    $response = $this->actingAs($admin, 'web')->get(UserResource::getUrl('index', panel: 'admin'));

    $response->assertOk();
    $response->assertDontSee($staff->password, escape: false);
    $response->assertDontSee('super-secret-otp-seed');
});

/**
 * User::assignRoleFromPosition() — bình luận trên model ghi rõ Action/trang sửa nhân sự M3 phải
 * gọi lại hàm này khi chức danh được đặt hoặc đổi, để vai trò spatie luôn khớp chức danh.
 */
it('assigns a spatie role matching the chosen position when a staff record is created', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Nhân sự mới',
            'email' => 'nhansumoi@luatvukhang.com',
            'position' => UserPosition::Lawyer->value,
            'password' => 'password',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::where('email', 'nhansumoi@luatvukhang.com')->firstOrFail();

    expect($created->hasRole(Role::Lawyer->value))->toBeTrue();
});

it('re-syncs the spatie role when an existing staff record changes position', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['position' => UserPosition::Manager->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $staff->refresh();

    expect($staff->hasRole(Role::Manager->value))->toBeTrue()
        ->and($staff->hasRole(Role::Lawyer->value))->toBeFalse();
});

// =========================================================================================
// R7 (M6.5 Task 4, kéo lên từ M7 R6; finding roles-07/deadlines-F4/notify-4/REQ-3) — nghỉ việc
// bị chặn khi còn việc dở dang, hoặc khi là quản trị viên đang hoạt động cuối cùng.
// GuardsStaffOffboarding (App\Actions\User\Concerns) dùng chung bởi UserPolicy::delete() và
// EditUser::handleRecordUpdate().
// =========================================================================================

/**
 * `callAction('delete')` trên `EditUser` của một luật sư còn dẫn một vụ mở: bị chặn (nút vẫn bấm
 * được — `authorizationNotification()` — nhưng UserPolicy::delete() từ chối), `leadLawyer` vẫn
 * nguyên. Thông điệp nêu đúng số lượng (R7): 1 vụ, 0 mốc hạn, 0 yêu cầu khách.
 */
it('refuses to delete a lawyer who still leads an open matter, and tells the admin how many', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => null]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->assertActionVisible('delete')
        ->callAction('delete')
        ->assertNotified(__('users.offboarding.open_work_blocked', [
            'name' => $lawyer->name,
            'matters' => 1,
            'deadlines' => 0,
            'requests' => 0,
        ]));

    expect($lawyer->fresh()->trashed())->toBeFalse()
        ->and($matter->fresh()->lead_lawyer_id)->toBe($lawyer->id);
});

/** Vế dương: sau khi bàn giao hết (không còn dẫn vụ mở), admin xoá được như trước. */
it('deletes a lawyer with no open work', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    // Một vụ ĐÃ ĐÓNG không tính là việc mở (R8) — vẫn để lawyer đứng tên lead trên hồ sơ cũ.
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->callAction('delete');

    expect($lawyer->fresh()->trashed())->toBeTrue();
});

/**
 * Tắt `is_active` trên form của một luật sư còn đứng tên mốc hạn chưa xong: bị chặn, thông điệp
 * có đúng số lượng (0 vụ do lead — họ chỉ là associate ở đây —, 1 mốc hạn, 0 yêu cầu khách).
 */
it('refuses to deactivate a staff member still holding an unfinished deadline, with a reason naming the counts', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create();
    Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($component->errors()->first('data.is_active'))->toBe(__('users.offboarding.open_work_blocked', [
        'name' => $assistant->name,
        'matters' => 0,
        'deadlines' => 1,
        'requests' => 0,
    ]));

    expect($assistant->fresh()->is_active)->toBeTrue();
});

/** Vế dương: sau khi bàn giao hết, tắt is_active thành công như trước. */
it('deactivates a staff member once all open work has been handed off', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($assistant->fresh()->is_active)->toBeFalse();
});

/**
 * R7 chỉ liệt "vô hiệu hoá và xoá" — KHÔNG liệt "đổi chức danh": một luật sư còn đứng tên mốc hạn
 * chưa xong vẫn đổi được chức danh (is_active không đổi, chỉ position) mà không bị hỏi lại
 * OpenWork. Đây là VẾ DƯƠNG bắt buộc cho điều kiện `$record->is_active && ! $newIsActive` ở
 * EditUser::handleRecordUpdate() — thiếu điều kiện này thì MỌI lần lưu (kể cả không đụng
 * is_active) đều hỏi OpenWork, và test này sẽ đỏ.
 */
it('lets a position change through even while the staff member still holds an unfinished deadline', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create();
    Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->fillForm(['position' => UserPosition::Manager->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($assistant->fresh()->position)->toBe(UserPosition::Manager);
});

/**
 * Chưa đóng yêu cầu khách cũng chặn, cùng thông điệp — mutation probe cho điều kiện thứ ba (số
 * yêu cầu khách). Xoá `->where('status', '!=', ClientRequestStatus::Closed->value)` khỏi
 * OpenWork::forUser() (đổi tạm sang không lọc gì) làm test NÀY vẫn xanh (không đo được gì mới ở
 * vế dương), nhưng test "deactivates a staff member once all open work has been handed off" ở
 * trên đỏ nếu điều kiện lọc mốc hạn bị xoá tương tự — cặp âm/dương đã có ở hai test mốc hạn trên.
 * Test này bổ sung vế client_requests còn thiếu.
 */
it('refuses to deactivate a staff member still assigned an open client request', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create();
    ClientRequest::factory()->for($matter)->create([
        'assigned_to' => $assistant->id,
        'status' => ClientRequestStatus::InProgress,
    ]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($assistant->fresh()->is_active)->toBeTrue();
});

// =========================================================================================
// R7 — quản trị viên đang hoạt động cuối cùng
// =========================================================================================

/**
 * Admin duy nhất tự đổi chức danh sang luật sư: bị chặn, `admins_left` vẫn bằng 1 (đếm lại số
 * admin đang hoạt động sau lần bị chặn — không đổi, đúng như trước khi bấm lưu).
 */
it('blocks the last active admin from demoting themself away from Admin, and the admin count stays at one', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $admin->getRouteKey()])
        ->fillForm(['position' => UserPosition::Lawyer->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($component->errors()->first('data.position'))->toBe(__('users.offboarding.last_admin_blocked'));

    $adminsLeft = User::query()->where('is_active', true)
        ->whereHas('roles', fn ($query) => $query->where('name', Role::Admin->value))
        ->count();

    expect($adminsLeft)->toBe(1)
        ->and($admin->fresh()->position)->toBe(UserPosition::Admin);
});

/** Cùng luật, đường tắt is_active: admin duy nhất tự vô hiệu hoá cũng bị chặn. */
it('blocks the last active admin from deactivating themself', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $admin->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($admin->fresh()->is_active)->toBeTrue();
});

/**
 * `callAction('delete')` trên chính admin duy nhất vẫn bị chặn — nhưng bởi luật CŨ (không tự xoá
 * chính mình, `UserPolicy::delete()` dòng `$user->isNot($model)`), không phải bởi
 * `wouldLeaveNoActiveAdmin()`: một actor KHÁC admin duy nhất đó không bao giờ tồn tại được (chỉ
 * admin mới có `settings.manage` để mở trang này), nên nhánh "admin cuối cùng" của `delete()` là
 * mã không kịch bản nào chạm tới — xem docblock `UserPolicy::delete()`. Nút vẫn bị ẨN (không có
 * message nên `authorizationNotification()` không giữ nó hiển thị), khác nút "Bàn giao" hay ô
 * is_active/position của form, nơi tự sửa (khác tự xoá) vẫn được phép và luật MỚI thật sự chạy.
 */
it('still hides the delete button on the last active admins own edit page, via the pre-existing self-delete rule', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $admin->getRouteKey()])
        ->assertActionHidden('delete');

    expect($admin->fresh()->trashed())->toBeFalse();
});

/**
 * Vế dương (mutation probe cho "admin cuối cùng"): khi có MỘT admin khác đang hoạt động, admin
 * tự đổi chức danh, hoặc tự tắt is_active đều thành công như trước — chứng minh luật chỉ chặn
 * đúng trường hợp KHÔNG còn admin nào khác, không chặn admin nói chung.
 */
it('lets an admin demote or deactivate themself when another active admin still exists', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create(); // admin thứ hai còn hoạt động

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $admin->getRouteKey()])
        ->fillForm(['position' => UserPosition::Lawyer->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($admin->fresh()->position)->toBe(UserPosition::Lawyer);
});

/**
 * Mutation probe (dán vào báo cáo): xoá điều kiện `$remainsActiveAdmin` (bắt
 * `wouldLeaveNoActiveAdmin()` LUÔN coi như KHÔNG còn giữ vai admin) khiến chính test "lets an
 * admin demote or deactivate themself when another active admin still exists" ở trên ĐỎ — bằng
 * chứng điều kiện đó thật sự phân biệt "còn admin khác" với "không còn ai".
 */

// =========================================================================================
// Carry-over Task 2 (C1-class hole): UserPolicy thiếu deleteAny/restoreAny/forceDeleteAny —
// bulk actions của UsersTable phải tự đi qua UserPolicy::delete() cho TỪNG bản ghi đã chọn.
// =========================================================================================

/** Lớp phòng thủ thứ hai: một luật sư còn dẫn vụ mở sống sót qua bulk delete, người còn lại mất. */
it('bulk-deletes only the staff member with no open work, and leaves the reason discoverable on the other', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $withOpenMatter = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $withOpenMatter->id, 'closed_at' => null]);
    $withoutOpenWork = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(ListUsers::class)
        ->callTableBulkAction('delete', [$withOpenMatter, $withoutOpenWork]);

    expect($withOpenMatter->fresh()->trashed())->toBeFalse()
        ->and($withoutOpenWork->fresh()->trashed())->toBeTrue();

    // Lý do bị chặn vẫn đi qua đúng UserPolicy::delete() — thứ authorizeIndividualRecords('delete')
    // gọi cho từng dòng — dù bulk delete không hiện nó ra thành một toast riêng cho từng bản ghi.
    $response = Gate::forUser($admin)->inspect('delete', $withOpenMatter);

    expect($response->denied())->toBeTrue()
        ->and($response->message())->toBe(__('users.offboarding.open_work_blocked', [
            'name' => $withOpenMatter->name,
            'matters' => 1,
            'deadlines' => 0,
            'requests' => 0,
        ]));
});

/**
 * Carry-over Task 2: cổng THÔ `deleteAny()`/`restoreAny()`/`forceDeleteAny()` — trước bản sửa
 * này, UserPolicy không định nghĩa chúng, nên một ability thiếu phương thức bị coi là CHO PHÉP ở
 * chế độ không nghiêm ngặt (mặc định dự án).
 *
 * Khác `ListClients` (đọc được bởi bất kỳ ai có `client.manage`), `ListUsers` đòi
 * `UserPolicy::viewAny()` — tức `settings.manage`, chỉ admin — nên một luật sư/trưởng phòng 404
 * ngay ở CHÍNH trang danh sách, không tới lượt hỏi `deleteAny()`. Đây đã là lớp chặn MẠNH HƠN
 * "ẩn nút xoá hàng loạt" (không ai không phải admin còn thấy được BẤT KỲ điều gì trên trang này,
 * kể cả một dòng nhân sự) — cùng luật đã ghim ở "hides the staff write pages from a manager" cho
 * create/edit, giờ thêm index.
 */
it('hides the staff list page entirely from a manager, closing the bulk-action door before it opens', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($manager, 'web')
        ->get(UserResource::getUrl('index', panel: 'admin'))
        ->assertNotFound();
});

/** Vế dương: admin thấy xoá và khôi phục hàng loạt (trừ xoá vĩnh viễn, không ai thấy). */
it('shows the bulk delete and restore actions to the admin, but never force-delete', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(ListUsers::class)
        ->assertTableBulkActionVisible('delete')
        ->filterTable('trashed', true)
        ->assertTableBulkActionVisible('restore')
        ->assertTableBulkActionHidden('forceDelete');
});
