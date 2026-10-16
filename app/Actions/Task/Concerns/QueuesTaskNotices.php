<?php

namespace App\Actions\Task\Concerns;

use App\Jobs\SendTaskNotice;
use App\Models\Task;
use Spatie\Activitylog\Models\Activity;

/**
 * Xếp lời báo (chuông + thư) của một lần chuyển trạng thái — `->afterCommit()` (M6.5 R2): gọi BÊN
 * TRONG transaction của Action, job chỉ vào hàng đợi sau khi transaction ngoài cùng commit; rollback thì
 * không ai được báo về một việc không tồn tại. Job đọc lại mọi thứ khi chạy và tự lọc người nhận —
 * xem {@see SendTaskNotice}.
 *
 * `$activity` là dòng nhật ký của chính lần chuyển này: id của nó là khoá chống trùng của chuông và của
 * thư (một việc có thể được giao, trả lại, giao lại nhiều lần — mỗi lần là một lời báo riêng).
 */
trait QueuesTaskNotices
{
    protected function queueNotice(Task $task, string $kind, ?int $recipientId, ?Activity $activity): void
    {
        if ($recipientId === null || $activity === null) {
            return;
        }

        SendTaskNotice::dispatch($task->getKey(), $kind, $recipientId, $activity->getKey())->afterCommit();
    }
}
