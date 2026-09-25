<?php

namespace App\Policies;

use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Enums\Permission;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Quản trị nhân sự nội bộ (SPEC §7.4, §4.1) — không có quyền riêng ở bảng SPEC §5, dùng
 * settings.manage (chỉ admin) vì đây cùng nhóm với quản trị Role/cấu hình hệ thống.
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
