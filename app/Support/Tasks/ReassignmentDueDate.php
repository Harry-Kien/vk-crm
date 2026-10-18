<?php

namespace App\Support\Tasks;

use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Matter\TransferStaffWork;
use App\Actions\Task\MoveTasksOnHandover;
use App\Filament\Admin\Resources\Tasks\Concerns\ProvidesTaskActions;
use App\Models\Task;

/**
 * Hạn của một việc khi nó sang tay người khác (làn fa1, phán quyết 7 — ô "Hạn" của "Giao lại"; gộp integ-fa, rà soát
 * chéo 2026-10-18, mục 2 — mọi đường chuyển hàng loạt). MỘT luật cho:
 *
 *  - hạn điền sẵn của hộp thoại "Giao lại" ({@see ProvidesTaskActions}) — người giao đổi được;
 *  - hạn mới của việc mà ba đường hàng loạt chuyển ({@see MoveTasksOnHandover} khi bàn giao vụ, {@see TransferStaffWork}
 *    khi chuyển việc nội bộ của một nhân sự, {@see RemoveTeamMember} khi gỡ thành viên kèm người nhận thay) — không ai
 *    gõ hạn ở đó, nên luật này quyết.
 *
 * Không hạn thì không hạn; hạn còn ở hôm nay hay tương lai thì giữ; hạn đã qua thì hôm nay cộng đúng số ngày người giữ
 * cũ đã có (từ ngày giao tới hạn cũ, ít nhất một ngày) — người mới có cùng khoảng thời gian để làm, và không nhận một
 * việc đã quá hạn ngay lúc nhận (bị nhắc "quá hạn" và tính trễ cho một hạn họ chưa từng giữ).
 */
final class ReassignmentDueDate
{
    /** @return string|null ngày `Y-m-d`, hay `null` khi việc không có hạn */
    public static function suggest(Task $task): ?string
    {
        $due = $task->due_date;

        if ($due === null || $due->toDateString() >= today()->toDateString()) {
            return $due?->toDateString();
        }

        $given = $task->assigned_at === null
            ? 1
            : (int) $task->assigned_at->copy()->setTimezone((string) config('app.timezone'))->startOfDay()->diffInDays($due->copy()->startOfDay());

        return today()->addDays(max(1, $given))->toDateString();
    }
}
