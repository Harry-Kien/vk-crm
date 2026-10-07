<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\ConflictOverride;

/**
 * Quyền trên một lần có người liên hệ văn phòng (M10, SPEC §5 đính chính 2026-09-24, R9).
 *
 * **Một định nghĩa "thấy được"**: {@see IntakeRequest::isVisibleTo()} / `scopeVisibleTo()` — quyền
 * `intake.viewAny` thấy mọi bản ghi; người chỉ có `intake.create` thấy bản ghi MÌNH ghi hoặc được
 * giao; kế toán không thấy gì. `view`, `update`, `convert`, `viewConflictReason`, `resolveConflict`
 * đều đi qua đó, để resource, widget và báo cáo (Task 3, 5, 6) dùng đúng một luật.
 *
 * **Bản ghi đã chuyển thành vụ `restricted` chỉ thấy được với người xem được vụ đó** (admin, luật
 * sư phụ trách còn `matter.view`) — bản ghi mang tên khách, câu chuyện và liên kết vụ, nên nếu không
 * thì `intake.viewAny` (hay việc đã ghi/được giao bản ghi) là cửa hậu vào vụ hạn chế. Luật đó nằm
 * trong `isVisibleTo()`/`scopeVisibleTo()`, không lặp ở đây: mọi ability đọc "thấy được" thừa hưởng.
 *
 * **Ba ability KHÔNG đọc cùng một thứ, và có chủ đích:**
 *  - `viewConflictReason` (R8 — lý do từ chối vì xung đột là loại nhạy cảm: nói lý do là tiết lộ có
 *    tồn tại một khách hàng khác) đọc QUYỀN `intake.viewAny`, đúng bảng R9.
 *  - `resolveConflict` (xử lý Đỏ, từ chối vì xung đột) đọc VAI qua {@see ConflictOverride::allowedFor()}
 *    — MỘT định nghĩa "ai ghi đè Đỏ" cho cả hệ thống, cùng cổng `OpenMatter`/`AddMatterParty`, cộng
 *    với xem được bản ghi. R9 xếp "xử lý Đỏ" dưới `intake.viewAny` còn R1/R8 nói "quản lý hoặc
 *    admin, cùng quy tắc ghi đè Đỏ của OpenMatter": hôm nay hai luật trùng người (manager, admin)
 *    nhưng là hai định nghĩa, và lựa chọn của làn là không tạo định nghĩa thứ hai (ghi ở Ghi chú M10).
 *  - `erase` (xoá theo yêu cầu, R7c) chỉ vai admin, cùng thành ngữ `ClientPolicy::delete()`. Việc
 *    "bản ghi đã chuyển đổi thì từ chối" là luật của Action (Task 7), không phải của policy.
 *
 * **Không ai xoá một bản ghi tiếp nhận** (xoá là ẩn danh, R7): `delete`, `restore`, `forceDelete` và
 * ba bản `*Any` luôn false — Filament coi ability thiếu phương thức là CHO PHÉP, nên phải khai đủ.
 *
 * **`ClientUser` luôn bị từ chối, mọi ability, không ngoại lệ** — khớp `applyClientPortalConstraints()`
 * của model (chặn `1 = 0` vĩnh viễn). `PortalCoverageTest` đòi mọi model dùng `RestrictedToClientPortal`
 * có một policy — đây là policy đó.
 */
class IntakeRequestPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User
            && ($user->can(Permission::IntakeCreate->value) || $user->can(Permission::IntakeViewAny->value));
    }

    public function view(User|ClientUser $user, IntakeRequest $intake): bool
    {
        return $user instanceof User && $intake->isVisibleTo($user);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::IntakeCreate->value);
    }

    /** Đổi trạng thái, giao việc, sửa phần danh tính: cùng tầm nhìn với `view` (R9). */
    public function update(User|ClientUser $user, IntakeRequest $intake): bool
    {
        return $this->view($user, $intake);
    }

    /**
     * Chuyển thành vụ việc (R3): cần `intake.convert` VÀ `matter.create` (trợ lý không có
     * `matter.create`, nên không chuyển đổi), và phải thấy bản ghi — một luật sư không chuyển đổi
     * được bản ghi của người khác mà họ không xem được.
     */
    public function convert(User|ClientUser $user, IntakeRequest $intake): bool
    {
        return $user instanceof User
            && $user->can(Permission::IntakeConvert->value)
            && $user->can(Permission::MatterCreate->value)
            && $intake->isVisibleTo($user);
    }

    /**
     * R8: lý do từ chối vì xung đột chỉ người có `intake.viewAny` thấy; người khác thấy "Đã từ chối".
     * Và chỉ trên bản ghi mình thấy được: bản ghi đã chuyển thành vụ `restricted` mà người này không
     * xem được thì lý do (tiết lộ có khách hàng khác) cũng không hiện.
     */
    public function viewConflictReason(User|ClientUser $user, IntakeRequest $intake): bool
    {
        return $user instanceof User
            && $user->can(Permission::IntakeViewAny->value)
            && $intake->isVisibleTo($user);
    }

    /** Xử lý Đỏ / từ chối vì xung đột — xem docblock lớp về lý do đọc VAI. */
    public function resolveConflict(User|ClientUser $user, IntakeRequest $intake): bool
    {
        return $user instanceof User
            && ConflictOverride::allowedFor($user)
            && $intake->isVisibleTo($user);
    }

    /** Xoá dữ liệu theo yêu cầu của chủ thể (R7c): chỉ admin. */
    public function erase(User|ClientUser $user, IntakeRequest $intake): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    public function delete(User|ClientUser $user, IntakeRequest $intake): bool
    {
        return false;
    }

    public function deleteAny(User|ClientUser $user): bool
    {
        return false;
    }

    public function restore(User|ClientUser $user, IntakeRequest $intake): bool
    {
        return false;
    }

    public function restoreAny(User|ClientUser $user): bool
    {
        return false;
    }

    public function forceDelete(User|ClientUser $user, IntakeRequest $intake): bool
    {
        return false;
    }

    public function forceDeleteAny(User|ClientUser $user): bool
    {
        return false;
    }
}
