<?php

namespace App\Events;

use App\Models\StageLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatch ở SPEC §6.2 bước 6, khi một `StageLog` vừa tạo có `publish = true` VÀ
 * `matter.is_published_to_portal = true`. Listener (dispatch job `SendStageUpdateNotification`,
 * kiểm tra `notified_at` để chống gửi trùng) thuộc M6 — không viết ở đây.
 */
class StageLogPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly StageLog $stageLog) {}
}
