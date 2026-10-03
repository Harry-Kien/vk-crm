<?php

namespace App\Listeners;

use App\Actions\Notification\NotifyClientOfChecklistItemRejected;
use App\Events\ChecklistItemRejected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Nối `App\Events\ChecklistItemRejected` với `NotifyClientOfChecklistItemRejected` — cùng khuôn
 * `App\Listeners\SendStageUpdateNotification` (xem docblock lớp đó cho toàn bộ lý lẽ về
 * `ShouldQueue`, đăng ký bằng tự dò, và `$tries`/`$backoff`).
 */
class SendChecklistItemRejectedNotification implements ShouldQueue
{
    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(private NotifyClientOfChecklistItemRejected $notify) {}

    public function handle(ChecklistItemRejected $event): void
    {
        $this->notify->handle($event->checklistItem);
    }

    public function failed(ChecklistItemRejected $event, ?Throwable $exception): void
    {
        $this->notify->reportFailure($event->checklistItem);
    }
}
