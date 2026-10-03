<?php

namespace App\Listeners;

use App\Actions\Notification\NotifyStaffOfNewClientDocument;
use App\Events\ClientDocumentSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Nối `App\Events\ClientDocumentSubmitted` với `NotifyStaffOfNewClientDocument` — cùng khuôn
 * `App\Listeners\SendDocumentPublishedNotification`.
 *
 * `ClientDocumentSubmitted` chưa có listener nào trước task này (đọc docblock sự kiện: "Listener
 * … thuộc M6 — không viết ở đây"). Đây là listener đầu tiên và duy nhất của nó.
 */
class SendNewClientDocumentNotification implements ShouldQueue
{
    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(private NotifyStaffOfNewClientDocument $notify) {}

    public function handle(ClientDocumentSubmitted $event): void
    {
        $this->notify->handle($event->documents);
    }

    public function failed(ClientDocumentSubmitted $event, ?Throwable $exception): void
    {
        $this->notify->reportFailure($event->documents);
    }
}
