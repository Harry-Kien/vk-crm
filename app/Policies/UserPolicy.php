<?php

namespace App\Policies;

use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Enums\Permission;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Performance\TeamRoster;
use Illuminate\Auth\Access\Response;

/**
 * Quản trị nhân sự nội bộ (SPEC §7.4, §4.1) — không có quyền riêng ở bảng SPEC §5, dùng
 * settings.manage (chỉ admin) vì đây cùng nhóm với quản trị Role/cấu hình hệ thống.
 *
 * Ngoại lệ duy nhất: hai câu hỏi của M13 về SỐ LIỆU của một nhân sự ({@see self::viewPerformance()},
 * {@see self::viewPerformanceRevenue()}) đi theo quyền riêng `performance.viewAny` (SPEC §5, bổ sung
 * 2026-10-04), không theo `settings.manage`.
 */
class UserPolicy
{
    use GuardsStaffOffboarding;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function view(User|ClientUser $user, User $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|ClientUser $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User|ClientUser $user, User $model): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Xoá (mềm) một tài khoản nhân sự — R7 (M6.5 Task 4, kéo lên từ M7 R6; finding `roles/roles-07`):
     * bị chặn khi người đó còn việc mở ({@see GuardsStaffOffboarding::offboardingOpenWorkReason()},
     * hỏi TRÊN TOÀN HỆ THỐNG — khác `RemoveTeamMember` Task 3 chỉ hỏi trong một vụ việc).
     *
     * Trả `Response::deny($reason)` thay vì `bool` — cùng thành ngữ `ClientPolicy::delete()`:
     * `EditUser` bật `authorizationNotification()` cho đúng `DeleteAction` này, nên lý do (kèm số
     * vụ/mốc hạn/yêu cầu, R7 "thông điệp nêu đúng số lượng") là thứ admin đọc được ngay khi bấm,
     * thay vì một nút biến mất không lời giải thích. `UsersTable::toolbarActions()` gọi
     * `authorizeIndividualRecords('delete')` để MỖI bản ghi đã chọn trong một lượt xoá hàng loạt
     * cũng đi qua đúng hàm này — không chỉ qua `deleteAny()` (cổng thô của cái nút).
     *
     * **KHÔNG hỏi lại `wouldLeaveNoActiveAdmin()` ở đây — có chủ đích, không phải bỏ sót.** R7 nói
     * "tự xoá" (phản thân): dòng `! $user->isNot($model)` ngay trên đã chặn TUYỆT ĐỐI mọi lần tự
     * xoá, với BẤT KỲ ai, không riêng admin — nên "admin cuối cùng tự xoá" đã là `false` VÔ ĐIỀU
     * KIỆN từ trước Task 4. Và một admin KHÁC xoá đúng admin cuối cùng còn lại là một tình huống
     * không tồn tại được: chỉ ai có `settings.manage` (tức LÀ admin) mới qua được cổng `viewAny()`
     * phía trên, nên actor — nếu khác `$model` — TỰ NÓ đã là một admin đang hoạt động còn lại sau
     * khi `$model` bị xoá. Thêm một nhánh `wouldLeaveNoActiveAdmin()` ở đây sẽ là mã chết: không
     * kịch bản nào gọi tới được nó, và một mutation probe xoá nhánh đó đi sẽ không có test nào đỏ —
     * đúng thứ CLAUDE.md cấm ("một test âm không có cặp dương đi kèm thì không tính", áp ngược lại
     * cho một NHÁNH không test nào chạm tới được). Nhánh này CÓ ý nghĩa thật ở
     * {@see EditUser::handleRecordUpdate()}, nơi TỰ SỬA
     * (khác tự XOÁ) vẫn được phép — admin duy nhất tự đổi chức danh hay tự tắt `is_active` không
     * bị chặn ở tầng nào khác, nên chỗ đó thật sự cần hỏi lại.
     *
     * **Tiền đề "chỉ admin có settings.manage" được ghim bằng test** (fix round 1, minor) —
     * `RolesAndPermissionsTest.php`, "grants settings.manage to admin only, pinning the premise
     * UserPolicy::delete() relies on". Nếu bảng quyền SPEC §5 từng đổi để một vai trò khác có
     * `settings.manage`, test đó đỏ TRƯỚC KHI lập luận ở trên kịp âm thầm sai.
     */
    public function delete(User|ClientUser $user, User $model): bool|Response
    {
        if (! ($user instanceof User && $this->viewAny($user) && $user->isNot($model))) {
            return false;
        }

        $openWorkReason = $this->offboardingOpenWorkReason($model);

        if ($openWorkReason !== null) {
            return Response::deny($openWorkReason);
        }

        return true;
    }

    /**
     * "Đặt lại 2FA" (R2, kế hoạch M8 Task 2) — nút trên `EditUser`, tên khớp
     * `App\Actions\User\ResetStaffTwoFactor` ({@see EditUser}).
     * Cùng cổng `settings.manage` với mọi thao tác quản trị nhân sự khác, CỘNG một điều kiện riêng:
     * `$user->isNot($model)` — không tự đặt lại 2FA của chính mình (Action cũng tự chặn lại, phòng
     * thủ hai lớp — cùng thành ngữ `EditProfile`/`EditClientUser::unlockLogin`). `HeaderActionsAreReachableTest`
     * đòi TÊN action trùng tên một phương thức policy — đây là phương thức đó.
     */
    public function resetTwoFactor(User|ClientUser $user, User $model): bool
    {
        return $user instanceof User && $this->viewAny($user) && $user->isNot($model);
    }

    /**
     * M8 Task 3 (SPEC §10.3): "Mở khoá đăng nhập" của nhân sự
     * (`App\Actions\User\UnlockStaffLogin`, nút `unlockLogin` ở {@see EditUser}). CHỈ quản trị
     * viên — cùng cổng `settings.manage` (`viewAny()`) với mọi thao tác quản trị nhân sự khác. Không
     * có điều kiện `isNot($model)` như `resetTwoFactor()`: mở khoá KHÔNG nới một quyền nào, và một
     * admin đang bị khoá không tự bấm được (họ không vào được panel) nên không có đường tự-mở-khoá.
     * `HeaderActionsAreReachableTest` đòi TÊN action trùng tên một phương thức policy.
     */
    public function unlockLogin(User|ClientUser $user, User $model): bool
    {
        return $user instanceof User && $this->viewAny($user);
    }

    /**
     * Cổng THÔ của `DeleteBulkAction` trên `ListUsers` (carry-over từ rà soát Task 2, C1-class
     * hole): Filament tự hỏi `deleteAny` cho nút xoá hàng loạt, và một ability KHÔNG có phương
     * thức tương ứng trên policy được coi là CHO PHÉP khi không ở chế độ nghiêm ngặt (mặc định dự
     * án) — thiếu hàm này, MỌI người vào được trang danh sách bấm xoá hàng loạt trót lọt, bỏ qua
     * cả `settings.manage` lẫn luật `delete()` ở trên. Luật THẬT cho từng bản ghi vẫn nằm nguyên ở
     * {@see self::delete()}; `UsersTable` gọi `authorizeIndividualRecords('delete')` để mỗi dòng
     * đi qua đúng hàm đó trước khi bị xoá — không lặp lại luật ở đây.
     */
    public function deleteAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $this->viewAny($user);
    }

    /** Cùng cổng với viewAny(): không có màn hình khôi phục riêng, nhưng RestoreBulkAction cần một ability thật để không rơi vào "thiếu ability = cho phép". */
    public function restore(User|ClientUser $user, User $model): bool
    {
        return $this->viewAny($user);
    }

    /** Cổng THÔ của `RestoreBulkAction` — cùng lý do {@see self::deleteAny()}. */
    public function restoreAny(User|ClientUser $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * M13 (R2, SPEC §5 bổ sung 2026-10-04): `$viewer` được xem số liệu theo dõi và hiệu suất của
     * `$subject` — trang `TeamMember` (`/team/{user}`), dòng của người đó trên "Hiệu suất theo kỳ",
     * và danh sách {@see TeamRoster::subjectsFor()}.
     *
     * (có `performance.viewAny` — admin, quản lý — HOẶC `$viewer` chính là `$subject` và có
     * `matter.view` — số của chính mình không cần quyền mới) VÀ `$subject` trackable
     * ({@see TeamRoster::isTrackable()}: vai trò luật sư/trợ lý/quản lý, chưa xoá mềm; người nghỉ
     * việc VẪN trackable). Kế toán không có cả hai vế nên "không gì cả", kể cả số của chính mình.
     * Admin không trackable, nên `/team/{admin}` là cùng một 404 với id không tồn tại.
     *
     * Không đọc vụ việc nào: quyền xem MỘT NGƯỜI không phụ thuộc người đó phụ trách vụ gì (R3, R4).
     */
    public function viewPerformance(User|ClientUser $viewer, User $subject): bool
    {
        if (! $viewer instanceof User) {
            return false;
        }

        $mayView = $viewer->can(Permission::PerformanceViewAny->value)
            || ($viewer->is($subject) && $viewer->can(Permission::MatterView->value));

        return $mayView && TeamRoster::isTrackable($subject);
    }

    /**
     * Cột doanh thu (P7) của `$subject` hiện cho `$viewer` (R2): `$viewer` có `billing.view` VÀ (có
     * `revenue.viewAny` HOẶC là chính `$subject`) — luật sư thấy doanh thu của chính mình như trang
     * Doanh thu cho họ thấy tiền của vụ mình; trợ lý không có `billing.view` nên không thấy cột này.
     *
     * Cộng thêm điều kiện {@see self::viewPerformance()}: cột nằm TRÊN dòng của một người, nên không
     * ai thấy tiền của một người mà mình không được xem số liệu. Vế này là thứ giữ kế toán ở "không gì
     * cả" (R2, câu hỏi mở 8): kế toán có `billing.view` và `revenue.viewAny`, câu hỏi "doanh thu theo
     * luật sư" của họ đã có ở trang Doanh thu.
     */
    public function viewPerformanceRevenue(User|ClientUser $viewer, User $subject): bool
    {
        return $viewer instanceof User
            && $this->viewPerformance($viewer, $subject)
            && $viewer->can(Permission::BillingView->value)
            && ($viewer->can(Permission::RevenueViewAny->value) || $viewer->is($subject));
    }

    /** Không ai xoá vĩnh viễn một tài khoản nhân sự được — cùng luật `ClientPolicy::forceDelete()`. */
    public function forceDelete(User|ClientUser $user, User $model): bool
    {
        return false;
    }

    /** Cổng THÔ của `ForceDeleteBulkAction` — cùng lý do {@see self::deleteAny()}, luôn từ chối. */
    public function forceDeleteAny(User|ClientUser $user): bool
    {
        return false;
    }
}
