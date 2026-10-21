<?php

namespace App\Actions\Matter;

use App\Actions\Matter\Concerns\OpensMatterSeat;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\MatterAssignmentStatus;
use App\Jobs\SendSeatReturnedTaskNotices;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Trả lại" hồ sơ (làn tk, Task 2) — người vừa được giao một ghế trong đội ngũ vụ (thêm vào đội ngũ, hay
 * nhận bàn giao làm luật sư phụ trách) báo không nhận, BẮT BUỘC kèm lý do (quá tải, xung đột lợi ích…).
 * Chỉ khi ghế đang chờ nhận; khoá vụ trước, ghế sau ({@see OpensMatterSeat}).
 *
 * # Không tự gỡ ai khỏi vụ
 *
 * Ghế ở lại, mang mốc và lý do trả lại (`declined_at`, `decline_reason`); vụ không bao giờ rơi vào tình
 * trạng không có luật sư phụ trách hay mất người đang giữ mốc hạn vì một cú bấm. Người giao và người quản
 * lý được báo, "Theo dõi đội ngũ" hiện ghế là "cần phân công lại", và họ xử lý bằng các nút sẵn có (gỡ
 * thành viên, bàn giao vụ cho người khác — lần giao mới ghi lại mốc, xoá mốc trả lại cũ).
 *
 * # Ai được báo: người giao, luật sư phụ trách, người giám sát vụ
 *
 * Chuông + thư `staff.task.matter.declined` kèm lý do, cho: người đã giao ghế (`assigned_by`), luật sư
 * phụ trách hiện tại (khi người trả lại không phải chính họ), và người giám sát vụ
 * ({@see ResolveStaffRecipients::supervisorsFor()} — quản lý được xem vụ, hay admin với vụ hạn chế).
 * Không ai nhận hai lần; người trả lại không tự báo mình. Mỗi người một job, xếp sau commit; job hỏi lại
 * "còn hoạt động và còn xem được vụ" khi chạy — một vụ hạn chế không bao giờ báo cho người không mở được
 * nó.
 *
 * # Người giao các việc người đó đang giữ (sửa sau kiểm toán 2026-10-21, mục 7)
 *
 * Người trả lại ghế còn giữ việc nội bộ trên hồ sơ (chờ nhận, đang làm) thì các việc đó hiện "Cần giao lại" (vế
 * `seat_declined`). Trước bản sửa chỉ người giao GHẾ được báo; người giao từng VIỆC (một luật sư cộng sự khác…) không
 * biết. Nay {@see SendSeatReturnedTaskNotices} báo mỗi người giao một chuông gộp "n việc trên hồ sơ … cần giao lại" —
 * trừ người trả lại (việc riêng) và những người đã nhận lời báo trả lại ghế ở trên.
 *
 * Bấm lần hai khi ghế đã "Trả lại": trả về ghế, không ghi thêm gì. Ghế đã nhận thì không trả lại ở đây —
 * người đó trao đổi với luật sư phụ trách hay quản lý.
 */
class DeclineMatterAssignment
{
    use OpensMatterSeat;

    public function handle(Matter $matter, User $actor, string $reason): MatterUser
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [__('tasks.validation.reason_required')]]);
        }

        if (mb_strlen($reason) > MatterUser::DECLINE_REASON_MAX_LENGTH) {
            throw ValidationException::withMessages(['reason' => [__('tasks.validation.too_long', ['max' => MatterUser::DECLINE_REASON_MAX_LENGTH])]]);
        }

        return DB::transaction(function () use ($matter, $actor, $reason): MatterUser {
            [$locked, $seat] = $this->openSeat($matter, $actor);

            $status = $seat->assignmentStatus();

            if ($status === MatterAssignmentStatus::Declined) {
                return $seat;
            }

            if ($status !== MatterAssignmentStatus::Pending) {
                $this->refuseSeatState($status);
            }

            $seat->forceFill(['declined_at' => now(), 'decline_reason' => $reason])->save();

            $activity = Audit::record('matter_assignment_declined', $locked, [
                'user_id' => $actor->getKey(),
                'role' => $seat->role_in_matter->value,
                'reason' => $reason,
            ], causer: $actor);

            $recipients = collect([$seat->assigned_by, $locked->lead_lawyer_id])
                ->merge(app(ResolveStaffRecipients::class)->supervisorsFor($locked)->modelKeys())
                ->filter(fn (?int $id): bool => $id !== null && $id !== $actor->getKey())
                ->map(fn (int $id): int => $id)
                ->unique()
                ->values();

            foreach ($recipients as $recipientId) {
                $this->queueSeatNotice($locked, $actor->getKey(), MatterAssignmentAlert::DECLINED, $recipientId, $activity);
            }

            // Sửa sau kiểm toán 2026-10-21 (mục 7) — xem docblock lớp, mục "Người giao các việc người đó đang giữ".
            $held = Task::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->where('matter_id', $locked->getKey())
                ->where('assignee_id', $actor->getKey())
                ->held()
                ->orderBy('id')
                ->get(['id', 'assigned_by']);

            if ($activity !== null && $held->contains(fn (Task $task): bool => $task->assigned_by !== $actor->getKey() && ! $recipients->contains($task->assigned_by))) {
                SendSeatReturnedTaskNotices::dispatch(
                    $locked->getKey(),
                    $actor->getKey(),
                    (int) $activity->getKey(),
                    $held->modelKeys(),
                    $recipients->all(),
                )->afterCommit();
            }

            return $seat;
        });
    }
}
