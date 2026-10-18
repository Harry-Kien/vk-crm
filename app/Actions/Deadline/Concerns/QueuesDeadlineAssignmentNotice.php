<?php

namespace App\Actions\Deadline\Concerns;

use App\Jobs\SendDeadlineAssignmentNotice;
use App\Models\Deadline;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa2, phán quyết 6 — một định nghĩa của "khi nào báo người giữ mốc mới", dùng chung cho `AddMatterDeadline`,
 * `UpdateDeadline` và `ChangeDeadlineResponsible`: người giữ mốc THẬT SỰ đổi (hay mốc vừa được thêm, `$previous`
 * rỗng) và người đó KHÔNG phải người bấm. Xếp {@see SendDeadlineAssignmentNotice} `->afterCommit()` (M6.5 R2) —
 * không gửi gì trong transaction; người nhận được lọc lại lúc job chạy.
 */
trait QueuesDeadlineAssignmentNotice
{
    private function queueAssignmentNotice(Deadline $deadline, User $holder, User $actor, ?Activity $event, ?int $previous): void
    {
        if ($event === null || $holder->is($actor) || $previous === (int) $holder->getKey()) {
            return;
        }

        SendDeadlineAssignmentNotice::dispatch($deadline->getKey(), $holder->getKey(), (int) $event->getKey())->afterCommit();
    }
}
