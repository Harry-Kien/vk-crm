<?php

namespace App\Actions\Matter;

use App\Actions\Matter\Concerns\OpensMatterSeat;
use App\Enums\MatterAssignmentStatus;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * "Đã nhận hồ sơ" (làn tk, Task 2) — người vừa được thêm vào đội ngũ một vụ, hay vừa nhận bàn giao làm
 * luật sư phụ trách, xác nhận đã nhận: MỘT cú bấm, không hỏi gì thêm. Chỉ chính người ngồi ghế đó, khi
 * ghế đang chờ nhận ({@see MatterUser::assignmentStatus()}); khoá vụ trước, ghế sau
 * ({@see OpensMatterSeat}).
 *
 * Bấm lần hai (hai tab, bấm đúp) khi ghế đã nhận: trả về ghế, không ghi thêm dòng nhật ký nào, không báo
 * lần hai. Ghế đã "Trả lại" thì không nhận lại được ở đây — người giao đã được báo và có thể đang phân
 * công người khác; người nhận trao đổi với họ (câu từ chối nói tình trạng hiện tại).
 *
 * Người giao (nếu còn hoạt động và còn xem được vụ — job lọc lại) nhận một chuông `accepted`, không thư.
 */
class AcceptMatterAssignment
{
    use OpensMatterSeat;

    public function handle(Matter $matter, User $actor): MatterUser
    {
        return DB::transaction(function () use ($matter, $actor): MatterUser {
            [$locked, $seat] = $this->openSeat($matter, $actor);

            $status = $seat->assignmentStatus();

            if ($status === MatterAssignmentStatus::Accepted) {
                return $seat;
            }

            if ($status !== MatterAssignmentStatus::Pending) {
                $this->refuseSeatState($status);
            }

            $seat->forceFill(['accepted_at' => now()])->save();

            $activity = Audit::record('matter_assignment_accepted', $locked, [
                'user_id' => $actor->getKey(),
                'role' => $seat->role_in_matter->value,
            ], causer: $actor);

            if ($seat->assigned_by !== $actor->getKey()) {
                $this->queueSeatNotice($locked, $actor->getKey(), MatterAssignmentAlert::ACCEPTED, $seat->assigned_by, $activity);
            }

            return $seat;
        });
    }
}
