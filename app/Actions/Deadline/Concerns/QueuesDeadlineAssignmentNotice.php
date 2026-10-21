<?php

namespace App\Actions\Deadline\Concerns;

use App\Jobs\SendDeadlineAssignmentNotice;
use App\Jobs\SendDeadlineHandedAwayNotice;
use App\Models\Deadline;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa2, phán quyết 6 — một định nghĩa của "khi nào báo người giữ mốc mới", dùng chung cho `AddMatterDeadline`,
 * `UpdateDeadline` và `ChangeDeadlineResponsible`: người giữ mốc THẬT SỰ đổi (hay mốc vừa được thêm, `$previous`
 * rỗng) và người đó KHÔNG phải người bấm. Xếp {@see SendDeadlineAssignmentNotice} `->afterCommit()` (M6.5 R2) —
 * không gửi gì trong transaction; người nhận được lọc lại lúc job chạy. Gộp integ-fa (rà soát chéo, mục 16): người giữ
 * mốc CŨ được báo bằng một chuông tin ({@see self::queueHandedAwayNotice()}) — `ChangeDeadlineResponsible`, `UpdateDeadline`.
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

    /**
     * Gộp integ-fa (rà soát chéo 2026-10-18, mục 16): người giữ mốc CŨ — khi có, khi mốc thật sự đổi người, và khi họ
     * không phải người bấm — nhận một chuông tin "mốc đã chuyển cho X" ({@see SendDeadlineHandedAwayNotice}, sau commit).
     */
    private function queueHandedAwayNotice(Deadline $deadline, User $holder, User $actor, ?Activity $event, ?int $previous): void
    {
        if ($event === null || $previous === null || $previous === (int) $actor->getKey() || $previous === (int) $holder->getKey()) {
            return;
        }

        SendDeadlineHandedAwayNotice::dispatch($deadline->getKey(), $previous, (int) $event->getKey())->afterCommit();
    }
}
