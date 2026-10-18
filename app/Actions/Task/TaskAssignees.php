<?php

namespace App\Actions\Task;

use App\Actions\Performance\BuildTeamWorkload;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * "Ai nhận được việc này" — MỘT luật, dùng ở hai chỗ: ô chọn người nhận của màn hình
 * ({@see self::eligible()}) và lần hỏi lại DƯỚI KHOÁ của {@see AssignTask}/{@see ReassignTask}
 * ({@see self::qualifies()}). Màn hình chỉ bày đúng tập này; Action không tin màn hình và hỏi lại.
 *
 *  - Việc chung: mọi nhân sự đang hoạt động, chưa xoá mềm.
 *  - Việc của một vụ: thành viên đội ngũ GIỮ VIỆC (ghế luật sư phụ trách, luật sư cộng sự, trợ lý — không
 *    "người theo dõi"), đang hoạt động, chưa xoá mềm, VÀ mở được vụ (`MatterPolicy::view`). Vế cuối là thứ
 *    giữ vụ `restricted` kín: một thành viên khác lead còn sót trong đội ngũ của vụ hạn chế không mở được
 *    vụ, nên không nhận được việc của nó (tiêu đề việc không bao giờ tới tay họ).
 *  - Vòng sửa cuối 1 (F3): người đã "Trả lại" chính ghế của mình trong vụ (quá tải, xung đột lợi ích…) không
 *    nhận được việc mới của vụ đó — cùng vế `seat_declined` của `Task::scopeNeedsReassignment()`, nơi việc
 *    họ còn giữ hiện "Cần giao lại". Ghế còn chờ nhận thì vẫn nhận được việc.
 *
 * Không ai tự giao việc cho chính mình: người giao không có trong danh sách, và Action từ chối riêng.
 */
class TaskAssignees
{
    /** @return list<string> ghế giữ việc — {@see Task::workingSeats()} */
    public static function workingSeats(): array
    {
        return Task::workingSeats();
    }

    /**
     * Người nhận bày ra trong ô chọn, theo tên. Chỉ hai cột (`id`, `name`) — email, SĐT của đồng nghiệp
     * không đi vào HTML của một ô chọn.
     *
     * @return Collection<int, User>
     */
    public function eligible(?Matter $matter, User $actor): Collection
    {
        $candidates = $matter === null
            ? User::query()->where('is_active', true)->orderBy('name')->get(['users.id', 'users.name', 'users.is_active', 'users.deleted_at'])
            : $matter->team()
                ->wherePivotIn('role_in_matter', self::workingSeats())
                ->where(fn (Builder $seat): Builder => self::notDeclined($seat))
                ->where('users.is_active', true)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.is_active', 'users.deleted_at']);

        return $candidates
            ->reject(fn (User $user): bool => $user->is($actor))
            ->filter(fn (User $user): bool => $matter === null || Gate::forUser($user)->allows('view', $matter))
            ->values();
    }

    /**
     * `id => nhãn` cho ô chọn người nhận — {@see self::eligible()}. Khi GIAO LẠI `$replacing`, người đang
     * giữ việc chỉ còn trong danh sách nếu họ đã trả lại (cùng luật {@see ReassignTask}).
     *
     * Nhãn (làn fa1, phán quyết 3): tên, kèm số việc người đó đang giữ (chờ nhận, đang làm — `Task::held()`) và
     * số việc quá hạn (`Task::overdueToHandle()`), để người giao cân khối lượng — CHỈ đếm việc `$actor` xem được
     * (`Task::visibleTo($actor)`): việc của một vụ hạn chế người giao không mở được không bao giờ lộ qua con số. Vì
     * vậy câu chữ nói đúng phạm vi đó ("… việc anh/chị thấy được", "không có việc nào anh/chị thấy được" — vòng sửa 1,
     * I2), không như một sự thật tuyệt đối về đồng nghiệp; ô chọn thêm câu gợi ý `tasks.assign.workload_scope`
     * (`ProvidesTaskActions::assigneeHelp()`).
     *
     * @return array<int, string>
     */
    public function options(?Matter $matter, User $actor, ?Task $replacing = null): array
    {
        $people = $this->eligible($matter, $actor)
            ->reject(fn (User $user): bool => $replacing !== null
                && $user->getKey() === $replacing->assignee_id
                && $replacing->status !== TaskStatus::Declined);

        if ($people->isEmpty()) {
            return [];
        }

        $ids = $people->modelKeys();
        $held = BuildTeamWorkload::countPer(Task::query()->visibleTo($actor)->held()->whereIn('tasks.assignee_id', $ids), 'tasks.assignee_id');
        $overdue = BuildTeamWorkload::countPer(Task::query()->visibleTo($actor)->overdueToHandle()->whereIn('tasks.assignee_id', $ids), 'tasks.assignee_id');

        return $people
            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name.' — '.(($held[$user->getKey()] ?? 0) === 0
                ? __('tasks.assign.workload_none')
                : __('tasks.assign.workload', ['held' => $held[$user->getKey()], 'overdue' => $overdue[$user->getKey()] ?? 0]))])
            ->all();
    }

    /**
     * Các vụ `$actor` giao việc được (làn fa1, phán quyết 3 — ô "Vụ việc" của nút "Giao việc" trên trang "Công
     * việc"): vụ đang mở mà `TaskPolicy::assignOnMatter` cho phép (luật sư phụ trách/cộng sự của vụ, quản lý, quản
     * trị; không trợ lý), theo mã. Truy vấn lọc trước đúng các vế của cổng đó (vụ mở, xem được, có `matter.update`,
     * ghế luật sư phụ trách/cộng sự với người không phải quản lý/quản trị); rồi mọi vụ còn lại hỏi cổng — một luật.
     * Nhãn: mã — tiêu đề vụ (người giao xem được vụ). `AssignTask` hỏi lại dưới khoá.
     *
     * @return array<int, string>
     */
    public function assignableMatters(User $actor): array
    {
        if (! $actor->can(Permission::MatterUpdate->value)
            || (! Task::isSupervisor($actor) && $actor->hasRole(Role::Assistant->value))) {
            return [];
        }

        return Matter::query()
            ->open()
            ->listableBy($actor)
            ->when(! Task::isSupervisor($actor), fn (Builder $mine): Builder => $mine->where(fn (Builder $seat): Builder => $seat
                ->where('matters.lead_lawyer_id', $actor->getKey())
                ->orWhereHas('team', fn (Builder $team): Builder => $team
                    ->whereKey($actor->getKey())
                    ->whereIn('matter_user.role_in_matter', [MatterRole::Lead->value, MatterRole::Associate->value]))))
            ->orderBy('matters.code')
            ->get()
            ->filter(fn (Matter $matter): bool => Gate::forUser($actor)->allows('assignOnMatter', [Task::class, $matter]))
            ->mapWithKeys(fn (Matter $matter): array => [$matter->getKey() => $matter->code.' — '.$matter->title])
            ->all();
    }

    /**
     * `$candidate` đã được caller KHOÁ dòng và đọc lại (kể cả đã xoá mềm — `withTrashed()`), nên
     * `is_active`/`trashed()` ở đây là giá trị hiện hành.
     */
    public function qualifies(User $candidate, ?Matter $matter): bool
    {
        if (! $candidate->is_active || $candidate->trashed()) {
            return false;
        }

        return $matter === null || $this->holdsSeat($candidate, $matter);
    }

    /**
     * Vế "của vụ" của {@see self::qualifies()}, không hỏi `is_active`: `$member` ngồi một ghế giữ việc của
     * `$matter`, chưa trả lại ghế đó, và mở được vụ. `TaskPolicy` (nút "Đã nhận" / "Hoàn thành" — vòng sửa
     * polish 1) hỏi đúng vế này: policy không đọc `is_active` (tài khoản bị vô hiệu thì Action từ chối dưới
     * khoá, bằng một câu tiếng Việt).
     */
    public function holdsSeat(User $member, Matter $matter): bool
    {
        return $matter->team()
            ->whereKey($member->getKey())
            ->wherePivotIn('role_in_matter', self::workingSeats())
            ->where(fn (Builder $seat): Builder => self::notDeclined($seat))
            ->exists()
            && Gate::forUser($member)->allows('view', $matter);
    }

    /** Ghế chưa "Trả lại" — phủ định của `MatterUser::scopeReturned()` trên bảng `matter_user` của quan hệ `team`. */
    private static function notDeclined(Builder $seat): Builder
    {
        return $seat->whereNotNull('matter_user.accepted_at')->orWhereNull('matter_user.declined_at');
    }
}
