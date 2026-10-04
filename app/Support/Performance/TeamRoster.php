<?php

namespace App\Support\Performance;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Định nghĩa DUY NHẤT của "ai được theo dõi" (M13, phán quyết R3) và của "người này có phụ trách
 * vụ việc không" (R6). Ba trang của M13, policy {@see UserPolicy::viewPerformance()} và tác vụ chụp
 * số liệu hằng ngày (Task 7) đều hỏi ở đây; không nơi nào khác viết lại danh sách.
 *
 * # Danh sách phụ thuộc VAI TRÒ, không phụ thuộc vụ việc
 *
 * Trackable = có một vai trò trong {@see self::TRACKED_ROLES} (luật sư, trợ lý, quản lý — vai trò
 * spatie, không phải cột `position`) và chưa xoá mềm. Admin không vào danh sách: giám đốc xem danh
 * sách chứ không nằm trong nó. Kế toán không giữ việc hồ sơ.
 *
 * Không lọc theo "có vụ mà người xem thấy được": một luật sư chỉ phụ trách vụ `restricted` mà biến
 * mất khỏi danh sách của trưởng phòng thì chính sự biến mất đó xác nhận có vụ trưởng phòng không
 * thấy (R4) — chiều ngược của lỗi `RevenueDashboard::filtersForm()` đã sửa.
 *
 * # Người nghỉ việc VẪN trackable, người đã xoá mềm thì không
 *
 * `is_active = false` không làm ai rời danh sách: số của kỳ họ đã làm là của họ, và liên kết tên
 * trên trang "Theo dõi đội ngũ" khi bật công tắc người nghỉ việc không bao giờ dẫn tới 404. Chỉ
 * DANH SÁCH "bây giờ" mặc định bỏ họ ({@see self::members()} với `$includeInactive = false`).
 * Xoá mềm là quyết định của admin rằng tài khoản không còn là một người trong văn phòng; không hàm
 * nào ở đây đảo quyết định đó, kể cả khi bật công tắc. Việc họ từng giữ chỉ còn ở dòng "Chung" (R8).
 *
 * # Đã nạp sẵn vai trò và quyền (R11)
 *
 * Mọi người trả về mang sẵn `roles.permissions` và `permissions` (một lần `with()`), nên hỏi Gate
 * theo từng người (`viewPerformance`, {@see self::leadsMatters()}) không sinh một truy vấn spatie cho
 * mỗi người. `TeamRosterTest` đếm truy vấn với 3 và với 12 người.
 *
 * `subjectsForPeriod()` (danh sách theo kỳ, R3) thêm ở Task 6, khi `PerformancePeriod` đã có.
 */
final class TeamRoster
{
    /** @var list<Role> */
    public const TRACKED_ROLES = [Role::Lawyer, Role::Assistant, Role::Manager];

    /**
     * Những người `$viewer` được xem số liệu: mọi người của {@see self::members()} mà
     * `UserPolicy::viewPerformance()` cho phép. Vì vậy người có `performance.viewAny` nhận cả danh
     * sách R3 ("bây giờ"); người khác chỉ nhận chính mình, nếu chính mình trackable và có
     * `matter.view`; kế toán nhận danh sách rỗng. Luật "ai xem được ai" chỉ nằm ở policy, không viết
     * lại ở đây.
     *
     * @return Collection<int, User> đã nạp sẵn `roles.permissions` và `permissions`, xếp theo tên
     */
    public static function subjectsFor(User $viewer, bool $includeInactive = false): Collection
    {
        $gate = Gate::forUser($viewer);

        return self::members($includeInactive)
            ->filter(fn (User $subject): bool => $gate->allows('viewPerformance', $subject))
            ->values();
    }

    /**
     * Mọi người trackable, không phụ thuộc người xem: đúng tập {@see self::isTrackable()} nói "có"
     * (truy vấn này và hàm đó là hai hình dạng của một luật — vai trò thuộc `TRACKED_ROLES`, chưa
     * xoá mềm qua `SoftDeletes`; `TeamRosterTest` so hai hình dạng trên một quần thể trộn đủ ca).
     * Mặc định chỉ người đang hoạt động; `$includeInactive` thêm người đã nghỉ việc. `subjectsFor()`,
     * `subjectsForPeriod()` (Task 6) và tác vụ chụp (Task 7) gọi lại.
     *
     * @return Collection<int, User> đã nạp sẵn `roles.permissions` và `permissions`, xếp theo tên
     */
    public static function members(bool $includeInactive = false): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', self::trackedRoleNames()))
            ->when(! $includeInactive, fn (Builder $users): Builder => $users->where('is_active', true))
            ->with(['roles.permissions', 'permissions'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Vai trò thuộc `TRACKED_ROLES` và chưa xoá mềm. KHÔNG đọc `is_active`: người nghỉ việc vẫn
     * trackable (R3) — `PerformanceAccessTest` mở trang của một luật sư đã nghỉ việc với trưởng phòng.
     */
    public static function isTrackable(User $subject): bool
    {
        return ! $subject->trashed() && $subject->hasAnyRole(self::trackedRoleNames());
    }

    /**
     * Người này có thể đứng tên luật sư phụ trách vụ việc: có `matter.transitionStage` — đúng quyền
     * mà `CreateMatter::leadLawyerOptions()` dùng để liệt kê người được chọn làm luật sư phụ trách.
     * Trợ lý không có quyền này nên không bao giờ đứng tên `lead_lawyer_id`; các cột "chỉ dành cho
     * người phụ trách vụ" hiện "Không áp dụng" với họ (R6).
     *
     * Theo QUYỀN, không bao giờ theo "người này có vụ nào không": suy từ vụ thì một luật sư chỉ phụ
     * trách vụ `restricted` hiện "Không áp dụng" thay vì 0 với trưởng phòng, tức lộ vụ đó (R4).
     */
    public static function leadsMatters(User $subject): bool
    {
        return $subject->can(Permission::MatterTransitionStage->value);
    }

    /** @return list<string> */
    private static function trackedRoleNames(): array
    {
        return array_map(fn (Role $role): string => $role->value, self::TRACKED_ROLES);
    }
}
