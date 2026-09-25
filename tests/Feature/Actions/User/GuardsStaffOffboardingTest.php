<?php

use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Enums\ClientRequestStatus;
use App\Enums\Role;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Test Ở TẦNG ACTION cho `App\Actions\User\Concerns\GuardsStaffOffboarding` (M6.5 Task 4, R7) —
 * cùng quy ước `tests/Feature/Actions/TeamMemberTest.php`: trait này không phải một MÀN HÌNH, nó
 * là logic dùng chung của `UserPolicy::delete()` và `EditUser::handleRecordUpdate()` — hai nơi đó
 * đã có test riêng Ở TẦNG MÀN HÌNH, đi qua Livewire (`UserResourceTest.php`), đúng yêu cầu của
 * brief Task 4 ("Never call the Actions directly in a screen test").
 *
 * Tệp này kiểm trực tiếp hai hàm bảo vệ của trait, qua một lớp vô danh chỉ mượn trait — không
 * dựng cả một `EditRecord`/`Policy` chỉ để đo đúng một hàm. Lý do cần một tệp riêng ở tầng này:
 * `wouldLeaveNoActiveAdmin()` có hai điều kiện sớm (`is_active`, `hasRole(Admin)`) mà KHÔNG kịch
 * bản màn hình thật nào chạm tới được theo hai chiều sai — `EditUser` chỉ mở được cho một admin
 * đang hoạt động (`UserPolicy::viewAny` đòi `settings.manage`), nên actor luôn LÀ một admin đang
 * hoạt động khác biệt với hầu hết bản ghi bị sửa, và "còn admin nào khác" luôn đúng nhờ chính actor
 * đó — che mất khả năng đo hai điều kiện sớm bằng một mutation probe ở tầng màn hình. Gọi trực
 * tiếp ở đây, không qua actor nào cả, mới đo được đúng hai điều kiện đó.
 */
function offboardingGuard(): object
{
    return new class
    {
        use GuardsStaffOffboarding;

        public function openWorkReason(User $user): ?string
        {
            return $this->offboardingOpenWorkReason($user);
        }

        public function leavesNoActiveAdmin(User $user, bool $remainsActiveAdmin): bool
        {
            return $this->wouldLeaveNoActiveAdmin($user, $remainsActiveAdmin);
        }
    };
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('reports no open work for a staff member with nothing outstanding', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    expect(offboardingGuard()->openWorkReason($staff))->toBeNull();
});

/** R7: "thông điệp nêu đúng số lượng" — ba loại việc, đếm đúng từng loại. */
it('names the exact counts of matters, deadlines, and client requests still open', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Kiểm Tra']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $staff->id, 'closed_at' => null]);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $staff->id, 'is_completed' => false]);
    ClientRequest::factory()->for($matter)->create(['assigned_to' => $staff->id, 'status' => ClientRequestStatus::New]);

    $reason = offboardingGuard()->openWorkReason($staff);

    expect($reason)->toBe(__('users.offboarding.open_work_blocked', [
        'name' => 'Luật sư Kiểm Tra',
        'matters' => 1,
        'deadlines' => 1,
        'requests' => 1,
    ]));
});

/**
 * Một vụ ĐÃ ĐÓNG không tính là việc mở (R8) — cùng định nghĩa `OpenWork` dùng cho Task 3.
 */
it('does not count a closed matter as open work', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $staff->id, 'closed_at' => now()]);

    expect(offboardingGuard()->openWorkReason($staff))->toBeNull();
});

/**
 * Vế "không phải admin" của `wouldLeaveNoActiveAdmin()`. Cố ý dùng một hệ thống KHÔNG có admin
 * nào cả (kịch bản mà không màn hình nào tạo ra được, vì phải là admin mới mở được EditUser) —
 * đây chính là kịch bản mutation probe dưới đây cần.
 */
it('never flags a non-admin as the last active admin, even when the whole system has no admin at all', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    expect(offboardingGuard()->leavesNoActiveAdmin($lawyer, remainsActiveAdmin: false))->toBeFalse();
});

/**
 * Vế "không đang hoạt động" của `wouldLeaveNoActiveAdmin()`: một admin ĐÃ bị vô hiệu hoá từ trước
 * không còn được tính là "admin đang hoạt động" — không có gì để bảo vệ ở người này nữa, dù họ là
 * admin CUỐI CÙNG còn tồn tại (đã ngừng hoạt động) trong hệ thống.
 */
it('never flags an already-inactive admin as the last active admin, even when no other admin exists', function () {
    $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);

    expect(offboardingGuard()->leavesNoActiveAdmin($inactiveAdmin, remainsActiveAdmin: false))->toBeFalse();
});

/** Vế dương: một admin đang hoạt động, admin CUỐI CÙNG, đúng là bị chặn. */
it('flags an active admin as the last one when no other active admin exists', function () {
    $onlyAdmin = User::factory()->admin()->create();

    expect(offboardingGuard()->leavesNoActiveAdmin($onlyAdmin, remainsActiveAdmin: false))->toBeTrue();
});

/** Vế dương thứ hai: một admin còn admin khác đang hoạt động thì không bị chặn. */
it('does not flag an active admin when another active admin still exists', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();

    expect(offboardingGuard()->leavesNoActiveAdmin($admin, remainsActiveAdmin: false))->toBeFalse();
});

/** Vế dương thứ ba: khi thay đổi KHÔNG làm mất vai admin (remainsActiveAdmin = true), không bị chặn dù là admin cuối cùng. */
it('does not flag the last admin when the change keeps them an active admin', function () {
    $onlyAdmin = User::factory()->admin()->create();

    expect(offboardingGuard()->leavesNoActiveAdmin($onlyAdmin, remainsActiveAdmin: true))->toBeFalse();
});
