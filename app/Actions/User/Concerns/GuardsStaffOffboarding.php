<?php

namespace App\Actions\User\Concerns;

use App\Actions\Matter\RemoveTeamMember;
use App\Enums\Role;
use App\Models\User;
use App\Support\OpenWork;

/**
 * Chặn nghỉ việc khi còn giữ việc dở dang, hoặc khi là quản trị viên đang hoạt động cuối cùng
 * (SPEC §11 "Bàn giao và lưu trữ"; M6.5 Task 4, R7, kéo lên từ M7 R6).
 *
 * Hai luật ĐỘC LẬP, dùng bởi HAI nơi gọi khác nhau:
 *
 *  - {@see UserPolicy::delete()} — vô hiệu hoá (mềm) MỘT tài khoản qua `DeleteAction`/
 *    `DeleteBulkAction`. Cả hai luật đều áp: mất việc dở dang lẫn mất vai admin cuối cùng đều là
 *    hệ quả của xoá.
 *  - {@see EditUser::handleRecordUpdate()} — tắt `is_active` hoặc đổi `position` qua form sửa.
 *    Chỉ luật "còn việc dở dang" áp cho TẮT `is_active` (R7 chỉ liệt "vô hiệu hoá và xoá", không
 *    liệt "đổi chức danh" — một luật sư đổi sang trợ lý vẫn còn nguyên các mốc hạn/vụ việc cũ, chỉ
 *    hết quyền QUẢN LÝ đội ngũ của chúng qua `MatterPolicy::manageTeam()`, việc Task 3 đã lo).
 *    Luật "admin cuối cùng" áp cho CẢ hai đường (tắt `is_active` VÀ đổi chức danh khỏi Quản trị).
 *
 * Trait này KHÔNG tự quyết định đường vào (`Response::deny()` cho policy, `ValidationException`
 * cho form) — nó chỉ trả về LÝ DO tiếng Việt (hoặc `null` khi không có gì chặn), để mỗi nơi gọi
 * gói lại đúng hình dạng exception của nó. Xem lý lẽ đầy đủ ở hai nơi gọi.
 */
trait GuardsStaffOffboarding
{
    /**
     * "Việc còn mở mà người này còn đứng tên" — hỏi {@see OpenWork::forUser()} TRÊN TOÀN HỆ THỐNG
     * (`$matter = null`), khác {@see RemoveTeamMember} (Task 3) chỉ hỏi trong
     * phạm vi MỘT vụ việc. Trả về lý do tiếng Việt nêu ĐÚNG số lượng từng loại (R7: "thông điệp
     * nêu đúng số lượng"), hoặc `null` khi không còn gì dở dang.
     */
    protected function offboardingOpenWorkReason(User $user): ?string
    {
        $openWork = OpenWork::forUser($user);

        if ($openWork->isEmpty()) {
            return null;
        }

        return __('users.offboarding.open_work_blocked', [
            'name' => $user->name,
            'matters' => $openWork->leadMatters->count(),
            'deadlines' => $openWork->deadlines->count(),
            'requests' => $openWork->clientRequests->count(),
        ]);
    }

    /**
     * "Admin đang hoạt động cuối cùng không thể tự hạ chức danh, tự vô hiệu hoá hay tự xoá" (R7).
     *
     * Đọc theo HIỆU ỨNG (còn ai khác giữ vai admin đang hoạt động sau thay đổi này không), không
     * theo "actor có phải chính người này không" — một admin khác đổi chức danh/vô hiệu hoá/xoá
     * đúng người admin cuối cùng còn lại cũng phải bị chặn, vì hệ quả giống hệt: hệ thống mất nốt
     * người quản trị được. `$remainsActiveAdmin` là câu trả lời "sau thay đổi này, $user còn được
     * tính là admin đang hoạt động không" — nơi gọi tự tính (tắt `is_active`, đổi `position` khỏi
     * Admin, hoặc xoá hẳn đều làm câu này thành `false`).
     */
    protected function wouldLeaveNoActiveAdmin(User $user, bool $remainsActiveAdmin): bool
    {
        if (! $user->is_active || ! $user->hasRole(Role::Admin->value)) {
            // Không phải admin đang hoạt động hôm nay — không có gì để bảo vệ ở đây.
            return false;
        }

        if ($remainsActiveAdmin) {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($user->getKey())
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', Role::Admin->value))
            ->exists();
    }

    protected function lastActiveAdminReason(): string
    {
        return __('users.offboarding.last_admin_blocked');
    }
}
