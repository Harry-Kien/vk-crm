<?php

namespace App\Support;

use App\Actions\Matter\TransferStaffWork;
use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Actions\User\SuspendStaffAccess;
use App\Enums\TaskStatus;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Việc NỘI BỘ còn dở của một nhân sự (làn fa2, phán quyết 2) — cùng hàng với {@see OpenWork} (vụ đang dẫn, mốc hạn,
 * yêu cầu của khách) và {@see OpenLeads} (lead đang mở) trong luật nghỉ việc / tạm khoá / xoá / đổi chức danh
 * ({@see GuardsStaffOffboarding}, {@see SuspendStaffAccess}). Tách khỏi `OpenWork` có chủ ý: `OpenWork` còn chặn "Gỡ
 * thành viên" khỏi đội ngũ, nơi việc nội bộ KHÔNG chặn mà được chuyển ngay trong hộp thoại (phán quyết 1).
 *
 *  - `$held`: việc người đó đang giữ — "Chờ nhận" hay "Đang làm" (`Task::scopeHeld()`);
 *  - `$assigned`: việc còn mở (chờ nhận, đang làm, bị trả lại) người đó ĐÃ GIAO cho người khác — rời đi thì không ai
 *    theo dõi, không ai nhận lời "bị trả lại / quá hạn";
 *  - `$pendingSeats`: hồ sơ được giao đang chờ người đó bấm "Đã nhận hồ sơ".
 *
 * Cả ba chỉ đếm việc chung (không thuộc hồ sơ) và việc trên hồ sơ ĐANG MỞ (`Matter::scopeOpen()` — chưa kết thúc,
 * chưa huỷ): đúng tập {@see TransferStaffWork} chuyển được. Việc còn sót trên hồ sơ đã kết thúc hay đã huỷ không ai
 * giao lại được nữa (hồ sơ huỷ: không ai thấy việc), nên đếm nó là chặn nghỉ việc mãi mãi.
 *
 * Không lọc theo "người hỏi xem được gì": đây là bộ đếm của màn hình quản trị nhân sự (chỉ quản trị viên), chỉ đưa ra
 * SỐ LƯỢNG, không mã hay tiêu đề — cùng cách `OpenWork`/`OpenLeads` dùng.
 */
final readonly class OpenTaskWork
{
    /**
     * @param  Collection<int, Task>  $held
     * @param  Collection<int, Task>  $assigned
     * @param  Collection<int, MatterUser>  $pendingSeats
     */
    public function __construct(
        public Collection $held,
        public Collection $assigned,
        public Collection $pendingSeats,
    ) {}

    public static function forUser(User $user): self
    {
        $id = $user->getKey();
        $tasks = fn (): Builder => Task::query()->withoutGlobalScope(ClientPortalScope::class)
            ->where(fn (Builder $where): Builder => $where
                ->whereNull('matter_id')
                ->orWhereHas('matter', fn (Builder $matter): Builder => $matter->withoutGlobalScope(ClientPortalScope::class)->open()));

        return new self(
            held: $tasks()->where('assignee_id', $id)->held()->get(),
            assigned: $tasks()->where('assigned_by', $id)->where('assignee_id', '!=', $id)
                ->whereIn('status', TaskStatus::openValues())->get(),
            pendingSeats: MatterUser::query()->withoutGlobalScope(ClientPortalScope::class)
                ->where('user_id', $id)
                ->awaitingAcceptance()
                ->whereHas('matter', fn (Builder $matter): Builder => $matter->open())
                ->get(),
        );
    }

    public function isEmpty(): bool
    {
        return $this->held->isEmpty() && $this->assigned->isEmpty() && $this->pendingSeats->isEmpty();
    }
}
