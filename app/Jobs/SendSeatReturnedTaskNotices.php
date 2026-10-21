<?php

namespace App\Jobs;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Matter\DeclineMatterAssignment;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\SeatReturnedTasksAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lời báo người GIAO việc khi người giữ việc "Trả lại" hồ sơ (sửa sau kiểm toán luồng Công việc 2026-10-21, mục 7).
 * {@see DeclineMatterAssignment} xếp `->afterCommit()`; payload chỉ mang id: hồ sơ, người trả lại, dòng
 * `matter_assignment_declined`, các việc họ đang giữ trên hồ sơ lúc đó, và những người ĐÃ nhận lời báo trả lại ghế.
 *
 * # Ai nhận, đọc lại lúc chạy
 *
 * Với mỗi việc trong payload mà người trả lại VẪN giữ (chờ nhận, đang làm — chưa ai giao lại), người giao HIỆN TẠI của
 * nó; trừ chính người trả lại (việc riêng), trừ những người đã nhận lời báo trả lại ghế (người giao ghế, luật sư phụ
 * trách, người giám sát vụ — họ đã biết), và chỉ người còn hoạt động, mở được hồ sơ
 * (`ResolveStaffRecipients::qualifies()` — hồ sơ hạn chế không bao giờ tới người không mở được nó). MỘT chuông mỗi người
 * cho mọi việc của họ ({@see SeatReturnedTasksAlert}: "n việc trên hồ sơ … cần giao lại").
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `seat-returned@<id dòng nhật ký>` ở `viewData.seat_returned_key`.
 */
class SendSeatReturnedTaskNotices implements ShouldQueue
{
    use Queueable;
    use ReadsWithoutPortalScope;

    public int $tries = 5;

    /**
     * @param  list<int>  $taskIds
     * @param  list<int>  $alreadyToldIds
     */
    public function __construct(
        public readonly int $matterId,
        public readonly int $memberId,
        public readonly int $eventId,
        public readonly array $taskIds,
        public readonly array $alreadyToldIds,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $matter = $this->scopelessly(Matter::query())->find($this->matterId);
        $member = User::query()->withTrashed()->find($this->memberId);

        if ($matter === null || $member === null || $this->taskIds === []) {
            return;
        }

        $counts = $this->scopelessly(Task::query())
            ->whereKey($this->taskIds)
            ->where('matter_id', $matter->getKey())
            ->where('assignee_id', $member->getKey())
            ->held()
            ->pluck('assigned_by')
            ->filter(fn (?int $id): bool => $id !== null && $id !== $member->getKey() && ! in_array($id, $this->alreadyToldIds, true))
            ->countBy()
            ->all();

        if ($counts === []) {
            return;
        }

        $key = 'seat-returned@'.$this->eventId;

        foreach (User::query()->whereKey(array_keys($counts))->get() as $user) {
            if (! $recipients->qualifies($user, $matter)) {
                continue;
            }

            $already = $user->notifications()
                ->where('type', SeatReturnedTasksAlert::class)
                ->where('data->viewData->seat_returned_key', $key)
                ->exists();

            if (! $already) {
                $user->notify(new SeatReturnedTasksAlert((int) $matter->getKey(), (string) $matter->code, (string) $member->name, $counts[$user->getKey()], $key));
            }
        }
    }
}
