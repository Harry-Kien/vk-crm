<?php

namespace App\Actions\Task;

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
     * `id => tên` cho ô chọn người nhận — {@see self::eligible()}. Khi GIAO LẠI `$replacing`, người đang
     * giữ việc chỉ còn trong danh sách nếu họ đã trả lại (cùng luật {@see ReassignTask}).
     *
     * @return array<int, string>
     */
    public function options(?Matter $matter, User $actor, ?Task $replacing = null): array
    {
        return $this->eligible($matter, $actor)
            ->reject(fn (User $user): bool => $replacing !== null
                && $user->getKey() === $replacing->assignee_id
                && $replacing->status !== TaskStatus::Declined)
            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name])
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

        if ($matter === null) {
            return true;
        }

        return $matter->team()
            ->whereKey($candidate->getKey())
            ->wherePivotIn('role_in_matter', self::workingSeats())
            ->where(fn (Builder $seat): Builder => self::notDeclined($seat))
            ->exists()
            && Gate::forUser($candidate)->allows('view', $matter);
    }

    /** Ghế chưa "Trả lại" — phủ định của `MatterUser::scopeReturned()` trên bảng `matter_user` của quan hệ `team`. */
    private static function notDeclined(Builder $seat): Builder
    {
        return $seat->whereNotNull('matter_user.accepted_at')->orWhereNull('matter_user.declined_at');
    }
}
