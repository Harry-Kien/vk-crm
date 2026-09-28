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

/**
 * Câu ghép của `offboardingOpenWorkReason()` (fix round 1, finding CRITICAL) — mỗi mảnh CHỈ góp
 * mặt khi đúng loại việc đó còn ( > 0), và mỗi mảnh nêu đúng màn hình xử lý được loại việc đó:
 * "Bàn giao" cho vụ việc lead, "Đổi người phụ trách" cho mốc hạn, "Giao việc" cho yêu cầu khách.
 * Dùng chung ở mọi tệp test đọc thông điệp này, để không có hai bản sao của cùng một phép ghép.
 */
function offboardingMessage(string $name, int $matters, int $deadlines, int $requests): string
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

    expect($reason)->toBe(offboardingMessage('Luật sư Kiểm Tra', matters: 1, deadlines: 1, requests: 1));
});

/**
 * CRITICAL (fix round 1): một người CHỈ còn đứng tên mốc hạn (không lead vụ nào, không được giao
 * yêu cầu khách nào) phải đọc đúng đường ra của LOẠI VIỆC đó — "Đổi người phụ trách" — và KHÔNG
 * thấy nhắc tới "Bàn giao" (nút đó không đổi được `responsible_user_id` của họ, xem docblock
 * `ChangeDeadlineResponsible`). Đây là chính kịch bản mà bản thông điệp trước fix round 1 nói sai.
 */
it('names only the deadline path for someone who holds no lead matter at all', function () {
    $staff = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Chỉ Có Mốc']);
    $matter = Matter::factory()->create(['closed_at' => null]);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $staff->id, 'is_completed' => false]);

    $reason = offboardingGuard()->openWorkReason($staff);

    expect($reason)->toBe(offboardingMessage('Trợ lý Chỉ Có Mốc', matters: 0, deadlines: 1, requests: 0))
        ->and($reason)->toContain(__('deadlines.tab.actions.change_responsible'))
        ->and($reason)->not->toContain('"Bàn giao"');
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
