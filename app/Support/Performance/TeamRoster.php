<?php

namespace App\Support\Performance;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

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
 * {@see self::subjectsForPeriod()} (Task 6) là danh sách theo KỲ của trang "Hiệu suất theo kỳ": thêm người
 * đã nghỉ việc trong hoặc sau kỳ (R3).
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

    // ---------------------------------------------------------------------------------------------
    // Task 6 (làn m13b) — danh sách theo KỲ của trang "Hiệu suất theo kỳ" (R3).
    // ---------------------------------------------------------------------------------------------

    /**
     * Những người `$viewer` được xem số liệu TRONG `$period` (R3): người trackable đang hoạt động, cộng
     * người đã nghỉ việc mà lần vô hiệu hoá GẦN NHẤT xảy ra từ 00:00 ngày đầu kỳ trở đi — họ đã làm một
     * phần kỳ đó, số của phần ấy là của họ. Luật sư nghỉ ngày 05/11 vẫn có dòng trong "tháng trước" (tháng
     * 10) mà không cần công tắc. `$includeInactive` ("Gồm người đã nghỉ việc") thêm MỌI người trackable đã
     * nghỉ. Người đã xoá mềm không bao giờ có dòng ({@see self::members()} bỏ họ), kể cả khi bật công tắc.
     *
     * Rồi lọc qua `UserPolicy::viewPerformance()` như {@see self::subjectsFor()}: người có
     * `performance.viewAny` nhận cả danh sách; người khác chỉ nhận chính mình; kế toán nhận rỗng.
     *
     * **Lần vô hiệu hoá đọc từ nhật ký**, vì `users` không có cột ngày nghỉ việc: `User` ghi `is_active`
     * qua `LogsActivity` (`logOnly([... 'is_active'])->logOnlyDirty()`), nên tắt tài khoản sinh một dòng
     * `updated` của chủ thể `user` mang `attributes.is_active = false` (và `old.is_active = true`). MỘT truy
     * vấn cho mọi người đang nghỉ (vài chục dòng), lọc bằng PHP — không truy vấn JSON (khác nhau giữa
     * SQLite và MariaDB).
     * Người nghỉ việc không có dòng nào như vậy (dữ liệu trước khi có nhật ký) coi như nghỉ trước mọi kỳ.
     * Ai bỏ `is_active` khỏi `logOnly()` của `User` thì người nghỉ việc biến khỏi kỳ họ đã làm —
     * `PerformancePageTest` ("deactivated on 5 November") đỏ.
     *
     * Tệp này là ngoại lệ có tên của `NoSecondDefinitionTest`: được viết điều kiện trên `is_active`,
     * `deleted_at` của `users` và trên `event`, `subject_type`, `created_at` của dòng nhật ký vô hiệu hoá.
     *
     * @return Collection<int, User> đã nạp sẵn `roles.permissions` và `permissions`, xếp theo tên
     */
    public static function subjectsForPeriod(User $viewer, PerformancePeriod $period, bool $includeInactive = false): Collection
    {
        $gate = Gate::forUser($viewer);
        $everyone = self::members(includeInactive: true);
        $leftDuringPeriod = self::deactivatedSince($everyone->reject(fn (User $user): bool => $user->is_active), $period->from);

        return $everyone
            ->filter(fn (User $subject): bool => $includeInactive || $subject->is_active || isset($leftDuringPeriod[$subject->getKey()]))
            ->filter(fn (User $subject): bool => $gate->allows('viewPerformance', $subject))
            ->values();
    }

    /**
     * Trong `$inactive`, những người mà lần vô hiệu hoá GẦN NHẤT xảy ra không sớm hơn `$since`. Một truy
     * vấn, kể cả khi `$inactive` rỗng (khi đó `whereIntegerInRaw` thành `0 = 1`).
     *
     * @param  Collection<int, User>  $inactive
     * @return array<int, true> khoá là id người
     */
    private static function deactivatedSince(Collection $inactive, CarbonInterface $since): array
    {
        $lastDeactivation = [];

        Activity::query()
            ->select(['id', 'subject_id', 'properties', 'created_at'])
            ->where('event', 'updated')
            ->where('subject_type', (new User)->getMorphClass())
            ->whereIntegerInRaw('subject_id', $inactive->map(fn (User $user): int => (int) $user->getKey())->all())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (Activity $row) use (&$lastDeactivation): void {
                if (self::isDeactivation($row)) {
                    $lastDeactivation[(int) $row->subject_id] = $row->created_at;
                }
            });

        return array_map(
            fn (): bool => true,
            array_filter($lastDeactivation, fn (CarbonInterface $at): bool => $at->gte($since)),
        );
    }

    /**
     * Dòng `updated` đặt `is_active` thành tắt. `User` ghi nhật ký `logOnlyDirty()`, nên khoá
     * `attributes.is_active` chỉ có mặt khi cột thật sự đổi — `false` ở đó nghĩa là đi từ bật sang tắt.
     * `is_active` cast boolean, nên nhật ký ghi `false` JSON. Dòng đổi cột khác (tên, điện thoại) không
     * mang khoá này và không phải một lần vô hiệu hoá.
     */
    private static function isDeactivation(Activity $row): bool
    {
        return data_get($row->properties?->all() ?? [], 'attributes.is_active') === false;
    }
}
