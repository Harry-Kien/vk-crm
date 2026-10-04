<?php

use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
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
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Câu ghép của `offboardingOpenWorkReason()` (fix round 1, CRITICAL) — cùng hàm dùng ở
 * `GuardsStaffOffboardingTest.php`, chép lại tại chỗ vì hai tệp Pest không dùng chung được một
 * hàm toàn cục (trùng tên khi cả hai tệp cùng nạp).
 */
function staffOffboardingMessage(string $name, int $matters, int $deadlines, int $requests): string
{
    $parts = [];

    if ($matters > 0) {
        $parts[] = __('users.offboarding.open_work_lead_matters', ['count' => $matters]);
    }

    if ($deadlines > 0) {
        $parts[] = __('users.offboarding.open_work_deadlines', ['count' => $deadlines]);
    }

    if ($requests > 0) {
        $parts[] = __('users.offboarding.open_work_client_requests', ['count' => $requests]);
    }

    return __('users.offboarding.open_work_intro', ['name' => $name])
        .' '.implode('; ', $parts).'. '
        .__('users.offboarding.open_work_outro');
}

/**
 * Cùng thân câu với `staffOffboardingMessage()` ở trên, khác câu MỞ ĐẦU — đổi chức danh KHÔNG phải
 * vô hiệu hoá/xoá (finding round 3, mục 4: `demotion_intro`, dùng bởi
 * `demotionBlockedByLeadMattersReason()`/`demotionBlockedByAnyOpenWorkReason()`).
 */
function staffDemotionMessage(string $name, int $matters, int $deadlines, int $requests): string
{
    $parts = [];

    if ($matters > 0) {
        $parts[] = __('users.offboarding.open_work_lead_matters', ['count' => $matters]);
    }

    if ($deadlines > 0) {
        $parts[] = __('users.offboarding.open_work_deadlines', ['count' => $deadlines]);
    }

    if ($requests > 0) {
        $parts[] = __('users.offboarding.open_work_client_requests', ['count' => $requests]);
    }

    return __('users.offboarding.demotion_intro', ['name' => $name])
        .' '.implode('; ', $parts).'. '
        .__('users.offboarding.open_work_outro');
}

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
        ->assertNotified(staffOffboardingMessage($lawyer->name, matters: 1, deadlines: 0, requests: 0));

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

    expect($component->errors()->first('data.is_active'))->toBe(staffOffboardingMessage($assistant->name, matters: 0, deadlines: 1, requests: 0));

    expect($assistant->fresh()->is_active)->toBeTrue();
});

/**
 * SPEC §11 "Bàn giao và lưu trữ", test thứ nhất, đúng chữ (M7 Task 11): "Vô hiệu hoá tài khoản luật
 * sư còn là lead lawyer của vụ việc đang mở → bị chặn, thông điệp nêu rõ số vụ cần bàn giao."
 * Trước test này, chặn VÔ HIỆU HOÁ một lead chỉ được khẳng định là "có lỗi form" (test "offboards a
 * lead lawyer…" và "sends a separate notification…"), còn câu NÊU SỐ VỤ chỉ được đo ở đường XOÁ
 * ("refuses to delete a lawyer…") và ở mức Action (`GuardsStaffOffboardingTest`). Hai vụ đang mở
 * cộng một vụ đã đóng: câu phải nói "2", không phải "1" hay "3" — vụ đã đóng không cần bàn giao.
 */
it('refuses to deactivate a lawyer who still leads open matters, with a reason naming how many to hand off', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->count(2)->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => null]);
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()]);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($component->errors()->first('data.is_active'))
        ->toBe(staffOffboardingMessage($lawyer->name, matters: 2, deadlines: 0, requests: 0))
        ->toContain(__('users.offboarding.open_work_lead_matters', ['count' => 2]));

    expect($lawyer->fresh()->is_active)->toBeTrue();
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
// R6 (M7 Task 2, brief: "Thêm liên kết tới màn hình này vào thông điệp chặn vô hiệu hoá của
// M6.5") — EditUser::attachBulkReassignLink() / BulkReassign::offboardingLinkAction().
// =========================================================================================

/**
 * Thông báo chặn XOÁ (`authorizationNotification()`/`unauthorizedNotification()` của
 * `DeleteAction`) mang thêm MỘT action-button trỏ tới "Bàn giao hàng loạt" mở sẵn đúng người bị
 * chặn (`?from=<id>`), khi người đó CÒN dẫn ít nhất một vụ mở — cùng kịch bản chặn xoá đã có ở
 * "refuses to delete a lawyer who still leads an open matter..." trên.
 */
it('adds a bulk-reassign link to the delete-blocked notification for a lawyer who still leads an open matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => null]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->callAction('delete');

    $component = new Notifications;
    $component->mount();

    $notification = $component->notifications->first(
        fn ($mounted): bool => $mounted->getTitle() === staffOffboardingMessage($lawyer->name, matters: 1, deadlines: 0, requests: 0)
    );

    expect($notification)->not->toBeNull();

    $actions = $notification->getActions();

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->getUrl())->toBe(BulkReassign::getUrl(['from' => $lawyer->id], panel: 'admin'));
});

/**
 * Tắt `is_active` bị chặn (lỗi form giữ NGUYÊN $reason, đo bởi test "refuses to deactivate..."
 * trên — KHÔNG đổi) gửi THÊM một `Notification` RIÊNG (chuỗi lỗi form không mang được URL) mang
 * cùng liên kết, khi người bị chặn còn dẫn vụ mở.
 */
it('sends a separate notification with the bulk-reassign link when deactivation is blocked by an open lead matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => null]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($lawyer->fresh()->is_active)->toBeTrue();

    $component = new Notifications;
    $component->mount();

    $notification = $component->notifications->first(
        fn ($mounted): bool => $mounted->getTitle() === __('users.offboarding.bulk_reassign_notice', ['name' => $lawyer->name])
    );

    expect($notification)->not->toBeNull();

    $actions = $notification->getActions();

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->getUrl())->toBe(BulkReassign::getUrl(['from' => $lawyer->id], panel: 'admin'));
});

/**
 * Mutation probe cặp với hai test trên: một người bị chặn KHÔNG vì vụ mở (chỉ vì mốc hạn/yêu cầu
 * khách chưa xong — `$assistant` không đứng tên `lead_lawyer_id` bất kỳ vụ nào) không nhận link
 * nào — `BulkReassign::offboardingLinkAction()` trả `null` khi `OpenWork::forUser()->leadMatters`
 * rỗng (docblock: "liên kết tới một màn hình rỗng không giúp gì"). Cả thông báo chặn xoá lẫn
 * thông báo is_active riêng đều KHÔNG mang action nào.
 */
it('adds no bulk-reassign link when the person blocked from offboarding leads no open matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create();
    Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->callAction('delete');

    $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    $component = new Notifications;
    $component->mount();

    expect($component->notifications->contains(
        fn ($mounted): bool => __('users.offboarding.bulk_reassign_notice', ['name' => $assistant->name]) === $mounted->getTitle()
    ))->toBeFalse();

    $deleteBlocked = $component->notifications->first(
        fn ($mounted): bool => $mounted->getTitle() === staffOffboardingMessage($assistant->name, matters: 0, deadlines: 1, requests: 0)
    );

    expect($deleteBlocked)->not->toBeNull()
        ->and($deleteBlocked->getActions())->toBe([]);
});

// =========================================================================================
// Ruling fix round 1 — "guard demotion": đổi chức danh sang một chức danh KHÔNG lãnh đạo được
// (Trợ lý/Kế toán) trong khi còn dẫn vụ đang mở bị chặn, cùng thông điệp còn việc dở dang.
// =========================================================================================

it('refuses to demote a lawyer who leads an open matter to a role that cannot lead', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => null]);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Assistant->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($component->errors()->first('data.position'))->toBe(staffDemotionMessage($lawyer->name, matters: 1, deadlines: 0, requests: 0))
        ->and($lawyer->fresh()->position)->toBe(UserPosition::Lawyer);
});

/** Cùng luật, đích đến là Kế toán thay vì Trợ lý — cả hai đều "không lãnh đạo được". */
it('refuses to demote a lawyer who leads an open matter to accountant', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => null]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Accountant->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($lawyer->fresh()->position)->toBe(UserPosition::Lawyer);
});

/**
 * Ruling (fix round 3, mục 5): Kế toán không xử lý được BẤT KỲ việc pháp lý nào — khác Trợ lý (vẫn
 * giữ được mốc hạn/yêu cầu khách). Một luật sư chỉ còn đứng tên MỘT mốc hạn chưa xong (không dẫn
 * vụ nào) VẪN bị chặn đổi sang Kế toán, dù đường sang Trợ lý (test kề bên) cho qua đúng người này.
 */
it('refuses to demote a lawyer holding only an unfinished deadline (no lead matter) to accountant', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create();
    Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $lawyer->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Accountant->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($component->errors()->first('data.position'))->toBe(staffDemotionMessage($lawyer->name, matters: 0, deadlines: 1, requests: 0))
        ->and($lawyer->fresh()->position)->toBe(UserPosition::Lawyer);
});

/**
 * Vế dương bắt buộc: đổi sang Trưởng phòng (VẪN lãnh đạo được) không bị chặn dù đang dẫn vụ mở —
 * chứng minh luật chỉ chặn đúng hai đích "không lãnh đạo được", không chặn mọi lần đổi chức danh
 * của một người đang dẫn vụ.
 */
it('lets a lawyer who leads an open matter be promoted to manager', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => null]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Manager->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lawyer->fresh()->position)->toBe(UserPosition::Manager);
});

/**
 * Minor (fix round 2): luật "guard demotion" thu hẹp lại — chỉ chặn khi người đó CÒN DẪN một vụ
 * việc đang mở (SPEC §7.4: Trợ lý/Kế toán không đứng tên `lead_lawyer_id` được). Một mốc thời hạn
 * (Trợ lý VẪN giữ được) không chặn được lần đổi này — bản trước dùng chung
 * `offboardingOpenWorkReason()` (cả ba loại việc) nên chặn NHẦM một người chỉ còn mốc hạn, không
 * còn vụ việc lead nào.
 */
it('lets a lawyer holding only an unfinished deadline (no lead matter) be demoted to assistant', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create();
    Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $lawyer->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Assistant->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lawyer->fresh()->position)->toBe(UserPosition::Assistant);
});

/** Vế dương thứ hai: không còn việc mở nào thì đổi sang Trợ lý cũng thành công như trước. */
it('lets a lawyer with no open work be demoted to assistant', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Assistant->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lawyer->fresh()->position)->toBe(UserPosition::Assistant);
});

/**
 * Vế dương thứ ba, mutation probe cho điều kiện "chức danh THẬT SỰ đổi": một trợ lý ĐÃ Ở SẴN
 * chức danh không lãnh đạo được, còn đứng tên mốc hạn chưa xong (không phải lead vụ nào), lưu lại
 * form KHÔNG đụng ô chức danh (gửi lên đúng giá trị hiện tại — điều Filament luôn làm, mọi ô đã
 * mount đều có mặt trong `$data`) không bị chặn — luật chỉ áp cho một lần ĐỔI SANG, không áp cho
 * mọi lần lưu của một người đã ở chức danh đó.
 */
it('lets an assistant with an unfinished deadline save the form without changing position', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    // `.position()` TƯỜNG MINH — `withRole()` một mình chỉ đồng bộ vai spatie, KHÔNG đụng cột
    // `position` (mặc định `Lawyer` của factory). Thiếu dòng này, $record->position thật sự VẪN
    // là Lawyer, nên "đổi sang Assistant" bị hiểu nhầm thành một lần đổi CHỨC DANH THẬT — đúng
    // cái bẫy test này cần tránh để đo đúng điều kiện "không đổi".
    $assistant = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Cũ']);
    $matter = Matter::factory()->create();
    Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->fillForm(['position' => UserPosition::Assistant->value, 'name' => 'Trợ lý Mới'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($assistant->fresh()->name)->toBe('Trợ lý Mới');
});

/**
 * CRITICAL (fix round 1) — bằng chứng end-to-end đủ ba bước cho đúng kịch bản mà đợt rà soát bắt
 * được: một trợ lý KHÔNG phải lead của bất kỳ vụ việc nào vẫn bị kẹt lại mãi mãi vì không màn
 * hình nào đổi được `responsible_user_id`. (1) Bị chặn vô hiệu hoá. (2) Đổi người phụ trách qua
 * ĐÚNG màn hình Mốc thời hạn (`DeadlinesRelationManager`, không gọi thẳng Action). (3) Vô hiệu
 * hoá thành công.
 */
it('offboards a non-lead staff member once their deadline is handed off through the Deadlines tab', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    // Bước 1: bị chặn — assistant không hề là lead của vụ việc nào, nên trước fix round 1 không
    // có đường ra nào cho họ.
    $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($assistant->fresh()->is_active)->toBeTrue();

    // Bước 2: đổi người phụ trách qua ĐÚNG màn hình Mốc thời hạn.
    $this->livewire(DeadlinesRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callTableAction('changeResponsible', $deadline, data: ['responsible_user_id' => $lawyer->id])
        ->assertHasNoTableActionErrors();

    expect($deadline->fresh()->responsible_user_id)->toBe($lawyer->id);

    // Bước 3: vô hiệu hoá thành công.
    $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($assistant->fresh()->is_active)->toBeFalse();
});

/**
 * Spec gap (fix round 1): "Sau khi bàn giao hết thì vô hiệu hoá được" — bằng chứng end-to-end đủ
 * ba bước cho một LEAD: (1) bị chặn vô hiệu hoá vì còn dẫn vụ đang mở kèm mốc hạn. (2) Bàn giao
 * qua ĐÚNG header action "Bàn giao" trên `ViewMatter` (không gọi thẳng `ReassignMatter`). (3) Vô
 * hiệu hoá thành công.
 */
it('offboards a lead lawyer once their matter is handed off through the Bàn giao action', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLead->id, 'closed_at' => null]);
    Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $oldLead->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    // Bước 1: bị chặn.
    $this->livewire(EditUser::class, ['record' => $oldLead->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($oldLead->fresh()->is_active)->toBeTrue();

    // Bước 2: bàn giao qua ĐÚNG header action — chuyển cả vai lead LẪN mốc hạn chưa xong của họ.
    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => false,
            'reason' => 'Nghỉ việc, bàn giao toàn bộ.',
        ])
        ->assertHasNoActionErrors();

    expect($matter->fresh()->lead_lawyer_id)->toBe($newLead->id);

    // Bước 3: vô hiệu hoá thành công.
    $this->livewire(EditUser::class, ['record' => $oldLead->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($oldLead->fresh()->is_active)->toBeFalse();
});

/**
 * Minor (fix round 2): `EditUser::handleRecordUpdate()` khoá dòng bằng `User::query()` (không
 * `withTrashed()`) — SoftDeletes global scope mặc định loại bỏ hàng đã xoá mềm, nên lưu form sửa
 * của một tài khoản ĐÃ xoá mềm 404 (`ModelNotFoundException` từ `firstOrFail()`) ngay tại khoá
 * dòng. Trang vẫn MỞ ĐƯỢC (`UserResource::getRecordRouteBindingEloquentQuery()` đã tự bỏ scope đó
 * cho việc MỞ trang — ví dụ một admin lọc `TrashedFilter` rồi mở form của một người đã nghỉ việc để
 * sửa lại số điện thoại cũ) — một cặp lệch nhau: mở được nhưng lưu không được.
 */
it('saves the edit form of a soft-deleted user instead of 404ing at the row lock', function () {
    $admin = User::factory()->admin()->create();
    $trashed = User::factory()->withRole(Role::Lawyer)->create();
    $trashed->delete();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $trashed->getRouteKey()])
        ->fillForm(['phone' => '0909000000'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($trashed->fresh()->phone)->toBe('0909000000');
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
 * I2.3 (fix round 2) — `assignRoleFromPosition()` giờ chạy TRONG transaction bị khoá của
 * `handleRecordUpdate()` (đã bỏ `afterSave()`), không còn tách rời SAU khi khoá đã nhả. Bản round 1
 * để hở đúng khe hở này: `wouldLeaveNoActiveAdmin()` đếm vai SPATIE (`GuardsStaffOffboarding.php`),
 * còn đồng bộ vai lại chạy ở `afterSave()` — RA KHỎI khoá. Hai admin cuối cùng cùng tự hạ chức danh
 * gần như đồng thời: lượt hai có thể acquire lại `Cache::lock` (đã được lượt một nhả ra ngay khi
 * `handleRecordUpdate()` trả về) TRƯỚC KHI `afterSave()` của lượt một kịp đồng bộ vai — đọc thấy
 * admin thứ nhất "vẫn còn vai Admin" (spatie chưa đổi), cho qua NHẦM cả hai, để hệ thống còn 0 admin.
 *
 * Mô phỏng TUẦN TỰ (không cần hai tiến trình PHP thật — "sequential simulation" đúng chữ finding
 * đòi): gọi thẳng `handleRecordUpdate()` qua `Closure::bind` (cùng thành ngữ
 * `ReassignMatterActionTest`'s `Closure::bind` trên `submitReassign()`), KHÔNG qua toàn bộ vòng đời
 * `save()` của Filament (tức KHÔNG có cơ hội cho một `afterSave()` tách rời chạy giữa hai lượt, dù
 * có tồn tại hay không) — nếu code đồng bộ vai NGAY TRONG cùng lệnh gọi này (bản sửa), lượt thứ hai
 * PHẢI thấy vai đã đổi. Nếu (giả sử) đồng bộ vai còn tách rời ở một bước riêng SAU lệnh gọi này,
 * lượt thứ hai gọi liền theo sau sẽ không thấy được thay đổi đó — chính khe hở round 1 để lại.
 */
it('keeps at least one admin when two last admins demote themselves sequentially, because the role sync now runs inside the same lock', function () {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();

    $this->actingAs($adminA, 'web');

    $pageA = $this->livewire(EditUser::class, ['record' => $adminA->getRouteKey()])->instance();
    $handleA = Closure::bind(fn (array $data) => $this->handleRecordUpdate($this->getRecord(), $data), $pageA, EditUser::class);

    $handleA(['position' => UserPosition::Lawyer->value]);

    // Nếu đồng bộ vai còn tách rời (bug round 1), dòng dưới đây đỏ NGAY Ở ĐÂY — spatie vẫn nói
    // Admin dù cột `position` đã đổi. Cùng cách round 1 phát hiện `withRole()`/`position` lệch
    // nhau: đo trực tiếp trạng thái spatie, không suy diễn từ cột `position`.
    expect($adminA->fresh()->hasRole(Role::Admin->value))->toBeFalse();

    $pageB = $this->livewire(EditUser::class, ['record' => $adminB->getRouteKey()])->instance();
    $handleB = Closure::bind(fn (array $data) => $this->handleRecordUpdate($this->getRecord(), $data), $pageB, EditUser::class);

    expect(fn () => $handleB(['position' => UserPosition::Lawyer->value]))
        ->toThrow(ValidationException::class);

    expect($adminB->fresh()->position)->toBe(UserPosition::Admin);
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

/**
 * Lớp phòng thủ thứ hai: một luật sư còn dẫn vụ mở sống sót qua bulk delete, người còn lại mất.
 *
 * **Fix round 1 (spec gap): đo thông báo THẬT mà bulk action gửi qua Livewire, không
 * `Gate::inspect()`.** Bản trước chỉ hỏi lại policy trực tiếp — đúng về mặt LOGIC (cùng hàm
 * `authorizeIndividualRecords('delete')` gọi) nhưng không chứng minh được thông điệp đó có thật
 * sự TỚI MẮT admin hay không. `Filament\Actions\Concerns\InteractsWithSelectedRecords` gom
 * `Response::deny($reason)` của từng bản ghi bị từ chối vào `bulkAuthorizationFailureMessages`,
 * rồi `CanNotify::getFailureNotificationBody()` ghép chúng vào THÂN của một `Notification` thật
 * — đọc đúng notification đó, cùng thành ngữ `sentNotification()` của `ViewMatterTest`.
 */
it('bulk-deletes only the staff member with no open work, and shows the real Vietnamese reason for the other', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $withOpenMatter = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $withOpenMatter->id, 'closed_at' => null]);
    $withoutOpenWork = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(ListUsers::class)
        ->callTableBulkAction('delete', [$withOpenMatter, $withoutOpenWork]);

    expect($withOpenMatter->fresh()->trashed())->toBeFalse()
        ->and($withoutOpenWork->fresh()->trashed())->toBeTrue();

    $component = new Notifications;
    $component->mount();

    $bodies = $component->notifications->map(fn ($notification): string => (string) $notification->getBody())->implode("\n");

    expect($bodies)->toContain(staffOffboardingMessage($withOpenMatter->name, matters: 1, deadlines: 0, requests: 0));
});

/**
 * Minor (fix round 3): một hàng ĐÃ XOÁ MỀM, chọn được qua bộ lọc "kèm đã xoá" — trước bản sửa này,
 * `DeleteStaffMember::handle()`'s `firstOrFail()` (không `withTrashed()`) ném `ModelNotFoundException`
 * cho đúng hàng đó, và `->using()` chỉ bắt `DomainException`/`AuthorizationException` — exception đó
 * THOÁT RA khỏi `$records->each()`, phá vỡ toàn bộ lượt xoá hàng loạt: những bản ghi CÒN LẠI (đứng
 * sau hàng đã xoá trong danh sách) không hề được xử lý. Đặt tên để hàng đã xoá LUÔN đứng TRƯỚC hàng
 * còn hoạt động trong sắp xếp mặc định (`defaultSort('name')`), để một khi lỗi thoát ra ở hàng đầu,
 * hàng thứ hai chắc chắn KHÔNG được xử lý nếu bug còn đó.
 */
it('does not abort a bulk delete when one of the selected rows is already soft-deleted', function () {
    $admin = User::factory()->admin()->create();
    $alreadyTrashed = User::factory()->withRole(Role::Lawyer)->create(['name' => 'A - Đã Nghỉ Việc']);
    $alreadyTrashed->delete();
    $stillActive = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Z - Còn Đi Làm']);

    $this->actingAs($admin, 'web');

    $this->livewire(ListUsers::class)
        ->filterTable('trashed', true)
        ->callTableBulkAction('delete', [$alreadyTrashed, $stillActive]);

    expect($stillActive->fresh()->trashed())->toBeTrue();
});

/**
 * I2.1 (fix round 2) — bulk delete phải đi qua `DeleteStaffMember`, không còn `$record->delete()`
 * trần của Filament: cùng luật "admin cuối cùng"/khoá dòng mà nút xoá ĐƠN (`EditUser`) đã áp từ
 * fix round 1, giờ áp luôn cho xoá hàng loạt — trước bản sửa này, `UsersTable::toolbarActions()`
 * chỉ lọc bằng `authorizeIndividualRecords('delete')` (KHÔNG khoá gì, không re-check gì dưới khoá)
 * rồi để Filament tự `$record->delete()`.
 *
 * Dựng cuộc đua CÙNG HÌNH DẠNG với `DeleteStaffMemberTest` (Action-tier): actor vừa bị hạ vai admin
 * bởi MỘT request khác NGAY TRƯỚC lượt xoá hàng loạt này — sửa CSDL trực tiếp, không qua đối tượng
 * PHP `$admin` mà `actingAs()` đang cầm, mô phỏng một request khác đã âm thầm đổi actor. Nếu
 * `DeleteStaffMember` không đọc lại actor dưới khoá (bug mà vòng sửa này đóng), `$lastAdmin` — admin
 * đang hoạt động DUY NHẤT còn lại sau khi actor mất vai — sẽ bị xoá, để hệ thống còn 0 admin.
 */
it('refuses a bulk delete that would leave zero active admins, once the acting admin has lost their own role mid-request', function () {
    $admin = User::factory()->admin()->create();
    $lastAdmin = User::factory()->admin()->create();

    $this->actingAs($admin, 'web');

    User::query()->whereKey($admin->id)->update(['is_active' => false]);

    $this->livewire(ListUsers::class)
        ->callTableBulkAction('delete', [$lastAdmin]);

    expect($lastAdmin->fresh()->trashed())->toBeFalse();
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

// -------------------------------------------------------------------------------------------
// Task 20 — mật khẩu nhân sự: cùng PasswordRule::default() với cổng khách (Task 7), và một
// dòng nhật ký mỗi lần admin đặt lại. Phát hiện gốc: "admin đặt được mật khẩu 1 cho luật sư mà
// không có nhật ký nào".
// -------------------------------------------------------------------------------------------

it('rejects a weak password when an admin resets a staff password', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['password' => '1'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect(
        Activity::query()->where('event', 'user_password_reset')->where('subject_id', $staff->id)->exists()
    )->toBeFalse();
});

it('logs an audit line when an admin resets a staff password with a valid one', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['password' => 'mat-khau-moi'])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()
        ->where('event', 'user_password_reset')
        ->where('subject_type', $staff->getMorphClass())
        ->where('subject_id', $staff->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($admin))->toBeTrue();
});

/**
 * Vế "không đổi mật khẩu": sửa tên/điện thoại mà không chạm ô mật khẩu không được sinh một dòng
 * `user_password_reset` — nếu không, dòng nhật ký không còn nói lên gì (đặt lại mật khẩu là một
 * SỰ KIỆN, không phải một tác dụng phụ của mọi lần lưu form sửa nhân sự).
 */
it('does not log a password-reset audit line when a staff record is saved without a new password', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['phone' => '0900000000']);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['phone' => '0911111111'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(
        Activity::query()->where('event', 'user_password_reset')->where('subject_id', $staff->id)->exists()
    )->toBeFalse();
});

// -------------------------------------------------------------------------------------------
// Final review X4 (A-I3): một admin thấy MỌI vụ `restricted`; hạ chức danh khỏi Quản trị viên
// làm người đó HẾT THẤY những vụ `restricted` mình không phụ trách — nếu họ còn ở đội ngũ, hay
// còn giữ một mốc hạn/yêu cầu khách chưa xong ở đó, vụ việc có một thành viên/người giữ việc vô
// hình. Chặn, kèm số vụ.
// -------------------------------------------------------------------------------------------

function adminToDemote(): User
{
    return User::factory()->position(UserPosition::Admin)->withRole(Role::Admin)->create();
}

it('refuses to demote an admin who sits on the team of a restricted matter they do not lead', function () {
    $actingAdmin = User::factory()->position(UserPosition::Admin)->withRole(Role::Admin)->create();
    $demoted = adminToDemote();
    $matter = Matter::factory()->restricted()->create();
    $matter->team()->attach($demoted, ['role_in_matter' => MatterRole::Associate->value]);

    $this->actingAs($actingAdmin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $demoted->getRouteKey()])
        ->fillForm(['position' => UserPosition::Lawyer->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($component->errors()->first('data.position'))
        ->toBe(__('users.offboarding.demotion_from_admin_restricted', ['name' => $demoted->name, 'count' => 1]))
        ->and($demoted->fresh()->position)->toBe(UserPosition::Admin)
        ->and($demoted->fresh()->hasRole(Role::Admin->value))->toBeTrue();
});

it('counts restricted matters where the admin holds an open deadline or an open client request', function () {
    $actingAdmin = User::factory()->position(UserPosition::Admin)->withRole(Role::Admin)->create();
    $demoted = adminToDemote();

    $withDeadline = Matter::factory()->restricted()->create();
    Deadline::factory()->for($withDeadline)->create(['responsible_user_id' => $demoted->id, 'is_completed' => false]);

    $withRequest = Matter::factory()->restricted()->create();
    ClientRequest::factory()->create([
        'matter_id' => $withRequest->id,
        'assigned_to' => $demoted->id,
        'status' => ClientRequestStatus::InProgress,
    ]);

    // Không tính: vụ restricted do chính người này phụ trách; mốc đã xong; yêu cầu đã đóng.
    Matter::factory()->restricted()->create(['lead_lawyer_id' => $demoted->id]);
    Deadline::factory()->for(Matter::factory()->restricted()->create())->create(['responsible_user_id' => $demoted->id, 'is_completed' => true]);
    ClientRequest::factory()->create([
        'matter_id' => Matter::factory()->restricted()->create()->id,
        'assigned_to' => $demoted->id,
        'status' => ClientRequestStatus::Closed,
    ]);

    $this->actingAs($actingAdmin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $demoted->getRouteKey()])
        ->fillForm(['position' => UserPosition::Manager->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($component->errors()->first('data.position'))
        ->toBe(__('users.offboarding.demotion_from_admin_restricted', ['name' => $demoted->name, 'count' => 2]));
});

it('still demotes an admin who is only on normal matters, or leads the restricted ones', function () {
    $actingAdmin = User::factory()->position(UserPosition::Admin)->withRole(Role::Admin)->create();
    $demoted = adminToDemote();
    Matter::factory()->create()->team()->attach($demoted, ['role_in_matter' => MatterRole::Associate->value]);
    Matter::factory()->restricted()->create(['lead_lawyer_id' => $demoted->id]);

    $this->actingAs($actingAdmin, 'web');

    $this->livewire(EditUser::class, ['record' => $demoted->getRouteKey()])
        ->fillForm(['position' => UserPosition::Lawyer->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($demoted->fresh()->position)->toBe(UserPosition::Lawyer);
});

it('does not apply the restricted-matter rule to a staff member who was not an admin', function () {
    $actingAdmin = User::factory()->position(UserPosition::Admin)->withRole(Role::Admin)->create();
    $manager = User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create();
    $matter = Matter::factory()->restricted()->create();
    $matter->team()->attach($manager, ['role_in_matter' => MatterRole::Associate->value]);

    $this->actingAs($actingAdmin, 'web');

    $this->livewire(EditUser::class, ['record' => $manager->getRouteKey()])
        ->fillForm(['position' => UserPosition::Lawyer->value])
        ->call('save')
        ->assertHasNoFormErrors();
});
